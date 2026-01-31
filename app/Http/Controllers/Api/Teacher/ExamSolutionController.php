<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ExamAnswer;
use App\Models\ExamStudent;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExamSolutionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $category_id = $request->category_id;
        $search = $request->search;

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $exams = $user->exams()
            ->with([
                'program.category:id', 'program.category.translations',
                'program' => function ($query) {
                    $query->select('id', 'category_id')
                        ->with('translations')
                        ->withCount('subscriptions');
                }
            ])
            ->select('exams.id', 'exams.title', 'exams.duration', 'exams.program_id', 'exams.tries_count')
            ->withCount('questions')
            ->orderByDesc('exams.id')
            ->get()
            ->map(function ($exam) {
                if ($exam->program) {
                    $exam->program->makeHidden(['teacher']);
                    $exam->makeHidden(['answers']);
                }

                // عدد الطلاب الي جاوبوا
                $exam->students_answered_count = ExamStudent::where('exam_id', $exam->id)
                    ->distinct('student_id')
                    ->count('student_id');

                // آخر محاولة (لكل طالب بشكل فردي) "أحدث درجة لكل طالب"
                $latestAttempts = ExamStudent::where('exam_id', $exam->id)
                ->with(['exam:id,title,program_id' , 'exam.program:id',
                'exam.program.translations', 'student:id,name'])
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->unique('student_id');

                $exam->last_grades = $latestAttempts->pluck('grade', 'student_id'); // [student_id => grade]

                // حساب عدد المحاولات المتبقية (لكل طالب هيبقى مختلف max/min)
                $totalTries = $exam->tries_count;
                $exam->students_remaining_tries = $latestAttempts->mapWithKeys(function ($attempt) use ($totalTries) {
                    $used = ExamStudent::where('exam_id', $attempt->exam_id)
                        ->where('student_id', $attempt->student_id)
                        ->count();
                    return [$attempt->student_id => max($totalTries - $used, 0)];
                });

                return $exam;
            });

        $exam_solutions = ExamStudent::whereIn('exam_id', $exams->pluck('id'))
        ->with(['exam:id,title,program_id', 'exam.program:id', 'exam.program.translations', 'student:id,name'])
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($solution) {
            $status = 'not_solved';

            if (!is_null($solution->grade)) {
                if ($solution->grade > 0) {
                    $status = 'marked';
                } else {
                    $status = 'not_marked';
                }
            } elseif (is_null($solution->grade)) {
                $status = 'not_marked';
            }

            $solution->status = $status;
            return $solution;
        })
        ->unique('student_id');

        $total_exams = $exams->count();
        $completed_exams = $exams->filter(function ($exam) {
            return $exam->program &&
                $exam->program->subscriptions_count == $exam->students_answered_count;
        })->count();
        $not_completed_exams = $exams->filter(function ($exam) {
            return $exam->program &&
                $exam->program->subscriptions_count != $exam->students_answered_count;
        })->count();


        // الشهر الحالي
        $current_month_exams = $exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->month &&
                $e->created_at->year == now()->year;
        })->count();

        // الشهر الماضي
        $last_month_exams = $exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->subMonth()->month &&
                $e->created_at->year == now()->subMonth()->year;
        })->count();
        // إجمالي الاختبارات للشهر الحالي
        $current_month_total = $current_month_exams;

        // إجمالي الاختبارات للشهر الماضي
        $last_month_total = $last_month_exams;

        // النسبة
        $total_percentage_change = $last_month_total > 0
            ? round((($current_month_total - $last_month_total) / $last_month_total) * 100, 2)
            : 100;


        // إجمالي المكتملين للشهر الحالي
        $current_month_completed = $exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->month &&
                $e->created_at->year == now()->year &&
                $e->program &&
                $e->program->subscriptions_count == $e->students_answered_count;
            })->count();

        // إجمالي المكتملين للشهر الماضي
        $last_month_completed = $exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->subMonth()->month &&
                $e->created_at->year == now()->subMonth()->year &&
                $e->program &&
                $e->program->subscriptions_count == $e->students_answered_count;
            })->count();

        // نسبة المكتملين
        $completed_percentage_change = $last_month_completed > 0
            ? round((($current_month_completed - $last_month_completed) / $last_month_completed) * 100, 2)
            : 100;


        // لغير المكتملين
        $current_month_uncompleted = $current_month_total - $current_month_completed;
        $last_month_uncompleted = $last_month_total - $last_month_completed;

        $uncompleted_percentage_change = $last_month_uncompleted > 0
            ? round((($current_month_uncompleted - $last_month_uncompleted) / $last_month_uncompleted) * 100, 2)
            : 100;

        if ($filter === 'latest') {
            $exams = $exams->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $exams = $exams->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $exams = $exams->sortBy(fn($exam) => mb_strtolower($exam->name))->values();
        }

        if ($category_id) {
            $exams = $exams->filter(function ($exam) use ($category_id) {
                return $category_id == $exam->program->category_id;
            })->values();
        }

        if ($filter === 'latest') {
            $exam_solutions = $exam_solutions->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $exam_solutions = $exam_solutions->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $exam_solutions = $exam_solutions->sortBy(fn($solution) => mb_strtolower($solution->student->name))->values();
        } elseif ($filter === 'evaluated') {
            $exam_solutions = $exam_solutions->filter(fn($solution) => $solution->grade != null)->values();
        } elseif ($filter === 'not_evaluated') {
            $exam_solutions = $exam_solutions->filter(fn($solution) => $solution->grade == null)->values();
        }

        if ($category_id) {
            $exams = $exams->filter(function ($assignment) use ($category_id) {
                return $category_id == $assignment->program->category_id;
            })->values();

            $exam_solutions = $exam_solutions
            ->whereIn('exam_id', $exams->pluck('id'))
            ->get();
        }

        if($search){
            $exam_solutions = $exam_solutions->filter(function ($solution) use ($search) {
                return mb_stripos($solution->student->name, $search) !== false ||
                mb_stripos($solution->exam->program->title, $search) !== false ||
                mb_stripos($solution->exam->title, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($exam_solutions, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_count' => $total_exams,
            'exams_percentage' => abs($total_percentage_change),
            'exams_status' => $total_percentage_change >= 0 ? 'increase' : 'decrease',

            'completed_count' => $completed_exams,
            'completed_percentage' => abs($completed_percentage_change),
            'completed_status' => $completed_percentage_change >= 0 ? 'increase' : 'decrease',

            'uncompleted_count' => $not_completed_exams,
            'uncompleted_percentage' => abs($uncompleted_percentage_change),
            'uncompleted_status' => $uncompleted_percentage_change >= 0 ? 'increase' : 'decrease',

            'data' => $paginated,
        ]);
    }

    public function show(string $id)
    {
        $solution = ExamStudent::with([
            'exam',
            'exam.questions.options',
            'exam.program.category:id', 'exam.program.category.translations',
            'exam.program' => function ($query) {
                $query->select('id','category_id')
                    ->with('translations')
                    ->withCount('subscriptions');
            },
            'student:id,name',
            'answers'
        ])
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data' => $solution
        ]);
    }

    public function update2(Request $request, $id)
    {
        $validated = $request->validate([
            'questions'         => 'required|numeric|array',
            'questions.*.point' => 'required|numeric',
        ]);

        $exam_student = ExamStudent::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        foreach ($validated['questions'] as $question) {
            $answer = ExamAnswer::where('exam_student_id', $exam_student->id)
                ->where('exam_question_id', $question['id'])
                ->first();

            $answer->point = $question['point'];
        }
        $answer->save();


        // grade
        $totalPoints = $exam_student->answers->sum('point');
        $totalQuestions = $exam_student->exam->questions->count();
        $exam_student->grade = $totalQuestions > 0 ? ($totalPoints / $totalQuestions) * 100 : 0;

        return response()->json([
            'success' => true,
            'data' => $exam_student
        ]);
    }

    public function update3(Request $request, $id)
    {
        $validated = $request->validate([
            'questions'              => 'required|array',
            'questions.*.id'         => 'required|exists:exam_questions,id',
            'questions.*.points'      => 'nullable|numeric',
        ]);

        $exam_student = ExamStudent::with(['exam.questions', 'answers'])->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $totalPoints = 0;
        $maxPoints   = $exam_student->exam->questions->sum('points');

        foreach ($validated['questions'] as $questionInput) {
            $question = $exam_student->exam->questions->firstWhere('id', $questionInput['id']);
            $answer   = $exam_student->answers->firstWhere('question_id', $question->id);

            if (!$answer) continue;

            if ($question->type === 'multiple_choice') {
                $correctOption = $answer->is_correct;
                if($correctOption){
                    $answer->points = $question->points;
                } else{
                    $answer->points = 0;
                }
            } elseif ($question->type === 'string') {
                // مقالي - المدرس يحدد الدرجة
                $givenPoint = $questionInput['points'] ?? 0;
                if ($givenPoint > $question->points) {
                    $givenPoint = $question->points;
                }
                $answer->points = $givenPoint;
            }

            $answer->save();
            $totalPoints += $answer->points;
        }

        $exam_student->grade = $maxPoints > 0 ? ($totalPoints / $maxPoints) * 100 : 0;
        $exam_student->save();

        return response()->json([
            'success' => true,
            'data' => [
                'total_points' => $totalPoints,
                'max_points'   => $maxPoints,
                'grade'        => $exam_student->grade,
                'exam_student' => $exam_student->load('answers'),
            ]
        ]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'questions'              => 'required|array',
            'questions.*.id'         => 'required|exists:exam_questions,id',
            'questions.*.points'     => 'required|numeric',
        ]);

        $exam_student = ExamStudent::with(['exam.questions', 'answers'])->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $totalPoints = 0;
        $maxPoints   = $exam_student->exam->questions->sum('points');

        foreach ($exam_student->exam->questions as $question) {
            $answer   = $exam_student->answers->firstWhere('question_id', $question->id);
            if (!$answer) continue;

            $questionInput = collect($request->questions)->firstWhere('id', $question->id);

            if ($question->type === 'multiple_choice') {
                // اختياري - الدرجة حسب صحة الإجابة
                $correctOption = $answer->is_correct;
                $answer->points = $correctOption ? $question->points : 0;
            } elseif ($question->type === 'string') {
                // مقالي - لازم يدخل درجة
                if (!$questionInput || !isset($questionInput['points'])) {
                    return response()->json([
                        'success' => false,
                        'message' => "يجب إدخال درجة للسؤال المقالي رقم {$question->id}"
                    ], 422);
                }

                $givenPoint = $questionInput['points'];
                if ($givenPoint > $question->points) {
                    $givenPoint = $question->points;
                }
                $answer->points = $givenPoint;
            }

            $answer->save();
            $totalPoints += $answer->points;
        }

        $exam_student->grade = $maxPoints > 0 ? ($totalPoints / $maxPoints) * 100 : 0;
        $exam_student->save();

        return response()->json([
            'success' => true,
            'data' => [
                'total_points' => $totalPoints,
                'max_points'   => $maxPoints,
                'grade'        => $exam_student->grade,
                'exam_student' => $exam_student->load('answers'),
            ]
        ]);
    }


}
