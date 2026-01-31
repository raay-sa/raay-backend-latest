<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\GeneralNotificationEvent;
use App\Events\StudentWarningEvent;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentNotification;
use App\Models\StudentNotificationSetting;
use App\Models\StudentWarning;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\TeacherNotification;
use App\Models\TeacherNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class StudentWarningController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
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
            'body'    => 'required|string|max:255',
            'type'    => 'required|string|in:warning,ban,alert',
            'student_id' => 'required|exists:students,id',
            'program_id' => 'required|exists:programs,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $student = Student::find($request->student_id);

        $subscription = Subscription::where('student_id', $request->student_id)
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

        // check: ban requires at least 1 previous warning
        if ($request->type === 'ban') {
            $hasWarning = StudentWarning::where('student_id', $request->student_id)
                ->where('type', 'warning')
                ->exists();

            if (! $hasWarning) {
                return response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.must_have_warning_before_ban'),
                ], 403);
            }

            // الغاء الاشتراك
            $subscription = Subscription::where('student_id', $request->student_id)
            ->where('program_id', $request->program_id)->first();

            $subscription->status = 'banned';
            $subscription->save();
        }

        $row = new StudentWarning();
        $row->body       = $request->body;
        $row->type       = $request->type;
        $row->student_id = $request->student_id;
        $row->program_id = $request->program_id;
        $row->save();

        // save in cache (helper method)
        storeInCache('student_warnings', $row);

        event(new StudentWarningEvent($row));

        if($request->type == 'ban'){
            $type = __('trans.global.ban');
            Mail::raw('Raay', function ($message) use ($request, $student, $type) {
                $message->to($student->email)
                ->subject('🚫 '. $type . ': ' . $request->body);
            });

        } elseif($request->type == 'warning'){
            $type = __('trans.global.warning');
            Mail::raw('Raay', function ($message) use ($request, $student, $type) {
                $message->to($student->email)
                ->subject('⚠️ '. $type . ': ' . $request->body);
            });

        } else{
            $type = __('trans.global.alert');
            Mail::raw('Raay', function ($message) use ($request, $student, $type) {
                $message->to($student->email)
                ->subject('🔔 '. $type . ': ' . $request->body);
            });
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
        $row = StudentWarning::findOr($id, function () {
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
        //
    }

}
