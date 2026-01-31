<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\SessionDiscussion;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $messages = SessionDiscussion::where('program_id', $id)
        ->with([
            'student:id,name',
            'teacher:id,name'
        ])->get()
        ->map(function ($msg) {
            // نحدد الـ sender بناءً على النوع
            $msg->sender = $msg->sender_type === 'student'
                ? $msg->student
                : $msg->teacher;

            unset($msg->student, $msg->teacher); // عشان ما نكرر البيانات
            return $msg;
        })
        ->toArray();

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

        // عدد الرسائل/الأسئلة اللي مالهاش أي ردود
        $countWithoutReplies = collect($tree)->filter(function ($msg) {
            return empty($msg['replies']);
        })->count();

        $perPage = (int) $request->input('per_page', 6);
        $currentPage = (int) $request->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;

        $paginated = new LengthAwarePaginator(
            array_slice($tree, $offset, $perPage),// البيانات اللي بتظهر
            count($tree),                         // العدد الكلي
            $perPage,                             // في كل صفحة كام
            $currentPage,                         // الصفحة الحالية
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function session_discussions(Request $request, $id)
    {
        $messages = SessionDiscussion::where('session_id', $id)
            ->with([
                'student:id,name',
                'teacher:id,name'
            ])->get()
            ->map(function ($msg) {
                $msg->sender = $msg->sender_type === 'student'
                    ? $msg->student
                    : $msg->teacher;

                unset($msg->student, $msg->teacher);
                return $msg;
            })
            ->toArray();

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

        // عدد الرسائل/الأسئلة اللي مالهاش أي ردود
        $countWithoutReplies = collect($tree)->filter(function ($msg) {
            return empty($msg['replies']);
        })->count();

        // الباجينيشن اليدوي
        $perPage = (int) $request->input('per_page', 6);
        $currentPage = (int) $request->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;

        $paginated = new LengthAwarePaginator(
            array_slice($tree, $offset, $perPage),// البيانات اللي تظهر
            count($tree),                         // العدد الكلي
            $perPage,                             // في كل صفحة كام
            $currentPage,                         // الصفحة الحالية
            ['path' => $request->url(), 'query' => $request->query()] // روابط صحيحة
        );

        return response()->json([
            'success' => true,
            'count_questions' => $countWithoutReplies,
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
            'title'       => 'required|string',
            'description' => 'nullable|string',
            'parent_id'   => 'nullable|exists:session_discussions,id',
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
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $request->program_id)
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

        $row = new SessionDiscussion();
        $row->title       = $request->title;
        $row->description = $request->description;
        $row->sender_id   = $user->id;
        $row->sender_type = 'student';
        $row->parent_id   = $request->parent_id;
        $row->program_id  = $request->program_id;
        $row->session_id  = $request->session_id;
        $row->save();

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
