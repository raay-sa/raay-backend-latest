<?php

namespace App\Http\Controllers\Api\Student;

use App\Events\ExamSolutionEvent;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\ExamQuestionOption;
use App\Models\ExamStudent;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $programs_id = Subscription::where('student_id', $user->id)->pluck('program_id');

        //  آخر محاولة للطالب في كل امتحان
        $latestAttempts = ExamStudent::select('exam_id', 'grade')
            ->where('student_id', $user->id)
            ->whereIn('exam_id', function ($query) use ($programs_id) {
                $query->select('id')->from('exams')->whereIn('program_id', $programs_id);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('exam_id')
            ->keyBy('exam_id');

        $exams = Exam::whereIn('program_id', $programs_id)
            ->where('user_type', $user->type)
            ->withCount('questions')
            ->get()
            ->map(function ($exam) use ($user, $latestAttempts) {
                $allAttempts = $exam->tries_count;
                $usedAttempts = ExamStudent::where('student_id', $user->id)
                    ->where('exam_id', $exam->id)
                    ->count();

                $exam->remaining_tries = max($allAttempts - $usedAttempts, 0);
                $exam->last_grade = $latestAttempts[$exam->id]->grade ?? null;

                return $exam;
            });

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($exams, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function program_exams(Request $request, string $program_id)
    {
        $program = Program::findOr($program_id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

         // آخر محاولة للطالب في كل امتحان
        $latestAttempts = ExamStudent::select('exam_id', 'grade')
            ->where('student_id', $user->id)
            ->whereIn('exam_id', function ($query) use ($program_id) {
                $query->select('id')->from('exams')->where('program_id', $program_id);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('exam_id')
            ->keyBy('exam_id');

        $exams = Exam::where('program_id', $program->id)
            ->where('user_type', $user->type)
            ->withCount('questions')
            ->get()
            ->map(function ($exam) use ($user, $latestAttempts) {
                $allAttempts = $exam->tries_count;
                $usedAttempts = ExamStudent::where('student_id', $user->id)
                    ->where('exam_id', $exam->id)
                    ->count();

                $exam->remaining_tries = max($allAttempts - $usedAttempts, 0);
                $exam->last_grade = $latestAttempts[$exam->id]->grade ?? null;

                return $exam;
            });

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($exams, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_id'               => 'required|exists:exams,id',
            'answers'               => 'required|array|min:1',
            'answers.*.question_id' => 'required|exists:exam_questions,id',
            'answers.*.option_id'   => 'nullable|exists:exam_question_options,id',
            'answers.*.text_answer' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $exam = Exam::withCount('questions')->find($request->exam_id);

        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $exam->program_id)
            ->first();

        if (!$subscription) {
            return response()->json([
                'error' => __('trans.alert.error.Student_not_subscribed_to_this_program')
            ], 422);
        }

        if (($subscription->expire_date && $subscription->expire_date->isPast()) || $subscription->status === 'banned')
        {
            return response()->json([
                'error' => __('trans.alert.error.subscription_has_expired')
            ], 422);
        }

        $allAttempts = $exam->tries_count;
        $usedAttempts = ExamStudent::where('student_id', $user->id)
            ->where('exam_id', $request->exam_id)
            ->count();
        $remaining_tries = $allAttempts - $usedAttempts;
        if($remaining_tries == 0){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.remaining_tries')
            ], 422);
        }

        $examStudent = new ExamStudent();
        $examStudent->student_id = $user->id;
        $examStudent->exam_id    = $request->exam_id;
        $examStudent->save();

        $totalGrade = 0;
        $totalCorrectAnswer = 0;
        $string_questions = 0;
        $answersData = [];

        foreach ($request->answers as $ans) {
            $question = ExamQuestion::find($ans['question_id']);
            $option = $ans['option_id'] ? ExamQuestionOption::find($ans['option_id']) : null;

            if ($question->type === 'string') {
                // سؤال نصي: make is_correct = null
                $string_questions++;
                $isCorrect = null;
                $grade = null; // مش بيتحسب غير بعد مراجعة المدرس
                $answer_points = null;
            } else {
                // سؤال اختيار من متعدد
                $isCorrect = $option ? (bool) $option->is_correct : false;
                $points = $question && isset($question->points) ? $question->points : 1;
                $grade = $isCorrect ? $points : 0;
                $answer_points = $isCorrect ? $points : 0;
            }

            // تحديث الإجمالي
            if ($isCorrect === true) {
                $totalCorrectAnswer++;
                $totalGrade += $grade;
            }

            $answer = new ExamAnswer();
            $answer->exam_student_id = $examStudent->id;
            $answer->exam_id         = $request->exam_id;
            $answer->question_id     = $ans['question_id'];
            $answer->option_id       = $ans['option_id'];
            $answer->text_answer     = $ans['text_answer'] ?? null;
            $answer->is_correct      = $isCorrect;
            $answer->points          = $answer_points;
            $answer->save();

            $answersData[] = [
                'answer_id' => $answer->id,
                'question_id' => $ans['question_id'],
                'option_id' => $ans['option_id'],
                'text_answer' => $ans['text_answer'] ?? null,
                'is_correct' => $isCorrect,
            ];
        }

        $totalPoints= $exam->questions->sum('points');
        $percentage = $totalPoints > 0 ? ($totalGrade / $totalPoints) * 100 : 0;
        $percentage = round($percentage);
        $examStudent->grade = $string_questions > 0 ? null : $percentage;
        $examStudent->save();

        $teacher = Program::find($exam->program_id)->teacher;
        $teacherSetting = $teacher->notification_setting()->first();
        if($teacherSetting && $teacherSetting->receiving_review_noti == 1){
            event(new ExamSolutionEvent($exam, $user, $teacher->id));
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data' => [
                'exam_success_rate' => $exam->success_rate,
                // 'student_grade_percentage' => $percentage,
                'student_grade_percentage' => $examStudent->grade,
                'answers' => $answersData,
            ]
        ]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $exam = Exam::withCount('questions')
        ->with('questions.options')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ]));
        });

        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $exam->program_id)
            ->first();

        if (!$subscription) {
            return response()->json([
                'error' => __('trans.alert.error.Student_not_subscribed_to_this_program')
            ], 422);
        }

        if (($subscription->expire_date && $subscription->expire_date->isPast()) || $subscription->status === 'banned')
        {
            return response()->json([
                'error' => __('trans.alert.error.subscription_has_expired')
            ], 422);
        }

        //  آخر محاولة للطالب في كل امتحان
        $latestAttempts = ExamStudent::select('exam_id', 'grade')
            ->where('student_id', $user->id)
            ->where('exam_id', $exam->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('exam_id')
            ->keyBy('exam_id');

        $allAttempts = $exam->tries_count;
        $usedAttempts = ExamStudent::where('student_id', $user->id)
            ->where('exam_id', $exam->id)
            ->count();

        $exam->remaining_tries = max($allAttempts - $usedAttempts, 0);
        $exam->last_grade = $latestAttempts[$exam->id]->grade ?? null;

        return response()->json(['success' => true, 'data' => $exam]);
    }

    public function solution(Request $request, $id)
    {
        $user = $request->user();

        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $exam = Exam::withCount('questions')
            ->with('questions.options')
            ->findOr($id, function () {
                abort(response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.data_not_exist'),
                ]));
            });

        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $exam->program_id)
            ->first();

        if (!$subscription) {
            return response()->json([
                'error' => __('trans.alert.error.Student_not_subscribed_to_this_program')
            ], 422);
        }

        if (($subscription->expire_date && $subscription->expire_date->isPast()) || $subscription->status === 'banned')
        {
            return response()->json([
                'error' => __('trans.alert.error.subscription_has_expired')
            ], 422);
        }

        // آخر محاولة للطالب في الامتحان
        $latestAttempt = ExamStudent::where('student_id', $user->id)
            ->where('exam_id', $exam->id)
            ->orderBy('created_at', 'desc')
            ->first();

        // إجابات الطالب في آخر محاولة
        $studentAnswers = [];
        if ($latestAttempt) {
            $studentAnswers = ExamAnswer::where('exam_id', $exam->id)
                ->where('exam_student_id', $latestAttempt->id)
                ->get();
        }

        // عدد المحاولات
        $allAttempts = $exam->tries_count;
        $usedAttempts = ExamStudent::where('student_id', $user->id)
            ->where('exam_id', $exam->id)
            ->count();

        $exam->remaining_tries = max($allAttempts - $usedAttempts, 0);
        $exam->last_grade = $latestAttempt->grade ?? null;
        $exam->student_answers = $studentAnswers;

        return response()->json([
            'success' => true,
            'data' => $exam
        ]);
    }

}
