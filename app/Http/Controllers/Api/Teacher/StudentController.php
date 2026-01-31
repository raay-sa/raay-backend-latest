<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSolution;
use App\Models\Exam;
use App\Models\ExamStudent;
use App\Models\Program;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class StudentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;
        $payment = $request->payment;

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $calcChange = fn($current, $previous) => $previous > 0
            ? round((($current - $previous) / $previous) * 100, 2)
            : 100;

        $programs = Program::where('teacher_id', $user->id)->with('translations')->get();

        $current_programs = $programs->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $previous_programs = $programs->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        $percentage_change = $calcChange($current_programs, $previous_programs);

        $subscriptions = Subscription::with(['program:id,type', 'program.translations', 'student:id,name,email,phone,image'])
            ->whereIn('program_id', $programs->pluck('id')->toArray())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($subscription) {
            // Find the related transaction for this student & program
            $transaction = Transaction::where('student_id', $subscription->student_id)
                ->where('created_at', $subscription->created_at)
                ->whereHas('programs', function ($query) use ($subscription) {
                    $query->where('program_id', $subscription->program_id);
                })
                ->first();

            // Add the transaction status as a new attribute
            $subscription->setAttribute('transaction_status', $transaction ? $transaction->status : null);

            $subscription->student->setAttribute('programs_count', $subscription->student->subscriptions->count() ?? 0);
            $subscription->student->makeHidden('subscriptions');

            $subscription->student->setAttribute('reviews_count', $subscription->student->reviews->count());
            $subscription->student->makeHidden('reviews');

            // Assignments
            $assignments = Assignment::where('program_id', $subscription->program_id)->get();
            $assignments_count = $assignments->count();
            $assignments_solution_count = AssignmentSolution::whereIn('assignment_id', $assignments->pluck('id'))
            ->where('student_id', $subscription->student_id)
            ->count();
            $subscription->program->setAttribute('assignments_count', $assignments_count);
            $subscription->program->setAttribute('assignments_solutions_count', $assignments_solution_count);
            if($assignments_solution_count == $assignments_count){
                $subscription->program->setAttribute('assignments_status', 'completed');
            } elseif($assignments_solution_count == 0){
                $subscription->program->setAttribute('assignments_status', 'not_started');
            } else {
                $subscription->program->setAttribute('assignments_status', 'not_completed');
            }

            // exams
            $exams = Exam::where('program_id', $subscription->program_id)
            ->where('user_type', 'student') // exams for student
            ->pluck('id')->toArray();

            $examResults = ExamStudent::where('student_id', $subscription->student_id)
            ->whereIn('exam_id', $exams)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('exam_id')
            ->map(function ($attempts) {
                return $attempts->first();
            });
            $averageScore = $examResults->avg('grade') ?? 0;
            $subscription->program->setAttribute('exam_score', $averageScore);

            return $subscription;
        });

        $current_students = $subscriptions->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $previous_students = $subscriptions->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        $student_percentage_change = $calcChange($current_students, $previous_students);

        $teacher_assignments = $user->assignments()->get();
        $teacher_exams = $user->exams()->get();

        $tasks_count = $teacher_assignments->count() + $teacher_exams->count();

        // الشهر الحالي
        $current_month_assignments = $teacher_assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->month &&
                $a->created_at->year == now()->year;
        })->count();

        $current_month_exams = $teacher_exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->month &&
                $e->created_at->year == now()->year;
        })->count();

        // الشهر الماضي
        $last_month_assignments = $teacher_assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->subMonth()->month &&
                $a->created_at->year == now()->subMonth()->year;
        })->count();

        $last_month_exams = $teacher_exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->subMonth()->month &&
                $e->created_at->year == now()->subMonth()->year;
        })->count();

          // إجمالي المهام والاختبارات للشهر الحالي
        $current_month_total = $current_month_assignments + $current_month_exams;

        // إجمالي المهام والاختبارات للشهر الماضي
        $last_month_total = $last_month_assignments + $last_month_exams;

        //  النسبة
        $tasks_percentage_change = $calcChange($current_month_total, $last_month_total);

        $profit_percentage = Setting::value('profit_percentage');
        // $totalRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))->sum('price');
        $totalRevenue = $subscriptions->sum('price');
        $adminProfit = ($profit_percentage / 100) * $totalRevenue;
        $teacherProfit = $totalRevenue - $adminProfit;
        $current_profit = $subscriptions
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('price') * ((100 - $profit_percentage) / 100);

        $previous_profit = $subscriptions
            ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->sum('price') * ((100 - $profit_percentage) / 100);
        $profit_percentage_change = $calcChange($current_profit, $previous_profit);

        if ($filter === 'latest') {
            $subscriptions = $subscriptions->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $subscriptions = $subscriptions->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $subscriptions = $subscriptions->sortBy(fn($subscription) => mb_strtolower($subscription->student->name))->values();
        } elseif ($filter === 'live_type') {
            $subscriptions = $subscriptions->filter(function ($subscription) {
                return $subscription->program->type === 'live';
            });
        } elseif ($filter === 'registered_type') {
            $subscriptions = $subscriptions->filter(function ($subscription) {
                return $subscription->program->type === 'registered';
            });
        } elseif ($filter == 'passed') {
            $subscriptions = $subscriptions->filter(function ($subscription) use ($user) {
                return $subscription->program->exam_score >= 50;
            });
        } elseif($filter == 'not_passed') {
            $subscriptions = $subscriptions->filter(function ($subscription) use ($user) {
                return $subscription->program->exam_score < 50;
            });
        } elseif ($filter === 'not_started_tasks') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->program->assignments_status == 'not_started')->values();
        } elseif ($filter === 'not_completed_tasks') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->program->assignments_status == 'not_completed')->values();
        } elseif ($filter === 'completed_tasks') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->program->assignments_status == 'completed')->values();
        }

        if ($search) {
            $subscriptions = $subscriptions->filter(function ($subscription) use ($search) {
                $programMatch = $subscription->program->translations->contains(function ($translation) use ($search) {
                    return mb_stripos($translation->title, $search) !== false;
                });

                return mb_stripos($subscription->student->name, $search) !== false || $programMatch;
            })->values();
        }

        if ($payment == 'successful') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->transaction_status == 'completed')->values();
        } elseif ($payment == 'failed') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->transaction_status == 'failed')->values();
        } elseif ($payment == 'pending') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->transaction_status == 'pending')->values();
        } elseif ($payment == 'cancelled') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->transaction_status == 'cancelled')->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $subscriptions = $subscriptions->filter(function ($subscription) use ($price_from, $price_to) {
                $price = $subscription->price;

                if ($price_from !== null && $price < $price_from) {
                    return false;
                }

                if ($price_to !== null && $price > $price_to) {
                    return false;
                }

                return true;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($subscriptions, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'students_count' => $subscriptions->count(),
            'students_percentage' => abs($student_percentage_change),
            'students_status' => $student_percentage_change >= 0 ? 'increase' : 'decrease',

            'programs_count' => $programs->count(),
            'program_percentage' => abs($percentage_change),
            'program_status' => $percentage_change >= 0 ? 'increase' : 'decrease',

            'tasks_count' => $tasks_count,
            'tasks_percentage' => abs($tasks_percentage_change),
            'tasks_status' => $tasks_percentage_change >= 0 ? 'increase' : 'decrease',

            'profit' => $teacherProfit,
            'profit_percentage' => abs($profit_percentage_change),
            'profit_status' => $profit_percentage_change >= 0 ? 'increase' : 'decrease',

            'data' => $paginated
        ]);

    }

    public function students_list(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = $user->programs;
        $students_id = Subscription::whereIn('program_id', $programs->pluck('id'))->pluck('student_id');
        $students = Student::whereIn('id', $students_id)->select(['id', 'name'])->get();

        return response()->json([
            'success' => true,
            'data' => $students
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
            'student_id'    => 'required_without:file|string|exists:students,id',
            'programs_id'   => 'required_without:file|array',
            'programs_id.*' => 'exists:programs,id',
            'file'          => 'required_without:student_id,programs_id|file|mimes:xls,xlsx',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        if ($request->hasFile('file')) {
            $importedStudents = $this->parseExcel($request->file('file'));

            foreach ($importedStudents as $row) {
                $student = null;

                if (!empty($row['phone'])) {
                    $student = Student::where('phone', $row['phone'])->first();
                } elseif (!empty($row['student_name'])) {
                    $student = Student::where('name', $row['student_name'])->first();
                }

                if (!$student) {
                    continue;
                }

                $program = null;
                if (!empty($row['program_name'])) {
                    $program = Program::whereHas('translations', function ($q) use ($row) {
                        $q->where('title', $row['program_name']);
                    })->first();
                }

                if ($program) {
                    if (Subscription::where('student_id', $student->id)->where('program_id', $program->id)->exists()) {
                        continue;
                    }
                    $subscription = new Subscription();
                    $subscription->student_id = $student->id;
                    $subscription->program_id = $program->id;
                    $subscription->price = $program->price;
                    $subscription->save();
                }
            }
        } else {
            foreach ($request->programs_id as $programId) {
                if (Subscription::where('student_id', $request->student_id)->where('program_id', $programId)->exists()) {
                    continue;
                }
                $subscription = new Subscription();
                $subscription->student_id = $request->student_id;
                $subscription->program_id = $programId;
                $subscription->price = Program::find($programId)->price ?? 0;
                $subscription->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
        ]);
    }

    private function parseExcel($file): array
    {
        $students = [];

        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $header = array_map('strtolower', $rows[0]);

        $studentNameIndex = array_search('student_name', $header);
        $phoneIndex       = array_search('phone', $header);
        $programIndex     = array_search('program_name', $header);

        for ($i = 1; $i < count($rows); $i++) {
            $student_name = $studentNameIndex !== false ? trim($rows[$i][$studentNameIndex]) : null;
            $phone        = $phoneIndex       !== false ? trim($rows[$i][$phoneIndex])       : null;
            $program_name = $programIndex     !== false ? trim($rows[$i][$programIndex])     : null;

            if (!empty($phone) || !empty($student_name)) {
                $students[] = [
                    'student_name' => $student_name,
                    'phone'        => $phone,
                    'program_name' => $program_name,
                ];
            }
        }

        return $students;
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $student = Student::with('subscriptions:id,program_id,student_id')
        ->select('id', 'name')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data'    => $student,
        ]);
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
        $validator = Validator::make($request->all(), [
            'student_id'    => 'required|string|exists:students,id',
            'programs_id'   => 'required|array',
            'programs_id.*' => 'exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $studentId = $request->student_id;
        $newProgramIds = $request->programs_id;

        Subscription::where('student_id', $studentId)
            ->whereNotIn('program_id', $newProgramIds)
            ->delete();

        foreach ($newProgramIds as $programId) {
            if (Subscription::where('student_id', $studentId)->where('program_id', $programId)->exists()) {
                continue;
            }
            $subscription = new Subscription();
            $subscription->student_id = $studentId;
            $subscription->program_id = $programId;
            $subscription->price = Program::find($programId)->price ?? 0;
            $subscription->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }


    public function students_excel(Request $request)
     {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;
        $payment = $request->payment;

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::where('teacher_id', $user->id)->get();

        $subscriptions = Subscription::with(['program:id,type', 'program.translations',
        'student:id,name,email,phone,image'])
            ->whereIn('program_id', $programs->pluck('id')->toArray())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($subscription) {
            // Find the related transaction for this student & program
            $transaction = Transaction::where('student_id', $subscription->student_id)
                ->where('created_at', $subscription->created_at)
                ->whereHas('programs', function ($query) use ($subscription) {
                    $query->where('program_id', $subscription->program_id);
                })
                ->first();

            // Add the transaction status as a new attribute
            $subscription->setAttribute('transaction_status', $transaction ? $transaction->status : null);

            $subscription->student->setAttribute('programs_count', $subscription->student->subscriptions->count() ?? 0);
            $subscription->student->makeHidden('subscriptions');

            $subscription->student->setAttribute('reviews_count', $subscription->student->reviews->count());
            $subscription->student->makeHidden('reviews');

            // assignments
            $assignments = Assignment::where('program_id', $subscription->program_id)->get();
            $assignments_count = $assignments->count();
            $assignments_solution_count = AssignmentSolution::whereIn('assignment_id', $assignments->pluck('id'))
            ->where('student_id', $subscription->student_id)
            ->count();
            $subscription->program->setAttribute('assignments_count', $assignments_count);
            $subscription->program->setAttribute('assignments_solutions_count', $assignments_solution_count);
            if($assignments_solution_count == $assignments_count){
                $subscription->program->setAttribute('assignments_status', 'completed');
            } elseif($assignments_solution_count == 0){
                $subscription->program->setAttribute('assignments_status', 'not_started');
            } else {
                $subscription->program->setAttribute('assignments_status', 'not_completed');
            }

            // exams
            $exams = Exam::where('program_id', $subscription->program_id)->pluck('id')->toArray();

            $examResults = ExamStudent::where('student_id', $subscription->student_id)
            ->whereIn('exam_id', $exams)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('exam_id')
            ->map(function ($attempts) {
                // آخر حل لكل امتحان
                return $attempts->first();
            });
            $averageScore = $examResults->avg('grade') ?? 0;
            $subscription->program->setAttribute('exam_score', $averageScore);

            return $subscription;
        });

        if ($filter === 'latest') {
            $subscriptions = $subscriptions->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $subscriptions = $subscriptions->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $subscriptions = $subscriptions->sortBy(fn($subscription) => mb_strtolower($subscription->student->name))->values();
        } elseif ($filter === 'live_type') {
            $subscriptions = $subscriptions->filter(function ($subscription) {
                return $subscription->program->type === 'live';
            });
        } elseif ($filter === 'registered_type') {
            $subscriptions = $subscriptions->filter(function ($subscription) {
                return $subscription->program->type === 'registered';
            });
        } elseif ($filter == 'passed') {
            $subscriptions = $subscriptions->filter(function ($subscription) use ($user) {
                return $subscription->program->exam_score >= 50;
            });
        } elseif($filter == 'not_passed') {
            $subscriptions = $subscriptions->filter(function ($subscription) use ($user) {
                return $subscription->program->exam_score < 50;
            });
        } elseif ($filter === 'not_started_tasks') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->program->assignments_status == 'not_started')->values();
        } elseif ($filter === 'not_completed_tasks') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->program->assignments_status == 'not_completed')->values();
        } elseif ($filter === 'completed_tasks') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->program->assignments_status == 'completed')->values();
        }

        if ($search) {
            $subscriptions = $subscriptions->filter(function ($subscription) use ($search) {
                $programMatch = $subscription->program->translations->contains(function ($translation) use ($search) {
                    return mb_stripos($translation->title, $search) !== false;
                });

                return mb_stripos($subscription->student->name, $search) !== false || $programMatch;
            })->values();
        }


        if ($payment == 'successful') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->transaction_status == 'completed')->values();
        } elseif ($payment == 'failed') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->transaction_status == 'failed')->values();
        } elseif ($payment == 'pending') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->transaction_status == 'pending')->values();
        } elseif ($payment == 'cancelled') {
            $subscriptions = $subscriptions->where(fn($subscription) => $subscription->transaction_status == 'cancelled')->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $subscriptions = $subscriptions->filter(function ($subscription) use ($price_from, $price_to) {
                $price = $subscription->price;

                if ($price_from !== null && $price < $price_from) {
                    return false;
                }

                if ($price_to !== null && $price > $price_to) {
                    return false;
                }

                return true;
            })->values();
        }

        $currentDate = Carbon::now();
        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
        foreach ($columns as $columnKey => $column):
            $Width = ($columnKey==0||$columnKey==10)? 25 : 15;
            $sheet->getColumnDimension($column)->setWidth($Width);
            $sheet->getStyle($column.'1')->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'FFFF00'], // Yellow background
                ],
            ]);
        endforeach;
        $sheet->setRightToLeft(true);

        // Set cell values
        $sheet->setCellValue('A1', __('trans.subscription.student_name'));
        $sheet->setCellValue('B1', __('trans.subscription.student_email'));
        $sheet->setCellValue('C1', __('trans.subscription.program_name'));
        $sheet->setCellValue('D1', __('trans.subscription.program_type'));
        $sheet->setCellValue('E1', __('trans.subscription.task_status'));
        $sheet->setCellValue('F1', __('trans.subscription.exam_status'));
        $sheet->setCellValue('G1', __('trans.subscription.value'));
        $sheet->setCellValue('H1', __('trans.subscription.payment_status'));
        $sheet->setCellValue('I1', __('trans.subscription.created_at'));

        foreach ($subscriptions as $key => $subscription):
            $key = $key+2;
            if($subscription->program->assignments_status == 'not_started') {
                $assignments_status = __('trans.subscription.not_started');
            } else if($subscription->program->assignments_status == 'not_completed') {
                $assignments_status = __('trans.subscription.not_completed');
            } else if($subscription->program->assignments_status == 'completed') {
                $assignments_status = __('trans.subscription.completed');
            }

            if($subscription->program->exam_score >= 50) {
                $exam_status = __('trans.subscription.passed');
            } else {
                $exam_status = __('trans.subscription.not_passed');
            }

            if($subscription->transaction_status == 'completed') {
                $payment_status = __('trans.subscription.completed');
            } elseif($subscription->transaction_status == 'failed') {
                $payment_status = __('trans.subscription.failed');
            } elseif($subscription->transaction_status == 'pending') {
                $payment_status = __('trans.subscription.pending');
            } elseif($subscription->transaction_status == 'cancelled') {
                $payment_status = __('trans.subscription.cancelled');
            }

            $sheet->setCellValue('A'.$key, $subscription->student->name ?? '-');
            $sheet->setCellValue('B'.$key, $subscription->student->email ?? '-');
            $sheet->setCellValue('C'.$key, $subscription->program->translations->firstWhere('locale', app()->getLocale())->title ?? '-');
            $sheet->setCellValue('D'.$key, $subscription->program->type == 'registered' ? __('trans.subscription.registered') : __('trans.subscription.live'));
            $sheet->setCellValue('E'.$key, $assignments_status ?? '-');
            $sheet->setCellValue('F'.$key, $exam_status ?? '-');
            $sheet->setCellValue('G'.$key, $subscription->price ?? '-');
            $sheet->setCellValue('H'.$key, $payment_status ?? '-');
            $sheet->setCellValue('I'.$key, Carbon::parse($subscription->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.subscription.students_management')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }
}
