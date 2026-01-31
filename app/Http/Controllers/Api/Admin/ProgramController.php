<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSolution;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Exam;
use App\Models\ExamStudent;
use App\Models\Program;
use App\Models\ProgramSection;
use App\Models\ProgramSectionTranslation;
use App\Models\ProgramTranslation;
use App\Models\Student;
use App\Models\StudentWarning;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\Notification;
use App\Models\TeacherNotification;
use App\Events\GeneralNotificationEvent;
use App\Notifications\ProgramApprovalNotification;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ProgramController extends Controller
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
        $score = $request->score;

        // programs
        $totalPrograms = Program::withTrashed()->where('is_approved', 1)->count();
        $current_programs = Program::withTrashed()->where('is_approved', 1)->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_programs = Program::withTrashed()->where('is_approved', 1)->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_programs > 0) {
            $program_percentage_change = round((($current_programs - $previous_programs) / $previous_programs) * 100, 2);
        } else {
            $program_percentage_change = 100;
        }

        // registered
        $registered_programs_count = Program::where('type', 'registered')->where('is_approved', 1)->count();
        $current_registered_programs = Program::where('type', 'registered')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_registered_programs = Program::where('type', 'registered')
            ->where('is_approved', 1)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_registered_programs > 0) {
            $program_registered_percentage_change = round((($current_registered_programs - $previous_registered_programs) / $previous_registered_programs) * 100, 2);
        } else {
            $program_registered_percentage_change = 100;
        }

        //live
        $live_programs_count = Program::where('type', 'live')->where('is_approved', 1)->count();
        $current_live_programs = Program::where('type', 'live')
            ->where('is_approved', 1)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_live_programs = Program::where('type', 'live')
            ->where('is_approved', 1)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_live_programs > 0) {
            $program_live_percentage_change = round((($current_live_programs - $previous_live_programs) / $previous_live_programs) * 100, 2);
        } else {
            $program_live_percentage_change = 100;
        }

        //deleted
        $deletedPrograms = Program::onlyTrashed()->where('is_approved', 1)->count();// عدد البرامج المحذوفة فقط (Soft Deleted)
        $current_deleted_programs = Program::onlyTrashed()->where('is_approved', 1)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_deleted_programs = Program::onlyTrashed()->where('is_approved', 1)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_deleted_programs > 0) {
            $program_deleted_percentage_change = round((($current_deleted_programs - $previous_deleted_programs) / $previous_deleted_programs) * 100, 2);
        } else {
            $program_deleted_percentage_change = 100; // لو مفيش بيانات سابقة، اعتبرها زيادة كاملة
        }

        $programs = program::with(['category:id', 'category.translations', 'teacher:id,name', 'reviews:id,score,program_id', 'translations'])
        ->where('is_approved', 1)
        ->select(['id', 'transaction_number', 'image', 'price', 'type', 'is_live', 'status', 'is_approved', 'notes', 'created_at', 'teacher_id', 'category_id', 'deleted_at'])
        ->withCount('subscriptions')
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($program) {
            $program->average_score = round($program->reviews->avg('score'), 2) ?? 0;
            return $program;
        });

        if ($filter === 'latest') {
            $programs = $programs->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $programs = $programs->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $programs = $programs->sortBy(fn($program) => mb_strtolower($program->title))->values();
        } elseif ($filter === 'active_status') {
            $programs = $programs->where(fn($program) => $program->deleted_at == null && $program->status > 1)->values();
        } elseif ($filter === 'deleted_status') {
            $programs = $programs->where(fn($program) => $program->deleted_at != null)->values();
        } elseif ($filter === 'live_type') {
            $programs = $programs->where('type', 'live')->values();
        } elseif ($filter === 'registered_type') {
            $programs = $programs->where('type', 'registered')->values();
        }

        if ($search) {
            $programs = $programs->filter(function ($program) use ($search) {
                // return mb_stripos($program->title, $search) !== false;
                return $program->translations->contains(function ($translation) use ($search) {
                    return mb_stripos($translation->title, $search) !== false;
                });
            })->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $programs = $programs->filter(function ($program) use ($price_from, $price_to) {
                $price = $program->price;

                if ($price_from !== null && $price < $price_from) {
                    return false;
                }

                if ($price_to !== null && $price > $price_to) {
                    return false;
                }

                return true;
            })->values();
        }

        if ($score !== null) {
            $programs = $programs->where(fn($program) => $program->average_score == $score)->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($programs, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_count' => $totalPrograms,
            'total_program_percentage' => abs($program_percentage_change),
            'total_program_status' => $program_percentage_change >= 0 ? 'increase' : 'decrease',

            'live_programs_count' => $live_programs_count,
            'total_live_program_percentage' => abs($program_live_percentage_change),
            'total_live_program_status' => $program_live_percentage_change >= 0 ? 'increase' : 'decrease',

            'registered_programs_count' => $registered_programs_count,
            'total_registered_program_percentage' => abs($program_registered_percentage_change),
            'total_registered_program_status' => $program_registered_percentage_change >= 0 ? 'increase' : 'decrease',

            'deleted_programs_count' => $deletedPrograms,
            'total_deleted_program_percentage' => abs($program_deleted_percentage_change),
            'total_deleted_program_status' => $program_deleted_percentage_change >= 0 ? 'increase' : 'decrease',

            'data' => $paginated
        ]);
    }

    public function list(Request $request)
    {
        $programs = Program::with('translations')
        ->where('is_approved', 1)
        ->where('status', 1)
        ->select('id', 'slug')->get();

        return response()->json([
            'success' => true,
            'data' => $programs
        ]);
    }

    public function teachers_list(Request $request)
    {
        $teachers = Teacher::select('id', 'name')->get();

        return response()->json([
            'success' => true,
            'data' => $teachers
        ]);
    }

    public function teacher_categories($teacher_id)
    {
        $teacher = Teacher::with('categories:id', 'categories.translations')
            ->findOr($teacher_id, function () {
                abort(response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.data_not_exist'),
                ], 422));
            });

        $categories = $teacher->categories;

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function students_list(Request $request)
    {
        $students = Student::select(['id', 'name'])->get();

        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    public function sections($id)
    {
        $program = Program::with(['translations', 'sections' => function ($query) {
            $query->select('id', 'program_id')->with('translations');
        }])->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data' => $program->sections
        ]);
    }

    public function excel_sheet_programs(Request $request)
    {
        // shape
        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T'];
        foreach ($columns as $columnKey => $column):
            $Width = ($columnKey==0||$columnKey==7||$columnKey==8||$columnKey==9||$columnKey==12)? 25 : 15;
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
        $sheet->setCellValue('A1', __('trans.excel_sheet.program').' ('.__('trans.excel_sheet.arabic').')');
        $sheet->setCellValue('B1', __('trans.excel_sheet.program').' ('.__('trans.excel_sheet.english').')');
        $sheet->setCellValue('C1', __('trans.excel_sheet.type'));
        $sheet->setCellValue('D1', __('trans.excel_sheet.level'));
        $sheet->setCellValue('E1', __('trans.excel_sheet.price'));
        $sheet->setCellValue('F1', __('trans.excel_sheet.duration_days'));
        $sheet->setCellValue('G1', __('trans.excel_sheet.date_from'));
        $sheet->setCellValue('H1', __('trans.excel_sheet.date_to'));
        $sheet->setCellValue('I1', __('trans.excel_sheet.time'));
        $sheet->setCellValue('J1', __('trans.excel_sheet.description').' ('.__('trans.excel_sheet.arabic').')');
        $sheet->setCellValue('K1', __('trans.excel_sheet.description').' ('.__('trans.excel_sheet.english').')');
        $sheet->setCellValue('L1', __('trans.excel_sheet.learning').' ('.__('trans.excel_sheet.arabic').')');
        $sheet->setCellValue('M1', __('trans.excel_sheet.learning').' ('.__('trans.excel_sheet.english').')');
        $sheet->setCellValue('N1', __('trans.excel_sheet.requirement').' ('.__('trans.excel_sheet.arabic').')');
        $sheet->setCellValue('O1', __('trans.excel_sheet.requirement').' ('.__('trans.excel_sheet.english').')');
        $sheet->setCellValue('P1', __('trans.excel_sheet.have_certificate'));
        $sheet->setCellValue('Q1', __('trans.excel_sheet.status'));
        $sheet->setCellValue('R1', __('trans.excel_sheet.category'));
        $sheet->setCellValue('S1', __('trans.excel_sheet.category'));
        $sheet->setCellValue('T1', __('trans.excel_sheet.teacher'));

        $sheet->setCellValue('A2', 'اسم البرنامج');
        $sheet->setCellValue('B2', 'program name');
        $sheet->setCellValue('C2', 'مسجل');
        $sheet->setCellValue('D2', 'مبتدئ');
        $sheet->setCellValue('E2', 200);
        $sheet->setCellValue('F2', 200);
        $sheet->setCellValue('G2', '2023-06-20');
        $sheet->setCellValue('H2', '2023-07-20');
        $sheet->setCellValue('I2', '');
        $sheet->setCellValue('J2', 'وصف البرنامج');
        $sheet->setCellValue('K2', 'description');
        $sheet->setCellValue('L2', 'مثال1, مثال2');
        $sheet->setCellValue('M2', 'example1, example2');
        $sheet->setCellValue('N2', 'مثال1, مثال2');
        $sheet->setCellValue('O2', 'example1, example2');
        $sheet->setCellValue('P2', 'نعم');
        $sheet->setCellValue('Q2', 'طالب');
        $sheet->setCellValue('R2', 'مفعل');
        $sheet->setCellValue('S2', 'اسم التخصص');
        $sheet->setCellValue('T2', 'اسم الخبير');

        $sheet->setCellValue('A3', 'اسم البرنامج');
        $sheet->setCellValue('B3', 'program name');
        $sheet->setCellValue('C3', 'لايف');
        $sheet->setCellValue('D3', 'متقدم');
        $sheet->setCellValue('E3', 200);
        $sheet->setCellValue('F3', 30);
        $sheet->setCellValue('G3', '2023-06-20');
        $sheet->setCellValue('H3', '2023-07-20');
        $sheet->setCellValue('I3', '5:00:00');
        $sheet->setCellValue('J3', 'وصف البرنامج');
        $sheet->setCellValue('K3', 'description');
        $sheet->setCellValue('L3', 'مثال1, مثال2');
        $sheet->setCellValue('M3', 'example1, example2');
        $sheet->setCellValue('N3', 'مثال1, مثال2');
        $sheet->setCellValue('O3', 'example1, example2');
        $sheet->setCellValue('P3', 'لا');
        $sheet->setCellValue('Q3', 'متدرب');
        $sheet->setCellValue('R3', 'غير مفعل');
        $sheet->setCellValue('S3', 'اسم التخصص');
        $sheet->setCellValue('T3', 'اسم الخبير');

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = "programs-excel-sheet.xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }

    public function store(Request $request)
    {
        // الحالة ١: رفع ملف Excel
        if ($request->hasFile('excel_file')) {
            $request->validate([
                'excel_file' => 'required|file|mimes:xls,xlsx',
            ]);

            $programsData = parseExcel($request->file('excel_file'));

            $created = [];
            $duplicates = [];

            foreach ($programsData as $index => $programData) {
                $rowNumber = $index + 2;

                try {
                    $teacher = Teacher::where('name', $programData[__('trans.excel_sheet.teacher')])->first();
                    if (!$teacher) {
                        $duplicates[] = [
                            'row_number' => $rowNumber,
                            'title'  => $programData['البرنامج'],
                            'reason' => 'المدرس غير موجود',
                        ];
                        continue; // skip
                    }

                    $categoryId = CategoryTranslation::where('title', $programData[__('trans.excel_sheet.category')])
                        ->value('parent_id');

                    if (!$categoryId) {
                        $duplicates[] = [
                            'row_number' => $rowNumber,
                            'title'  => $programData[__('trans.excel_sheet.program').' ('.__('trans.excel_sheet.arabic').')'],
                            'reason' => 'التخصص غير موجود',
                        ];
                        continue; // skip
                    }

                    $category = Category::find($categoryId);

                    if (!$teacher->categories()->where('category_id', $category->id)->exists()) {
                        $duplicates[] = [
                            'row_number' => $rowNumber,
                            'title'  => $programData[__('trans.excel_sheet.program').' ('.__('trans.excel_sheet.arabic').')'],
                            'reason' => 'المدرس لا ينتمي لهذا التخصص',
                        ];
                        continue; // skip
                    }

                    $title_ar = $programData[__('trans.excel_sheet.program').' ('.__('trans.excel_sheet.arabic').')'] ?? null;
                    $title_en = $programData[__('trans.excel_sheet.program').' ('.__('trans.excel_sheet.english').')'] ?? null;

                    if (!$title_ar || !$title_en) {
                        continue;
                    }

                    $isTitleDuplicate = ProgramTranslation::where('title', $title_ar)
                    ->orWhere('title', $title_en)
                    ->exists();

                    if ($isTitleDuplicate) {
                        $duplicates[] = [
                            'row_number' => $rowNumber,
                            'ar' => $title_ar,
                            'en' => $title_en,
                            'reason' => 'اسم البرنامج مكرر',
                        ];
                        continue;
                    }

                    $typeValue = $programData[__('trans.excel_sheet.type')];
                    $programType = match($typeValue) {
                        'مسجل' => 'registered',
                        'لايف' => 'live',
                        'حضوري' => 'onsite',
                        default => 'registered'
                    };
                    if ($programType === 'live' && empty($programData[__('trans.excel_sheet.time')])) {
                        $duplicates[] = [
                            'row_number' => $rowNumber,
                            'title'  => $programData[__('trans.excel_sheet.program').' ('.__('trans.excel_sheet.arabic').')'],
                            'reason' => 'الوقت مطلوب للبرامج اللايف',
                        ];
                        continue;
                    }

                    $program = new Program();
                    $program->slug             = Str::slug($programData[__('trans.excel_sheet.program').' ('.__('trans.excel_sheet.english').')']);
                    $program->price            = $programData[__('trans.excel_sheet.price')];
                    $program->type             = $programType;
                    $program->level            = $programData[__('trans.excel_sheet.level')];
                    $program->duration         = $programData[__('trans.excel_sheet.duration_days')];
                    $program->date_from        = $programData[__('trans.excel_sheet.date_from')];
                    $program->date_to          = $programData[__('trans.excel_sheet.date_to')];
                    $program->time             = $programData[__('trans.excel_sheet.time')];
                    $program->address          = $programData[__('trans.excel_sheet.address')] ?? null;
                    $program->url              = $programData[__('trans.excel_sheet.url')] ?? null;
                    $program->have_certificate = $programData['لديه شهادة'] == 'نعم' ? true : false;
                    $program->user_type        = $programData['الفئة المستهدفة'] == 'طالب' ? 'student' : 'teacher';
                    $program->is_approved      = 1;
                    $program->status           = $programData['الحالة'] == 'مفعل' ? 1 : 0;
                    $program->category_id      = $category->id ?? null;
                    $program->teacher_id       = $teacher->id;
                    $program->save();

                    $new = new ProgramTranslation();
                    $new->locale = 'ar';
                    $new->parent_id = $program->id;
                    $new->title = $title_ar;
                    $new->description = $programData[__('trans.excel_sheet.description').' ('.__('trans.excel_sheet.arabic').')'] ?? null;
                    $new->learning = !empty($programData[__('trans.excel_sheet.learning').' ('.__('trans.excel_sheet.arabic').')'])
                        ? array_map('trim', explode(',', $programData[__('trans.excel_sheet.learning').' ('.__('trans.excel_sheet.arabic').')']))
                        : [];
                    $new->requirement = !empty($programData[__('trans.excel_sheet.requirement').' ('.__('trans.excel_sheet.arabic').')'])
                        ? array_map('trim', explode(',', $programData[__('trans.excel_sheet.requirement').' ('.__('trans.excel_sheet.arabic').')']))
                        : [];
                    $new->save();

                    $new = new ProgramTranslation();
                    $new->locale = 'en';
                    $new->parent_id = $program->id;
                    $new->title = $title_en;
                    $new->description = $programData[__('trans.excel_sheet.description').' ('.__('trans.excel_sheet.arabic').')'] ?? null;
                    $new->learning = !empty($programData[__('trans.excel_sheet.learning').' ('.__('trans.excel_sheet.english').')'])
                        ? array_map('trim', explode(',', $programData[__('trans.excel_sheet.learning').' ('.__('trans.excel_sheet.english').')']))
                        : [];
                    $new->requirement = !empty($programData[__('trans.excel_sheet.requirement').' ('.__('trans.excel_sheet.english').')'])
                        ? array_map('trim', explode(',', $programData[__('trans.excel_sheet.requirement').' ('.__('trans.excel_sheet.english').')']))
                        : [];
                    $new->save();

                    // $created[] = $program->load('translations');
                    $created[] = $program->id;
                } catch (\Exception $e) {
                    $duplicates[] = [
                        'title' => $programData[__('trans.excel_sheet.program').' ('.__('trans.excel_sheet.arabic').')'],
                        'reason' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($created) > 0 ? __('trans.alert.success.done_create') : null,
                'created_count'         => count($created),
                'failed_count'          => count($duplicates),
                'faliled_programs_list' => $duplicates,
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title'            => 'required|array',
            'title.*'          => 'string|max:255',
            'image'            => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp,avif|max:2048',
            'price'            => 'required|numeric',
            'level'            => 'required|string|max:255|in:مبتدئ,متوسط,متقدم,خبير',
            'duration'         => 'required_if:type,registered|nullable|numeric',
            'date_from'        => 'required_if:type,live,onsite,registered|nullable|date',
            'date_to'          => 'required_if:type,live,onsite|nullable|date',
            'time'             => 'required_if:type,live|nullable|date_format:H:i:s',
            'address'          => 'required_if:type,onsite|nullable|string|max:500',
            'url'              => 'required_if:type,onsite|nullable|url|max:255',
            'program_date'     => 'nullable|string',
            'description'      => 'required|array',
            'description.*'    => 'string',
            'learning'         => 'required|array',
            'learning.*'       => 'array',
            'learning.*.*'     => 'string|max:500',
            'requirement'      => 'required|array',
            'requirement.*'    => 'array',
            'requirement.*.*'  => 'string|max:500',
            'main_axes'        => 'nullable|array',
            'main_axes.*'      => 'array',
            'main_axes.*.*'    => 'string|max:500',
            'notes'            => 'nullable|string',
            'have_certificate' => 'required|boolean|in:0,1',
            'status'           => 'required|in:0,1',
            'user_type'        => 'required|in:student,trainee',
            'category_id'      => 'required|exists:categories,id',
            'teacher_id'       => 'required|exists:teachers,id',

            'sections'         => 'required_if:type,registered|nullable|array',
            'sections.*.title' => 'required_if:type,registered|array',
            'sections.*.title.*' => 'required_if:type,registered|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $teacher = Teacher::find($request->teacher_id);
        if (!$teacher->categories()->find($request->category_id)) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.teacher_not_have_this_category'),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $program = new Program();
            $program->slug             = Str::slug($request->title['en'] ?? $request->title[array_key_first($request->title)]);
            $program->price            = $request->price;
            $program->type             = $request->type;
            $program->level            = $request->level;
            $program->duration         = $request->duration;
            $program->date_from        = $request->date_from;
            $program->date_to          = $request->date_to;
            $program->time             = $request->time;
            $program->address          = $request->address;
            $program->url              = $request->url;
            $program->have_certificate = $request->have_certificate;
            $program->is_approved      = 1;
            $program->status           = $request->status;
            $program->user_type        = $request->user_type;
            $program->category_id      = $request->category_id;
            $program->teacher_id       = $request->teacher_id;
            $program->notes            = $request->notes ?? null;
            $program->save();

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileName = 'image_' . time() . '_' . uniqid() . '.' . $file->extension();
                $path = 'uploads/programs/program_id_'.$program->id;

                $fullPath = public_path($path);
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }
                $file->move($fullPath, $fileName);
                $program->image = $path . '/' . $fileName;
                $program->save();
            }

            if ($request->sections && is_array($request->sections)) {
                foreach ($request->sections as $sectionData) {
                    $section = new ProgramSection();
                    $section->program_id = $program->id;
                    $section->save();

                    if (isset($sectionData['title']) && is_array($sectionData['title'])) {
                        foreach ($sectionData['title'] as $locale => $title) {
                            $trans_section = new ProgramSectionTranslation();
                            $trans_section->locale    = $locale;
                            $trans_section->parent_id = $section->id;
                            $trans_section->title     = $title;
                            $trans_section->save();
                        }
                    }
                }
            }

            // Handle program translations
            foreach($request->title as $locale => $title){
                $trans_program = new ProgramTranslation();
                $trans_program->locale      = $locale;
                $trans_program->parent_id   = $program->id;
                $trans_program->title       = $title;
                $trans_program->description = $request->description[$locale];
                $trans_program->learning    = isset($request->learning[$locale]) ? $request->learning[$locale] : [];
                $trans_program->requirement = isset($request->requirement[$locale]) ? $request->requirement[$locale] : [];
                $trans_program->main_axes   = isset($request->main_axes[$locale]) ? $request->main_axes[$locale] : [];
                $trans_program->save();
            }

            $program->load('translations');
            storeInCache('programs', $program);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('trans.alert.success.done_create'),
                'data'    => $program
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.done_create'),
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function program_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;
        $score = $request->score;

        $programs = getDataFromCache('all_programs', function () {
            return Program::withTrashed()
                ->with(['category:id', 'category.translations', 'teacher:id,name', 'reviews:id,score,program_id', 'translations'])
                ->select(['id', 'transaction_number', 'image', 'price', 'notes', 'created_at', 'teacher_id', 'category_id'])
                ->withCount('subscriptions')
                ->get()
                ->map(function ($program) {
                    $program->average_score = round($program->reviews->avg('score'), 2) ?? 0;
                    return $program;
                });
        });

        if ($filter === 'latest') {
            $programs = $programs->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $programs = $programs->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $programs = $programs->sortBy(fn($program) => mb_strtolower($program->title))->values();
        } elseif ($filter === 'active_status') {
            $programs = $programs->where(fn($program) => $program->deleted_at == null && $program->status > 1)->values();
        } elseif ($filter === 'deleted_status') {
            $programs = $programs->where(fn($program) => $program->deleted_at != null)->values();
        } elseif ($filter === 'live_type') {
            $programs = $programs->where('type', 'live');
        } elseif ($filter === 'registered_type') {
            $programs = $programs->where('type', 'registered');
        }

        if ($search) {
            $programs = $programs->filter(function ($program) use ($search) {
                // return mb_stripos($program->title, $search) !== false;
                return $program->translations->contains(function ($translation) use ($search) {
                    return mb_stripos($translation->title, $search) !== false;
                });
            })->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $programs = $programs->filter(function ($program) use ($price_from, $price_to) {
                $price = $program->price;

                if ($price_from !== null && $price < $price_from) {
                    return false;
                }

                if ($price_to !== null && $price > $price_to) {
                    return false;
                }

                return true;
            })->values();
        }

        if ($score !== null) {
            $programs = $programs->filter(function ($program) use ($score) {
                $average = $program->reviews->avg('score');
                return $average !== null && $average >= $score;
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
        $sheet->setCellValue('A1', __('trans.program.name'));
        $sheet->setCellValue('B1', __('trans.program.category'));
        $sheet->setCellValue('C1', __('trans.program.expert_name'));
        $sheet->setCellValue('D1', __('trans.program.trainners_num'));
        $sheet->setCellValue('E1', __('trans.program.description'));
        $sheet->setCellValue('F1', __('trans.program.price'));
        $sheet->setCellValue('G1', __('trans.program.reviews'));
        $sheet->setCellValue('H1', __('trans.program.status'));
        $sheet->setCellValue('I1', __('trans.program.created_at'));

        foreach ($programs as $key => $program):
            $key = $key+2;

            if($program->deleted_at == null && $program->status == 1){
                $status = __('trans.program.active');
            } elseif($program->deleted_at == null && $program->status == 0){
                $status = __('trans.program.not_active');
            } elseif($program->deleted_at != null && $program->status == 1){
                $status = __('trans.program.deleted');
            }

            $sheet->setCellValue('A'.$key, __('trans.program.name').'-'.$program->id ?? '-');
            $sheet->setCellValue('B'.$key, $program->category->translations->firstWhere('locale', app()->getLocale())->title ?? '-');
            $sheet->setCellValue('C'.$key, $program->teacher->name ?: '-');
            $sheet->setCellValue('D'.$key, $program->subscriptions_count ?? '-');
            $sheet->setCellValue('E'.$key, $program->description ?? '-');
            $sheet->setCellValue('F'.$key, $program->price ?? '-');
            $sheet->setCellValue('G'.$key, $program->average_score ?? '-');
            $sheet->setCellValue('H'.$key, $status ?? '-');
            $sheet->setCellValue('I'.$key, Carbon::parse($program->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.program.title')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }

    public function show(string $id)
    {
        $program = Program::with([
            'translations',
            'category:id',
            'category.translations',
            'teacher:id,name',
            'reviews.student:id,name,image',
            'sections' => function ($q) {
                $q->withCount('sessions as video_count')
                ->withSum('sessions as section_duration', 'video_duration')
                ->with('free_materials', 'translations');
            },
        ])
        ->withCount('sections')
        ->withCount('sessions as video_count')
        ->withSum('sessions as program_duration', 'video_duration')
        ->withAvg('reviews', 'score')
        ->withCount(['reviews', 'subscriptions'])
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });


        $program->program_duration = formatDuration($program->program_duration);

        // ============== files ==============
        // حساب عدد الملفات من جميع السيشنز
        $filesCount = 0;
        foreach ($program->sections as $section) {
            foreach ($section->sessions as $session) {
                if (!empty($session->files)) {
                    $files = is_array($session->files)
                        ? $session->files
                        : json_decode($session->files, true);

                    if (is_array($files)) {
                        $filesCount += count($files);
                    }
                }
            }
            $section->section_duration = formatDuration($section->section_duration);
        }
        $program->files_count = $filesCount;

        // ============== reviews ==============
        $totalReviews = $program->reviews->count();
        // حساب عدد التقييمات لكل درجة (من 1 إلى 5 نجوم)
        $starCounts = $program->reviews->groupBy('score')->map->count();
        // النتائج
        $ratings = [];
        for ($i = 5; $i >= 1; $i--) {
            $count = $starCounts[$i] ?? 0;
            $percentage = $totalReviews > 0 ? round(($count / $totalReviews) * 100) : 0;

            $ratings[$i] = [ // $ratings[$i.'_stars'] = [
                'percentage' => $percentage,
            ];
        }
        // متوسط التقييم
        $average = $totalReviews > 0 ? round($program->reviews->avg('score'), 1) : 0;

        $program->makeHidden(['subscriptions']);

        return response()->json([
            'success' => true,
            'data' => $program,
            'reviews_summary' => [
                'total' => $totalReviews,
                'average' => $average,
                'stars' => $ratings,
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title'            => 'required|array',
            'title.*'          => 'required|string|max:255',
            'image'            => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,svg,webp,avif|max:2048',
            'price'            => 'required|numeric',
            'level'            => 'required|string|max:255|in:مبتدئ,متوسط,متقدم,خبير',
            'date_from'        => 'required_if:type,live,onsite,registered|nullable|string',
            'date_to'          => 'required_if:type,live,onsite|nullable|string',
            'time'             => 'required_if:type,live|nullable|string',
            'duration'         => 'required_if:type,registered|nullable|numeric',
            'address'          => 'required_if:type,onsite|nullable|string|max:500',
            'url'              => 'required_if:type,onsite|nullable|url|max:255',
            'description'      => 'required|array',
            'description.*'    => 'string',
            'learning'         => 'required|array',
            'learning.*'       => 'array',
            'learning.*.*'     => 'string|max:500',
            'requirement'      => 'required|array',
            'requirement.*'    => 'array',
            'requirement.*.*'  => 'string|max:500',
            'main_axes'        => 'nullable|array',
            'main_axes.*'      => 'array',
            'main_axes.*.*'    => 'string|max:500',
            'notes'            => 'nullable|string',
            'have_certificate' => 'required|boolean',
            'status'           => 'required|in:0,1',
            'category_id'      => 'required|exists:categories,id',
            'teacher_id'       => 'required|exists:teachers,id',

            'sections'              => 'required_if:type,registered|nullable|array',
            'sections.*.id'         => 'nullable|exists:program_sections,id',
            'sections.*.title'      => 'required_if:type,registered|array',
            'sections.*.title.*'    => 'required_if:type,registered|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        // check teacher have this category
        $teacher = Teacher::find($request->teacher_id);
        if (!$teacher->categories()->find($request->category_id)) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.teacher_not_have_this_category'),
            ], 422);
        }

        $program = Program::with(['sections:id,program_id', 'sections.translations'])
        ->findOr($id, function () use ($id){
            abort(response()->json([
                'success' => false,
                'program' => $id,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });
        $program->makeHidden(['teacher']);

        DB::beginTransaction();
        try {
            // Handle image upload
            if ($request->hasFile('image')) {
                // remove old image
                if ($program->image) {
                    $path = public_path($program->image);
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
                $image = $request->file('image');
                $imageName = 'image_' . time() . '.' . $image->getClientOriginalExtension();
                $path = 'uploads/programs/program_id_'.$program->id;

                $fullPath = public_path($path);
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }
                $image->move($fullPath, $imageName);
                $program->image = $path . '/' . $imageName;
            }

            $program->price             = $request->price;
            $program->type              = $request->type;
            $program->level             = $request->level;
            $program->duration          = $request->duration;
            $program->date_from         = $request->date_from;
            $program->date_to           = $request->date_to;
            $program->time              = $request->time;
            $program->address           = $request->address;
            $program->url               = $request->url;
            $program->have_certificate  = $request->have_certificate;
            $program->status            = $request->status;
            $program->category_id       = $request->category_id;
            $program->teacher_id        = $request->teacher_id;
            $program->notes             = $request->notes ?? null;
            $program->save();

            // save in cache (helper method)
            updateInCache('programs', $program);

            // Create new sections if provided
             if ($request->has('sections') && is_array($request->sections)) {
                $existingSections = $program->sections->values(); // احتفظ بالترتيب
                $updatedSectionIds = [];

                foreach ($request->sections as $index => $sectionData) {
                    if (isset($sectionData['title']) && is_array($sectionData['title'])) {
                       // لو في section موجود في نفس الـ index، استخدمه
                        if (isset($existingSections[$index])) {
                            $section = $existingSections[$index];
                            $updatedSectionIds[] = $section->id;
                        }
                        // لو في id في الـ request
                        elseif (isset($sectionData['id'])) {
                            $section = ProgramSection::find($sectionData['id']);
                            if ($section) {
                                $updatedSectionIds[] = $sectionData['id'];
                            } else {
                                $section = new ProgramSection();
                                $section->program_id = $program->id;
                            }
                        }
                        // إنشاء section جديد
                        else {
                            $section = new ProgramSection();
                            $section->program_id = $program->id;
                        }

                        $section->save();

                        // حذف الترجمات القديمة وإنشاء الجديدة
                        ProgramSectionTranslation::where('parent_id', $section->id)->delete();

                        foreach ($sectionData['title'] as $locale => $title) {
                            $trans_section = new ProgramSectionTranslation();
                            $trans_section->parent_id = $section->id;
                            $trans_section->locale = $locale;
                            $trans_section->title = $title;
                            $trans_section->save();
                        }

                        $section->load('translations');
                        storeInCache('program_' . $program->id . '_sections', $section);
                    }
                }

                // حذف السيشنات اللي مش موجودة
                $existingSectionIds = $program->sections->pluck('id')->toArray();
                $sectionsToDelete = array_diff($existingSectionIds, $updatedSectionIds);

                if (!empty($sectionsToDelete)) {
                    ProgramSectionTranslation::whereIn('parent_id', $sectionsToDelete)->delete();
                    ProgramSection::whereIn('id', $sectionsToDelete)->delete();
                }
            }

             // Delete existing program translations
            ProgramTranslation::where('parent_id', $program->id)->delete();

            // Create new program translations
            foreach ($request->title as $locale => $title) {
                $trans_program = new ProgramTranslation();
                $trans_program->parent_id = $program->id;
                $trans_program->locale = $locale;
                $trans_program->title = $title;
                $trans_program->description = $request->description[$locale];
                $trans_program->learning = isset($request->learning[$locale]) ? $request->learning[$locale] : [];
                $trans_program->requirement = isset($request->requirement[$locale]) ? $request->requirement[$locale] : [];
                $trans_program->main_axes = isset($request->main_axes[$locale]) ? $request->main_axes[$locale] : [];
                $trans_program->save();
            }

            $program->load('translations'); // Fixed: should be 'translations' not 'translation'
            updateInCache('programs', $program);
            updateInCache('all_programs', $program);

            if($program->type == 'live'){
                $subscriptions = Subscription::where('program_id', $program->id)->get();
                foreach($subscriptions as $sub)
                {
                    $sub->expire_date = $program->date_to;
                    $sub->save();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $program
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.done_create'),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $program = Program::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $program->delete(); // Soft delete

        deleteFromCache('programs', $program);
        updateInCache('all_programs', $program);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

     public function restore($id)
    {
        $program = Program::withTrashed()->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $program->restore();
        storeInCache('programs', $program);
        updateInCache('all_programs', $program);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }

    public function hard_delete($id)
    {
        // Program::withTrashed()->find($id)->forceDelete(); //  حذف نهائي
        $program = Program::withTrashed()->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        // remove folder of program directory
        $folderPath = public_path($id);
        if (File::isDirectory($folderPath)) {
            File::deleteDirectory($folderPath);
        }

        $program->forceDelete();

        // delete from cache
        deleteFromCache('programs', $program);
        deleteFromCache('all_programs', $program);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

    public function program_students(Request $request, $id)
    {
        $subscriptions = Subscription::with(['student:id,name,email,phone,image,type'])
            ->where('program_id', $id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($subscription) {
                $student = $subscription->student;
                $program = $subscription->program;

                $student->setAttribute('programs_count', $student->subscriptions->count() ?? 0);
                $student->makeHidden('subscriptions');

                $student->setAttribute('reviews_count', $student->reviews->count());
                $student->makeHidden('reviews');

                // جلب كل المهام الخاصة بالبرنامج
                $assignments = Assignment::where('program_id', $subscription->program_id)->get();
                $assignments_count = $assignments->count();
                $assignments_solution_count = AssignmentSolution::whereIn('assignment_id', $assignments->pluck('id'))
                    ->where('student_id', $subscription->student_id)
                    ->count();

                $assignmentProgress = $assignments_count > 0
                    ? round(($assignments_solution_count / $assignments_count) * 100, 2)
                    : 0;

                $exams = Exam::where('program_id', $subscription->program_id)
                    ->where('user_type', 'student') // exams for student
                    ->pluck('id')->toArray();

                // متوسط نسبه النجاح
                $examResults = ExamStudent::where('student_id', $subscription->student_id)
                    ->whereIn('exam_id', $exams)
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->groupBy('exam_id') // نجمع حسب كل امتحان
                    ->map(function ($attempts) {
                        return $attempts->first(); // نأخذ آخر محاولة
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

                // نسبة إنجاز الطالب النهائية
                // $finalProgress = $assignments_count > 0 && count($exams) > 0
                //     ? round(($assignmentProgress + $examProgress) / 2, 2)
                //     : ($assignments_count > 0 ? $assignmentProgress : $examProgress);

                $student->setAttribute('student_progress', $finalProgress);

                // هل الطالب اتاخد عليه warning قبل كدا؟
                $hasWarning = StudentWarning::where('student_id', $student->id)
                    ->where('type', 'warning')
                    ->exists();

                $student->setAttribute('has_warning_before', $hasWarning);

                return $student;
            });


        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($subscriptions, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function report(Request $request, $id)
    {
        $program = Program::with(['translations','category:id', 'category.translations'])
        ->withCount('subscriptions')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $subscriptions = Subscription::with(['student:id,name,email,phone,image,type'])
            ->where('program_id', $id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($subscription) use ($program) {
                $student = $subscription->student;

                $student->setAttribute('programs_count', $student->subscriptions->count() ?? 0);
                $student->makeHidden('subscriptions');
                $student->setAttribute('reviews_count', $student->reviews->count());
                $student->makeHidden('reviews');

                // ========== حساب التقدم ==========
                $assignments = $program->assignments;
                $exams       = $program->exams()->where('user_type', $subscription->student->type)->get();
                $sessions    = $program->sessions;

                $totalAssignments = $assignments->count();
                $totalExams       = $exams->count();
                $totalSessions    = $sessions->count();

                // المهام المحلولة
                $solvedAssignments = $student->assignment_solutions()
                    ->whereIn('assignment_id', $assignments->pluck('id'))
                    ->pluck('assignment_id')
                    ->unique()
                    ->count();

                // الامتحانات المحلولة
                $solvedExams = $student->exam_answers()
                    ->whereIn('exam_id', $exams->pluck('id'))
                    ->pluck('exam_id')
                    ->unique()
                    ->count();

                // السيشنز المشاهدة
                $watchedSessions = $student->session_views()
                    ->whereIn('session_id', $sessions->pluck('id'))
                    ->pluck('session_id')
                    ->unique()
                    ->count();

                $totalRequired = $totalAssignments + $totalExams + $totalSessions;
                $totalDone     = $solvedAssignments + $solvedExams + $watchedSessions;

                $progress = $totalRequired > 0
                    ? round(($totalDone / $totalRequired) * 100, 2)
                    : 0;

                $student->setAttribute('student_progress', $progress);

                return $student;
            });


        // حساب متوسط إكمال الكورس لجميع الطلاب
        $program->setAttribute('students_progress', $subscriptions->avg('student_progress') ?? 0);

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($subscriptions, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => [
                'program' => $program,
                'subscriptions' => $paginated
            ]
        ]);
    }

    public function report_excel(Request $request, $id)
    {
        $program = Program::with(['translation','category:id', 'category.translation'])
        ->withCount('subscriptions')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $students_subscriptions = Subscription::with(['student:id,name,email,phone,image,type'])
            ->where('program_id', $id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($subscription) use ($program) {
                $student = $subscription->student;

                $student->setAttribute('programs_count', $student->subscriptions->count() ?? 0);
                $student->makeHidden('subscriptions');
                $student->setAttribute('reviews_count', $student->reviews->count());
                $student->makeHidden('reviews');

                // ========== حساب التقدم ==========
                $assignments = $program->assignments;
                $exams       = $program->exams()->where('user_type', $subscription->student->type)->get();
                $sessions    = $program->sessions;

                $totalAssignments = $assignments->count();
                $totalExams       = $exams->count();
                $totalSessions    = $sessions->count();

                // المهام المحلولة
                $solvedAssignments = $student->assignment_solutions()
                    ->whereIn('assignment_id', $assignments->pluck('id'))
                    ->pluck('assignment_id')
                    ->unique()
                    ->count();

                // الامتحانات المحلولة
                $solvedExams = $student->exam_answers()
                    ->whereIn('exam_id', $exams->pluck('id'))
                    ->pluck('exam_id')
                    ->unique()
                    ->count();

                // السيشنز المشاهدة
                $watchedSessions = $student->session_views()
                    ->whereIn('session_id', $sessions->pluck('id'))
                    ->pluck('session_id')
                    ->unique()
                    ->count();

                $totalRequired = $totalAssignments + $totalExams + $totalSessions;
                $totalDone     = $solvedAssignments + $solvedExams + $watchedSessions;

                $progress = $totalRequired > 0
                    ? round(($totalDone / $totalRequired) * 100, 2)
                    : 0;

                $student->setAttribute('student_progress', $progress);

                return $student;
            });

        // حساب متوسط إكمال الكورس لجميع الطلاب
        $program->setAttribute('students_progress', $students_subscriptions->avg('student_progress') ?? 0);

        $currentDate = Carbon::now();

        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);

        // ========== تنسيق عنوان التقرير ==========
        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', __('trans.program.program_report'));
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => '4472C4']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // ========== تفاصيل البرنامج ==========
        $currentRow = 3;

        // اسم البرنامج
        $sheet->setCellValue('A'.$currentRow, __('trans.program.name'));
        $sheet->mergeCells('B'.$currentRow.':E'.$currentRow);
        $sheet->setCellValue('B'.$currentRow, $program->translation->title ?? '-');
        $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
        $currentRow++;

        // نوع البرنامج
        $sheet->setCellValue('A'.$currentRow, __('trans.program.type'));
        $sheet->mergeCells('B'.$currentRow.':E'.$currentRow);
        $sheet->setCellValue('B'.$currentRow, $program->type == 'live' ? __('trans.program.live') : __('trans.program.registered'));
        $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
        $currentRow++;

        // الفئة
        $sheet->setCellValue('A'.$currentRow, __('trans.category.title'));
        $sheet->mergeCells('B'.$currentRow.':E'.$currentRow);
        $sheet->setCellValue('B'.$currentRow, $program->category->translation->title ?? '-');
        $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
        $currentRow++;

        // الخبير
        $sheet->setCellValue('A'.$currentRow, __('trans.teacher.name'));
        $sheet->mergeCells('B'.$currentRow.':E'.$currentRow);
        $sheet->setCellValue('B'.$currentRow, $program->teacher->name);
        $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
        $currentRow++;

        // عدد الطلاب المشتركين
        $sheet->setCellValue('A'.$currentRow, __('trans.program.subscriptions_count'));
        $sheet->mergeCells('B'.$currentRow.':E'.$currentRow);
        $sheet->setCellValue('B'.$currentRow, $program->subscriptions_count ?? 0);
        $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
        $currentRow++;

        // متوسط تقدم الطلاب
        $sheet->setCellValue('A'.$currentRow, __('trans.program.average_progress'));
        $sheet->mergeCells('B'.$currentRow.':E'.$currentRow);
        $sheet->setCellValue('B'.$currentRow, round($program->students_progress, 2) . '%');
        $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
        $currentRow++;

        // تاريخ البرنامج
        $sheet->setCellValue('A'.$currentRow, __('trans.program.created_at'));
        $sheet->mergeCells('B'.$currentRow.':E'.$currentRow);
        $sheet->setCellValue('B'.$currentRow, $currentDate->format('Y-m-d H:i'));
        $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
        // $currentRow++;

        // خلفية تفاصيل البرنامج
        $sheet->getStyle('A3:A'.$currentRow)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['rgb' => 'E7E6E6']
            ]
        ]);

        // ========== جدول الطلاب ==========
        $currentRow += 2; // سطر فارغ
        $headerRow = $currentRow;

        // عناوين الأعمدة
        $columns = ['A', 'B', 'C', 'D', 'E'];
        $headers = [
            __('trans.student.name'),
            __('trans.student.email'),
            __('trans.student.phone'),
            __('trans.student.subscribed_programs_count'),
            __('trans.student.progress')
        ];

        foreach ($columns as $index => $column) {
            // $width = ($index == 0) ? 25 : 15;
            $width = 25;
            $sheet->getColumnDimension($column)->setWidth($width);
            $sheet->setCellValue($column.$headerRow, $headers[$index]);
            $sheet->getStyle($column.$headerRow)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF']
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'FFC000']
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER
                ]
            ]);
        }

        // بيانات الطلاب
        $currentRow++;
        foreach ($students_subscriptions as $key => $student) {
            $sheet->setCellValue('A'.$currentRow, $student->name ?? '-');
            $sheet->setCellValue('B'.$currentRow, $student->email ?? '-');
            $sheet->setCellValueExplicit('C'.$currentRow, (string) $student->phone, DataType::TYPE_STRING);
            $sheet->setCellValue('D'.$currentRow, $student->programs_count ?? '-');
            $sheet->setCellValue('E'.$currentRow, $student->student_progress . '%');

            // تلوين متبادل للصفوف
            if ($key % 2 == 0) {
                $sheet->getStyle('A'.$currentRow.':E'.$currentRow)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'color' => ['rgb' => 'F2F2F2']
                    ]
                ]);
            }

            $currentRow++;
        }

        // إضافة حدود للجدول
        $lastRow = $currentRow - 1;
        $sheet->getStyle('A'.$headerRow.':E'.$lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);

        // حفظ الملف
        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.program.program_report')."- $today".".xlsx";
        $writer->save($fileName);

        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }

    public function requests(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $price_from = $request->price_from;
        $price_to = $request->price_to;

        // programs
        $totalPrograms = Program::withTrashed()->where('is_approved', 0)->count();
        $current_programs = Program::withTrashed()->where('is_approved', 0)->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_programs = Program::withTrashed()->where('is_approved', 0)->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_programs > 0) {
            $program_percentage_change = round((($current_programs - $previous_programs) / $previous_programs) * 100, 2);
        } else {
            $program_percentage_change = 100;
        }

        // registered
        $registered_programs_count = Program::where('type', 'registered')->where('is_approved', 0)->count();
        $current_registered_programs = Program::where('type', 'registered')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_registered_programs = Program::where('type', 'registered')
            ->where('is_approved', 0)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_registered_programs > 0) {
            $program_registered_percentage_change = round((($current_registered_programs - $previous_registered_programs) / $previous_registered_programs) * 100, 2);
        } else {
            $program_registered_percentage_change = 100;
        }

        //live
        $live_programs_count = Program::where('type', 'live')->where('is_approved', 0)->count();
        $current_live_programs = Program::where('type', 'live')
            ->where('is_approved', 0)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_live_programs = Program::where('type', 'live')
            ->where('is_approved', 0)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_live_programs > 0) {
            $program_live_percentage_change = round((($current_live_programs - $previous_live_programs) / $previous_live_programs) * 100, 2);
        } else {
            $program_live_percentage_change = 100;
        }

        //deleted
        $deletedPrograms = Program::onlyTrashed()->where('is_approved', 0)->count();
        $current_deleted_programs = Program::onlyTrashed()->where('is_approved', 0)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $previous_deleted_programs = Program::onlyTrashed()->where('is_approved', 0)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // نسبة الزيادة أو النقصان
        if ($previous_deleted_programs > 0) {
            $program_deleted_percentage_change = round((($current_deleted_programs - $previous_deleted_programs) / $previous_deleted_programs) * 100, 2);
        } else {
            $program_deleted_percentage_change = 100;
        }

        $programs = program::with(['category:id', 'category.translations', 'teacher:id,name', 'reviews:id,score,program_id', 'translations'])
        ->where('is_approved', 0)
        ->select(['id', 'transaction_number', 'image', 'price', 'type', 'is_live', 'status', 'is_approved', 'notes', 'created_at', 'teacher_id', 'category_id', 'deleted_at'])
        ->withCount('subscriptions')
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($program) {
            $program->average_score = round($program->reviews->avg('score'), 2) ?? 0;
            return $program;
        });

        if ($filter === 'latest') {
            $programs = $programs->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $programs = $programs->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $programs = $programs->sortBy(fn($program) => mb_strtolower($program->title))->values();
        } elseif ($filter === 'active_status') {
            $programs = $programs->where(fn($program) => $program->deleted_at == null && $program->status > 1)->values();
        } elseif ($filter === 'deleted_status') {
            $programs = $programs->where(fn($program) => $program->deleted_at != null)->values();
        } elseif ($filter === 'live_type') {
            $programs = $programs->where('type', 'live')->values();
        } elseif ($filter === 'registered_type') {
            $programs = $programs->where('type', 'registered')->values();
        }

        if ($search) {
            $programs = $programs->filter(function ($program) use ($search) {
                // return mb_stripos($program->title, $search) !== false;
                return $program->translations->contains(function ($translation) use ($search) {
                    return mb_stripos($translation->title, $search) !== false;
                });
            })->values();
        }

        if ($price_from !== null || $price_to !== null) {
            $programs = $programs->filter(function ($program) use ($price_from, $price_to) {
                $price = $program->price;

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
        $paginated = paginationData($programs, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_count' => $totalPrograms,
            'total_program_percentage' => abs($program_percentage_change),
            'total_program_status' => $program_percentage_change >= 0 ? 'increase' : 'decrease',

            'live_programs_count' => $live_programs_count,
            'total_live_program_percentage' => abs($program_live_percentage_change),
            'total_live_program_status' => $program_live_percentage_change >= 0 ? 'increase' : 'decrease',

            'registered_programs_count' => $registered_programs_count,
            'total_registered_program_percentage' => abs($program_registered_percentage_change),
            'total_registered_program_status' => $program_registered_percentage_change >= 0 ? 'increase' : 'decrease',

            'deleted_programs_count' => $deletedPrograms,
            'total_deleted_program_percentage' => abs($program_deleted_percentage_change),
            'total_deleted_program_status' => $program_deleted_percentage_change >= 0 ? 'increase' : 'decrease',

            'data' => $paginated
        ]);
    }

    public function multi_approve(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'programs_id' => 'required|array',
            'programs_id.*' => 'integer|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $allProgramsIds = $request->input('programs_id', []);
            $programs = Program::with('teacher', 'translation')->whereIn('id', $allProgramsIds)->get();

            // Update approval status
            foreach ($programs as $program) {
                $program->is_approved = 1;
                $program->save();
            }

            // Group programs by teacher_id to handle multiple teachers
            $programsByTeacher = $programs->groupBy('teacher_id');

            $totalNotificationsSent = 0;
            $totalEmailsSent = 0;

            // Process notifications for each teacher
            foreach ($programsByTeacher as $teacherId => $teacherPrograms) {
                try {
                    $teacher = Teacher::find($teacherId);
                    if (!$teacher) {
                        Log::warning('Teacher not found for program approval', [
                            'teacher_id' => $teacherId,
                            'program_ids' => $teacherPrograms->pluck('id')->toArray()
                        ]);
                        continue;
                    }

                    $programsCount = $teacherPrograms->count();
                    $programTitles = $teacherPrograms->map(function ($program) {
                        $translation = $program->translation;
                        return $translation ? $translation->title : 'Program #' . $program->id;
                    })->toArray();

                    // Create notification title and content
                    $notificationTitle = $programsCount === 1
                        ? 'Program Approved'
                        : "{$programsCount} Programs Approved";

                    $notificationContent = $programsCount === 1
                        ? "Your program '{$programTitles[0]}' has been approved by the admin."
                        : "Your {$programsCount} programs have been approved by the admin: " . implode(', ', $programTitles);

                    // Create notification record
                    $notification = Notification::create([
                        'title' => $notificationTitle,
                        'content' => $notificationContent,
                        'type' => 'notice',
                        'users_type' => ['teacher'],
                        'status' => 'sent'
                    ]);

                    // Send email notification
                    try {
                        $teacher->notify(new ProgramApprovalNotification($teacherPrograms));
                        $totalEmailsSent++;
                    } catch (\Exception $e) {
                        Log::error('Failed to send email notification to teacher', [
                            'teacher_id' => $teacherId,
                            'error' => $e->getMessage(),
                            'program_ids' => $teacherPrograms->pluck('id')->toArray()
                        ]);
                    }

                    // Create teacher notification record
                    $teacherNotification = new TeacherNotification();
                    $teacherNotification->notification_id = $notification->id;
                    $teacherNotification->teacher_id = $teacher->id;
                    $teacherNotification->is_read = false;
                    $teacherNotification->save();

                    // Fire real-time push notification event
                    event(new GeneralNotificationEvent($notification, 'teacher', $teacher->id));
                    $totalNotificationsSent++;

                } catch (\Exception $e) {
                    Log::error('Failed to process notification for teacher', [
                        'teacher_id' => $teacherId,
                        'error' => $e->getMessage(),
                        'program_ids' => $teacherPrograms->pluck('id')->toArray()
                    ]);
                    // Continue processing other teachers even if one fails
                }
            }

            DB::commit();
            Cache::forget('programs');

            return response()->json([
                'success' => true,
                'message' => __('trans.alert.success.done_update'),
                'data' => [
                    'programs_approved' => $programs->count(),
                    'teachers_notified' => $programsByTeacher->count(),
                    'emails_sent' => $totalEmailsSent,
                    'notifications_sent' => $totalNotificationsSent,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve programs', [
                'program_ids' => $request->input('programs_id', []),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while approving programs.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
