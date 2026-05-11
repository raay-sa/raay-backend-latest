<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\AssignmentSolution;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Exam;
use App\Models\ExamStudent;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentNotificationSetting;
use App\Models\Teacher;
use App\Models\TeacherNotificationSetting;
use App\Models\Transaction;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class AccountManagementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $users_type = $request->users_type;
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;
        $category_id = $request->category_id;

        $profit_percentage = Setting::value('profit_percentage');

        $calcChange = fn($current, $previous) => $previous > 0
            ? round((($current - $previous) / $previous) * 100, 2)
            : 100;

        $students = Student::where('type', 'student')
            ->with(['subscriptions.program'])
            ->withCount('subscriptions')
            ->withSum('subscriptions', 'price')
            ->get()
            ->map(function ($student) {
                $student->makeHidden(['password']);
                $student->subscriptions_sum_price = $student->subscriptions_sum_price ?? 0;

                $totalProgress = 0;
                $programCount  = $student->subscriptions->count();

                foreach ($student->subscriptions as $subscription) {
                    // $programId = $subscription->program_id;
                    $program = $subscription->program;
                    $programId = $program->id;

                    // --- المهام ---
                    $assignments = Assignment::where('program_id', $programId)->get();
                    $assignments_count = $assignments->count();
                    $assignments_solution_count = AssignmentSolution::whereIn('assignment_id', $assignments->pluck('id'))
                        ->where('student_id', $student->id)
                        ->count();

                    $assignmentProgress = $assignments_count > 0
                        ? round(($assignments_solution_count / $assignments_count) * 100, 2)
                        : 0;

                    // --- الامتحانات ---
                    $exams = Exam::where('program_id', $programId)
                        // ->where('user_type', 'trainee')
                        ->pluck('id')
                        ->toArray();

                    $examResults = ExamStudent::where('student_id', $student->id)
                        ->whereIn('exam_id', $exams)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->groupBy('exam_id')
                        ->map(function ($attempts) {
                            return $attempts->first(); // آخر محاولة
                        });

                    $examProgress = $examResults->avg('grade') ?? 0;

                    $sessions = $program->sessions;
                    $sessions_count = $sessions->count();
                    $watchedSessions = $student->session_views()
                        ->whereIn('session_id', $sessions->pluck('id'))
                        ->pluck('session_id')
                        ->unique()
                        ->count();

                    $sessionProgress = $sessions_count > 0
                        ? round(($watchedSessions / $sessions_count) * 100, 2)
                        : 0;

                    // --- نسبة إنجاز الطالب في البرنامج ---
                    $finalProgress = round(($assignmentProgress + $examProgress + $sessionProgress) / 3, 2);
                    
                    $totalProgress += $finalProgress;

                    // --- نسبة إنجاز الطالب في البرنامج ---
                    // $finalProgress = $assignments_count > 0 && count($exams) > 0
                    //     ? round(($assignmentProgress + $examProgress) / 2, 2)
                    //     : ($assignments_count > 0 ? $assignmentProgress : $examProgress);

                    // $totalProgress += $finalProgress;
                }

                // --- نسبة الإنجاز النهائية لكل برامج الطالب ---
                $student->progress = $programCount > 0
                    ? round($totalProgress / $programCount, 2)
                    : 0;

                return $student;
            });


        $total_students = $students->count();
        $current_students = $students->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $previous_students = $students->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        $percentage_change = $calcChange($current_students, $previous_students);

        if($users_type == 'students')
        {
            if ($filter === 'latest') {
                $students = $students->sortByDesc('created_at')->values();
            } elseif ($filter === 'oldest') {
                $students = $students->sortBy('created_at')->values();
            } elseif ($filter === 'name') {
                $students = $students->sortBy(fn($student) => mb_strtolower($student->name))->values();
            } elseif ($filter === 'active_status') {
                $students = $students->where(fn($student) => $student->status === 'active')->values();
            } elseif ($filter === 'inactive_status') {
                $students = $students->where(fn($student) => $student->status === 'inactive')->values();
            }

            if ($search) {
                $students = $students->filter(function ($student) use ($search) {
                    return mb_stripos($student->name, $search) !== false;
                })->values();
            }

            if ($price_from !== null || $price_to !== null) {
                $students = $students->filter(function ($student) use ($price_from, $price_to) {
                    $price = $student->subscriptions_sum_price;

                    if ($price_from !== null && $price < $price_from) {
                        return false;
                    }

                    if ($price_to !== null && $price > $price_to) {
                        return false;
                    }

                    return true;
                })->values();
            }
        }


        // =========================================================
        $trainees = Student::where('type', 'trainee')
            ->withCount('subscriptions')
            ->withSum('subscriptions', 'price')
            ->get()
            ->map(function ($trainee) {
                $trainee->makeHidden(['password']);
                $trainee->subscriptions_sum_price = $trainee->subscriptions_sum_price ?? 0;
                return $trainee;
            });

        $total_trainees = $trainees->count();
        $current_trainees = $trainees->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $previous_trainees = $trainees->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        $trainee_percentage_change = $calcChange($current_trainees, $previous_trainees);

        if($users_type == 'trainees')
        {
            if ($filter === 'latest') {
                $trainees = $trainees->sortByDesc('created_at')->values();
            } elseif ($filter === 'oldest') {
                $trainees = $trainees->sortBy('created_at')->values();
            } elseif ($filter === 'name') {
                $trainees = $trainees->sortBy(fn($trainee) => mb_strtolower($trainee->name))->values();
            } elseif ($filter === 'active_status') {
                $trainees = $trainees->where(fn($trainee) => $trainee->status === 'active')->values();
            } elseif ($filter === 'inactive_status') {
                $trainees = $trainees->where(fn($trainee) => $trainee->status === 'inactive')->values();
            }

            if ($search) {
                $trainees = $trainees->filter(function ($trainee) use ($search) {
                    return mb_stripos($trainee->name, $search) !== false;
                })->values();
            }

            if ($price_from !== null || $price_to !== null) {
                $trainees = $trainees->filter(function ($trainee) use ($price_from, $price_to) {
                    $price = $trainee->subscriptions_sum_price;

                    if ($price_from !== null && $price < $price_from) {
                        return false;
                    }

                    if ($price_to !== null && $price > $price_to) {
                        return false;
                    }

                    return true;
                })->values();
            }
        }

        // =========================================================
        $admin_profit = $profit_percentage;

        $teachers = getDataFromCache('teachers', function () use ($admin_profit) {
            return Teacher::where('type', 'teacher')
                ->with(['categories.translations'])
                ->withCount('programs')
                ->withSum('programs', 'price')
                ->get();
        })
        // Always post-process (even when coming from cache)
        ->map(function ($teacher) use ($admin_profit) {
            $teacher->makeHidden(['password']);
            $teacher->programs_sum_price = $teacher->programs_sum_price ?? 0;
            $teacher->admin_profit_amount = round(($teacher->programs_sum_price * $admin_profit) / 100, 2);

            // Ensure categories exists
            if ($teacher->relationLoaded('categories')) {
                $teacher->categories->transform(function ($category) {
                    // Access translations (may lazy load if not cached)
                    $localized = optional($category->translations->firstWhere('locale', app()->getLocale()))->title;
                    $category->title = $localized ?? (optional($category->translations->first())->title);
                    return $category->makeHidden(['pivot', 'translations']);
                });
            }

            return $teacher;
        });

        $total_teachers = $teachers->count();
        $current_teachers = $teachers->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $previous_teachers = $teachers->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        // نسبة الزيادة أو النقصان
        $teacher_percentage_change = $calcChange($current_teachers, $previous_teachers);

        if($users_type == 'teachers')
        {
            if ($filter === 'latest') {
                $teachers = $teachers->sortByDesc('created_at')->values();
            } elseif ($filter === 'oldest') {
                $teachers = $teachers->sortBy('created_at')->values();
            } elseif ($filter === 'name') {
                $teachers = $teachers->sortBy(fn($teacher) => mb_strtolower($teacher->name))->values();
            } elseif ($filter === 'active_status') {
                $teachers = $teachers->where(fn($teacher) => $teacher->status === 'active')->values();
            } elseif ($filter === 'inactive_status') {
                $teachers = $teachers->where(fn($teacher) => $teacher->status === 'inactive')->values();
            }

            if ($search) {
                $teachers = $teachers->filter(function ($teacher) use ($search) {
                    return mb_stripos($teacher->name, $search) !== false;
                })->values();
            }

            if (($price_from !== null && $price_from !== '') || ($price_to !== null && $price_to !== '')) {
                $teachers = $teachers->filter(function ($teacher) use ($price_from, $price_to) {
                    $price = $teacher->programs_sum_price ?? 0;

                    if ($price_from !== null && $price_from !== '' && $price < (float) $price_from) {
                        return false;
                    }

                    if ($price_to !== null && $price_to !== '' && $price > (float) $price_to) {
                        return false;
                    }

                    return true;
                })->values();
            }
        }

        // =========================================================
        $transactions = getDataFromCache('transactions', function () {
            return Transaction::with(['student:id,name', 'programs:id', 'programs.translations'])
                ->select(['id', 'student_id', 'status', 'total_price', 'created_at'])
                ->get()
                ->map(function ($transaction) {
                    $transaction->programs->transform(function ($program) {
                        return $program->makeHidden(['pivot', 'teacher', 'category']);
                    });

                    return $transaction;
                });
        });

        $total_profit = Subscription::sum('price') * ($profit_percentage / 100);
        $current_profit = Subscription::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('price') * ($profit_percentage / 100);
        $previous_profit = Subscription::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('price') * ($profit_percentage / 100);
        $profit_percentage_change = $calcChange($current_profit, $previous_profit);

        if($users_type == 'transactions')
        {

            if ($filter === 'latest') {
                $transactions = $transactions->sortByDesc('created_at')->values();
            } elseif ($filter === 'oldest') {
                $transactions = $transactions->sortBy('created_at')->values();
            } elseif ($filter === 'name') {
                $transactions = $transactions->sortBy(fn($transaction) => mb_strtolower($transaction->name))->values();
            } elseif ($filter === 'successful') {
                $transactions = $transactions->where(fn($trans) => $trans->status === 'completed')->values();
            } elseif ($filter === 'failed') {
                $transactions = $transactions->where(fn($trans) => $trans->status === 'failed')->values();
            } elseif ($filter === 'pending') {
                $transactions = $transactions->where(fn($trans) => $trans->status === 'pending')->values();
            } elseif ($filter === 'cancelled') {
                $transactions = $transactions->where(fn($trans) => $trans->status === 'cancelled')->values();
            }

            if ($search) {
                $transactions = $transactions->filter(function ($transaction) use ($search) {
                    if (!$transaction->student) return false;

                    $studentName = mb_strtolower(trim($transaction->student->name));
                    $searchTerm = mb_strtolower(trim($search));

                    return str_contains($studentName, $searchTerm);
                })->values();
            }

            if ($price_from !== null || $price_to !== null) {
                $transactions = $transactions->filter(function ($transaction) use ($price_from, $price_to) {
                    $price = $transaction->total_price;

                    if ($price_from !== null && $price < $price_from) {
                        return false;
                    }

                    if ($price_to !== null && $price > $price_to) {
                        return false;
                    }

                    return true;
                })->values();
            }
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated_students = paginationData($students, $perPage, $currentPage);
        $paginated_trainees = paginationData($trainees, $perPage, $currentPage);
        $paginated_teachers = paginationData($teachers, $perPage, $currentPage);
        $paginated_transactions = paginationData($transactions, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_students' => $total_students,
            'student_percentage' => abs($percentage_change),
            'student_status' => $percentage_change >= 0 ? 'increase' : 'decrease',

            'total_trainees' => $total_trainees,
            'trainee_percentage' => abs($trainee_percentage_change),
            'trainee_status' => $trainee_percentage_change >= 0 ? 'increase' : 'decrease',

            'total_teachers' => $total_teachers,
            'teacher_percentage' => abs($teacher_percentage_change),
            'teacher_status' => $teacher_percentage_change >= 0 ? 'increase' : 'decrease',

            'total_profit' => $total_profit,
            'profit_percentage' => abs($profit_percentage_change),
            'profit_status' => $profit_percentage_change >= 0 ? 'increase' : 'decrease',

            'students' => $paginated_students,
            'trainees' => $paginated_trainees,
            'teachers' => $paginated_teachers,
            'transactions' => $paginated_transactions
        ]);
    }

    public function excel_sheet_students(Request $request)
    {
        // shape
        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D'];
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
        $sheet->setCellValue('A1', __('trans.excel_sheet.student'));
        $sheet->setCellValue('B1', __('trans.excel_sheet.phone'));
        $sheet->setCellValue('C1', __('trans.excel_sheet.email'));
        $sheet->setCellValue('D1', __('trans.excel_sheet.password'));

        $sheet->setCellValue('A2', __('trans.excel_sheet.student_name'));
        $sheet->setCellValue('B2', '501234567');
        $sheet->setCellValue('C2', 'example@example.com');
        $sheet->setCellValue('D2', '123456');

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = "students-excel-sheet.xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }

    public function create_student(Request $request)
    {
        // الحالة ١: رفع ملف Excel
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|file|mimes:xls,xlsx',
            ]);

            $importedStudents = parseExcel($request->file('file'));
            $duplicates = []; // الطلاب المكرر بياناتهم
            $created    = []; // الطلاب اللي اتسجلو بنجاح

            foreach ($importedStudents as $row)
            {
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
                        'name'  => $row['الطالب'],
                        'phone' => $phone,
                        'email' => $email,
                    ];
                    continue; // skip this student
                }

                $user = new Student();
                $user->name        = $row['الطالب'];
                $user->phone       = $phone;
                $user->email       = $email;
                $user->password    = Hash::make($request->password);                $user->type        = 'student';
                $user->status      = 'active';
                $user->is_approved = 1;
                $user->save();

                // store in cache
                storeInCache('students', $user);

                $noti_setting = new StudentNotificationSetting();
                $noti_setting->student_id = $user->id;
                $noti_setting->save();

                $created[] = $user; // الليست الناجحة
            }

            return response()->json([
                'success'       => true,
                'message'       => count($created) > 0 ? __('trans.alert.success.done_create') : null,
                'created_count' => count($created),
                'failed_count'  => count($duplicates),
                'reason'        => count($duplicates) > 0 ? __('trans.alert.error.email_or_phone_used_before') : null,
                'faliled_students_list' => $duplicates,
            ]);
        }

        try {
            $phone = $request->phone;

            if (!str_starts_with($phone, '+966')) {
                $phone = '+966' . ltrim($phone, '0');
            }
            $request->merge(['phone' => $phone]);

            $rules = [
                'name'        => 'required|string|max:255',
                'phone'       => ['required', 'string',
                    'regex:/^\+9665\d{8}$/',
                    'unique:students,phone',
                    'unique:teachers,phone',
                    'unique:admins,phone',
                ],
                'email'       => 'required|email|unique:students,email|unique:teachers,email|unique:admins,email',
                'password'    => 'required|string|min:6',
                'status'      => 'required|in:active,inactive'
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = new Student();
            $user->name        = $request->name;
            $user->phone       = $phone;
            $user->email       = $request->email;
            $user->type        = 'student';
            // $user->password    = Hash::make('12345678'); (was creating password to 12345678 for every trainee)
            $user->password    = Hash::make($request->password);
            $user->status      = $request->status;
            $user->is_approved = 1;
            $user->save();

            // store in cache
            storeInCache('students', $user);

            $noti_setting = new StudentNotificationSetting();
            $noti_setting->student_id = $user->id;
            $noti_setting->save();

            return response()->json([
                'message' => __('trans.alert.success.register'),
                'user' => $user,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show_student(Request $request, $id)
    {
        $row = Student::with('subscriptions.program:id', 'subscriptions.program.translations')
        ->withCount('subscriptions')
        ->withSum('subscriptions', 'price')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'row' => $row
        ]);
    }

    public function update_student(Request $request, $id)
    {
        $row = Student::find($id);
        if(!$row){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins,email|unique:teachers,email|unique:students,email,' . $id,
            'phone' => ['required', 'string',
                'regex:/^\+9665\d{8}$/',
                'unique:admins,phone',
                'unique:teachers,phone',
                'unique:students,phone,' . $id,
            ],
            'password'    => 'nullable|string|min:6',
            'status'      => 'required|in:active,inactive'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row->name        = $request->name;
        $row->email       = $request->email;
        $row->phone       = $request->phone;
        $row->status      = $request->status;
        if($request->password){
            $row->password = Hash::make($request->password);
        }
        $row->save();

        // update cache
        updateInCache('students', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row,
        ], 200);
    }

    public function delete_student(Request $request, $id)
    {
        $row = Student::find($id);
        if(!$row){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        deleteFromCache('students', $row);
        $row->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ], 200);
    }

    public function delete_multi_student(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'students_id' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allStudentIds = $request->input('students_id', []);
        Student::whereIn('id', $allStudentIds)->delete();
        Cache::forget('students');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }



    // ====================================================================

    public function excel_sheet_teachers(Request $request)
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
        $sheet->setCellValue('A1', __('trans.excel_sheet.teacher'));
        $sheet->setCellValue('B1', __('trans.excel_sheet.phone'));
        $sheet->setCellValue('C1', __('trans.excel_sheet.email'));
        $sheet->setCellValue('D1', __('trans.excel_sheet.password'));
        $sheet->setCellValue('E1', __('trans.excel_sheet.category'));

        $sheet->setCellValue('A2', __('trans.excel_sheet.teacher_name'));
        $sheet->setCellValue('B2', '501234567');
        $sheet->setCellValue('C2', 'example@example.com');
        $sheet->setCellValue('D2', '123456');
        $sheet->setCellValue('E2', 'التحول الرقمي, الأمن السيبراني');

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = "teachers-excel-sheet.xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }

    public function create_teacher(Request $request)
    {
        // الحالة ١: رفع ملف Excel
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|file|mimes:xls,xlsx',
            ]);

            $importedTeachers = parseExcel($request->file('file'));
            $duplicates = []; // الطلاب المكرر بياناتهم
            $created    = []; // الطلاب اللي اتسجلو بنجاح

            foreach ($importedTeachers as $row) {

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
                        'name'  => $row['الخبير'],
                        'phone' => $phone,
                        'email' => $email,
                    ];
                    continue; // skip this teacher
                }

                $user = new Teacher();
                $user->name        = $row['الخبير'];
                $user->phone       = $phone;
                $user->email       = $email;
                $user->password    = Hash::make($request->password);                $user->type        = 'teacher';
                $user->status      = 'active';
                $user->is_approved = 1;
                $user->save();

                if (!empty($row['التخصص'])) {
                    $specialties = explode(',', $row['التخصص']);
                    $specialties = array_map('trim', $specialties);

                    // $categoryIds = Category::whereIn('title', $specialties)->pluck('id')->toArray();
                    $categoryIds = CategoryTranslation::whereIn('title', $specialties)
                    ->pluck('parent_id')
                    ->unique()
                    ->toArray();
                    $user->categories()->sync($categoryIds);
                }

                // store in cache
                storeInCache('teachers', $user, ['categories', 'categories.translations']);

                $noti_setting = new TeacherNotificationSetting();
                $noti_setting->teacher_id = $user->id;
                $noti_setting->save();

                $created[] = $user; // الليست الناجحة
            }

            return response()->json([
                'success' => true,
                'message' => count($created) > 0 ? __('trans.alert.success.done_create') : null,
                'created_count'    => count($created),   // عدد اللي اتسجلو
                'failed_count' => count($duplicates),    // ليست باللي مااتسجلوش
                'reason' => count($duplicates) > 0 ? __('trans.alert.error.email_or_phone_used_before') : null,
                'faliled_teachers_list' => $duplicates,
            ]);
        }

        try {
            $phone = $request->phone;

            if (!str_starts_with($phone, '+966')) {
                $phone = '+966' . ltrim($phone, '0');
            }
            $request->merge(['phone' => $phone]);

            $rules = [
                'name'        => 'required|string|max:255',
                'phone'       => ['required', 'string',
                    'regex:/^\+9665\d{8}$/',
                    'unique:students,phone',
                    'unique:teachers,phone',
                    'unique:admins,phone',
                ],
                'email'       => 'required|email|unique:students,email|unique:teachers,email|unique:admins,email',
                'password'    => 'required|string|min:6',
                'status'      => 'required|in:active,inactive',
                'categories.*'=> 'exists:categories,id',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors(),
                ], 422);
            }


            $user = new Teacher();
            $user->name        = $request->name;
            $user->phone       = $phone;
            $user->email       = $request->email;
            $user->password    = Hash::make($request->password);
            $user->status      = $request->status;
            $user->is_approved = 1;
            $user->save();

            $user->categories()->sync($request->categories); // `sync` replaces old ones

            // store in cache
            storeInCache('teachers', $user, ['categories', 'categories.translations']);

            $noti_setting = new TeacherNotificationSetting();
            $noti_setting->teacher_id = $user->id;
            $noti_setting->save();

            return response()->json([
                'message' => __('trans.alert.success.register'),
                'user' => $user,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show_teacher(Request $request, $id)
    {
        $row = Teacher::with(['categories.translations'])
        ->withCount('programs')
        ->withSum('programs', 'price')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $row->makeHidden('password');

        return response()->json([
            'success' => true,
            'row' => $row
        ]);
    }

    public function update_teacher(Request $request, string $id)
    {
        $row = Teacher::find($id);
        if(!$row){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:admins,email|unique:students,email|unique:teachers,email,' . $id,
            'categories'  => 'nullable|array',
            'categories.*'=> 'exists:categories,id',
            'phone'       => ['required', 'string',
                'regex:/^\+9665\d{8}$/',
                'unique:admins,phone',
                'unique:students,phone',
                'unique:teachers,phone,' . $id,
            ],
            'password'    => 'nullable|string|min:6',
            'status'      => 'required|in:active,inactive'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row->name   = $request->name;
        $row->email  = $request->email;
        $row->phone  = $request->phone;
        $row->status = $request->status;
        if ($request->password) {
            $row->password = Hash::make($request->password);
        }
        $row->save();

        $row->categories()->sync($request->categories);
        $row->load('categories.translations');

        updateInCache('teachers', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row,
        ], 200);
    }

    public function delete_teacher(Request $request, $id)
    {
        $row = Teacher::find($id);
        if(!$row){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        deleteFromCache('teachers', $row);
        $row->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ], 200);
    }

    public function delete_multi_teacher(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'teachers_id' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allTeacherIds = $request->input('teachers_id', []);
        Teacher::whereIn('id', $allTeacherIds)->delete();
        Cache::forget('teachers');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }




    // ====================================================================

    public function student_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;

        $students = getDataFromCache('students', function () {
            return Student::withCount('subscriptions')
                ->withSum('subscriptions', 'price')
                ->get();
        })->map(function ($student) {
            $student->makeHidden(['password']);
            $student->subscriptions_sum_price = $student->subscriptions_sum_price ?? 0;
            return $student;
        });

        if ($filter === 'latest') {
            $students = $students->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $students = $students->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $students = $students->sortBy(fn($student) => mb_strtolower($student->name))->values();
        } elseif ($filter === 'active_status') {
            $students = $students->where(fn($student) => $student->status === 'active')->values();
        } elseif ($filter === 'inactive_status') {
            $students = $students->where(fn($student) => $student->status === 'inactive')->values();
        }

        if ($search) {
            $students = $students->filter(function ($student) use ($search) {
                return mb_stripos($student->name, $search) !== false;
            })->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $students = $students->filter(function ($student) use ($price_from, $price_to) {
                $price = $student->subscriptions_sum_price;
                return ($price_from === null || $price >= $price_from) &&
                       ($price_to === null || $price <= $price_to);
            })->values();
        }

        $currentDate = Carbon::now();
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
        $sheet->setCellValue('A1', __('trans.student.name'));
        $sheet->setCellValue('B1', __('trans.student.phone'));
        $sheet->setCellValue('C1', __('trans.student.email'));
        $sheet->setCellValue('D1', __('trans.student.num_programs'));
        $sheet->setCellValue('E1', __('trans.student.value'));
        $sheet->setCellValue('F1', __('trans.student.status'));
        $sheet->setCellValue('G1', __('trans.student.created_at'));

        foreach ($students as $key => $student):
            $key = $key+2;

            $sheet->setCellValue('A'.$key, $student->name ?? '-');
            $sheet->setCellValueExplicit('B'.$key, (string) $student->phone, DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$key, $student->email ?? '-');
            $sheet->setCellValue('D'.$key, $student->subscriptions_count ?? '-');
            $sheet->setCellValue('E'.$key, $student->subscriptions_sum_price ?? '-');
            $sheet->setCellValue('F'.$key, $student->status == 'active' ? __('trans.student.active') : __('trans.student.inactive'));
            $sheet->setCellValue('G'.$key, Carbon::parse($student->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.student.title')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }


    // ================================================
    public function teacher_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;
        $category_id = $request->category_id;

        $admin_profit = Setting::first()->profit_percentage ?? 0;

        $teachers = getDataFromCache('teachers', function () use ($admin_profit) {
            return Teacher::where('type', 'teacher')
                ->with('categories:id','categories.translations')
                ->withCount('programs')
                ->withSum('programs', 'price')
                ->get()
                ->map(function ($teacher) use ($admin_profit) {
                    $teacher->makeHidden(['password']);
                    $teacher->programs_sum_price = $teacher->programs_sum_price ?? 0;

                    // حساب نسبة ربح الأدمن
                    $teacher->admin_profit_amount = round(($teacher->programs_sum_price * $admin_profit) / 100, 2);

                    $teacher->categories->transform(function ($category) {
                        return $category->makeHidden(['pivot']);
                    });

                    return $teacher;
                });
        });


        if ($filter === 'latest') {
            $teachers = $teachers->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $teachers = $teachers->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $teachers = $teachers->sortBy(fn($teacher) => mb_strtolower($teacher->name))->values();
        } elseif ($filter === 'active_status') {
            $teachers = $teachers->where(fn($teacher) => $teacher->status === 'active')->values();
        } elseif ($filter === 'inactive_status') {
            $teachers = $teachers->where(fn($teacher) => $teacher->status === 'inactive')->values();
        }

        if ($search) {
            $teachers = $teachers->filter(function ($teacher) use ($search) {
                return mb_stripos($teacher->name, $search) !== false;
            })->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $teachers = $teachers->filter(function ($teacher) use ($price_from, $price_to) {
                $price = $teacher->programs_sum_price;

                if ($price_from !== null && $price < $price_from) {
                    return false;
                }

                if ($price_to !== null && $price > $price_to) {
                    return false;
                }

                return true;
            })->values();
        }

        if ($category_id) {
            $teachers = $teachers->filter(function ($teacher) use ($category_id) {
                return in_array($category_id, $teacher->categories->pluck('id')->toArray());
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
        $sheet->setCellValue('A1', __('trans.teacher.name'));
        $sheet->setCellValue('B1', __('trans.teacher.phone'));
        $sheet->setCellValue('C1', __('trans.teacher.email'));
        $sheet->setCellValue('D1', __('trans.teacher.category'));
        $sheet->setCellValue('E1', __('trans.teacher.num_programs'));
        $sheet->setCellValue('F1', __('trans.teacher.value'));
        $sheet->setCellValue('G1', __('trans.teacher.profit'));
        $sheet->setCellValue('H1', __('trans.teacher.status'));
        $sheet->setCellValue('I1', __('trans.teacher.created_at'));

        foreach ($teachers as $key => $teacher):
            $key = $key+2;
            // $categories = $teacher->categories->pluck('title')->implode(', ');
            $categories = $teacher->categories->map(function ($category) {
                return optional($category->translations->firstWhere('locale', app()->getLocale()))->title;
            })->filter()->implode(', ');

            $sheet->setCellValue('A'.$key, $teacher->name ?? '-');
            $sheet->setCellValueExplicit('B'.$key, (string) $teacher->phone, DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$key, $teacher->email ?? '-');
            $sheet->setCellValue('D'.$key, $categories ?: '-');
            $sheet->setCellValue('E'.$key, $teacher->programs_count ?? '-');
            $sheet->setCellValue('F'.$key, $teacher->programs_sum_price ?? '-');
            $sheet->setCellValue('G'.$key, $teacher->admin_profit_amount ?? '-');
            $sheet->setCellValue('H'.$key, $teacher->status == 'active' ? __('trans.teacher.active') : __('trans.teacher.inactive'));
            $sheet->setCellValue('I'.$key, Carbon::parse($teacher->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.teacher.title')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }


    // ============================================================
    public function transaction_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;

        $transactions = getDataFromCache('transactions', function () {
            return Transaction::with(['student:id,name', 'programs:id', 'programs.translations'])
                ->select(['id', 'student_id', 'status', 'total_price', 'created_at'])
                ->get()
                ->map(function ($transaction) {
                    $transaction->programs->transform(function ($program) {
                        return $program->makeHidden(['pivot', 'teacher', 'category']);
                    });

                    return $transaction;
                });
        });


        if ($filter === 'latest') {
            $transactions = $transactions->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $transactions = $transactions->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $transactions = $transactions->sortBy(fn($transaction) => mb_strtolower($transaction->name))->values();
        } elseif ($filter === 'successful') {
            $transactions = $transactions->where(fn($trans) => $trans->status === 'completed')->values();
        } elseif ($filter === 'failed') {
            $transactions = $transactions->where(fn($trans) => $trans->status === 'failed')->values();
        }

        if ($search) {
            $transactions = $transactions->filter(function ($transaction) use ($search) {
                if (!$transaction->student) return false;

                $studentName = mb_strtolower(trim($transaction->student->name));
                $searchTerm = mb_strtolower(trim($search));

                return str_contains($studentName, $searchTerm);
            })->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $transactions = $transactions->filter(function ($transaction) use ($price_from, $price_to) {
                $price = $transaction->total_price;

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
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
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
        $sheet->setCellValue('A1', __('trans.transaction.number'));
        $sheet->setCellValue('B1', __('trans.transaction.student_name'));
        $sheet->setCellValue('C1', __('trans.transaction.details'));
        $sheet->setCellValue('D1', __('trans.transaction.value'));
        $sheet->setCellValue('E1', __('trans.transaction.status'));
        $sheet->setCellValue('F1', __('trans.transaction.created_at'));

        foreach ($transactions as $key => $transaction):
            $key = $key+2;
            // $programs = $transaction->programs->pluck('title')->implode(', ');
            $programs = $transaction->programs->map(function ($program) {
                return optional($program->translations->firstWhere('locale', app()->getLocale()))->title;
            })->filter()->implode(', ');

            if($transaction->status == 'completed') {
                $status_msg = __('trans.transaction.completed');
            } elseif($transaction->status == 'failed') {
                $status_msg = __('trans.transaction.failed');
            } elseif($transaction->status == 'pending') {
                $status_msg = __('trans.transaction.pending');
            } elseif($transaction->status == 'cancelled') {
                $status_msg = __('trans.transaction.cancelled');
            }

            $sheet->setCellValue('A'.$key, __('trans.transaction.name').'-'.$transaction->id ?? '-');
            $sheet->setCellValue('B'.$key, $transaction->student->name ?? '-');
            $sheet->setCellValue('C'.$key, $programs ?: '-');
            $sheet->setCellValue('D'.$key, $transaction->total_price ?? '-');
            $sheet->setCellValue('E'.$key, $status_msg ?? '-');
            $sheet->setCellValue('F'.$key, Carbon::parse($transaction->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.transaction.title')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }


    // private function parseExcel($file): array
    // {
    //     $students = [];

    //     $spreadsheet = IOFactory::load($file);
    //     $sheet = $spreadsheet->getActiveSheet();
    //     $rows = $sheet->toArray();

    //     // الهيدر (الأعمدة)
    //     $header = array_map('strtolower', $rows[0]);

    //     $studentNameIndex = array_search('student_name', $header);
    //     $phoneIndex       = array_search('phone', $header);
    //     $emailIndex       = array_search('email', $header);
    //     $passwordIndex    = array_search('password', $header);

    //     for ($i = 1; $i < count($rows); $i++) {
    //         $student_name = $studentNameIndex !== false ? trim($rows[$i][$studentNameIndex]) : null;
    //         $phone        = $phoneIndex       !== false ? trim($rows[$i][$phoneIndex])       : null;
    //         $email        = $emailIndex       !== false ? trim($rows[$i][$emailIndex])       : null;
    //         $password     = $passwordIndex    !== false ? trim($rows[$i][$passwordIndex])    : null;

    //         if (!empty($phone) || !empty($student_name)) {
    //             $students[] = [
    //                 'student_name' => $student_name,
    //                 'phone'        => $phone,
    //                 'email'        => $email,
    //                 'password'     => $password,
    //             ];
    //         }
    //     }

    //     return $students;
    // }

}

