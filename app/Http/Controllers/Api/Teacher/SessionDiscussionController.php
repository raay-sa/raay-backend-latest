<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\SessionDiscussion;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SessionDiscussionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
       //
    }

    public function program_discussions(Request $request, $id)
    {
        $messages = SessionDiscussion::where('program_id', $id)->get()->toArray();

        $items = [];
        foreach ($messages as $msg) {
            $msg['replies'] = [];
            $items[$msg['id']] = $msg;
        }

        $tree = [];
        foreach ($items as $id => &$item) {
            if ($item['parent_id']) {
                $items[$item['parent_id']]['replies'][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }
        unset($item);

        return response()->json([
            'success' => true,
            'data' => $tree
        ]);
    }

    public function session_discussions(Request $request, $id)
    {
        $messages = SessionDiscussion::where('session_id', $id)->get()->toArray();

        $items = [];
        foreach ($messages as $msg) {
            $msg['replies'] = [];
            $items[$msg['id']] = $msg;
        }

        $tree = [];
        foreach ($items as $id => &$item) {
            if ($item['parent_id']) {
                $items[$item['parent_id']]['replies'][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }
        unset($item);

        return response()->json([
            'success' => true,
            'data' => $tree
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
            'title'       => 'required|string',
            'description' => 'nullable|string',
            'parent_id'   => 'required|exists:session_discussions,id',
            'program_id'  => 'required|exists:programs,id',
            'session_id'  => 'required|exists:program_sessions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $ownsProgram = Program::where('id', $request->program_id)
        ->where('teacher_id', $user->id)
        ->exists();

        if (!$ownsProgram) {
            return response()->json(['error' => __('trans.alert.error.program_not_belong_to_this_teacher')], 422);
        }

        $row = new SessionDiscussion();
        $row->title       = $request->title;
        $row->description = $request->description;
        $row->sender_id   = $user->id;
        $row->sender_type = 'teacher';
        $row->parent_id  = $request->parent_id;
        $row->program_id  = $request->program_id;
        $row->session_id  = $request->session_id;
        $row->save();

        // save in cache
        storeInCache('program'. $request->program_id .'_discussions', SessionDiscussion::class);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data' => $row,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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
