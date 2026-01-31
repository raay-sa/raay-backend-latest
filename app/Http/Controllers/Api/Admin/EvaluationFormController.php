<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvaluationForm;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationResponse;
use App\Models\EvaluationSection;
use App\Models\EvalutionOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class EvaluationFormController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $durationDays = $request->duration; // عدد الأيام
        $durationMinutes = $durationDays * 24 * 60;
        $locale = $request->lang ?? app()->getLocale();

        $reviews = EvaluationResponse::with([
            'student:id,name,email',
            'program' => function ($query) {
                $query->select('id')
                ->with('translations')
                ->withSum('sessions as program_duration', 'video_duration');
            }
        ])->get()
        ->map(function ($response) use ($locale) {
            return [
                'response_id'     => $response->id,
                'student_name'    => $response->student?->name,
                'student_email'   => $response->student?->email,
                'program_titles'  => $response->program->translations->firstWhere('locale', $locale)->title ?? '-',
                'program_duration'=> $response->program?->program_duration
                    ? formatDuration($response->program->program_duration)
                    : '0:00',
                'created_at'      => $response->created_at
            ];
        });

        if ($filter === 'name') {
            $reviews = $reviews->sortBy(fn($review) =>
                mb_strtolower($review['program_titles'][$locale] ?? '')
            )->values();
        }

        if ($search) {
            $reviews = $reviews->filter(function ($review) use ($search, $locale) {
                return mb_stripos($review['program_titles'][$locale] ?? '', $search) !== false;
            })->values();
        }


        if($durationDays) {
            $reviews = $reviews->filter(function ($review) use ($durationMinutes) {
                return $review['program_duration'] <= $durationMinutes;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($reviews, $perPage, $currentPage);

        return response()->json([
            'success' => true,
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
        $validator = validator(request()->all(), [
            'status'                               => 'boolean',
            'sections'                             => 'required|array',
            'sections.*.title'                     => 'required|string',
            'sections.*.questions'                 => 'required|array',
            'sections.*.questions.*.title'         => 'required|string',
            'sections.*.questions.*.type'          => 'required|in:text,radio,checkbox',
            'sections.*.questions.*.is_required'   => 'boolean',
            'sections.*.questions.*.choices_count' => 'required_if:sections.*.questions.*.type,radio,checkbox|numeric|min:0',
            'sections.*.questions.*.options'       => 'required_if:sections.*.questions.*.type,radio,checkbox|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $form = EvaluationForm::first();
            if($form){
                // حذف البيانات السابقة المرتبطة بالنموذج
                foreach ($form->sections as $section) {
                    foreach ($section->questions as $question) {
                        $question->options()->delete();
                    }
                    $section->questions()->delete();
                }
                $form->sections()->delete();

                // create new
                foreach ($request->sections as $sectionData) {
                    $section = new EvaluationSection();
                    $section->form_id = $form->id;
                    $section->title = $sectionData['title'];
                    $section->save();

                    foreach ($sectionData['questions'] as $questionData) {
                        $question = new EvaluationQuestion();
                        $question->section_id    = $section->id;
                        $question->title         = $questionData['title'];
                        $question->type          = $questionData['type'];
                        $question->is_required   = $questionData['is_required'] ?? false;
                        $question->choices_count = $questionData['choices_count'];
                        $question->save();

                        if (in_array($question->type, ['radio', 'checkbox']) && isset($questionData['options'])) {
                            foreach ($questionData['options'] as $choiceTitle) {
                                $option = new EvalutionOption();
                                $option->question_id = $question->id;
                                $option->title = $choiceTitle;
                                $option->save();
                            }
                        }
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => __('trans.alert.success.done_update'),
                ]);
            }
            else{
                $form = new EvaluationForm();
                $form->status = $request->status;
                $form->save();

                foreach ($request->sections as $sectionData)
                {
                    $section = new EvaluationSection();
                    $section->form_id = $form->id;
                    $section->title = $sectionData['title'];
                    $section->save();

                    foreach ($sectionData['questions'] as $questionData)
                    {
                        $question = new EvaluationQuestion();
                        $question->section_id = $section->id;
                        $question->title = $questionData['title'];
                        $question->type = $questionData['type'];
                        $question->is_required = $questionData['is_required'] ?? false;
                        $question->choices_count = $questionData['choices_count'] ?? 0;
                        $question->save();

                        // إنشاء الاختيارات لو كانت موجودة
                        if (in_array($questionData['type'], ['radio', 'checkbox']) && isset($questionData['options']))
                        {
                            foreach ($questionData['options'] as $choiceTitle)
                            {
                                $option = new EvalutionOption();
                                $option->question_id = $question->id;
                                $option->title = $choiceTitle;
                                $option->save();
                            }
                        }
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => __('trans.alert.success.done_create'),
                ]);
            }


        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.done_create'),
                'error' => $e->getMessage()
            ], 500);
        }

    }

    public function assignFormToProgram(Request $request)
    {
        $validator = validator(request()->all(), [
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

       // آخر نسخة من الفورم اللي اتنشئت
        $form = EvaluationForm::with('sections.questions.options')->latest()->first();

        if (!$form) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        // خزّن نسخة snapshot من الفورم
        // $snapshot = json_encode($form->toArray());

        // اربط الفورم بالبرنامج مع حفظ snapshot
        $form->programs()->attach($request->program_id, [
            'assigned_at' => now(),
            // 'snapshot' => $snapshot,
        ]);

        // وقت العرض
        // $form->programs->each(function ($program) {
        //     $snapshot = json_decode($program->pivot->snapshot, true);
        // });

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.form_assigned_to_program'),
        ]);
    }

    public function view(Request $request)
    {
        $form = EvaluationForm::with([
            'sections.questions.options'
        ])->latest()->first();

        if(!$form){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        };

        return response()->json([
            'success' => true,
            'data' => $form
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $form = EvaluationForm::with([
            'sections.questions.options'
        ])->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data' => $form
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
    public function update(Request $request, $id)
    {
        $validator = validator(request()->all(), [
            'program_id'                           => 'required|exists:programs,id|unique:evaluation_forms,program_id,' . $id,
            'status'                               => 'boolean',
            'sections'                             => 'required|array',
            'sections.*.title'                     => 'required|string',
            'sections.*.questions'                 => 'required|array',
            'sections.*.questions.*.title'         => 'required|string',
            'sections.*.questions.*.type'          => 'required|in:text,radio,checkbox',
            'sections.*.questions.*.is_required'   => 'boolean',
            'sections.*.questions.*.choices_count' => 'required_if:sections.*.questions.*.type,radio,checkbox|numeric|min:0',
            'sections.*.questions.*.options'       => 'required_if:sections.*.questions.*.type,radio,checkbox|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $form = EvaluationForm::findOrFail($id);
            $form->program_id = $request->program_id;
            $form->status     = $request->status ?? true;
            $form->save();

            // حذف البيانات السابقة المرتبطة بالنموذج
            foreach ($form->sections as $section) {
                foreach ($section->questions as $question) {
                    // حذف الخيارات
                    $question->options()->delete();
                }
                // حذف الأسئلة
                $section->questions()->delete();
            }
            // حذف الأقسام
            $form->sections()->delete();

            // إنشاء الأقسام والأسئلة والخيارات الجديدة
            foreach ($request->sections as $sectionData) {
                $section = new EvaluationSection();
                $section->form_id = $form->id;
                $section->title = $sectionData['title'];
                $section->save();

                foreach ($sectionData['questions'] as $questionData) {
                    $question = new EvaluationQuestion();
                    $question->section_id    = $section->id;
                    $question->title         = $questionData['title'];
                    $question->type          = $questionData['type'];
                    $question->is_required   = $questionData['is_required'] ?? false;
                    $question->choices_count = $questionData['choices_count'];
                    $question->save();

                    if (in_array($question->type, ['radio', 'checkbox']) && isset($questionData['options'])) {
                        foreach ($questionData['options'] as $choiceTitle) {
                            $option = new EvalutionOption();
                            $option->question_id = $question->id;
                            $option->title = $choiceTitle;
                            $option->save();
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('trans.alert.success.done_update'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.done_update'),
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function delete_multi_response(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'responses_id' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allResponseIds = $request->input('responses_id', []);
        EvaluationResponse::whereIn('id', $allResponseIds)->delete();
        // Cache::forget('students');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }


    public function evaluation_form_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        $responses = EvaluationResponse::with(['student', 'program.translations', 'answers'])->get();

        // الترتيب حسب اسم البرنامج
        if ($filter === 'name') {
            $responses = $responses->sortBy(fn($review) => mb_strtolower($review['program_title']))->values();
        }

        // الفلترة
        if ($search) {
            $responses = $responses->filter(function ($review) use ($search) {
                return mb_stripos($review['program_title'], $search) !== false;
            })->values();
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header
        $header = ['Student Name', 'Student Email', 'program_name', 'Submitted At', 'Section', 'Question', 'Answer'];
        $sheet->fromArray($header, null, 'A1');

        $row = 2;
        $responseCount = $responses->count();

        foreach ($responses as $index => $response) {
            $studentName = $response->student?->name ?? '';
            $studentEmail= $response->student?->email ?? '';
            $programName = $response->program?->translations->firstWhere('locale', App()->getLocale())?->title ?? '';
            $submittedAt = $response->created_at?->format('Y-m-d H:i') ?? '';
            $answers = $response->answers;

            // decode snapshot (الهيكل وقت تقديم الطالب)
            $snapshot = json_decode($response->snapshot, true);
            if (!$snapshot || !isset($snapshot['sections'])) continue;

            foreach ($snapshot['sections'] as $section) {
                $sectionTitle = $section['title'] ?? '';

                foreach ($section['questions'] as $question) {
                    $questionId = $question['id'] ?? null;
                    $questionTitle = $question['title'] ?? '';
                    $questionType = $question['type'] ?? '';

                    // ابحث عن إجابة الطالب للسؤال ده
                    $answer = $answers->firstWhere('question_id', $questionId);
                    if (!$answer) continue;

                    // Decode answer
                    if (in_array($questionType, ['radio', 'text'])) {
                        $value = $answer->answer;
                    } elseif ($questionType === 'checkbox') {
                        $vals = json_decode($answer->answer, true);
                        $value = is_array($vals) ? implode(', ', $vals) : $answer->answer;
                    } else {
                        $value = $answer->answer;
                    }

                    $sheet->fromArray([
                        $studentName,
                        $studentEmail,
                        $programName,
                        $submittedAt,
                        $sectionTitle,
                        $questionTitle,
                        $value
                    ], null, 'A' . $row);

                    $row++;
                }
            }

            // صف فاصل بين الطلاب
            if ($index < $responseCount - 1) {
                $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFFF00');
                $row++;
            }
        }

        $fileName = 'evaluation_responses_' . date('Ymd_His') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save(public_path($fileName));

        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }


    public function download_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        // تحميل البيانات
        $responses = EvaluationResponse::with([
            'student',
            'form.program',
            'form.program.translations',
            'answers.question.section'
        ])->get();

        // تحويلها إلى مصفوفة قابلة للفلترة والفرز
        $responses = $responses->map(function ($response) {
            return [
                'form_title'     => $response->form?->program?->translations->firstWhere('locale', App()->getLocale())?->title ?? '',
                'student_name'   => $response->student?->name ?? '',
                'student_email'  => $response->student?->email ?? '',
                'created_at'     => $response->created_at,
                'answers'        => $response->answers
            ];
        });

        if ($search) {
            $responses = $responses->filter(function ($r) use ($search) {
                return mb_stripos($r['form_title'], $search) !== false;
            })->values();
        }

        if ($filter === 'name') {
            $responses = $responses->sortBy(fn($r) => mb_strtolower($r['form_title']))->values();
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header
        $header = ['Form Title', 'Student Name', 'Student Email', 'Submitted At', 'Section', 'Question', 'Answer'];
        $sheet->fromArray($header, null, 'A1');

        $row = 2;
        $responseCount = $responses->count();

        for ($i = 0; $i < $responseCount; $i++) {
            $response = $responses[$i];
            $formTitle = $response['form_title'];
            $studentName = $response['student_name'];
            $studentEmail = $response['student_email'];
            $submittedAt = $response['created_at'] ? $response['created_at']->format('Y-m-d H:i') : '';
            $answers = $response['answers'];

            foreach ($answers as $answer) {
                $question = $answer->question;
                $sectionTitle = $question->section->title ?? '';
                $questionTitle = $question->title ?? '';

                if (in_array($question->type, ['radio', 'text'])) {
                    $value = $answer->answer;
                } elseif ($question->type === 'checkbox') {
                    $vals = json_decode($answer->answer, true);
                    $value = is_array($vals) ? implode(', ', $vals) : $answer->answer;
                } else {
                    $value = $answer->answer;
                }

                $sheet->fromArray([
                    $formTitle,
                    $studentName,
                    $studentEmail,
                    $submittedAt,
                    $sectionTitle,
                    $questionTitle,
                    $value
                ], null, 'A' . $row);

                $row++;
            }

            // صف فاصل
            if ($i < $responseCount - 1) {
                $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFFF00'); // Yellow
                $row++;
            }
        }

        $fileName = 'filtered_evaluation_responses_' . date('Ymd_His') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save(public_path($fileName));

        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }

    public function download_excel1(Request $request)
    {
        $responses = EvaluationResponse::with([
            'student',
            'form.program',
            'form.program.translations',
            'answers.question.section'
        ])->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Build header
        $header = ['Form Title', 'Student Name', 'Student Email', 'Submitted At', 'Section', 'Question', 'Answer'];
        $sheet->fromArray($header, null, 'A1');

        $row = 2;
        $responseCount = $responses->count();

        for ($i = 0; $i < $responseCount; $i++) {
            $response = $responses[$i];
            $formTitle = $response->form->program->translations->firstWhere('locale', app()->getLocale())->title ?? '';
            $studentName = $response->student->name ?? '';
            $studentEmail = $response->student->email ?? '';
            $submittedAt = $response->created_at ? $response->created_at->format('Y-m-d H:i') : '';

            foreach ($response->answers as $answer) {
                $question = $answer->question;
                $sectionTitle = $question->section->title ?? '';
                $questionTitle = $question->title;

                // Decode answer based on type
                if (in_array($question->type, ['radio', 'text'])) {
                    $value = $answer->answer;
                } elseif ($question->type === 'checkbox') {
                    $vals = json_decode($answer->answer, true);
                    $value = is_array($vals) ? implode(', ', $vals) : $answer->answer;
                } else {
                    $value = $answer->answer;
                }

                $sheet->fromArray([
                    $formTitle,
                    $studentName,
                    $studentEmail,
                    $submittedAt,
                    $sectionTitle,
                    $questionTitle,
                    $value
                ], null, 'A' . $row);

                $row++;
            }

            if ($i < $responseCount - 1) {
                $sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFFF00'); // Yellow
                $row++;
            }
        }

        $fileName = 'all_evaluation_responses_' . date('Ymd_His') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save(public_path($fileName));

        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }

    public function download_excel2(Request $request)
    {
        $form_id = $request->input('form_id');
        $form = EvaluationForm::with(['sections.questions.options'])->findOrFail($form_id);
        $responses = \App\Models\EvaluationResponse::with(['student', 'answers.question'])->where('form_id', $form_id)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Build header: Section - Question
        $header = ['Student Name', 'Student Email', 'Submitted At'];
        $questions = [];
        foreach ($form->sections as $section) {
            foreach ($section->questions as $question) {
                $header[] = $section->title . ' - ' . $question->title;
                $questions[] = $question;
            }
        }
        $sheet->fromArray($header, null, 'A1');

        // Fill data
        $row = 2;
        foreach ($responses as $response) {
            $data = [
                $response->student->name ?? '',
                $response->student->email ?? '',
                $response->created_at ? $response->created_at->format('Y-m-d H:i') : '',
            ];
            foreach ($questions as $question) {
                $answer = $response->answers->firstWhere('question_id', $question->id);
                if (!$answer) {
                    $data[] = '';
                } else {
                    // Handle different types
                    if (in_array($question->type, ['radio', 'text'])) {
                        $data[] = $answer->answer;
                    } elseif ($question->type === 'checkbox') {
                        $vals = json_decode($answer->answer, true);
                        if (is_array($vals)) {
                            $data[] = implode(', ', $vals);
                        } else {
                            $data[] = $answer->answer;
                        }
                    } else {
                        $data[] = $answer->answer;
                    }
                }
            }
            $sheet->fromArray($data, null, 'A'.$row);
            $row++;
        }

        $fileName = 'evaluation_form_'.$form_id.'_responses_'.date('Ymd_His').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }
}
