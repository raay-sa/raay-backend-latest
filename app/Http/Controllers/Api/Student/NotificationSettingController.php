<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationSettingController extends Controller
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

        $setting = StudentNotificationSetting::where('student_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'data' => $setting ?? [],
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
        //
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
        $validator = Validator::make($request->all(), [
            'live_program_noti' => 'required|in:0,1',
            'certificate_noti'  => 'required|in:0,1',
            'global_noti'       => 'required|in:0,1',
            // 'offers_noti'       => 'required|in:0,1',
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

        $setting = StudentNotificationSetting::find($id);
        if(!$setting){
            $setting = new StudentNotificationSetting;
        }
        $setting->live_program_noti = $request->live_program_noti;
        $setting->certificate_noti  = $request->certificate_noti;
        $setting->offers_noti       = $request->offers_noti;
        $setting->global_noti       = $request->global_noti;
        $setting->student_id        = $user->id;

        $setting->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'setting' => $setting,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
