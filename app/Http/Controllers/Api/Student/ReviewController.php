<?php

namespace App\Http\Controllers\Api\Student;

use App\Events\ReviewEvent;
use App\Http\Controllers\Controller;
use App\Models\AdminNotificationSetting;
use App\Models\Program;
use App\Models\Review;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'score'      => 'required|numeric|min:0|max:5',
            'comment'    => 'nullable|string',
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data',
                'errors' => $validator->errors()
            ], 400);
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

        $review = new Review();
        $review->score      = $request->score;
        $review->comment    = $request->comment;
        $review->program_id = $request->program_id;
        $review->student_id = $user->id;
        $review->save();

        storeInCache('reviews', $review);

        $teacher = Program::find($request->program_id)->teacher;
        $teacherSetting = $teacher->notification_setting()->first();
        $adminSetting = AdminNotificationSetting::get();

        foreach ($adminSetting as $setting) {
            if ($setting->receiving_review_noti == 1) {
                event(new ReviewEvent($review, $user, 'admin', null));
            }
        }

        if ($teacherSetting && $teacherSetting->receiving_review_noti == 1) {
            event(new ReviewEvent($review, $user, 'teacher', $teacher->id));
        }

        return response()->json([
            'success'  => true,
            'message' => __('trans.alert.success.done_create'),
            'data'    => $review,
        ]);
    }
}
