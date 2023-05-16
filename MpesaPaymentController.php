<?php

namespace App\Http\Controllers;

use PDF;
use Carbon\Carbon;
use App\Helpers\Mpesa;
use App\Models\Member;
use App\Models\Invoice;
use App\Models\Payment;
use App\Jobs\SendDocuments;
use Illuminate\Support\Str;
use App\Models\MpesaPayment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\RegistrationPayment;
use App\Notifications\InvoicePayment;

class MpesaPaymentController extends Controller
{
    /**
    * @param $phone
    * @param $amount
    * @param $callback
    * @param $account_number
    * @param $remarks
    * @return array
    */
   public function stkPush($phone, $amount, $callback, $account_number, $remarks)
   {
    $url = Mpesa::oxerus_mpesaGetStkPushUrl();
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . Mpesa::oxerus_mpesaGenerateAccessToken()));
    $curl_post_data = [
        'BusinessShortCode' => config('services.mpesa.business_shortcode'),
        'Password' => Mpesa::oxerus_mpesaLipaNaMpesaPassword(),
        'Timestamp' => Carbon::now()->format('YmdHis'),
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => config('services.mpesa.business_shortcode'),
        'PhoneNumber' => $phone,
        'CallBackURL' => $callback,
        'AccountReference' => $account_number,
        'TransactionDesc' => $remarks,
    ];
    $data_string = json_encode($curl_post_data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
    $curl_response = curl_exec($curl);
    $responseObj = json_decode($curl_response);
    $response_details = [
        "merchant_request_id" => $responseObj->MerchantRequestID ?? null,
        "checkout_request_id" => $responseObj->CheckoutRequestID ?? null,
        "response_code" => $responseObj->ResponseCode ?? null,
        "response_desc" => $responseObj->ResponseDescription ?? null,
        "customer_msg" => $responseObj->CustomerMessage ?? null,
        "phone" => $phone,
        "amount" => $amount,
        "remarks" => $remarks
    ];

    return $response_details;
   }

   /**
    * @param Request $request
    */
   public function stkPushCallback(Request $request)
   {
      $callbackJSONData = file_get_contents('php://input');
      $callbackData = json_decode($callbackJSONData);

      info($callbackJSONData);

      $result_code = $callbackData->Body->stkCallback->ResultCode;
      // $result_desc = $callbackData->Body->stkCallback->ResultDesc;
      $merchant_request_id = $callbackData->Body->stkCallback->MerchantRequestID;
      $checkout_request_id = $callbackData->Body->stkCallback->CheckoutRequestID;
      $amount = $callbackData->Body->stkCallback->CallbackMetadata->Item[0]->Value;
      $mpesa_receipt_number = $callbackData->Body->stkCallback->CallbackMetadata->Item[1]->Value;
      // $transaction_date = $callbackData->Body->stkCallback->CallbackMetadata->Item[3]->Value;
      // $phone_number = $callbackData->Body->stkCallback->CallbackMetadata->Item[4]->Value;


      $result = [
         // "result_desc" => $result_desc,
         "result_code" => $result_code,
         "merchant_request_id" => $merchant_request_id,
         "checkout_request_id" => $checkout_request_id,
         "amount" => $amount,
         "mpesa_receipt_number" => $mpesa_receipt_number,
         // "phone" => $phone_number,
         // "transaction_date" => Carbon::parse($transaction_date)->toDateTimeString()
      ];

      if($result['result_code'] == 0) {
        $mpesa_payment = MpesaPayment::where('checkout_request_id', $result['checkout_request_id'])->first();

        if ($mpesa_payment->purpose === 'License Payment' || $mpesa_payment->purpose === NULL) {
            $invoice = Invoice::with('licenses', 'payments')->where('invoice_id', $mpesa_payment->account)->first();

            $PSV = ['CSOK-03'];
            $Broadcaster = ['CSOK-19', 'CSOK-20', 'CSOK-21', 'CSOK-22'];

            $payment = Payment::create([
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'amount' => $result['amount'],
                'method' => 'Mpesa',
                'transaction_id' => $result['mpesa_receipt_number']
            ]);

            if ((int) $result['amount'] == ((int) $invoice->getDiscountedPrice() - (int) $invoice->payments->sum('amount'))) {
                $invoice->status = 'Paid';
                $invoice->save();

                $licenses = $invoice->licenses->count() > 0 ? $invoice->licenses : $invoice->psvLicenses;

                foreach ($licenses as $key => $license) {
                    $license->update([
                        'valid_until' => now()->addYear()
                    ]);
                }

                $licenses_pdfs = [];

                foreach ($licenses as $key => $license) {
                    if (in_array($license->tariff->category, $PSV)) {
                        $license['type'] = 'PSV';
                        $pdf = PDF::loadView("Invoices.psvlicences", compact('license'));
                    } elseif (in_array($license->tariff->category, $Broadcaster)) {
                        $license['type'] = 'Broadcaster';
                        $pdf = PDF::loadView("Invoices.broadcatingLicences", compact('license'));
                    } else {
                        $license['type'] = 'Business';
                        $pdf = PDF::loadView("Invoices.BusinessLicences", compact('license'));
                    }
                    array_push($licenses_pdfs, $pdf->output());
                }

                $invoice_pdf = PDF::loadView("Invoices.invoiceMelanie", compact('invoice'));

                SendDocuments::dispatchAfterResponse($invoice->user, 'License and Invoice', $licenses_pdfs, $invoice_pdf->output());
            } else {
                if ($payment->invoice->licenses->count() > 0) {
                    if (in_array($payment->invoice->licenses->first()->tariff->category, $Broadcaster)) {
                        $payment['type'] = 'Broadcaster';
                    } else {
                        $payment['type'] = 'Business';
                    }
                } else {
                    $payment['type'] = 'PSV';
                }
                // Issue Receipt and send updated invoice and receipt to email
                $receipt_pdf = PDF::loadView("receipt.receipt", compact('payment'));
                $invoice_pdf = PDF::loadView("Invoices.invoiceMelanie", compact('invoice'));

                SendDocuments::dispatchAfterResponse($invoice->user, 'Invoice and Receipt', NULL, $invoice_pdf->output(), $receipt_pdf->output());
            }

            activity()
                ->causedBy($invoice->user)
                ->log($invoice->user->first_name.' '.$invoice->user->last_name.' paid for the Invoice #'.$invoice->invoice_id);

            $invoice->user->notify(new InvoicePayment($invoice));

        } elseif ($mpesa_payment->purpose === 'Registration Payment') {
            $member = Member::where('tracking_number', $mpesa_payment->account)->first();
            $registration_payment = RegistrationPayment::updateOrCreate(
                [
                    'member_id' => $member->id
                ],
                [
                    'balance' => (int) config('services.membership.registration_amount') - (int) $result['amount'],
                ],
            );

            $registration_payment = RegistrationPayment::where('member_id', $member->id)->first();

            if ($registration_payment->balance == 0) {
                activity()
                    ->causedBy($member)
                    ->log($member->business_name ? $member->business_name : $member->surname.', '.$member->other_names.' paid their registration fee.');
            }

            $pdf = PDF::loadView("receipt.membership-receipt", compact('registration_payment'));

            SendDocuments::dispatchAfterResponse($registration_payment->member, 'Receipt', NULL, NULL, $pdf->output());
        }
        //  SendSms::dispatchAfterResponse($order->service->vendor->company_phone_number, 'The client completed the payment for the order '.$order->order_id);
      }
   }

   public function confirmationCallback(Request $request)
   {
        $callbackJSONData = file_get_contents('php://input');
        $callbackData = json_decode($callbackJSONData);

        $result = [
            'transaction_id' => $callbackData->data->TransID,
            'payment_ref' => $callbackData->data->BillRefNumber,
            'amount' => $callbackData->data->TransAmount,
        ];

        $invoice = Invoice::with('licenses', 'psvLicenses')->where('invoice_id', Str::upper($result['payment_ref']))->first();

        if ($invoice) {
            $payment = Payment::create([
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'amount' => $result['amount'],
                'method' => 'Mpesa',
                'transaction_id' => $result['transaction_id']
            ]);

            if ((int) $result['amount'] == ((int) $invoice->getDiscountedPrice() - (int) $invoice->payments->sum('amount'))) {
                $invoice->status = 'Paid';
                $invoice->save();

                $licenses = $invoice->licenses->count() > 0 ? $invoice->licenses : $invoice->psvLicenses;

                foreach ($licenses as $key => $license) {
                    $license->update([
                        'valid_until' => now()->addYear()
                    ]);
                }
            } else {
                $PSV = ['CSOK-03'];
                $Broadcaster = ['CSOK-19', 'CSOK-20', 'CSOK-21', 'CSOK-22'];

                if ($payment->invoice->licenses->count() > 0) {
                    if (in_array($payment->invoice->licenses->first()->tariff->category, $Broadcaster)) {
                        $payment['type'] = 'Broadcaster';
                    } else {
                        $payment['type'] = 'Business';
                    }
                } else {
                    $payment['type'] = 'PSV';
                }
                // Issue Receipt and send updated invoice and receipt to email
                $receipt_pdf = PDF::loadView("receipt.receipt", compact('payment'));
                $invoice_pdf = PDF::loadView("Invoices.invoiceMelanie", compact('invoice'));

                SendDocuments::dispatchAfterResponse($invoice->user, 'Invoice and Receipt', NULL, $invoice_pdf->output(), $receipt_pdf->output());
            }

            $invoice->user->notify(new InvoicePayment($invoice));
            
            activity()
                ->causedBy($invoice->user)
                ->log($invoice->user->first_name.' '.$invoice->user->last_name.' paid for the Invoice #'.$invoice->invoice_id);
        } else {
            $member = Member::where('tracking_number', $result['payment_ref'])->first();

            if ($member) {
                $registration_payment = RegistrationPayment::updateOrInsert(
                    [
                        'member_id' => $mpesa_payment->member->id
                    ],
                    [
                        'balance' => (int) config('services.membership.registration_amount') - (int) $result['amount'],
                    ],
                );
            }
        }
   }

   public function validationUrl(Request $request)
   {
        info($request);
   }

   public function registerUrl()
   {
        $url = 'https://api.safaricom.co.ke/mpesa/c2b/v1/registerurl';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization:Bearer ' . Mpesa::oxerus_mpesaGenerateAccessToken(),
            'Content-Type: application/json'
        ]);

        $curl_post_data = [
            "ShortCode" => "884350",
            "ResponseType" => "Completed",
            "ConfirmationURL" => route('confirmation.callback'),
            "ValidationURL" => route('validation.callback'),
        ];

        $data_string = json_encode($curl_post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        $curl_response = curl_exec($curl);
        $responseObj = json_decode($curl_response);

        return response()->json($responseObj, 200);
   }
}
