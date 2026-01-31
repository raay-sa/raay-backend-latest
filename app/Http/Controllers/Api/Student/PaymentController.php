<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use Illuminate\Http\Request;
use App\Services\TapPaymentService;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected $tap;

    public function __construct(TapPaymentService $tap)
    {
        $this->tap = $tap;
    }

    /**
     * Initiate payment process
     */
    public function checkout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Log the incoming request for debugging
            Log::info('Payment checkout initiated', [
                'amount' => $request->amount,
                'currency' => 'SAR',
                // 'request_data' => $request->all()
            ]);

            DB::beginTransaction();

            $user = $request->user();
            $cartItems = Cart::where('student_id', $user->id)->with('program')->get();

            if ($request->amount != $cartItems->sum('program.price')) {
                return response()->json([
                    'error' => 'Amount mismatch',
                    'correct_amount' => $cartItems->sum('program.price')
                ], 400);
            }

            // Prepare customer data
            $customer = [
                "first_name" => $user->name ?? 'Customer',
                "last_name" => '',
                "email" => $user->email,
                "phone" => [
                    "country_code" => "966",
                    "number" => $user->phone ?? "50000000"
                ],
            ];

            // Generate unique reference
            $reference = 'ORD-' . $user->id . '-' . Str::random(8);

            // Callback URLs
            $successUrl = route('api.payment.success', ['order' => $reference]);
            $cancelUrl = route('api.payment.cancel', ['order' => $reference]);

            // Create payment record
            $transaction = new Transaction();
            $transaction->student_id     = $user->id;
            $transaction->total_price    = $request->amount;
            $transaction->currency       = 'SAR';
            $transaction->status         = 'pending';
            $transaction->save();

            // Metadata for tracking
            $metadata = [
                'order_id' => $transaction->id,
                'payment_id' => $transaction->id,
                'reference' => $reference,
                'user_id' => $user->id
            ];

            Log::info('Creating Tap charge with data', [
                'amount' => $request->amount,
                'currency' => 'SAR',
                'customer' => $customer,
                'successUrl' => $successUrl,
                'metadata' => $metadata
            ]);

            // Create Tap charge
            $charge = $this->tap->createCharge(
                $request->amount,
                'SAR',
                $customer,
                $successUrl,
                $metadata
            );

            Log::info('Tap charge created successfully', ['charge' => $charge]);

            $transaction->invoice_id = $charge['id'];
            $transaction->reference  = json_encode($charge);
            $transaction->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'payment_url' => $charge['transaction']['url'],
                // 'charge_id' => $charge['id'],
                // 'reference' => $reference,
                // 'charge_data' => $charge // Include full response for debugging
            ]);

        } catch (\Exception $e) {
            DB::rollback();

            Log::error('Payment Checkout Error - Full Details', [
                'user_id' => $user->id,
                'order_id' => $transaction->id,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            // Return more detailed error for debugging
            return response()->json([
                'error' => 'Unable to process payment. Please try again.',
                'debug_info' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Handle successful payment callback
     */
    public function success(Request $request)
    {
        try {
            // Log all incoming request data for debugging
            Log::info('Payment Success Callback - Full Request', [
                'all_parameters' => $request->all(),
                'query_string' => $request->getQueryString(),
                'url' => $request->fullUrl()
            ]);

            $tapId = $request->tap_id;

            if (!$tapId) {
                Log::warning('No tap_id in success callback', ['request_data' => $request->all()]);
                return response()->json([
                    'success' => false,
                    'message' => 'No tap_id found in callback',
                    'request_data' => $request->all()
                ], 400);
            }

            Log::info('Retrieving charge details from Tap', ['tap_id' => $tapId]);

            // Retrieve charge details from Tap
            $charge = $this->tap->retrieveCharge($tapId);

            $transaction = Transaction::where('invoice_id', $tapId)->first();

            if (!$transaction) {
                Log::error('Payment record not found', ['tap_id' => $tapId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Payment record not found',
                    'tap_id' => $tapId
                ], 400);
            }

            $this->updatePaymentStatus($transaction, $charge);

            Log::info('Retrieved charge from Tap', [
                'tap_id' => $tapId,
                'charge_status' => $charge['status'] ?? 'unknown',
                'full_charge' => $charge
            ]);

            if ($charge['status'] === 'CAPTURED') {
                Log::info('Payment CAPTURED successfully', ['tap_id' => $tapId]);

                return response()->json([
                    'success' => true,
                    'message' => __('trans.alert.success.payment_successfully'),
                    // 'tap_id' => $tapId,
                    // 'status' => $charge['status'],
                    // 'amount' => $charge['amount'] ?? 'unknown',
                    // 'currency' => $charge['currency'] ?? 'unknown',
                    // 'full_charge_data' => $charge
                ]);
            } else {
                Log::warning('Payment not captured', [
                    'tap_id' => $tapId,
                    'status' => $charge['status'],
                    'full_charge' => $charge
                ]);

                return response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.payment_failed'),
                    // 'tap_id' => $tapId,
                    // 'status' => $charge['status'],
                    // 'full_charge_data' => $charge
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Payment Success Callback Error - Detailed', [
                'tap_id' => $request->tap_id ?? 'not_provided',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'request_data' => $request->all(),
                'stack_trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Payment verification failed: ' . $e->getMessage(),
                'tap_id' => $request->tap_id ?? 'not_provided',
                'debug_info' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Handle payment cancellation
     */
    public function cancel(Request $request)
    {
        Log::info('Payment Cancel Callback', [
            'all_parameters' => $request->all(),
            'tap_id' => $request->tap_id ?? 'not_provided'
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Payment was cancelled by user',
            // 'tap_id' => $request->tap_id ?? 'not_provided',
            // 'request_data' => $request->all()
        ]);
    }

    /**
     * Handle Tap webhook notifications
     */
    public function webhook(Request $request)
    {
        try {
            $payload = $request->getContent();
            $signature = $request->header('X-Tap-Signature');

            // Verify webhook signature
            if (!$this->tap->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Invalid webhook signature');
                return response('Invalid signature', 400);
            }

            $data = json_decode($payload, true);

            Log::info('Tap Webhook Received', ['event' => $data['event'] ?? 'unknown']);

            // Handle different webhook events
            switch ($data['event']) {
                case 'charge.updated':
                    $this->handleChargeUpdate($data['data']);
                    break;

                case 'refund.updated':
                    $this->handleRefundUpdate($data['data']);
                    break;

                default:
                    Log::info('Unhandled webhook event', ['event' => $data['event']]);
            }

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('Webhook processing error', ['error' => $e->getMessage()]);
            return response('Error', 500);
        }
    }

    /**
     * Process refund request
     */
    public function refund(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'amount'         => 'nullable|numeric|min:0.01',
            'reason'         => 'nullable|string|max:255'
        ]);

        try {
            $transaction = Transaction::findOrFail($request->transaction_id);

            // Check if transaction is refundable
            if ($transaction->status !== 'completed') {
                return response()->json(['error' => 'Payment cannot be refunded'], 400);
            }

            $refund = $this->tap->createRefund(
                // $payment->tap_charge_id,
                $transaction->invoice_id,
                $request->amount,
                $request->reason
            );

            $transaction->reverse_status = $request->amount ? 'partially_refunded' : 'refunded';
            $transaction->reverse_message = json_encode($refund);
            // 'refunded_at' => now(),
            $transaction->save();

            return response()->json([
                'success' => true,
                'refund_id' => $refund['id'],
                'message' => 'Refund processed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Refund Error', [
                'transaction_id' => $request->transaction_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Unable to process refund'
            ], 500);
        }
    }

    /**
     * Update payment status based on charge data
     */
    private function updatePaymentStatus($transaction, $charge)
    {
        $statusMap = [
            'INITIATED'  => 'pending',
            'ABANDONED'  => 'failed',
            'CANCELLED'  => 'cancelled',
            'FAILED'     => 'failed',
            'DECLINED'   => 'failed',
            'RESTRICTED' => 'failed',
            'CAPTURED'   => 'completed',
            'VOID'       => 'cancelled',
            'TIMEDOUT'   => 'failed',
        ];

        $status = $statusMap[$charge['status']] ?? 'unknown';

        $transaction->merchant_id    = $charge['merchant']['id'] ?? null; // معرف التاجر
        $transaction->transaction_id = $charge['transaction']['authorization_id'] ?? null;
        $transaction->payment_id     = $charge['metadata']['payment_id'] ?? null;
        $transaction->holder_name    = $charge['customer']['first_name'] ?? null;
        $transaction->payment_brand  = $charge['card']['brand'] ?? null;
        $transaction->currency       = $charge['currency'] ?? 'SAR';
        $transaction->status         = $status;
        $transaction->result_status  = $statusMap[$charge['status']];
        $transaction->result_code    = $charge['response']['code'] ?? null;
        $transaction->result_message = $charge['response']['message'] ?? null;
        $transaction->reference      = json_encode($charge);
        $transaction->save();

        if($status === 'completed') {
            $cartItems = Cart::where('student_id', $transaction->student_id)->with('program')->get();

            // إضافة الاشتراكات من السلة
            $cartPrograms = $cartItems->pluck('program_id')->toArray();
            $transaction->programs()->sync($cartPrograms);

            foreach ($cartItems as $item) {
                $subscrip = new Subscription();
                $subscrip->price      = $item->program->price;
                $subscrip->student_id = $item->student_id;
                $subscrip->program_id = $item->program_id;
                $subscrip->save();

                $item->delete(); // حذف من السلة
            }
        }
    }

    /**
     * Handle charge update webhook
     */
    private function handleChargeUpdate($chargeData)
    {
        return ['chargeData' => $chargeData];

        $transaction = Transaction::where('invoice_id', $chargeData['id'])->first();

        if ($transaction) {
            $this->updatePaymentStatus($transaction, $chargeData);

            // Update order status if payment completed
            if ($chargeData['status'] === 'CAPTURED') {
                $transaction->status = 'completed';
                $transaction->save();
            }
        }
    }

    /**
     * Handle refund update webhook
     */
    private function handleRefundUpdate($refundData)
    {
        // Handle refund status updates
        Log::info('Refund updated', ['refund_data' => $refundData]);
    }
}




// {
// "success": true,
// "message": "Payment completed successfully!",
// "tap_id": "chg_TS03A3820251544t5Q71709104",
// "status": "CAPTURED",
// "amount": 10,
// "currency": "SAR",
// "full_charge_data": {
// "id": "chg_TS03A3820251544t5Q71709104",
// "object": "charge",
// "live_mode": false,
// "customer_initiated": true,
// "api_version": "V2",
// "method": "GET",
// "status": "CAPTURED",
// "amount": 10,
// "currency": "SAR",
// "threeDSecure": true,
// "card_threeDSecure": true,
// "save_card": false,
// "product": "",
// "statement_descriptor": "Your Store Payment",
// "description": "Order Payment",
// "metadata": {
// "order_id": "1",
// "payment_id": "1",
// "reference": "ORD-1-Rd801N2W",
// "user_id": "1"
// },
// "order": {
// "id": "ord_POX842251544jrqe17588A430"
// },
// "transaction": {
// "authorization_id": "143571",
// "timezone": "UTC+03:00",
// "created": "1758123878103",
// "expiry": {
// "period": 30,
// "type": "MINUTE"
// },
// "asynchronous": false,
// "amount": 10,
// "currency": "SAR",
// "date": {
// "created": 1758123878103,
// "completed": 1758123938923,
// "transaction": 1758123878103
// }
// },
// "reference": {
// "track": "tck_TS03A3820251544u7BF1709114",
// "payment": "38172515440911427075",
// "acquirer": "526015143571",
// "gateway": "123456789"
// },
// "response": {
// "code": "000",
// "message": "Captured"
// },
// "card_security": {
// "code": "M",
// "message": "MATCH"
// },
// "security": {
// "threeDSecure": {
// "id": "auth_payer_kmRt5251545VFtG17jV8k946",
// "status": "Y"
// }
// },
// "acquirer": {
// "response": {
// "code": "00",
// "message": "Approved"
// }
// },
// "gateway": {
// "response": {
// "code": "0",
// "message": "APPROVED"
// }
// },
// "card": {
// "object": "card",
// "first_six": "512345",
// "first_eight": "51234500",
// "scheme": "MASTERCARD",
// "brand": "MASTERCARD",
// "last_four": "0008"
// },
// "receipt": {
// "id": "203817251544095779",
// "email": true,
// "sms": true
// },
// "customer": {
// "id": "cus_TS03A3520251545Rw7k1709591",
// "first_name": "Customer",
// "email": "customer@example.com",
// "phone": {
// "country_code": "965",
// "number": "50000000"
// }
// },
// "merchant": {
// "country": "SA",
// "currency": "SAR",
// "id": "36757024"
// },
// "source": {
// "object": "token",
// "type": "CARD_NOT_PRESENT",
// "payment_type": "DEBIT",
// "channel": "INTERNET",
// "id": "tok_TS75A5251545Mg0h17tQ8g69",
// "on_file": false,
// "payment_method": "MASTERCARD"
// },
// "redirect": {
// "status": "SUCCESS",
// "url": "http://127.0.0.1:8000/api/payment/success/1"
// },
// "post": {
// "status": "ERROR",
// "url": "http://127.0.0.1:8000/api/payment/webhook"
// },
// "authentication": {
// "version": "3DS2",
// "acsEci": "02",
// "authentication_token": "kHyn+7YFi1EUAREAAAAvNUe6Hv8=",
// "transaction_id": "b7bff35c-5250-45c7-8128-432d090ed8e2",
// "paRes_status": "Y",
// "protocol_version": "2.2.0",
// "transaction_status": "Y",
// "ds_transaction_id": "b7bff35c-5250-45c7-8128-432d090ed8e2",
// "mode": "C",
// "acs_transaction_id": "776806b4-da10-4322-8919-edfffc3cee5e",
// "acquirer_merchant_id": "TEST80001701",
// "provider": "MPGS",
// "id": "auth_payer_kmRt5251545VFtG17jV8k946",
// "status": "AUTHENTICATED"
// },
// "activities": [
// {
// "id": "activity_TS07A3620251545k0B21709701",
// "object": "activity",
// "created": 1758123878103,
// "status": "INITIATED",
// "currency": "SAR",
// "amount": 10,
// "remarks": "charge - created",
// "txn_id": "chg_TS03A3820251544t5Q71709104"
// },
// {
// "id": "activity_TS06A3820251545l5RJ1709923",
// "object": "activity",
// "created": 1758123938923,
// "status": "CAPTURED",
// "currency": "SAR",
// "amount": 10,
// "remarks": "charge - captured",
// "txn_id": "chg_TS03A3820251544t5Q71709104"
// }
// ],
// "auto_reversed": false,
// "intent": {
// "id": "intent_yOHj382515445lti17rI8s311"
// },
// "protect": {
// "id": "protect_TS053620251545h7J31709662",
// "is_in_exclusion_list": true,
// "status": "SCREENED"
// }
// }
// }
