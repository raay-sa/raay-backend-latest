<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Exam;
use App\Models\Program;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\Transaction;

class DashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::where('teacher_id', $user->id)->pluck('id')->toArray();
        $current_programs = Program::where('teacher_id', $user->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_programs = Program::where('teacher_id', $user->id)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();
        // النسبة
        if ($previous_programs > 0) {
            $percentage_change = round((($current_programs - $previous_programs) / $previous_programs) * 100, 2);
        } else {
            $percentage_change = 100; // مفيش بيانات سابقة
        }

        // students
        $total_students = Subscription::whereIn('program_id', $programs)->distinct('student_id')->count('student_id');
        // عدد الطلاب الجدد في الشهر الحالي
        $current_students = Subscription::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->whereIn('program_id', $programs)
            ->distinct('student_id')
            ->count('student_id');

        // عدد الطلاب الجدد في الشهر السابق
        $previous_students = Subscription::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->whereIn('program_id', $programs)
            ->distinct('student_id')
            ->count('student_id');

        // النسبة
        if ($previous_students > 0) {
            $percentage_change = round((($current_students - $previous_students) / $previous_students) * 100, 2);
        } else {
            $percentage_change = 100;
        }


        // reviews
        $total_reviews = Review::whereIn('program_id', $programs)->distinct('student_id')->count('student_id');
        // عدد الاراء الجدد في الشهر الحالي
        $current_reviews = Review::whereMonth('created_at', now()->month)
           ->whereYear('created_at', now()->year)
           ->whereIn('program_id', $programs)
           ->distinct('student_id')
           ->count('student_id');

        // عدد الطلاب الجدد في الشهر السابق
        $previous_reviews = Review::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->whereIn('program_id', $programs)
            ->distinct('student_id')
            ->count('student_id');

        // حساب النسبة
        if ($previous_reviews > 0) {
            $percentage_change = round((($current_reviews - $previous_reviews) / $previous_reviews) * 100, 2);
        } else {
            $percentage_change = 100;
        }

        $profit_percentage = Setting::value('profit_percentage'); // ترجع القيمة فقط مباشرة
        $totalRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))->sum('price');
        $adminProfit = ($profit_percentage / 100) * $totalRevenue;
        $teacherProfit = $totalRevenue - $adminProfit;

        $currentRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('price');
        $previousRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('price');

        // حساب النسبة
        if ($previousRevenue > 0) {
            $revenue_percentage = round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 2);
        } else {
            $revenue_percentage = 100;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_students' => $total_students,
                'student_percentage' => abs($percentage_change),
                'student_status' => $percentage_change >= 0 ? 'increase' : 'decrease',

                'total_programs' => count($programs),
                'program_percentage' => abs($percentage_change),
                'program_status' => $percentage_change >= 0 ? 'increase' : 'decrease',

                'total_reviews' => $total_reviews,
                'review_percentage' => abs($percentage_change),
                'review_status' => $percentage_change >= 0 ? 'increase' : 'decrease',

                'total_profit' => $teacherProfit,
                'profit_percentage' => abs($revenue_percentage),
                'profit_status' => $revenue_percentage >= 0 ? 'increase' : 'decrease'
            ]
        ]);
    }

    public function userRegistration(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::where('teacher_id', $user->id)
            ->with('subscriptions.student', 'translations')
            ->get();

        $subscriptions = $programs->flatMap->subscriptions;

        // الحصول على الشهر السابق والحالي
        $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();

        $startOfCurrentMonth = Carbon::now()->startOfMonth();
        $endOfCurrentMonth = Carbon::now()->endOfMonth();

        // لحساب عدد الطلاب حسب الأسابيع داخل شهر معين
        $calculateWeeklyCounts = function ($startDate, $endDate) use ($subscriptions) {
            $weekly_counts = [];
            $startOfWeek = $startDate->copy();
            $weekIndex = 1;

            while ($startOfWeek <= $endDate) {
                $endOfWeek = $startOfWeek->copy()->addDays(6)->endOfDay();
                if ($endOfWeek > $endDate) {
                    $endOfWeek = $endDate->copy()->endOfDay();
                }

                $weekly_counts[] = [
                    'label' => 'الأسبوع ' . $weekIndex,
                    'count_students' => $subscriptions->filter(function ($subscription) use ($startOfWeek, $endOfWeek) {
                        return Carbon::parse($subscription->created_at)->between($startOfWeek, $endOfWeek);
                    })->pluck('user_id')->unique()->count(),
                ];

                $startOfWeek = $endOfWeek->copy()->addDay()->startOfDay();
                $weekIndex++;
            }

            return $weekly_counts;
        };

        $lastMonthWeeks = $calculateWeeklyCounts($startOfLastMonth, $endOfLastMonth);
        $currentMonthWeeks = $calculateWeeklyCounts($startOfCurrentMonth, $endOfCurrentMonth);

        return response()->json([
            'success' => true,
            'data' => [
                'current_month' => $currentMonthWeeks,
                'last_month' => $lastMonthWeeks,
            ],
        ]);
    }

    public function profitStats(Request $request)
    {
        $filter = $request->filter ?? 'monthly'; // values: 'weekly', 'monthly', 'yearly'
        $profit_percentage = Setting::value('profit_percentage') ?? 0;

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $data = [];

        switch ($filter) {
            case 'weekly':
                // آخر 7 أيام (كل يوم لوحده)
                for ($i = 6; $i >= 0; $i--) {
                    $date = Carbon::now()->subDays($i)->startOfDay();
                    $endDate = $date->copy()->endOfDay();

                    $totalRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
                        ->whereBetween('created_at', [$date, $endDate])
                        ->sum('price');

                    $adminProfit = ($profit_percentage / 100) * $totalRevenue;
                    $profit = $totalRevenue - $adminProfit;

                    $data[] = [
                        'label' => $date->translatedFormat('l j M'), // الاثنين 22 يوليو
                        'profit' => round($profit, 2),
                    ];
                }
                break;

            case 'monthly':
            default:
                // الأسابيع داخل هذا الشهر
                $startOfMonth = Carbon::now()->startOfMonth();
                $endOfMonth = Carbon::now()->endOfMonth();
                $startOfWeek = $startOfMonth->copy();
                $weekIndex = 1;

                while ($startOfWeek <= $endOfMonth) {
                    $endOfWeek = $startOfWeek->copy()->addDays(6)->endOfDay();
                    if ($endOfWeek > $endOfMonth) {
                        $endOfWeek = $endOfMonth->copy()->endOfDay();
                    }

                    $totalRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
                        ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                        ->sum('price');

                    $adminProfit = ($profit_percentage / 100) * $totalRevenue;
                    $profit = $totalRevenue - $adminProfit;

                    $data[] = [
                        'label' => 'الأسبوع ' . $weekIndex,
                        'profit' => round($profit, 2),
                    ];

                    // تحديث بداية الأسبوع
                    $startOfWeek = $endOfWeek->copy()->addDay()->startOfDay();
                    $weekIndex++;
                }
                break;

            case 'yearly':
                // كل شهر في السنة الحالية
                for ($month = 1; $month <= 12; $month++) {
                    $startOfMonth = Carbon::create(null, $month, 1)->startOfMonth();
                    $endOfMonth = $startOfMonth->copy()->endOfMonth();

                    $totalRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
                        ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                        ->sum('price');

                    $adminProfit = ($profit_percentage / 100) * $totalRevenue;
                    $profit = $totalRevenue - $adminProfit;

                    $data[] = [
                        'label' => $startOfMonth->translatedFormat('F'), // يناير - فبراير ...
                        'profit' => round($profit, 2),
                    ];
                }
                break;
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function contentStats(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $registered_programs_count = Program::where('teacher_id', $user->id)
        ->where('type', 'registered')->count();
        $live_programs_count = Program::where('teacher_id', $user->id)
        ->where('type', 'live')->count();

        $program_ids = Program::where('teacher_id', $user->id)->pluck('id');

        $assignments_count = Assignment::whereIn('program_id', $program_ids)->count();
        $exams_count       = Exam::whereIn('program_id', $program_ids)->count();

        $total = $registered_programs_count + $live_programs_count + $assignments_count + $exams_count;

        $data = [
            'registered_programs_percentage' => $total > 0 ? round(($registered_programs_count / $total) * 100, 1) : 0,
            'live_programs_percentage'       => $total > 0 ? round(($live_programs_count / $total) * 100, 1) : 0,
            'tasks_percentage'               => $total > 0 ? round((($assignments_count + $exams_count) / $total) * 100, 1) : 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }


    public function program_interactions(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::withCount([
            'reviews',
            'assignment_solutions',
            'subscriptions',
        ])
        ->with('translations')
        ->where('teacher_id', $user->id)
        ->get(['id']);

        $maxReviews = $programs->max('reviews_count') ?: 1;
        $locale = $request->lang ?? app()->getLocale();

        $data = $programs->map(function ($program) use ($maxReviews, $locale) {
            $reviewsPercentage = 0;
            if ($program->subscriptions_count > 0) {
                $reviewsPercentage = round(($program->reviews_count / $program->subscriptions_count) * 100, 2);
            }

            $assignmentsPercentage = 0;
            if ($program->subscriptions_count > 0) {
                $assignmentsPercentage = round(($program->assignment_solutions_count / $program->subscriptions_count) * 100, 2);
            }

            return [
                'program' => $program->translations->firstWhere('locale', $locale)->title ?? '-',
                // 'program' => $program->title,
                // 'reviews' => round(($program->reviews_count / $maxReviews) * 100, 2),
                'reviews' => $reviewsPercentage,
                'assignments' => $assignmentsPercentage,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }


    public function reviews(Request $request) {
        $filter = $request->filter ?? 'monthly'; // 'weekly', 'monthly', 'yearly'
        $data = [];

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::where('teacher_id', $user->id)->pluck('id')->toArray();

        switch ($filter) {
            case 'weekly':
                for ($i = 6; $i >= 0; $i--) {
                    $date = Carbon::now()->subDays($i)->startOfDay();
                    $endDate = $date->copy()->endOfDay();

                    $total_reviews  = Review::whereIn('program_id', $programs)
                    ->whereBetween('created_at', [$date, $endDate])
                    ->count();

                    $data[] = [
                        'label' => $date->translatedFormat('l j M'), // مثال: الاثنين 22 يوليو
                        'count' => $total_reviews,
                    ];
                }
                break;

            case 'monthly':
            default:
                $startOfMonth = Carbon::now()->startOfMonth();
                $endOfMonth = Carbon::now()->endOfMonth();
                $startOfWeek = $startOfMonth->copy();
                $weekIndex = 1;

                while ($startOfWeek <= $endOfMonth) {
                    $endOfWeek = $startOfWeek->copy()->addDays(6)->endOfDay();
                    if ($endOfWeek > $endOfMonth) {
                        $endOfWeek = $endOfMonth->copy()->endOfDay();
                    }

                    $total_reviews  = Review::whereIn('program_id', $programs)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->count();

                    $data[] = [
                        'label' => 'الأسبوع ' . $weekIndex,
                        'count' => $total_reviews,
                    ];

                    $startOfWeek = $endOfWeek->copy()->addDay()->startOfDay();
                    $weekIndex++;
                }
                break;

            case 'yearly':
                for ($month = 1; $month <= 12; $month++) {
                    $startOfMonth = Carbon::create(null, $month, 1)->startOfMonth();
                    $endOfMonth = $startOfMonth->copy()->endOfMonth();

                    $total_reviews  = Review::whereIn('program_id', $programs)
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->count();

                    $data[] = [
                        'label' => $startOfMonth->translatedFormat('F'), // يناير - فبراير ...
                        'count' => $total_reviews,
                    ];
                }
                break;
        }

        $totalReviews = Review::whereIn('program_id', $programs)->count();

        return response()->json([
            'success' => true,
            'total_reviews' => $totalReviews,
            'data' => $data
        ]);
    }


    public function last_transactions(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $locale = $request->lang ?? app()->getLocale();

        // اخر 5 اشتراكات بالطلاب بتاعتهم
        $teacherProgramIds = Program::where('teacher_id', $user->id)->pluck('id');
        $transactions = Transaction::with([
                'student',
                'programs' => function ($query) use ($teacherProgramIds) {
                    $query->whereIn('programs.id', $teacherProgramIds);
                }
            ])
            ->whereHas('programs', function ($query) use ($teacherProgramIds) {
                $query->whereIn('programs.id', $teacherProgramIds);
            })
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($transaction) use($locale) {
                return [
                    'id' => $transaction->id,
                    'student_name' => $transaction->student->name,
                    'total_price' => $transaction->total_price,
                    'created_at' => $transaction->created_at,
                    'programs' => $transaction->programs->map(function ($program) use($locale) {
                        return [
                            'id' => $program->id,
                            'title' => $program->translations->firstWhere('locale', $locale)->title ?? '-'
                            // 'title' => $program->title,
                        ];
                    }),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }
}
