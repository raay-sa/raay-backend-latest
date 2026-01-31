<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Exam;
use App\Models\Program;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // students
        $total_students = Student::count();
        $current_students = Student::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $previous_students = Student::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        if ($previous_students > 0) {
            $percentage_change = round((($current_students - $previous_students) / $previous_students) * 100, 2);
        } else {
            $percentage_change = 100;
        }

        // teachers
        $total_teachers = Teacher::where('type', 'teacher')->count();
        $current_teachers = Teacher::where('type', 'teacher')->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_teachers = Teacher::where('type', 'teacher')->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_teachers > 0) {
            $teacher_percentage_change = round((($current_teachers - $previous_teachers) / $previous_teachers) * 100, 2);
        } else {
            $teacher_percentage_change = 100; // لو مفيش بيانات سابقة، نعتبرها زيادة كاملة
        }

        // programs
        $total_programs = Program::count();
        $current_programs = Program::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_programs = Program::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_programs > 0) {
            $program_percentage_change = round((($current_programs - $previous_programs) / $previous_programs) * 100, 2);
        } else {
            $program_percentage_change = 100;
        }

        // reviews
        $total_reviews = Review::distinct('student_id')->count('student_id');
        $current_reviews = Review::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->distinct('student_id')
            ->count('student_id');
        $previous_reviews = Review::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->distinct('student_id')
            ->count('student_id');

        // نسبة الزيادة أو النقصان
        if ($previous_reviews > 0) {
            $review_percentage_change = round((($current_reviews - $previous_reviews) / $previous_reviews) * 100, 2);
        } else {
            $review_percentage_change = 100;
        }

        // profit
        $profit_percentage = Setting::value('profit_percentage');
        $total_profit = Subscription::sum('price') * ($profit_percentage / 100);
        $current_profit = Subscription::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('price') * ($profit_percentage / 100);
        $previous_profit = Subscription::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('price') * ($profit_percentage / 100);

        // نسبة الزيادة أو النقصان في الأرباح
        if ($previous_profit > 0) {
            $profit_percentage_change = round((($current_profit - $previous_profit) / $previous_profit) * 100, 2);
        } else {
            $profit_percentage_change = 100;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_students' => $total_students,
                'student_percentage' => abs($percentage_change),
                'student_status' => $percentage_change >= 0 ? 'increase' : 'decrease',

                'total_teachers' => $total_teachers,
                'teacher_percentage' => abs($teacher_percentage_change),
                'teacher_status' => $teacher_percentage_change >= 0 ? 'increase' : 'decrease',

                'total_programs' => $total_programs,
                'program_percentage' => abs($program_percentage_change),
                'program_status' => $program_percentage_change >= 0 ? 'increase' : 'decrease',

                'total_reviews' => $total_reviews,
                'review_percentage' => abs($review_percentage_change),
                'review_status' => $review_percentage_change >= 0 ? 'increase' : 'decrease',

                'total_profit' => $total_profit,
                'profit_percentage' => abs($profit_percentage_change),
                'profit_status' => $profit_percentage_change >= 0 ? 'increase' : 'decrease',
            ],
        ]);
    }


    public function userRegistration(Request $request)
    {
        $filter = $request->filter; // values: 'week', 'month', 'year'

        $total_teachers = Teacher::where('type', 'teacher')->count();
        $total_students = Student::count();

        $weekly_counts = [];

        switch ($filter) {
            case 'weekly':
                // آخر 7 أيام (كل يوم لوحده)
                for ($i = 6; $i >= 0; $i--) {
                    $date = Carbon::now()->subDays($i)->startOfDay();
                    $endDate = $date->copy()->endOfDay();

                    $weekly_counts[] = [
                        'label' => $date->translatedFormat('l j M'), // الاثنين 22 يوليو
                        'count_student' => Student::whereBetween('created_at', [$date, $endDate])->count(),
                        'count_teacher' => Teacher::where('type', 'teacher')->whereBetween('created_at', [$date, $endDate])->count(),
                    ];
                }
                break;

            case 'monthly':
            default:
                // الأسابيع داخل الشهر
                $startOfMonth = Carbon::now()->startOfMonth();
                $endOfMonth = Carbon::now()->endOfMonth();
                $startOfWeek = $startOfMonth->copy();
                $weekIndex = 1;

                while ($startOfWeek <= $endOfMonth) {
                    $endOfWeek = $startOfWeek->copy()->addDays(6)->endOfDay();
                    if ($endOfWeek > $endOfMonth) {
                        $endOfWeek = $endOfMonth->copy()->endOfDay();
                    }

                    $weekly_counts[] = [
                        'label' => 'الأسبوع ' . $weekIndex,
                        'count_student' => Student::whereBetween('created_at', [$startOfWeek, $endOfWeek])->count(),
                        'count_teacher' => Teacher::where('type', 'teacher')->whereBetween('created_at', [$startOfWeek, $endOfWeek])->count(),
                    ];

                    $startOfWeek = $endOfWeek->copy()->addDay()->startOfDay();
                    $weekIndex++;
                }
                break;

            case 'yearly':
                // كل شهر في السنة الحالية
                for ($month = 1; $month <= 12; $month++) {
                    $startOfMonth = Carbon::create(null, $month, 1)->startOfMonth();
                    $endOfMonth = $startOfMonth->copy()->endOfMonth();

                    $weekly_counts[] = [
                        'label' => $startOfMonth->translatedFormat('F'), // يناير - فبراير ...
                        'count_student' => Student::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
                        'count_teacher' => Teacher::where('type', 'teacher')->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
                    ];
                }
                break;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'counts' => $weekly_counts,
                'total_students' => $total_students,
                'total_teachers' => $total_teachers,
            ]
        ]);
    }

    public function profitStats(Request $request)
    {
        $filter = $request->filter ?? 'monthly'; // values: 'weekly', 'monthly', 'yearly'
        $profit_percentage = Setting::value('profit_percentage') ?? 0;

        $data = [];

        switch ($filter) {
            case 'weekly':
                // آخر 7 أيام (كل يوم لوحده)
                for ($i = 6; $i >= 0; $i--) {
                    $date = Carbon::now()->subDays($i)->startOfDay();
                    $endDate = $date->copy()->endOfDay();

                    $sum = Subscription::whereBetween('created_at', [$date, $endDate])->sum('price');
                    $profit = $sum * ($profit_percentage / 100);

                    $data[] = [
                        'label' => $date->translatedFormat('l j M'), //  الاثنين 22 يوليو
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

                    $sum = Subscription::whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('price');
                    $profit = $sum * ($profit_percentage / 100);

                    $data[] = [
                        'label' => 'الأسبوع ' . $weekIndex,
                        'profit' => round($profit, 2),
                    ];

                    // 🔁 تحديث بداية الأسبوع
                    $startOfWeek = $endOfWeek->copy()->addDay()->startOfDay();
                    $weekIndex++;
                }
                break;

            case 'yearly':
                // كل شهر في السنة الحالية
                for ($month = 1; $month <= 12; $month++) {
                    $startOfMonth = Carbon::create(null, $month, 1)->startOfMonth();
                    $endOfMonth = $startOfMonth->copy()->endOfMonth();

                    $sum = Subscription::whereBetween('created_at', [$startOfMonth, $endOfMonth])->sum('price');
                    $profit = $sum * ($profit_percentage / 100);

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

    public function contentStats()
    {
        $registered_programs_count = Program::where('type', 'registered')->count();
        $live_programs_count = Program::where('type', 'live')->count();

        $assignments_count   = Assignment::count();
        $exams_count         = Exam::count();

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

    public function programSubscriptions(Request $request)
    {
        $filter = $request->filter ?? 'monthly'; // 'weekly', 'monthly', 'yearly'

        if ($filter === 'weekly') {
            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();

            $programs = Program::select('id')->with('translations')->withCount([
                // عدد الاشتراكات داخل الفترة
                'subscriptions' => function ($query) use ($startOfWeek, $endOfWeek) {
                    $query->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
                },
                // عدد الجلسات
                'sessions'
            ])
            ->get()
            ->map(function ($program) {
                $program->makeHidden(['category', 'teacher']);
                return $program;
            });

        } elseif ($filter === 'monthly') {
            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            $programs = Program::select('id')->with('translations')->withCount([
                'subscriptions' => function ($query) use ($startOfMonth, $endOfMonth) {
                    $query->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
                },
                'sessions'
            ])
            ->get()
            ->map(function ($program) {
                $program->makeHidden(['category', 'teacher']);
                return $program;
            });

        } elseif ($filter === 'yearly') {
            $startOfYear = Carbon::now()->startOfYear();
            $endOfYear = Carbon::now()->endOfYear();

            $programs = Program::select('id')->with('translations')->withCount([
                'subscriptions' => function ($query) use ($startOfYear, $endOfYear) {
                    $query->whereBetween('created_at', [$startOfYear, $endOfYear]);
                },
                'sessions'
            ])
            ->get()
            ->map(function ($program) {
                $program->makeHidden(['category', 'teacher']);
                return $program;
            });
        }

        return response()->json([
            'success' => true,
            'data' => $programs
        ]);
    }

    public function reviews(Request $request) {
        $filter = $request->filter ?? 'monthly'; // 'weekly', 'monthly', 'yearly'
        $data = [];

        switch ($filter) {
            case 'weekly':
                for ($i = 6; $i >= 0; $i--) {
                    $date = Carbon::now()->subDays($i)->startOfDay();
                    $endDate = $date->copy()->endOfDay();

                    $count = Review::whereBetween('created_at', [$date, $endDate])->count();

                    $data[] = [
                        'label' => $date->translatedFormat('l j M'), // مثال: الاثنين 22 يوليو
                        'count' => $count,
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

                    $count = Review::whereBetween('created_at', [$startOfWeek, $endOfWeek])->count();

                    $data[] = [
                        'label' => 'الأسبوع ' . $weekIndex,
                        'count' => $count,
                    ];

                    $startOfWeek = $endOfWeek->copy()->addDay()->startOfDay();
                    $weekIndex++;
                }
                break;

            case 'yearly':
                for ($month = 1; $month <= 12; $month++) {
                    $startOfMonth = Carbon::create(null, $month, 1)->startOfMonth();
                    $endOfMonth = $startOfMonth->copy()->endOfMonth();

                    $count = Review::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();

                    $data[] = [
                        'label' => $startOfMonth->translatedFormat('F'), // يناير - فبراير ...
                        'count' => $count,
                    ];
                }
                break;
        }

        $totalReviews = Review::count();

        return response()->json([
            'success' => true,
            'total_reviews' => $totalReviews,
            'data' => $data
        ]);
    }

    public function last_transactions(Request $request)
    {
        $locale = $request->lang ?? App()->getLocale();

        //  اخر 5 اشتراكات بالطلاب بتاعتهم
        $transactions = Transaction::with([
            'student',
            'programs.translations'
        ])
        ->latest() // حسب created_at
        ->take(5)
        ->get()
        ->map(function ($transaction) use($locale) {
            return [
                'id' => $transaction->id,
                'student_name' => $transaction->student?->name ?? '-',
                'total_price' => $transaction->total_price,
                'created_at' => $transaction->created_at,
                'programs' => $transaction->programs->map(function ($program) use($locale) {
                    return [
                        'id' => $program->id,
                        'title' => $program->translations->firstWhere('locale', $locale)->title ?? '-',
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
