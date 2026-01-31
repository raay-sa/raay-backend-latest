<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamQuestionOption;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $category_id = $request->category_id;

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $exams = $user->exams()
        ->with([
            'program' => function ($query) {
                $query->select('id','category_id')
                ->with('category:id', 'category.translations', 'translations')
                ->withCount('subscriptions');
            }
        ])
        ->select('exams.id', 'exams.title', 'exams.duration', 'exams.program_id')
        ->withCount('questions')
        // ->orderByDesc('id')
        ->orderByDesc('exams.id')
        ->get()
        ->each(function ($exam) {
            if ($exam->program) {
                $exam->program->makeHidden(['teacher']);
                $exam->makeHidden(['answers']);
            }

            $exam->students_answered_count = $exam->answers->pluck('student_id')->unique()->count();
        });

        if ($filter === 'latest') {
            $exams = $exams->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $exams = $exams->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $exams = $exams->sortBy(fn($exam) => mb_strtolower($exam->title))->values(); // ✅ title بدل name
        }

        if ($category_id) {
            $exams = $exams->filter(function ($exam) use ($category_id) {
                return $exam->program && $exam->program->category_id == $category_id;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($exams, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function program_exams(Request $request, string $id)
    {
        $exams = Exam::where('program_id', $id)
        ->withCount('questions')
        ->orderBy('id', 'desc')
        ->get();

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($exams, $perPage, $currentPage);

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
        $validator = Validator::make($request->all(), [
            'title'                => 'required|string',
            'description'          => 'nullable|string',
            'tries_count'          => 'required|integer',
            'success_rate'         => 'required|integer',
            'duration'             => 'required|string',
            'user_type'            => 'required|in:student,trainee',
            'program_id'           => 'required|exists:programs,id',

            'questions'            => 'required|array',
            'questions.*.question' => 'required|string',
            'questions.*.answer'   => 'required|string',
            'questions.*.type'     => 'required|in:string,multiple_choice',
            'questions.*.points'   => 'required|numeric',
            'questions.*.sort'     => 'nullable|numeric',
            'questions.*.image'    => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'questions.*.file'     => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:2048',

            'questions.*.options'              => 'required_if:questions.*.type,multiple_choice|array',
            'questions.*.options.*.option'     => 'required|string',
            'questions.*.options.*.is_correct' => 'required|in:0,1',
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
            $exam = new Exam();
            $exam->title        = $request->title;
            $exam->duration     = $request->duration;
            $exam->tries_count  = $request->tries_count;
            $exam->success_rate = $request->success_rate;
            $exam->description  = $request->description;
            $exam->user_type    = $request->user_type;
            $exam->program_id   = $request->program_id;
            $exam->save();

            foreach ($request->questions as $questionData)
            {
                $question           = new ExamQuestion();
                $lastSort           = ExamQuestion::where('exam_id', $exam->id)->max('sort');
                $question->sort     = $lastSort ? $lastSort + 1 : 1;
                $question->question = $questionData['question'];
                $question->answer   = $questionData['answer'];
                $question->type     = $questionData['type'];
                $question->points   = $questionData['points'];
                $question->exam_id  = $exam->id;
                $question->save();

                $image = null;
                if ($questionData['image']) {
                    $file = $questionData['image'];
                    // $file = $request->file('image');
                    $fileName = 'image_' . time() . '_' . uniqid() . '.' . $file->extension();
                    $path = 'uploads/exams/exam_id_'.$question->id;

                    $fullPath = public_path($path);
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }
                    $file->move($fullPath, $fileName);
                    $image = $path . '/' . $fileName;
                    $question->image = $image;
                    $question->save();
                }

                $file = null;
                if ($questionData['file']) {
                    $file = $questionData['file'];
                    // $file = $request->file('file');
                    $fileName = 'file_' . time() . '_' . uniqid() . '.' . $file->extension();
                    $path = 'uploads/exams/exam_id_'.$question->id;

                    $fullPath = public_path($path);
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }
                    $file->move($fullPath, $fileName);
                    $file = $path . '/' . $fileName;
                    $question->file = $file;
                    $question->save();
                }

                if ($questionData['type'] === 'multiple_choice' && isset($questionData['options'])) {
                    foreach ($questionData['options'] as $optionData) {
                        $option = new ExamQuestionOption();
                        $option->question_id = $question->id;
                        $option->option = $optionData['option'];
                        $option->is_correct = $optionData['is_correct'];
                        $option->save();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('trans.alert.success.done_create'),
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

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // $exam = Exam::with('questions.options')->withCount('questions')->findOrFail($id);
        $exam = Exam::with([
            'program' => function ($query) {
                $query->select('id', 'category_id')
                ->with('category:id', 'category.translations', 'translations')
                ->withCount('subscriptions');
            },
            'questions.options'
        ])
        // ->select('id', 'title', 'duration', 'success_rate', 'program_id')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        // $exam->program->makeHidden(['category', 'teacher']);
        // $exam->makeHidden(['answers']);
        $exam->students_answered_count = $exam->answers->pluck('student_id')->unique()->count();

        return response()->json(['success' => true, 'data' => $exam]);
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
        $validator = Validator::make($request->all(), [
            'title'                            => 'required|string',
            'description'                      => 'nullable|string',
            'tries_count'                      => 'required|integer',
            'success_rate'                     => 'required|integer',
            'duration'                         => 'required|string',
            'user_type'                        => 'required|in:student,trainee',
            'program_id'                       => 'required|exists:programs,id',

            'questions'                        => 'required|array',
            'questions.*.question'             => 'required|string',
            'questions.*.answer'               => 'required|string',
            'questions.*.type'                 => 'required|in:string,multiple_choice',
            'questions.*.points'               => 'required|numeric',
            'questions.*.sort'                 => 'nullable|numeric',
            'questions.*.image'                => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'questions.*.file'                 => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:2048',

            'questions.*.options'              => 'required_if:questions.*.type,multiple_choice|array',
            'questions.*.options.*.option'     => 'required|string',
            'questions.*.options.*.is_correct' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $exam = Exam::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        DB::beginTransaction();

        try {
            $exam->title        = $request->title;
            $exam->duration     = $request->duration;
            $exam->tries_count  = $request->tries_count;
            $exam->success_rate = $request->success_rate;
            $exam->description  = $request->description;
            $exam->user_type    = $request->user_type;
            $exam->program_id   = $request->program_id;
            $exam->save();

            // حذف البيانات السابقة المرتبطة بالنموذج
            foreach ($exam->questions as $question) {
                foreach ($question->options as $option) {
                    $option->delete();
                }
                if($question->image) {
                    $path = public_path($question->image);
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }

                if($question->file) {
                    $path = public_path($question->file);
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
                $question->delete();
            }

            // create new
            foreach ($request->questions as $questionData)
            {
                $question           = new ExamQuestion();
                $lastSort           = ExamQuestion::where('exam_id', $exam->id)->max('sort');
                $question->sort     = $lastSort ? $lastSort + 1 : 1;
                $question->question = $questionData['question'];
                $question->answer   = $questionData['answer'];
                $question->type     = $questionData['type'];
                $question->points   = $questionData['points'];
                $question->exam_id  = $exam->id;
                $question->save();

                $image = null;
                if (isset($questionData['image']) && $questionData['image'] instanceof \Illuminate\Http\UploadedFile) {
                    $file = $questionData['image'];
                    $fileName = 'image_' . time() . '_' . uniqid() . '.' . $file->extension();
                    $path = 'uploads/exams/exam_id_'.$question->id;

                    $fullPath = public_path($path);
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }
                    $file->move($fullPath, $fileName);
                    $image = $path . '/' . $fileName;
                    $question->image = $image;
                    $question->save();
                }

                $file = null;
                if (isset($questionData['file']) && $questionData['file'] instanceof \Illuminate\Http\UploadedFile) {
                    $file = $questionData['file'];
                    $fileName = 'file_' . time() . '_' . uniqid() . '.' . $file->extension();
                    $path = 'uploads/exams/exam_id_'.$question->id;

                    $fullPath = public_path($path);
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }
                    $file->move($fullPath, $fileName);
                    $file = $path . '/' . $fileName;
                    $question->file = $file;
                    $question->save();
                }

                if ($questionData['type'] === 'multiple_choice' && isset($questionData['options'])) {
                    foreach ($questionData['options'] as $optionData) {
                        $option = new ExamQuestionOption();
                        $option->question_id = $question->id;
                        $option->option = $optionData['option'];
                        $option->is_correct = $optionData['is_correct'];
                        $option->save();
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
        $exam = Exam::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $questions = ExamQuestion::where('exam_id', $exam->id)->get();

        foreach ($questions as $question) {
            if ($question->image) {
                $path = public_path($question->image);
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            if ($question->file) {
                $path = public_path($question->file);
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            $question->delete();
        }

        deleteFromCache('program_' . $exam->program_id . '_exams', $exam);
        $exam->delete();

        return response()->json(['success' => true, 'message' => __('trans.alert.success.done_delete')], 201);
    }
}
