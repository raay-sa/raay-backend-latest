<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\EvaluationAnswer;
use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\EvalutionProgram;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EvaluationFormController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
            'program_slug'          => 'required|exists:programs,slug',
            'answers'               => 'required|array',
            'answers.*.question_id' => 'required|exists:evaluation_questions,id',
            'answers.*.answer'      => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $program = Program::where('slug', $request->program_slug)->first();
        if(!$program){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 404);
        }

        $program_form = EvalutionProgram::where('program_id', $program->id)->latest()->first();
        if(!$program_form){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 404);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $program->id)
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

        // التحقق من وجود إجابات سابقة لهذا الطالب على هذا النموذج
        $alreadySubmitted = EvaluationResponse::where('program_id', $program->id)
        ->where('student_id', $user->id)
        ->exists();

        if ($alreadySubmitted) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.evaluated_form_before'),
            ], 409); // 409 Conflict
        }

        DB::beginTransaction();

        try {
            $form = EvaluationForm::with('sections.questions.options')->first();
            if (!$form) {
                return response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.data_not_exist'),
                ], 422);
            }

            $response = new EvaluationResponse();
            $response->snapshot    = json_encode($form->toArray());
            $response->form_id     = $form->id;
            $response->program_id  = $program->id;
            $response->student_id  = $user->id;
            $response->save();

            foreach ($request->answers as $answerData) {
                // لو الإجابة checkbox لازم نحولها لـ JSON
                $answerValue = is_array($answerData['answer'])
                    ? json_encode($answerData['answer'])
                    : $answerData['answer'];

                $answer = new EvaluationAnswer();
                $answer->answer      = $answerValue;
                $answer->question_id = $answerData['question_id'];
                $answer->response_id    = $response->id;
                $answer->save();
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
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $slug)
    {
        $program = Program::where('slug', $slug)->first();
        if(!$program){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 404);
        }

        $program_form = EvalutionProgram::where('program_id', $program->id)->latest()->first();
        if(!$program_form){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 404);
        }

        $user = $request->user();
        // Ensure it's a teacher model
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $program->id)
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

        $form = EvaluationForm::with([
            'sections.questions.options'
        ])->first();

        if (!$form) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 404);
        }

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
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
