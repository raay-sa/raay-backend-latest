<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\AssignmentSolution;
use App\Models\Exam;
use App\Models\ExamStudent;
use App\Models\Program;
use App\Models\ProgramTransaction;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentNotificationSetting;
use App\Models\Subscription;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class TraineeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        //  لحساب النسبة
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
            ->whereHas('student', function ($query) {
                $query->where('type', 'trainee'); // فلترة الطلاب المتدربين
            })
            ->get()
            ->map(function ($subscription) {

            $subscription->student->setAttribute('programs_count', $subscription->student->subscriptions->count() ?? 0);
            $subscription->student->makeHidden('subscriptions');

            $subscription->student->setAttribute('reviews_count', $subscription->student->reviews->count());
            $subscription->student->makeHidden('reviews');

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

            $assignmentProgress = $assignments_count > 0
            ? round(($assignments_solution_count / $assignments_count) * 100, 2)
            : 0;

            $subscription->program->setAttribute('assignments_progress', $assignmentProgress);

            $exams = Exam::where('program_id', $subscription->program_id)
            ->where('user_type', 'trainee') // exams for trainee
            ->pluck('id')->toArray();

            $examResults = ExamStudent::where('student_id', $subscription->student_id)
            ->whereIn('exam_id', $exams)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('exam_id')
            ->map(function ($attempts) {
                // نأخذ آخر حل لكل امتحان
                return $attempts->first();
            });
            $averageScore = $examProgress = $examResults->avg('grade') ?? 0;
            $subscription->program->setAttribute('exam_score', $averageScore);

            $sessions = $subscription->program->sessions;
            $sessions_count = $sessions->count();
            $watchedSessions = $subscription->student->session_views()
                ->whereIn('session_id', $sessions->pluck('id'))
                ->pluck('session_id')
                ->unique()
                ->count();

            $sessionProgress = $sessions_count > 0
                ? round(($watchedSessions / $sessions_count) * 100, 2)
                : 0;

            // --- نسبة إنجاز الطالب في البرنامج ---
            $finalProgress = round(($assignmentProgress + $examProgress + $sessionProgress) / 3, 2);

            // نسبة إنجاز الطالب النهائية
            // $finalProgress = $assignments_count > 0 && count($exams) > 0
            //     ? round(($assignmentProgress + $averageScore) / 2, 2)
            //     : ($assignments_count > 0 ? $assignmentProgress : $averageScore);

            $subscription->program->setAttribute('student_progress', $finalProgress);

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

        // حساب النسبة
        $tasks_percentage_change = $calcChange($current_month_total, $last_month_total);

        $profit_percentage = Setting::value('profit_percentage');
        // $totalRevenue = Subscription::whereHas('program', fn($q) => $q->where('teacher_id', $user->id))->sum('price');
        $totalRevenue = $subscriptions->sum('price');
        $adminProfit = ($profit_percentage / 100) * $totalRevenue;
        $teacherProfit = $totalRevenue - $adminProfit;
        $current_profit = $subscriptions
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('price') * ((100 - $profit_percentage) / 100); // ربح المعلم للشهر الحالي

        $previous_profit = $subscriptions
            ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->sum('price') * ((100 - $profit_percentage) / 100); // ربح المعلم للشهر السابق
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
                return $programMatch;
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

    public function excel_sheet_trainees(Request $request)
    {
        // shape
        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E'];
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
        $sheet->setCellValue('A1', __('trans.excel_sheet.trainee'));
        $sheet->setCellValue('B1', __('trans.excel_sheet.phone'));
        $sheet->setCellValue('C1', __('trans.excel_sheet.email'));
        $sheet->setCellValue('D1', __('trans.excel_sheet.password'));
        $sheet->setCellValue('E1', __('trans.excel_sheet.program'));

        $sheet->setCellValue('A2', __('trans.excel_sheet.trainee_name'));
        $sheet->setCellValue('B2', '501234567');
        $sheet->setCellValue('C2', 'example@example.com');
        $sheet->setCellValue('D2', '123456');
        $sheet->setCellValue('E2', __('trans.excel_sheet.program_name'));

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = "trainees-excel-sheet.xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }

    public function store(Request $request)
    {
        $teacher = $request->user();
        if (!$teacher || !($teacher instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|file|mimes:xls,xlsx',
            ]);

            $importedStudents = parseExcel($request->file('file'));
            $duplicates = [];
            $created    = [];

            foreach ($importedStudents as $row) {
                $phone = $row['الهاتف'];
                $email = $row['البريد الإلكتروني'];

                if (!str_starts_with($phone, '+966')) {
                    $phone = '+966' . ltrim($phone, '0');
                }

                $isDuplicate =
                    Student::where('phone', $phone)->orWhere('email', $email)->exists() ||
                    Teacher::where('phone', $phone)->orWhere('email', $email)->exists() ||
                    Admin::where('phone', $phone)->orWhere('email', $email)->exists();

                if ($isDuplicate) {
                    $duplicates[] = [
                        'name'  => $row['المتدرب'],
                        'phone' => $phone,
                        'email' => $email,
                    ];
                    continue;
                }

                $user = new Student();
                $user->name        = $row['المتدرب'];
                $user->phone       = $phone;
                $user->email       = $email;
                $user->password    = Hash::make($row['كلمة المرور'] ?? '123456');
                $user->type        = 'trainee';
                $user->status      = 'inactive';
                $user->is_approved = 1;
                $user->createdBy()->associate($teacher);
                $user->save();

                $created[] = $user;

                if (!empty($row['البرنامج'])) {
                    $program_trans = ProgramTransaction::where('title', $row['البرنامج'])->first();
                    $program = Program::find($program_trans->parent_id);
                    // $program = Program::where('title', $row['البرنامج'])->first();
                    if ($program && !Subscription::where('student_id', $user->id)->where('program_id', $program->id)->exists()) {
                        $sub = new Subscription();
                        $sub->student_id = $user->id;
                        $sub->program_id = $program->id;
                        $sub->price      = 0;
                        $sub->save();
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($created) > 0 ? __('trans.alert.success.done_create') : null,
                'created_count'    => count($created),
                'failed_count' => count($duplicates),
                'reason' => count($duplicates) > 0 ? __('trans.alert.error.email_or_phone_used_before') : null,
                'faliled_trainees_list' => $duplicates,
            ]);
        }

        // insert data manually
        $phone = $request->phone;
        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'phone'         => ['required','string','regex:/^\+9665\d{8}$/','unique:students,phone','unique:teachers,phone','unique:admins,phone'],
            'email'         => 'required|email|unique:students,email|unique:teachers,email|unique:admins,email',
            'password'      => 'required|string|min:6',
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

        $user = new Student();
        $user->name        = $request->name;
        $user->phone       = $phone;
        $user->email       = $request->email;
        $user->password    = Hash::make($request->password);
        $user->type        = 'trainee';
        $user->status      = 'inactive';
        $user->is_approved = 1;
        $user->createdBy()->associate($teacher);
        $user->save();

        $user->makeHidden('password');
        storeInCache('students', $user);

        StudentNotificationSetting::create(['student_id' => $user->id]);

        foreach ($request->programs_id as $programId) {
            $sub = new Subscription();
            $sub->student_id = $user->id;
            $sub->program_id = $programId;
            $sub->price      = 0;
            $sub->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
        ]);
    }

    public function show(string $id)
    {
        $student = Student::with('subscriptions:id,program_id,student_id')
        ->select('id', 'name', 'email', 'phone', 'education')
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

    public function update(Request $request, string $id)
    {
        $student = Student::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:admins,email|unique:teachers,email|unique:students,email,' . $student->id,
            'password'      => 'nullable|string|min:6',
            'phone'         => ['required', 'string',
                'regex:/^\+9665\d{8}$/',
                'unique:admins,phone',
                'unique:teachers,phone',
                'unique:students,phone,' . $student->id,
            ],
            'programs_id'    => 'required|array',
            'programs_id.*'  => 'exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $student->name = $request->name;
        $student->phone = $request->phone;
        $student->email = $request->email;
        if ($request->filled('password')) {
            $student->password = Hash::make($request->password);
        }
        $student->save();

        $newProgramIds = $request->programs_id;

        Subscription::where('student_id', $student->id)
            ->whereNotIn('program_id', $newProgramIds)
            ->delete();

        foreach ($newProgramIds as $programId) {
            if (Subscription::where('student_id', $student->id)->where('program_id', $programId)->exists()) {
                continue;
            }
            $subscription = new Subscription();
            $subscription->program_id = $programId;
            $subscription->price = 0;
            $subscription->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }

    public function trainees_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $programs = Program::where('teacher_id', $user->id)->with('translations')->get();

        $subscriptions = Subscription::with(['program:id,type', 'program.translations', 'student:id,name,email,phone,image'])
            ->whereIn('program_id', $programs->pluck('id')->toArray())
            ->whereHas('student', function ($query) {
                $query->where('type', 'trainee'); // فلترة الطلاب المتدربين
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($subscription) {
            $subscription->student->setAttribute('programs_count', $subscription->student->subscriptions->count() ?? 0);
            $subscription->student->makeHidden('subscriptions');

            $subscription->student->setAttribute('reviews_count', $subscription->student->reviews->count());
            $subscription->student->makeHidden('reviews');

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

            $exams = Exam::where('program_id', $subscription->program_id)
            ->where('user_type', 'trainee') // exams for trainee
            ->pluck('id')->toArray();

            // متوسط نسبه النجاح
            $examResults = ExamStudent::where('student_id', $subscription->student_id)
            ->whereIn('exam_id', $exams)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('exam_id') // نجمع حسب كل امتحان
            ->map(function ($attempts) {
                // نأخذ آخر حل لكل امتحان
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

        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
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
        $sheet->setCellValue('G1', __('trans.subscription.created_at'));

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

            $sheet->setCellValue('A'.$key, $subscription->student->name ?? '-');
            $sheet->setCellValue('B'.$key, $subscription->student->email ?? '-');
            $sheet->setCellValue('C'.$key, $subscription->program->translations->firstWhere('locale', app()->getLocale())->title ?? '-');
            $sheet->setCellValue('D'.$key, $subscription->program->type == 'registered' ? __('trans.subscription.registered') : __('trans.subscription.live'));
            $sheet->setCellValue('E'.$key, $assignments_status ?? '-');
            $sheet->setCellValue('F'.$key, $exam_status ?? '-');
            $sheet->setCellValue('G'.$key, Carbon::parse($subscription->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.subscription.students_management')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }
}
