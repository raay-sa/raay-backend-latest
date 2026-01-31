<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
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
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $cart_count = Cart::where('student_id', $user->id)->count();
        $cart_programs = Cart::where('student_id', $user->id)->pluck('program_id')->toArray();
        $cart_total = Program::whereIn('id', $cart_programs)->sum('price');

        // Calculate tax breakdown for total cart
        $cartTaxBreakdown = getTaxBreakdown($cart_total);

        $cartItems = Cart::with([
            'program' => function ($q) {
                $q->select('id', 'price', 'level', 'image', 'category_id')
                    ->withAvg('reviews', 'score')
                    ->withCount('reviews')
                    ->withSum('sessions as program_duration', 'video_duration')
                    ->withCount('sessions as video_count')
                    ->with([
                        'translations',
                        'category:id',
                        'category.translations',
                        'sections' => function ($q) {
                            $q->withCount('sessions as video_count');
                        }
                    ]);
            }
        ])
            ->where('student_id', $user->id)
            ->get()
            ->map(function ($item) {
                $item->program->program_duration = formatDuration($item->program->program_duration);

                // Add tax breakdown for each item
                if ($item->program && $item->program->price) {
                    $item->price_breakdown = getTaxBreakdown($item->program->price);
                }

                return $item;
            });

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($cartItems, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'cart_count' => $cart_count,
            'cart_price' => $cart_total, // Total including tax (for backward compatibility)
            'cart_summary' => [
                'subtotal' => $cartTaxBreakdown['subtotal'],
                'tax_amount' => $cartTaxBreakdown['tax_amount'],
                'tax_rate' => $cartTaxBreakdown['tax_rate'],
                'total' => $cartTaxBreakdown['total'],
                'currency' => 'SAR'
            ],
            'data' => $paginated
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $program = Program::findOrFail($request->program_id);

        // Check if user already has a subscription for this program
        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $request->program_id)
            ->first();

        if ($subscription) {
            // Check if subscription is expired or banned
            if ($subscription->isExpired() || $subscription->isBanned()) {
                return response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.subscription_has_expired')
                ], 422);
            }

            // Check if subscription is active
            if ($subscription->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.Student_subscribed_to_this_program')
                ], 422);
            }
        }

        if ($program->user_type !== $user->type) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.student_not_authorized_to_enroll_in_this_course')
            ], 422);
        }


        // Check if user already has this program in cart
        $exists = Cart::where('student_id', $user->id)
            ->where('program_id', $request->program_id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الدورة موجودة بالفعل في السلة'
            ], 422);
        }

        $cart = new Cart;
        $cart->student_id = $user->id;
        $cart->program_id = $request->program_id;
        $cart->save();

        // Calculate updated cart totals with tax breakdown
        $cart_programs = Cart::where('student_id', $user->id)->pluck('program_id')->toArray();
        $cart_total = Program::whereIn('id', $cart_programs)->sum('price');
        $cartTaxBreakdown = getTaxBreakdown($cart_total);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'cart_summary' => [
                'cart_count' => count($cart_programs),
                'subtotal' => $cartTaxBreakdown['subtotal'],
                'tax_amount' => $cartTaxBreakdown['tax_amount'],
                'tax_rate' => $cartTaxBreakdown['tax_rate'],
                'total' => $cartTaxBreakdown['total'],
                'currency' => 'SAR'
            ]
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $cart = Cart::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        if (!$cart) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $student_id = $cart->student_id;
        $cart->delete();

        // Calculate updated cart totals with tax breakdown
        $cart_programs = Cart::where('student_id', $student_id)->pluck('program_id')->toArray();
        $cart_total = count($cart_programs) > 0 ? Program::whereIn('id', $cart_programs)->sum('price') : 0;
        $cartTaxBreakdown = getTaxBreakdown($cart_total);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
            'cart_summary' => [
                'cart_count' => count($cart_programs),
                'subtotal' => $cartTaxBreakdown['subtotal'],
                'tax_amount' => $cartTaxBreakdown['tax_amount'],
                'tax_rate' => $cartTaxBreakdown['tax_rate'],
                'total' => $cartTaxBreakdown['total'],
                'currency' => 'SAR'
            ]
        ]);
    }

    /**
     * Check if a course can be purchased (within 7 days of start date)
     */
    public function canPurchase(Request $request, $programId)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $program = Program::find($programId);
        if (!$program) {
            return response()->json([
                'success' => false,
                'message' => 'Course not found'
            ], 404);
        }

        $canPurchase = true;
        $message = '';
        $daysUntilStart = null;

        if ($program->date_from) {
            $courseStartDate = \Carbon\Carbon::parse($program->date_from);
            $sevenDaysBefore = \Carbon\Carbon::now()->addDays(7);
            $daysUntilStart = \Carbon\Carbon::now()->diffInDays($courseStartDate, false);

            if ($courseStartDate->gt($sevenDaysBefore)) {
                $canPurchase = false;
                $message = 'يمكنك شراء هذه الدورة قبل أسبوع واحد من بدايتها فقط';
            }
        }

        // Check if user already has this program in cart
        $inCart = Cart::where('student_id', $user->id)
            ->where('program_id', $programId)
            ->exists();


        if ($inCart) {
            $message = 'هذه الدورة موجودة في السلة - يمكنك شراؤها الآن';
        } else {
            $message = 'يمكن إضافة هذه الدورة إلى السلة';
        }

        return response()->json([
            'success' => true,
            'can_purchase' => $canPurchase,
            'message' => $message,
            'days_until_start' => $daysUntilStart,
            'course_start_date' => $program->date_from,
            'show_buy_button' => $canPurchase,
            'is_within_7_days' => $program->date_from ?
                (\Carbon\Carbon::parse($program->date_from)->lte(\Carbon\Carbon::now()->addDays(7))) : true,
            'in_cart' => $inCart
        ]);
    }

    /**
     * Purchase a specific course from cart
     */
    public function purchaseCourse(Request $request, $cartId)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $cartItem = Cart::where('student_id', $user->id)
            ->where('id', $cartId)
            ->with('program')
            ->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Course not found in cart'
            ], 404);
        }

        $program = $cartItem->program;

        // Check if course start date is within 7 days
        if ($program->date_from) {
            $courseStartDate = \Carbon\Carbon::parse($program->date_from);
            $sevenDaysBefore = \Carbon\Carbon::now()->addDays(7);

            if ($courseStartDate->gt($sevenDaysBefore)) {
                return response()->json([
                    'success' => false,
                    'message' => 'يمكنك شراء هذه الدورة قبل أسبوع واحد من بدايتها فقط'
                ], 422);
            }
        }

        // Check if user already has a subscription for this program
        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $program->id)
            ->first();

        if ($subscription && $subscription->isActive()) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.Student_subscribed_to_this_program')
            ], 422);
        }

        // Create payment for this specific course
        try {
            // Note: Program price already includes 15% VAT
            $transaction = new \App\Models\Transaction();
            $transaction->cart_id = $cartItem->id;
            $transaction->student_id = $user->id;
            $transaction->total_price = $program->price; // Price includes VAT
            $transaction->currency = 'SAR';
            $transaction->status = 'pending';
            $transaction->result_status  = 'pending';
            $transaction->save();

            $transactionId = $transaction->id;

            $response = Http::withBasicAuth($this->moyasar_secret_key, '')
                ->post('https://api.moyasar.com/v1/invoices', [
                    'amount' => $program->price * 100, // hallah
                    'currency' => 'SAR',
                    'description' => 'Program',
                    'success_url' => url('api/moyasar/success'),
                    'back_url' => url('api/moyasar/cancel?transaction_id=' . $transactionId),
                    'metadata' => [
                        'order_id' => $transaction->id,
                        'cart_item_id'   => $cartItem->id,
                        'student_id'     => $user->id,
                        'program_id'     => $program->id,
                        'type'           => 'single_course',
                    ],
                ]);

            if ($response->failed()) {
                $transaction->delete();
                return response()->json(['error' => $response->json()], 400);
            }

            $data = $response->json();

            // return response()->json([
            //     'response' => $data,
            // ], 200);

            if (!isset($data['url'])) {
                $transaction->delete();
                return response()->json([
                    'error' => 'Transaction URL not found',
                    'response' => $data,
                ], 400);
            }

            $transaction->invoice_id = $data['id'];
            $transaction->result_status = $data['status'];
            $transaction->save();

            return response()->json([
                'success' => true,
                'payment_url' => $data['url'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment: ' . $e->getMessage()
            ], 500);
        }
    }
}
