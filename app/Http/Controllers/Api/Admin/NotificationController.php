<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\GeneralNotificationEvent;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentNotification;
use App\Models\StudentNotificationSetting;
use App\Models\Teacher;
use App\Models\TeacherNotification;
use App\Models\TeacherNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $type = $request->type;
        $users_type = $request->users_type;
        $search = $request->search;

        $notifications = getDataFromCache('notifications', Notification::class);

        if ($type) {
            $notifications = $notifications->where(fn($notification) => $notification->type == $type)->values();
        }

        if ($users_type) {
            $notifications = $notifications->filter(function ($notification) use ($users_type) {
                $usersType = is_array($notification->users_type)
                    ? $notification->users_type
                    : json_decode($notification->users_type, true) ?? [];
                return in_array($users_type, $usersType);
            })->values();
        }

        if ($search) {
            $notifications = $notifications->filter(function ($notification) use ($search) {
                return $notification->title && mb_stripos($notification->title, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10); // عدد العناصر في كل صفحة
        $currentPage = (int) $request->input('page', 1); // الصفحة الحالية
        $paginated = paginationData($notifications, $perPage, $currentPage);

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
            'title'      => 'required|string|max:255',
            'content'    => 'required|string|max:255',
            'type'       => 'required|string|in:alert,offer,notice',
            'users_type' => 'required|array|in:student,teacher',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = new Notification();
        $row->title      = $request->title;
        $row->content    = $request->content;
        $row->type       = $request->type;
        $row->users_type = json_encode($request->users_type);
        $row->save();

        // save in cache (helper method)
        storeInCache('notifications', $row);

        $usersType = is_array($request->users_type) ? $request->users_type : [$request->users_type];
        $teacherIds = [];
        $studentIds = [];

        if (in_array('student', $usersType)) {
            $studentIds = StudentNotificationSetting::where('global_noti', 1)
            ->pluck('student_id')->toArray();
            // سجل لكل طالب
            foreach ($studentIds as $studentId) {
                $student_noti = new StudentNotification();
                $student_noti->notification_id = $row->id;
                $student_noti->student_id      = $studentId;
                $student_noti->is_read         = false;
                $student_noti->save();

                event(new GeneralNotificationEvent($row, 'student', $studentId));
            }
        }

        if (in_array('teacher', $usersType)) {
            $teacherIds = TeacherNotificationSetting::where('global_noti', 1)
            ->pluck('teacher_id')->toArray();
            foreach ($teacherIds as $teacherId) {
                $teacher_noti = new TeacherNotification();
                $teacher_noti->notification_id = $row->id;
                $teacher_noti->teacher_id      = $teacherId;
                $teacher_noti->is_read         = false;
                $teacher_noti->save();

                event(new GeneralNotificationEvent($row, 'teacher', $teacherId));
            }
        }


        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data' => $row
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $row = Notification::findOr($id, function () {
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
        $validator = Validator::make($request->all(), [
            'title'      => 'required|string|max:255',
            'content'    => 'required|string|max:255',
            'type'       => 'required|string|in:alert,offer,notice',
            'users_type' => 'required|array|in:student,teacher',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = Notification::findOrFail($id);
        $row->title      = $request->title;
        $row->content    = $request->content;
        $row->type       = $request->type;
        $row->users_type = json_encode($request->users_type);
        $row->status     = 'sent';
        $row->save();

        // save in cache (helper method)
        updateInCache('notifications', $row);

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
        $notification = Notification::findOrFail($id);

        if(!$notification){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $notification->delete();

        // delete from cache (helper method)
        deleteFromCache('notifications', $notification);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

    public function multi_delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notifications_id' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allNotificationIds = $request->input('notifications_id', []);
        Notification::whereIn('id', $allNotificationIds)->delete();
        Cache::forget('notifications');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }
}
