<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Assignment;
use App\Models\Exam;
use App\Models\Program;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class StatisticsController extends Controller
{
    public function userRegistration(Request $request)
    {
        $request->validate([
            'from_date' => 'required|date_format:d-m-Y',
            'to_date' => 'required|date_format:d-m-Y|after_or_equal:from_date',
        ]);

        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date)->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date)->endOfDay();

        $total_teachers = Teacher::where('type', 'teacher')->count();
        $total_students = Student::count();

        $diffInDays = $fromDate->diffInDays($toDate);

        $resultData = [];

        if ($diffInDays <= 7) {
            // Daily breakdown
            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dayLabel = $current->format('Y-m-d');

                $resultData[] = [
                    'label' => $dayLabel,
                    'count_student' => Student::whereDate('created_at', $current->toDateString())->count(),
                    'count_teacher' => Teacher::where('type', 'teacher')->whereDate('created_at', $current->toDateString())->count(),
                ];

                $current->addDay();
            }

        } elseif ($diffInDays <= 31) {
            // Weekly breakdown
            $start = $fromDate->copy()->startOfWeek();
            $week = 1;
            while ($start < $toDate) {
                $end = $start->copy()->endOfWeek();
                if ($end > $toDate) $end = $toDate;

                $resultData[] = [
                    'label' => "الأسبوع $week",
                    'count_student' => Student::whereBetween('created_at', [$start, $end])->count(),
                    'count_teacher' => Teacher::where('type', 'teacher')->whereBetween('created_at', [$start, $end])->count(),
                ];

                $start->addWeek();
                $week++;
            }

        } else {
            // Monthly breakdown
            $current = $fromDate->copy()->startOfMonth();
            while ($current <= $toDate) {
                $startOfMonth = $current->copy()->startOfMonth();
                $endOfMonth = $current->copy()->endOfMonth();
                if ($endOfMonth > $toDate) $endOfMonth = $toDate;

                $resultData[] = [
                    'label' => $startOfMonth->format('Y-m'),
                    'count_student' => Student::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
                    'count_teacher' => Teacher::where('type', 'teacher')->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
                ];

                $current->addMonth();
            }
        }

        return response()->json([
            'success' => true,
            'total_teachers' => $total_teachers,
            'total_students' => $total_students,
            'data' => $resultData,
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

        $profit_percentage = Setting::value('profit_percentage') ?? 0;
        $totalRevenue = Subscription::whereBetween('created_at', [$fromDate, $toDate])->sum('price');
        $adminProfit = ($profit_percentage / 100) * $totalRevenue;

        $diffInDays = $fromDate->diffInDays($toDate);

        $resultData = [];

        if ($diffInDays <= 7) {
            // Daily breakdown
            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dayLabel = $current->format('Y-m-d');

                $dayProfit = Subscription::whereDate('created_at', $current->toDateString())->sum('price');
                $adminDayProfit = ($profit_percentage / 100) * $dayProfit;

                $resultData[] = [
                    'label' => $dayLabel,
                    'profit' => round($adminDayProfit, 2),
                ];

                $current->addDay();
            }

        } elseif ($diffInDays <= 31) {
            // Weekly breakdown
            $start = $fromDate->copy()->startOfWeek();
            $week = 1;
            while ($start < $toDate) {
                $end = $start->copy()->endOfWeek();
                if ($end > $toDate) $end = $toDate;

                $weekProfit = Subscription::whereBetween('created_at', [$start, $end])->sum('price');
                $adminWeekProfit = ($profit_percentage / 100) * $weekProfit;

                $resultData[] = [
                    'label' => "الأسبوع $week",
                    'profit' => round($adminWeekProfit, 2),
                ];

                $start->addWeek();
                $week++;
            }

        } else {
            // Monthly breakdown
            $current = $fromDate->copy()->startOfMonth();
            while ($current <= $toDate) {
                $startOfMonth = $current->copy()->startOfMonth();
                $endOfMonth = $current->copy()->endOfMonth();
                if ($endOfMonth > $toDate) $endOfMonth = $toDate;

                $monthProfit = Subscription::whereBetween('created_at', [$startOfMonth, $endOfMonth])->sum('price');
                $adminMonthProfit = ($profit_percentage / 100) * $monthProfit;

                $resultData[] = [
                    'label' => $startOfMonth->format('Y-m'),
                    'profit' => round($adminMonthProfit, 2),
                ];

                $current->addMonth();
            }
        }

        return response()->json([
            'success' => true,
            'total_profit' => round($adminProfit, 2),
            'data' => $resultData,
        ]);
    }

    public function teachersActivity()
    {
        $teachers = Teacher::where('type', 'teacher')->select('name')
            ->withCount('programs')
            ->get();

        // $totalPrograms = Program::count();
        // $totalPrograms = Program::withTrashed()->count();
        $totalPrograms = $teachers->sum('programs_count'); // same Program::count

        $teachers = $teachers->map(function ($teacher) use ($totalPrograms) {
            $teacher->percentage = $totalPrograms > 0
                ? round(($teacher->programs_count / $totalPrograms) * 100, 2)
                : 0;
            return $teacher;
        });

        return response()->json([
            'success' => true,
            'totalPrograms' => $totalPrograms,
            'data' => $teachers
        ]);
    }

    public function reviews(Request $request)
    {
        $request->validate([
            'from_date' => 'required|date_format:d-m-Y',
            'to_date' => 'required|date_format:d-m-Y|after_or_equal:from_date',
        ]);

        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date)->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date)->endOfDay();

        $diffInDays = $fromDate->diffInDays($toDate);

        $resultData = [];

        if ($diffInDays <= 7) {
            // Daily breakdown
            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dayLabel = $current->format('Y-m-d');

                $positive_count = Review::where('score', '>=' , 3)->whereDate('created_at', $current->toDateString())->count();
                $negative_count = Review::where('score', '<' , 3)->whereDate('created_at', $current->toDateString())->count();

                $resultData[] = [
                    'label' => $dayLabel,
                    'positive_count' => $positive_count,
                    'negative_count' => $negative_count
                ];

                $current->addDay();
            }

        } elseif ($diffInDays <= 31) {
            // Weekly breakdown
            $start = $fromDate->copy()->startOfWeek();
            $week = 1;
            while ($start < $toDate) {
                $end = $start->copy()->endOfWeek();
                if ($end > $toDate) $end = $toDate;

                $positive_count = Review::where('score', '>=' , 3)->whereBetween('created_at', [$start, $end])->count();
                $negative_count = Review::where('score', '<' , 3)->whereBetween('created_at', [$start, $end])->count();

                $resultData[] = [
                    'label' => 'Week ' . $week,
                    'positive_count' => $positive_count,
                    'negative_count' => $negative_count
                ];

                $start->addWeek();
                $week++;
            }

        } else {
            // Monthly breakdown
            $current = $fromDate->copy()->startOfMonth();
            while ($current <= $toDate) {
                $startOfMonth = $current->copy()->startOfMonth();
                $endOfMonth = $current->copy()->endOfMonth();
                if ($endOfMonth > $toDate) $endOfMonth = $toDate;

                $positive_count = Review::where('score', '>=' , 3)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();
                $negative_count = Review::where('score', '<' , 3)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();

                $resultData[] = [
                    'label' => $startOfMonth->format('Y-m'),
                    'positive_count' => $positive_count,
                    'negative_count' => $negative_count
                ];

                $current->addMonth();
            }
        }

        return response()->json([
            'success' => true,
            'data' => $resultData,
        ]);
    }

    public function contentStats()
    {
        $fromDate = Carbon::createFromFormat('d-m-Y', $request->from_date ?? Carbon::today()->format('d-m-Y'))->startOfDay();
        $toDate = Carbon::createFromFormat('d-m-Y', $request->to_date ?? Carbon::today()->format('d-m-Y'))->endOfDay();

        $registered_programs_count = Program::where('type', 'registered')
        ->whereBetween('created_at', [$fromDate, $toDate])
        ->count();

        $live_programs_count = Program::where('type', 'live')
        ->whereBetween('created_at', [$fromDate, $toDate])
        ->count();

        $program_ids = Program::whereBetween('created_at', [$fromDate, $toDate])->pluck('id');

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

    public function programSubscriptions(Request $request)
    {
        $from_date = $request->from_date ?? Carbon::today()->toDateString();
        $to_date   = $request->to_date   ?? Carbon::today()->toDateString();

        $from = Carbon::parse($from_date)->startOfDay();
        $to   = Carbon::parse($to_date)->endOfDay();

        $programs = Program::select('id')->with('translations')
        ->withCount([
            'subscriptions' => function ($query) use ($from, $to) {
                $query->whereBetween('created_at', [$from, $to]);
            },
            'sessions'
        ])
        ->get()
        ->map(function ($program) {
            $program->makeHidden(['category', 'teacher']);
            return $program;
        });

        return response()->json([
            'success' => true,
            'data' => $programs
        ]);
    }




    // ============================== excel ===============================
    public function userRegistration_excel(Request $request)
    {
        $fromDate = Carbon::parse($request->from_date)->startOfDay() ?? Carbon::today()->startOfDay();
        $toDate = Carbon::parse($request->to_date)->endOfDay() ?? Carbon::today()->endOfDay();

        $diffInDays = $fromDate->diffInDays($toDate);

        $from_date = $fromDate->format('Y-m-d');
        $to_date = $toDate->format('Y-m-d');

        $resultData = [];

        if ($diffInDays <= 7) {
            // Daily breakdown
            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dayLabel = $current->format('Y-m-d');

                $resultData[] = [
                    'label' => $dayLabel,
                    'students' => Student::where('type', 'student')->whereDate('created_at', $current->toDateString())->get(),
                    'teachers' => Teacher::where('type', 'teacher')->whereDate('created_at', $current->toDateString())->get(),
                ];

                $current->addDay();
            }

        } elseif ($diffInDays <= 31) {
            // Weekly breakdown
            $start = $fromDate->copy()->startOfWeek();
            $week = 1;
            while ($start < $toDate) {
                $end = $start->copy()->endOfWeek();
                if ($end > $toDate) $end = $toDate;

                $resultData[] = [
                    'label' => "الأسبوع $week",
                    'students' => Student::where('type', 'student')->whereBetween('created_at', [$start, $end])->get(),
                    'teachers' => Teacher::where('type', 'teacher')->whereBetween('created_at', [$start, $end])->get(),
                ];

                $start->addWeek();
                $week++;
            }

        } else {
            // Monthly breakdown
            $current = $fromDate->copy()->startOfMonth();
            while ($current <= $toDate) {
                $startOfMonth = $current->copy()->startOfMonth();
                $endOfMonth = $current->copy()->endOfMonth();
                if ($endOfMonth > $toDate) $endOfMonth = $toDate;

                $resultData[] = [
                    'label' => $startOfMonth->format('Y-m'),
                    'students' => Student::where('type', 'student')->whereBetween('created_at', [$startOfMonth, $endOfMonth])->get(),
                    'teachers' => Teacher::where('type', 'teacher')->whereBetween('created_at', [$startOfMonth, $endOfMonth])->get(),
                ];

                $current->addMonth();
            }
        }

        $exportExcel = function ($dataList, $type) use($from_date, $to_date) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $columns = ['A', 'B', 'C', 'D'];
            foreach ($columns as $columnKey => $column) {
                $Width = ($columnKey==0||$columnKey==10)? 25 : 15;
                $sheet->getColumnDimension($column)->setWidth($Width);
                $sheet->getStyle($column.'1')->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'color' => ['rgb' => 'FFFF00'],
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
            foreach ($dataList as $data) {
                $collection = $type == 'students' ? $data['students'] : $data['teachers'];
                if ($collection->isEmpty()) continue;

                $sheet->mergeCells("A{$row}:D{$row}");
                // $sheet->setCellValue("A{$row}", $data['label'] . ' (' . $collection->count() . ' ' . ($type=='students'?'طالب':'مدرس') . ')');
                $sheet->setCellValue("A{$row}", "البيانات من تاريخ $from_date الى تاريخ $to_date" . ' (' . $collection->count() . ' ' . ($type=='students'?'طالب':'مدرس') . ')');
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10],
                    'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'color' => ['rgb' => 'D9EAD3']
                    ],
                ]);
                $row++;

                foreach ($collection as $person) {
                    $sheet->setCellValue('A' . $row, $person->name ?? '-');
                    $sheet->setCellValue('B' . $row, $person->email ?? '-');
                    $sheet->setCellValueExplicit('C' . $row, (string)$person->phone, DataType::TYPE_STRING);
                    $sheet->setCellValue('D' . $row, $person->created_at->format('Y-m-d H:i'));
                    $row++;
                }

                $row++;
            }

            $writer = new Xlsx($spreadsheet);
            $today = date('Y-m-d');
            $fileName = ($type=='students' ? __('trans.student.user_registeration') : __('trans.teacher.user_registeration')) . "-$today.xlsx";
            $filePath = public_path($fileName);
            $writer->save($filePath);

            return $filePath;
        };

        // 2 file
        $studentFile = $exportExcel($resultData, 'students');
        $teacherFile = $exportExcel($resultData, 'teachers');

        // downloda 2 file in zip
        $zipFile = public_path('user-registration-'.date('Y-m-d').'.zip');
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) === TRUE) {
            $zip->addFile($studentFile, basename($studentFile));
            $zip->addFile($teacherFile, basename($teacherFile));
            $zip->close();
        }

        // after downloda => delete 2 file
        @unlink($studentFile);
        @unlink($teacherFile);

        return response()->download($zipFile)->deleteFileAfterSend(true);
    }

    public function profitStats_excel(Request $request)
    {
        $fromDate = Carbon::parse($request->from_date)->startOfDay() ?? Carbon::today()->startOfDay();
        $toDate = Carbon::parse($request->to_date)->endOfDay() ?? Carbon::today()->endOfDay();
        $diffInDays = $fromDate->diffInDays($toDate);

        $from_date = $fromDate->format('Y-m-d');
        $to_date = $toDate->format('Y-m-d');

        $profit_percentage = Setting::value('profit_percentage') ?? 0;

        $resultData = [];

        if ($diffInDays <= 7) {
            // Daily breakdown
            $current = $fromDate->copy();
            while ($current <= $toDate) {
                $dayLabel = $current->format('Y-m-d');

                $dayProfit = Subscription::whereDate('created_at', $current->toDateString())->sum('price');
                $adminDayProfit = ($profit_percentage / 100) * $dayProfit;

                $students = Subscription::whereBetween('created_at', [$current, $current->copy()->endOfDay()])
                    ->with(['student', 'program'])
                    ->get()
                    ->groupBy('student_id');

                $resultData[] = [
                    'label' => $dayLabel,
                    'profit' => round($adminDayProfit, 2),
                    'students' => $students
                ];

                $current->addDay();
            }

        } elseif ($diffInDays <= 31) {
            // Weekly breakdown
            $start = $fromDate->copy()->startOfWeek();
            $week = 1;
            while ($start < $toDate) {
                $end = $start->copy()->endOfWeek();
                if ($end > $toDate) $end = $toDate;

                $weekProfit = Subscription::whereBetween('created_at', [$start, $end])->sum('price');
                $adminWeekProfit = ($profit_percentage / 100) * $weekProfit;

                 $students = Subscription::whereBetween('created_at', [$start, $end])
                    ->with(['student', 'program'])
                    ->get()
                    ->groupBy('student_id');

                $resultData[] = [
                    'label' => "الأسبوع $week",
                    'profit' => round($adminWeekProfit, 2),
                    'students' => $students
                ];

                $start->addWeek();
                $week++;
            }

        } else {
            // Monthly breakdown
            $current = $fromDate->copy()->startOfMonth();
            while ($current <= $toDate) {
                $startOfMonth = $current->copy()->startOfMonth();
                $endOfMonth = $current->copy()->endOfMonth();
                if ($endOfMonth > $toDate) $endOfMonth = $toDate;

                $monthProfit = Subscription::whereBetween('created_at', [$startOfMonth, $endOfMonth])->sum('price');
                $adminMonthProfit = ($profit_percentage / 100) * $monthProfit;

                $students = Subscription::whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->with(['student', 'program'])
                    ->get()
                    ->groupBy('student_id');

                $resultData[] = [
                    'label' => $startOfMonth->format('Y-m'),
                    'profit' => round($adminMonthProfit, 2),
                    'students' => $students
                ];

                $current->addMonth();
            }
        }

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
            // سطر للفترة كفاصل
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", "البيانات من تاريخ $from_date الى تاريخ $to_date" . ' (' . $period['students']->count() . ' طالب)' . ' - (الربح ' . $period['profit'] . ' ريال)');
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
            foreach ($period['students'] as $student_id => $subscriptions) {
                $student = $subscriptions->first()->student;
                // $programs = $subscriptions->pluck('program.title')->implode(', ');
                $programs = $subscriptions->map(function ($subscription) {
                    return $subscription->program->translations->where('locale', app()->getLocale())
                    ->pluck('title')->implode(' / ');
                })->implode(', ');


                $sheet->setCellValue('A' . $row, $student->name ?? '-');
                $sheet->setCellValue('B' . $row, $student->email ?? '-');
                $sheet->setCellValueExplicit('C' . $row, (string)$subscriptions->first()->student->phone, DataType::TYPE_STRING);
                $sheet->setCellValue('D' . $row, $programs);
                $sheet->setCellValue('E' . $row, $subscriptions->sum('price'));
                $sheet->setCellValue('F' . $row, $subscriptions->sum('price') * ($profit_percentage / 100));
                $row++;
            }
        }

        // تنزيل الملف
        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.subscription.total_profit')."-$today.xlsx";
        $filePath = public_path($fileName);

        $writer->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    public function programSubscriptions_excel(Request $request)
    {
        $from_date = $request->from_date ?? Carbon::today()->toDateString();
        $to_date   = $request->to_date   ?? Carbon::today()->toDateString();

        $from = Carbon::parse($from_date)->startOfDay();
        $to   = Carbon::parse($to_date)->endOfDay();

        $programs = Program::with('teacher', 'translations') // عشان نجيب المدرس
            ->withCount([
                'sessions',
                'subscriptions' => function ($query) use ($from, $to) {
                    $query->whereBetween('created_at', [$from, $to]);
                },
            ])
            ->get();

        // Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E'];
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
        $sheet->setCellValue('B1', __('trans.program.type'));
        $sheet->setCellValue('C1', __('trans.program.sessions_count'));
        $sheet->setCellValue('D1', __('trans.program.subscriptions_count'));
        $sheet->setCellValue('E1', __('trans.program.expert'));

        $row = 2;

        $sheet->mergeCells("A{$row}:E{$row}");
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

        foreach ($programs as $program) {
            $sheet->setCellValue('A' . $row, $program->translations->firstWhere('locale', app()->getLocale())->title ?? '-');
            $sheet->setCellValue('B' . $row, $program->type == 'live' ? __('trans.program.live') : __('trans.program.registered'));
            $sheet->setCellValue('C' . $row, $program->sessions_count ?? 0);
            $sheet->setCellValue('D' . $row, $program->subscriptions_count ?? 0);
            $sheet->setCellValue('E' . $row, $program->teacher->name ?? '-');
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
