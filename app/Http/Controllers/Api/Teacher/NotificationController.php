<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Teacher;
use App\Models\TeacherNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $teacherNotifications = TeacherNotification::where('teacher_id', $user->id)
        ->with('notification:id,title,content,type,created_at')
        ->select('id', 'is_read', 'notification_id')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $teacherNotifications,
        ]);
    }

    public function mark_read(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notifications_id' => 'required|array',
            'notifications_id.*' => 'integer|exists:teacher_notifications,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $teacher_notifications = TeacherNotification::whereIn('id', $request->notifications_id)
            ->where('teacher_id', $user->id)
            ->get();

        foreach ($teacher_notifications as $teacher_notification) {
            $teacher_notification->is_read = true;
            $teacher_notification->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }
}
