<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentNotification;
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
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $studentNotifications = StudentNotification::where('student_id', $user->id)
        ->with('notification:id,title,content,type,created_at')
        ->select('id', 'is_read', 'notification_id')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $studentNotifications,
        ]);
    }

    public function mark_read(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notifications_id' => 'required|array',
            'notifications_id.*' => 'integer|exists:student_notifications,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $student_notifications = StudentNotification::whereIn('id', $request->notifications_id)
            ->where('student_id', $user->id)
            ->get();

        foreach ($student_notifications as $student_notification) {
            $student_notification->is_read = true;
            $student_notification->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }
}
