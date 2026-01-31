<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSolution;
use App\Models\Exam;
use App\Models\ExamStudent;
use App\Models\Program;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Carbon\CarbonPeriod;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class StatisticsController extends Controller
{
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
        $request->validate([
            'from_date' => 'required|date_format:d-m-Y',
            'to_date' => 'required|date_format:d-m-Y|after_or_equal:from_date',
        ]);

        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date)->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date)->endOfDay();

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $profit_percentage = Setting::value('profit_percentage') ?? 0;
        $totalRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->sum('price');

        $adminProfit = ($profit_percentage / 100) * $totalRevenue;
        $teacherProfit = $totalRevenue - $adminProfit;

        $diffInDays = $fromDate->diffInDays($toDate);

        $labels = [];
        $data = [];

        if ($diffInDays <= 7) {
            // Daily breakdown
            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dayLabel = $current->format('Y-m-d');

                $dayProfit = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
                    ->whereDate('created_at', $current->toDateString())
                    ->sum('price');

                $adminDayProfit = ($profit_percentage / 100) * $dayProfit;

                $labels[] = $dayLabel;
                $data[] = round($dayProfit - $adminDayProfit, 2);

                $current->addDay();
            }

        } elseif ($diffInDays <= 31) {
            // Weekly breakdown
            $start = $fromDate->copy()->startOfWeek();
            $week = 1;
            while ($start < $toDate) {
                $end = $start->copy()->endOfWeek();
                if ($end > $toDate) $end = $toDate;

                $weekProfit = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('price');

                $adminWeekProfit = ($profit_percentage / 100) * $weekProfit;

                $labels[] = "Week $week";
                $data[] = round($weekProfit - $adminWeekProfit, 2);

                $start->addWeek();
                $week++;
            }

        } else {
            // Monthly breakdown
            $current = $fromDate->copy()->startOfMonth();
            // $month = 1;
            while ($current <= $toDate) {
                $startOfMonth = $current->copy()->startOfMonth();
                $endOfMonth = $current->copy()->endOfMonth();
                if ($endOfMonth > $toDate) $endOfMonth = $toDate;

                $monthProfit = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->sum('price');

                $adminMonthProfit = ($profit_percentage / 100) * $monthProfit;

                // $labels[] = "Month $month";
                $labels[] = $startOfMonth->format('Y-m');
                $data[] = round($monthProfit - $adminMonthProfit, 2);

                $current->addMonth();
                // $month++;
            }
        }

        return response()->json([
            'success' => true,
            'total_profit' => round($teacherProfit, 2),
            'labels' => $labels,
            'data' => $data,
        ]);
    }

    public function contentStats(Request $request)
    {
        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date ?? Carbon::today()->format('d-m-Y'))->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date ?? Carbon::today()->format('d-m-Y'))->endOfDay();

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $registered_programs_count = Program::where('teacher_id', $user->id)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->where('type', 'registered')
            ->count();

        $live_programs_count = Program::where('teacher_id', $user->id)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->where('type', 'live')
            ->count();

        $program_ids = Program::where('teacher_id', $user->id)
        ->whereBetween('created_at', [$fromDate, $toDate])->pluck('id');

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
        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date ?? Carbon::today()->format('d-m-Y'))->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date ?? Carbon::today()->format('d-m-Y'))->endOfDay();

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
        ->whereBetween('created_at', [$fromDate, $toDate])
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

    // اجمالي التعليقات خلال فترة زمنية
    public function reviews(Request $request)
    {
        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date ?? Carbon::today()->format('d-m-Y'))->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date ?? Carbon::today()->format('d-m-Y'))->endOfDay();

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $diffInDays = $fromDate->diffInDays($toDate);
        if ($diffInDays <= 7) {
            $interval = '1 day';
            $labelFormat = 'Y-m-d';
        } elseif ($diffInDays <= 60) {
            $interval = '1 week';
            $labelFormat = '"أسبوع W"';
        } else {
            $interval = '1 month';
            $labelFormat = 'Y-m';
        }

        $period = CarbonPeriod::create($fromDate, $interval, $toDate);
        $data = [];

        $weekIndex = 1;
        foreach ($period as $start) {
            $startClone = clone $start;
            if ($interval === '1 month') {
                $end = $startClone->copy()->endOfMonth();
            } elseif ($interval === '1 week') {
                $end = $startClone->copy()->endOfWeek();
            } else {
                $end = $startClone->copy()->endOfDay();
            }

            $registeredCount = Review::whereHas('program', function ($query) use ($user, $startClone, $end) {
                $query->where('teacher_id', $user->id)
                    ->where('type', 'registered')
                    ->whereBetween('created_at', [$startClone, $end]);
            })->count();

            $liveCount = Review::whereHas('program', function ($query) use ($user, $startClone, $end) {
                $query->where('teacher_id', $user->id)
                    ->where('type', 'live')
                    ->whereBetween('created_at', [$startClone, $end]);
            })->count();

            $data[] = [
                'label' => $interval === '1 week' ? 'week ' . $weekIndex++ : $start->format($labelFormat),
                'registered' => $registeredCount,
                'live' => $liveCount,
                'total' => $registeredCount + $liveCount
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'reviews' => $data,
                'total_reviews' => collect($data)->sum('total')
            ]
        ]);
    }

    public function achievement(Request $request)
    {
        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date ?? Carbon::today()->format('d-m-Y'))->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date ?? Carbon::today()->format('d-m-Y'))->endOfDay();

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::with(['assignments', 'exams', 'translations'])
            ->where('teacher_id', $user->id)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get();

        $programStats = [];

        foreach ($programs as $program) {
            $total_subscriptions = Subscription::where('program_id', $program->id)->count();

            $totalAssignments = $program->assignments->count();
            $totalExams = $program->exams->count();

            $completedCount = 0;
            $inProgressCount = 0;
            $notStartedCount = 0;

            $studentIds = Subscription::where('program_id', $program->id)->pluck('student_id');

            foreach ($studentIds as $studentId) {
                $solvedAssignments = AssignmentSolution::whereIn('assignment_id', $program->assignments->pluck('id'))
                    ->where('student_id', $studentId)
                    ->distinct('assignment_id')
                    ->count('assignment_id');

                $solvedExams = ExamStudent::whereIn('exam_id', $program->exams->pluck('id'))
                    ->where('student_id', $studentId)
                    ->distinct('exam_id')
                    ->count('exam_id');

                if (
                    $totalAssignments > 0 && $totalExams > 0 &&
                    $solvedAssignments === $totalAssignments &&
                    $solvedExams === $totalExams
                ) {
                    $completedCount++;
                } elseif ($solvedAssignments > 0 || $solvedExams > 0) {
                    $inProgressCount++;
                } else {
                    $notStartedCount++;
                }
            }

            $completionRate   = $total_subscriptions > 0 ? round(($completedCount / $total_subscriptions) * 100, 2) : 0;
            $inProgressRate   = $total_subscriptions > 0 ? round(($inProgressCount / $total_subscriptions) * 100, 2) : 0;
            $notStartedRate   = $total_subscriptions > 0 ? round(($notStartedCount / $total_subscriptions) * 100, 2) : 0;

            $local = $request->lang ?? app()->getLocale();

            $programStats[] = [
                'program_id'       => $program->id,
                'program_title'    => $program->translations->firstWhere('locale', $local)->title ?? '-',
                // 'program_title'    => $program->title,
                'total_students'   => $total_subscriptions,
                'completed_rate'   => $completionRate,
                'in_completed_rate'=> $inProgressRate,
                'not_started_rate' => $notStartedRate,
            ];
        }

        return response()->json([
            'success' => true,
            'programs' => $programStats
        ]);
    }

    public function best_trainers(Request $request)
    {
        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date ?? Carbon::today()->format('d-m-Y'))->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date ?? Carbon::today()->format('d-m-Y'))->endOfDay();

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::with(['assignments', 'exams', 'translations'])
            ->where('teacher_id', $user->id)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get();

        $studentsStats = [];

        foreach ($programs as $program) {
            $studentIds = Subscription::where('program_id', $program->id)->pluck('student_id');

            foreach ($studentIds as $studentId) {
                $totalAssignments = $program->assignments->count() ?: 1; // تجنب القسمة على صفر
                $totalExams = $program->exams->count() ?: 1;
                $totalReviews = Review::where('student_id', $studentId)->count();

                // عدد المهام المحلولة
                $solvedAssignments = AssignmentSolution::whereIn('assignment_id', $program->assignments->pluck('id'))
                    ->where('student_id', $studentId)
                    ->distinct('assignment_id')
                    ->count('assignment_id');

                // عدد الاختبارات المحلولة
                $solvedExams = ExamStudent::whereIn('exam_id', $program->exams->pluck('id'))
                    ->where('student_id', $studentId)
                    ->distinct('exam_id')
                    ->count('exam_id');

                // متوسط الدرجات
                $avgGrade = ExamStudent::whereIn('exam_id', $program->exams->pluck('id'))
                    ->where('student_id', $studentId)
                    ->avg('grade') ?? 0;

                // حفظ البيانات في مصفوفة
                if (!isset($studentsStats[$studentId])) {
                    $studentsStats[$studentId] = [
                        'student_id' => $studentId,
                        'student_name' => Student::find($studentId)->name,
                        'assignments_completion' => 0,
                        'exams_completion' => 0,
                        'avg_grade' => 0,
                        'total_reviews' => 0
                    ];
                }

                $studentsStats[$studentId]['assignments_completion'] = ($solvedAssignments / $totalAssignments) * 100;
                $studentsStats[$studentId]['exams_completion'] = ($solvedExams / $totalExams) * 100;
                $studentsStats[$studentId]['avg_grade'] = $avgGrade;
                $studentsStats[$studentId]['total_reviews'] = $totalReviews;
            }
        }

        $topStudents = collect($studentsStats)
            ->sort(function ($a, $b) {
                // الترتيب حسب الدرجة أولاً
                if ($a['avg_grade'] != $b['avg_grade']) {
                    return $b['avg_grade'] <=> $a['avg_grade'];
                }

                // لو الدرجات متساوية نرتب حسب نسبة حل المهام
                if ($a['assignments_completion'] != $b['assignments_completion']) {
                    return $b['assignments_completion'] <=> $a['assignments_completion'];
                }

                // لو الاتنين متساويين نرتب حسب نسبة حل الاختبارات
                if($a['exams_completion'] != $b['exams_completion']){
                    return $b['exams_completion'] <=> $a['exams_completion'];
                }

                // لو الاتنين متساويين نرتب حسب نسبة المراجعات
                return $b['total_reviews'] <=> $a['total_reviews'];
            })
            ->take(5)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $topStudents
        ]);
    }


    public function userRegistration_excel(Request $request)
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

        $resultData = collect([
            [
                'label' => 'الشهر السابق',
                'students' => $subscriptions
                    ->filter(function ($subscription) use ($startOfLastMonth, $endOfLastMonth) {
                        return Carbon::parse($subscription->created_at)->between($startOfLastMonth, $endOfLastMonth);
                    })
                    ->pluck('student')
                    ->unique('id'),
            ],
            [
                'label' => 'الشهر الحالي',
                'students' => $subscriptions
                    ->filter(function ($subscription) use ($startOfCurrentMonth, $endOfCurrentMonth) {
                        return Carbon::parse($subscription->created_at)->between($startOfCurrentMonth, $endOfCurrentMonth);
                    })
                    ->pluck('student')
                    ->unique('id'),
            ],
        ]);


        // Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D'];
        foreach ($columns as $columnKey => $column) {
            $Width = ($columnKey==0||$columnKey==10)? 25 : 15; // width of column
            $sheet->getColumnDimension($column)->setWidth($Width);
            $sheet->getStyle($column.'1')->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'FFFF00'], // Yellow background
                ],
            ]);
        }
        $sheet->setRightToLeft(true);

        // Header
        $sheet->setCellValue('A1', __('trans.student.name'));
        $sheet->setCellValue('B1', __('trans.student.email'));
        $sheet->setCellValue('C1', __('trans.student.phone'));
        $sheet->setCellValue('D1', __('trans.student.registered_at'));

        $row = 2;
        foreach ($resultData as $data) {

            if ($data['students']->isEmpty()) continue;
            //  للفترة كفاصل
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", $data['label'] . ' (' . $data['students']->count() . ' طالب)');
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10],
                'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'D9EAD3'] // أخضر فاتح كخلفية
                ],
            ]);
            $row++;

            // بيانات الطلاب
            foreach ($data['students'] as $student) {
                $sheet->setCellValue('A' . $row, $student->name ?? '-');
                $sheet->setCellValue('B' . $row, $student->email ?? '-');
                $sheet->setCellValueExplicit('C' . $row, (string)$student->phone, DataType::TYPE_STRING);
                $sheet->setCellValue('D' . $row, $student->created_at->format('Y-m-d H:i'));
                $row++;
            }

            // سطر فاضي بعد كل فترة
            $row++;
        }

        // تنزيل الملف
        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.student.user_registeration')."-$today.xlsx";
        $filePath = public_path($fileName);

        $writer->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    public function profitStats_excel(Request $request)
    {
        $fromDate = Carbon::parse($request->from_date)->startOfDay() ?? Carbon::now()->startOfDay();
        $toDate   = Carbon::parse($request->to_date)->endOfDay() ?? Carbon::now()->endOfDay();

        $from_date = $fromDate->format('Y-m-d');
        $to_date = $toDate->format('Y-m-d');

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $profit_percentage = Setting::value('profit_percentage') ?? 0;

        $subscriptions = Subscription::with(['student', 'program.translations'])
            ->whereHas('program', fn($q) => $q->where('teacher_id', $user->id))
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get();

        $studentsGrouped = $subscriptions->groupBy('student_id');

        $teacherProfit = round($subscriptions->sum('price') - ($subscriptions->sum('price') * $profit_percentage / 100), 2);

        $resultData = [
            [
                'students' => $studentsGrouped,
                'profit'   => $teacherProfit,
            ]
        ];

        // Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
        foreach ($columns as $columnKey => $column) {
            $Width = ($columnKey==0||$columnKey==10)? 25 : 15; // width of column
            $sheet->getColumnDimension($column)->setWidth($Width);
            $sheet->getStyle($column.'1')->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'FFFF00'], // Yellow background
                ],
            ]);
        }
        $sheet->setRightToLeft(true);

        // Header
        $sheet->setCellValue('A1', __('trans.student.name'));
        $sheet->setCellValue('B1', __('trans.student.email'));
        $sheet->setCellValue('C1', __('trans.student.phone'));
        $sheet->setCellValue('D1', __('trans.subscription.programs'));
        $sheet->setCellValue('E1', __('trans.subscription.total_price'));
        $sheet->setCellValue('F1', __('trans.subscription.profit'));

        $row = 2;
        foreach ($resultData as $period) {

            if ($period['students']->isEmpty()) continue;

            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", "البيانات من تاريخ $from_date الى تاريخ $to_date" .
                ' (' . $period['students']->count() . ' طالب)' .
                ' - (الربح ' . $period['profit'] . ' ريال)');
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10],
                'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'D9EAD3']
                ],
            ]);
            $row++;

            foreach ($period['students'] as $student_id => $studentSubscriptions) {
                $student = $studentSubscriptions->first()->student;
                // $programs = $studentSubscriptions->pluck('program.title')->implode(', ');
                $programs = $studentSubscriptions->map(function ($subscription) {
                    return optional(
                        $subscription->program->translations->where('locale', app()->getLocale())->first()
                    )->title ?? '-';
                })->implode(', ');

                $sheet->setCellValue('A' . $row, $student->name ?? '-');
                $sheet->setCellValue('B' . $row, $student->email ?? '-');
                $sheet->setCellValueExplicit('C' . $row, (string)$student->phone, DataType::TYPE_STRING);
                $sheet->setCellValue('D' . $row, $programs);
                $sheet->setCellValue('E' . $row, $studentSubscriptions->sum('price'));
                $sheet->setCellValue('F' . $row, $studentSubscriptions->sum('price') * ($profit_percentage / 100));
                $row++;
            }
        }

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.subscription.total_profit')."-$today.xlsx";
        $filePath = public_path($fileName);

        $writer->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    public function achievement_excel(Request $request)
    {
        $fromDate = Carbon::parse($request->from_date)->startOfDay() ?? Carbon::now()->startOfDay();
        $toDate   = Carbon::parse($request->to_date)->endOfDay() ?? Carbon::now()->endOfDay();

        $from_date = $fromDate->format('Y-m-d');
        $to_date = $toDate->format('Y-m-d');

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::with(['assignments', 'exams', 'translations'])
            ->where('teacher_id', $user->id)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get();

        $programStats = [];

        foreach ($programs as $program) {
            $total_subscriptions = Subscription::where('program_id', $program->id)->count();

            $totalAssignments = $program->assignments->count();
            $totalExams = $program->exams->count();

            $completedCount = 0;
            $inProgressCount = 0;
            $notStartedCount = 0;

            $studentIds = Subscription::where('program_id', $program->id)->pluck('student_id');

            foreach ($studentIds as $studentId) {
                $solvedAssignments = AssignmentSolution::whereIn('assignment_id', $program->assignments->pluck('id'))
                    ->where('student_id', $studentId)
                    ->distinct('assignment_id')
                    ->count('assignment_id');

                $solvedExams = ExamStudent::whereIn('exam_id', $program->exams->pluck('id'))
                    ->where('student_id', $studentId)
                    ->distinct('exam_id')
                    ->count('exam_id');

                if (
                    $totalAssignments > 0 && $totalExams > 0 &&
                    $solvedAssignments === $totalAssignments &&
                    $solvedExams === $totalExams
                ) {
                    $completedCount++;
                } elseif ($solvedAssignments > 0 || $solvedExams > 0) {
                    $inProgressCount++;
                } else {
                    $notStartedCount++;
                }
            }

            $completionRate   = $total_subscriptions > 0 ? round(($completedCount / $total_subscriptions) * 100, 2) : 0;
            $inProgressRate   = $total_subscriptions > 0 ? round(($inProgressCount / $total_subscriptions) * 100, 2) : 0;
            $notStartedRate   = $total_subscriptions > 0 ? round(($notStartedCount / $total_subscriptions) * 100, 2) : 0;

            $programStats[] = [
                'program_id'       => $program->id,
                'program_title'    => $program->translations->firstWhere('locale', app()->getLocale())->title ?? '-',
                'total_students'   => $total_subscriptions,
                'completed_rate'   => $completionRate,
                'in_completed_rate'=> $inProgressRate,
                'not_started_rate' => $notStartedRate,
            ];
        }

        // Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        foreach ($columns as $columnKey => $column) {
            $Width = ($columnKey == 0) ? 30 : 20; // عرض الأعمدة
            $sheet->getColumnDimension($column)->setWidth($Width);
            $sheet->getStyle($column.'1')->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'FFFF00'], // خلفية صفراء
                ],
                'font' => [
                    'bold' => true,
                ],
            ]);
        }
        $sheet->setRightToLeft(true);

        // Header
        $sheet->setCellValue('A1', __('trans.program.title'));
        $sheet->setCellValue('B1', __('trans.program.total_students'));
        $sheet->setCellValue('C1', __('trans.program.completed'));
        $sheet->setCellValue('D1', __('trans.program.in_completed'));
        $sheet->setCellValue('E1', __('trans.program.not_started'));
        $sheet->setCellValue('F1', __('trans.program.type'));
        $sheet->setCellValue('G1', __('trans.program.sessions_count'));
        $sheet->setCellValue('H1', __('trans.program.expert'));

        $row = 2;

        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->setCellValue("A{$row}", "البيانات من تاريخ $from_date الى تاريخ $to_date");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => 'D9EAD3'] // أخضر فاتح كخلفية
            ],
        ]);
        $row++;

        foreach ($programStats as $stat) {
            $program = $programs->firstWhere('id', $stat['program_id']);

            $sheet->setCellValue('A' . $row, $stat['program_title'] ?? '-');
            $sheet->setCellValue('B' . $row, $stat['total_students']);
            $sheet->setCellValue('C' . $row, $stat['completed_rate'] . '%');
            $sheet->setCellValue('D' . $row, $stat['in_completed_rate'] . '%');
            $sheet->setCellValue('E' . $row, $stat['not_started_rate'] . '%');
            $sheet->setCellValue('F' . $row, $program->type == 'live' ? __('trans.program.live') : __('trans.program.registered'));
            $sheet->setCellValue('G' . $row, $program->sessions_count ?? 0);
            $sheet->setCellValue('H' . $row, $program->teacher->name ?? '-');
            $row++;
        }

        // تنزيل الملف
        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.program.title')."-$today.xlsx";
        $filePath = public_path($fileName);

        $writer->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    public function best_trainers_excel(Request $request)
    {
        $fromDate = Carbon::parse($request->from_date)->startOfDay() ?? Carbon::now()->startOfDay();
        $toDate   = Carbon::parse($request->to_date)->endOfDay() ?? Carbon::now()->endOfDay();

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $from_date = $fromDate->format('Y-m-d');
        $to_date = $toDate->format('Y-m-d');

        $programs = Program::with(['assignments', 'exams', 'translations'])
            ->where('teacher_id', $user->id)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->get();

        $studentsStats = [];

        foreach ($programs as $program) {
            $studentIds = Subscription::where('program_id', $program->id)->pluck('student_id');

            foreach ($studentIds as $studentId) {
                $totalAssignments = $program->assignments->count() ?: 1; // تجنب القسمة على صفر
                $totalExams = $program->exams->count() ?: 1;
                $totalReviews = Review::where('student_id', $studentId)->count();

                // عدد المهام المحلولة
                $solvedAssignments = AssignmentSolution::whereIn('assignment_id', $program->assignments->pluck('id'))
                    ->where('student_id', $studentId)
                    ->distinct('assignment_id')
                    ->count('assignment_id');

                // عدد الاختبارات المحلولة
                $solvedExams = ExamStudent::whereIn('exam_id', $program->exams->pluck('id'))
                    ->where('student_id', $studentId)
                    ->distinct('exam_id')
                    ->count('exam_id');

                // متوسط الدرجات
                $avgGrade = ExamStudent::whereIn('exam_id', $program->exams->pluck('id'))
                    ->where('student_id', $studentId)
                    ->avg('grade') ?? 0;

                // حفظ البيانات في مصفوفة
                if (!isset($studentsStats[$studentId])) {
                    $studentsStats[$studentId] = [
                        'student_id' => $studentId,
                        'student_name' => Student::find($studentId)->name,
                        'student_email' => Student::find($studentId)->email,
                        'student_phone' => Student::find($studentId)->phone,
                        'assignments_completion' => 0,
                        'exams_completion' => 0,
                        'avg_grade' => 0,
                        'total_reviews' => 0
                    ];
                }

                $studentsStats[$studentId]['assignments_completion'] = ($solvedAssignments / $totalAssignments) * 100;
                $studentsStats[$studentId]['exams_completion'] = ($solvedExams / $totalExams) * 100;
                $studentsStats[$studentId]['avg_grade'] = $avgGrade;
                $studentsStats[$studentId]['total_reviews'] = $totalReviews;
            }
        }

        $topStudents = collect($studentsStats)
        ->sort(function ($a, $b) {
            // الترتيب حسب الدرجة أولاً
            if ($a['avg_grade'] != $b['avg_grade']) {
                return $b['avg_grade'] <=> $a['avg_grade'];
            }

            // لو الدرجات متساوية نرتب حسب نسبة حل المهام
            if ($a['assignments_completion'] != $b['assignments_completion']) {
                return $b['assignments_completion'] <=> $a['assignments_completion'];
            }

            // لو الاتنين متساويين نرتب حسب نسبة حل الاختبارات
            if($a['exams_completion'] != $b['exams_completion']){
                return $b['exams_completion'] <=> $a['exams_completion'];
            }

            // لو الاتنين متساويين نرتب حسب نسبة المراجعات
            return $b['total_reviews'] <=> $a['total_reviews'];
        })
        ->values();

       // Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        foreach ($columns as $columnKey => $column) {
            $Width = ($columnKey == 0) ? 30 : 20; // عرض الأعمدة
            $sheet->getColumnDimension($column)->setWidth($Width);
            $sheet->getStyle($column.'1')->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'FFFF00'], // خلفية صفراء
                ],
                'font' => [
                    'bold' => true,
                ],
            ]);
        }
        $sheet->setRightToLeft(true);

        // Header
        $sheet->setCellValue('A1', __('trans.student.name'));
        $sheet->setCellValue('B1', __('trans.student.email'));
        $sheet->setCellValue('C1', __('trans.student.phone'));
        $sheet->setCellValue('D1', __('trans.student.assignments_completion'));
        $sheet->setCellValue('E1', __('trans.student.exams_completion'));
        $sheet->setCellValue('F1', __('trans.student.avg_grade'));
        $sheet->setCellValue('G1', __('trans.student.total_reviews'));

        $row = 2;

        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->setCellValue("A{$row}", "البيانات من تاريخ $from_date الى تاريخ $to_date");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => 'D9EAD3'] // أخضر فاتح كخلفية
            ],
        ]);
        $row++;

        foreach ($topStudents as $student) {
            $sheet->setCellValue('A' . $row, $student['student_name'] ?? '-');
            $sheet->setCellValue('B' . $row, $student['student_email'] ?? '-');
            $sheet->setCellValueExplicit('C' . $row, (string)$student['student_phone'], DataType::TYPE_STRING);
            $sheet->setCellValue('D' . $row, $student['assignments_completion'] . '%');
            $sheet->setCellValue('E' . $row, $student['exams_completion'] . '%');
            $sheet->setCellValue('F' . $row, $student['avg_grade'] . '%');
            $sheet->setCellValue('G' . $row, $student['total_reviews'] . '%');
            $row++;
        }

        // تنزيل الملف
        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.program.title')."-$today.xlsx";
        $filePath = public_path($fileName);

        $writer->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

}
