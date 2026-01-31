<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Notifications\PaymentSuccessNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDF;

class TransactionController extends Controller
{
    public $moyasar_secret_key;
    public $frontend_payment_url;

    public function __construct()
    {
        $this->moyasar_secret_key = config('services.moyasar.secret_key');
        $this->frontend_payment_url = env('FRONTEND_Payment_URL');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $query = Transaction::where('student_id', $user->id)
            ->where('status', 'completed')
            ->withCount('programs')
            ->with('programs:id,category_id', 'programs.translations');

        if ($filter === 'latest') {
            $query->orderBy('created_at', 'desc');
        } elseif ($filter === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $transactions = $query->get();

        $total = $transactions->count();
        $success_count = $transactions->count();

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($transactions, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_count' => $total,
            'success_count' => $success_count,
            'data' => $paginated
        ], 200);
    }

    public function create(Request $request)
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

    public function show(Request $request, $id)
    {
        $transaction = Transaction::with(['programs:id,category_id', 'programs.translations'])
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ]), 422);
        });

        // Calculate tax breakdown for invoice response (same as cart and payment)
        $totalPrice = (float) ($transaction->total_price ?? 0);
        $taxBreakdown = getTaxBreakdown($totalPrice);

        return response()->json([
            'success' => true,
            'data' => $transaction,
            'invoice_summary' => [
                'subtotal' => $taxBreakdown['subtotal'],
                'tax_amount' => $taxBreakdown['tax_amount'],
                'tax_rate' => $taxBreakdown['tax_rate'],
                'total' => $taxBreakdown['total'],
                'currency' => $transaction->currency ?? 'SAR'
            ]
        ], 200);
    }

    public function edit(Request $request)
    {
        //
    }

    public function update(Request $request)
    {
        //
    }

    public function destroy(Request $request)
    {
        //
    }

    public function invoice(Request $request, $id)
    {
        $transaction = Transaction::with([
            'programs:id,price',
            'programs.translations',
            'student:id,name,phone'
        ])->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ]), 422);
        });

        // Load programs with category translations for the invoice view
        $transaction->load(['programs.category.translations']);

        $pdf = PDF::loadView('invoice', ['data' => $transaction], [], [
            'format' => 'A4',
            'defaultFont' => 'Cairo',
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
            'isFontSubsettingEnabled' => true,
        ]);

        $filename = 'invoice-'.now()->format('Y-m-d-His').'.pdf';

        // Get PDF output as string
        $output = $pdf->output();

        // Return as response with CORS headers
        return response($output, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin');
    }

    public function checkout(Request $request)
    {
        // purchase fron cart controller
    }

    public function moyasar_cancel(Request $request)
    {
        $transactionId = $request->query('transaction_id');

        if (!$transactionId) {
            return redirect()->away($this->frontend_payment_url . '/payment?status=failed&message=transaction_not_exist&transaction_id=' . $transactionId);
        }

        $transaction = Transaction::find($transactionId);

        if (!$transaction) {
            return redirect()->away($this->frontend_payment_url . '/payment?status=failed&message=transaction_not_exist&transaction_id=' . $transactionId);
        }

        $transaction->status = 'failed';
        $transaction->result_status = 'cancelled';
        $transaction->save();

        return redirect()->away($this->frontend_payment_url . '/payment?status=failed&message=payment_cancelled&transaction_id=' . $transactionId);
        // return response()->json([
            // 'success' => false,
            // 'message' => 'payment cancelled',
        // ]);
    }

    public function moyasar_success(Request $request)
    {
        $payment = Http::withBasicAuth($this->moyasar_secret_key, '')
            ->get("https://api.moyasar.com/v1/payments/{$request->id}");

        if ($payment->failed()) {
            return redirect()->away($this->frontend_payment_url . '/payment?status=false&message=payment_failed&transaction_id=' . $request->id);
        }

        $paymentData = $payment->json();

        DB::transaction(function () use ($request, $paymentData) {
            $transaction = Transaction::lockForUpdate()
                ->where('invoice_id', $request->invoice_id)
                ->first();

            if (!$transaction) {
                throw new \Exception('Transaction not found');
            }

            if ($transaction->status === 'completed') {
                return;
            }

            $transaction->transaction_id = $request->id;
            $transaction->payment_brand  = $paymentData['source']['company'] ?? 'unknown';
            $transaction->holder_name    = $paymentData['source']['name'] ?? 'unknown';
            $transaction->result_code    = $paymentData['source']['response_code'] ?? 'unknown';
            $transaction->result_message = $request->message ?? 'Payment successful';
            $transaction->reference      = json_encode($paymentData);

            if ($paymentData['status'] === 'paid') {
                $this->process_product_order($transaction);

                $transaction->status = 'completed';
                $transaction->result_status = 'paid';
            } else {
                $transaction->status = 'failed';
                $transaction->result_status = $paymentData['status'];
            }

            $transaction->save();

            try {
                $student = Student::find($transaction->student_id);
                if ($student) {
                    $student->notify(new PaymentSuccessNotification($transaction));
                    Log::info('Payment success email sent', [
                        'transaction_id' => $transaction->id,
                        'student_id' => $student->id
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send payment success email', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage()
                ]);
            }
        });

        // http://127.0.0.1:8000/api/moyasar/success?id=bdd81831-cd06-428d-b884-e2ed1a9a656c&status=paid&message=APPROVED&invoice_id=42c9df4c-c9bc-4648-8fb7-4ffc115f294c
        return redirect()->away($this->frontend_payment_url . '/payment?status=success&message=payment_successfully&transaction_id=' . $request->id);
    }

    public function moyasar_webhook(Request $request)
    {
        // التحقق من التوقيع
        $signature = $request->header('X-Moyasar-Signature');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $this->moyasar_secret_key);

        if (!hash_equals($expectedSignature, $signature)) {
            abort(403, 'Invalid webhook signature');
        }

        DB::transaction(function () use ($request) {
            $transaction = Transaction::lockForUpdate()
                ->where('invoice_id', $request->invoice_id)
                ->first();

            if (!$transaction || $transaction->status === 'successful') {
                return; // تم معالجته مسبقًا
            }

            if ($request->status === 'paid') {
                // هنا فقط يتم حفظ المنتجات والاستشارات
                $this->process_product_order($transaction);

                $transaction->status        = 'successful';
                $transaction->result_status = 'paid';
                $transaction->reference     = json_encode($request->all());
                $transaction->save();
            }
        });

        return redirect()->away($this->frontend_payment_url . '/payment?status=success&message=payment_successfully&transaction_id=' . $request->id);
        // return response()->json(['message' => 'received'], 200);
    }

    private function process_product_order($transaction)
    {
        // Check if this is a single course purchase

        // $chargeData = json_decode($transaction->reference, true);
        // $cartItemId = $chargeData['metadata']['cart_item_id'] ?? null;
        $cartItemId = $transaction->cart_id;

        if ($cartItemId) {
            // Single course purchase - handle specific cart item
            $cartItem = Cart::where('id', $cartItemId)
                ->where('student_id', $transaction->student_id)
                ->with('program')
                ->first();

            if ($cartItem) {
                $transaction->programs()->sync([$cartItem->program_id]);

                $subscription = Subscription::where('student_id', $cartItem->student_id)
                    ->where('program_id', $cartItem->program_id)->first();

                if($subscription) {
                    $subscription->price       = $cartItem->program->price;
                    $subscription->start_date  = now();
                    $subscription->expire_date = now()->addDays($cartItem->program->duration ?? $cartItem->program->date_to);
                    $subscription->status      = 'active';
                    $subscription->save();
                } else {
                    $subscrip = new Subscription();
                    $subscrip->price       = $cartItem->program->price;
                    $subscrip->student_id  = $cartItem->student_id;
                    $subscrip->program_id  = $cartItem->program_id;
                    $subscrip->start_date  = now();
                    $subscrip->expire_date = now()->addDays($cartItem->program->duration ?? $cartItem->program->date_to);
                    $subscrip->save();
                }

                $cartItem->delete(); // Remove from cart
            }
        } else {
            // Bulk purchase - handle all cart items
            $cartItems = Cart::where('student_id', $transaction->student_id)->with('program')->get();

            // إضافة الاشتراكات من السلة
            $cartPrograms = $cartItems->pluck('program_id')->toArray();
            $transaction->programs()->sync($cartPrograms);

            foreach ($cartItems as $item) {
                $subscription = Subscription::where('student_id', $item->student_id)
                ->where('program_id', $item->program_id)->first();

                if($subscription) {
                    $subscription->price       = $item->program->price;
                    $subscription->start_date  = now();
                    $subscription->expire_date = now()->addDays($item->program->duration ?? $item->program->date_to);
                    $subscription->status      = 'active';
                    $subscription->save();
                } else {
                    $subscrip = new Subscription();
                    $subscrip->price       = $item->program->price;
                    $subscrip->student_id  = $item->student_id;
                    $subscrip->program_id  = $item->program_id;
                    $subscrip->start_date  = now();
                    $subscrip->expire_date = now()->addDays($item->program->duration ?? $item->program->date_to);
                    $subscrip->save();
                }

                $item->delete(); // حذف من السلة
            }
        }
    }
}

