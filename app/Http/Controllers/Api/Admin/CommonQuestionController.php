<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommonQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class CommonQuestionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $users_type = $request->users_type;

        $common_questions = getDataFromCache('common_questions', CommonQuestion::class);

        if ($filter === 'latest') {
            $common_questions = $common_questions->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $common_questions = $common_questions->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $common_questions = $common_questions->sortBy(fn($que) => mb_strtolower($que->question))->values();
        }

        if($users_type){
            $common_questions = $common_questions->where(fn($que) => $que->user_type == $users_type)->values();
        }

        if ($search) {
            $common_questions = $common_questions->filter(function ($que) use ($search) {
                return $que->question && mb_stripos($que->question, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($common_questions, $perPage, $currentPage);

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
            'question' => 'required|string',
            'answer' => 'required|string',
            'user_type' => 'required|in:student,teacher',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = new CommonQuestion();
        $row->question = request()->question;
        $row->answer = request()->answer;
        $row->user_type = request()->user_type;
        $row->save();

        // save in cache (helper method)
        storeInCache('common_questions', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data' => $row
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $row = CommonQuestion::findOr($id, function () {
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
        $validator = validator(request()->all(), [
            'question' => 'required|string',
            'answer' => 'required|string',
            'user_type' => 'required|in:student,teacher',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = CommonQuestion::find($id);
        $row->question = request()->question;
        $row->answer = request()->answer;
        $row->user_type = request()->user_type;
        $row->save();

        // save in cache (helper method)
        updateInCache('common_questions', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $common_questions = getDataFromCache('common_questions', CommonQuestion::class);
        $row = $common_questions->firstWhere('id', $id);

        if(!$row) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $row->delete();

        // delete from cache (helper method)
        deleteFromCache('common_questions', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

    public function multi_delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'questions_id' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allQuestionIds = $request->input('questions_id', []);
        CommonQuestion::whereIn('id', $allQuestionIds)->delete();
        Cache::forget('common_questions');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

    public function multi_hide(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'questions_id' => 'required|array',
            'questions_id.*' => 'integer|exists:common_questions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allQuestionIds = $request->input('questions_id', []);
        $questions = CommonQuestion::whereIn('id', $allQuestionIds)->get();

        foreach ($questions as $que) {
            $que->status = !$que->status;
            $que->save();
        }

        Cache::forget('common_questions');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }

}
