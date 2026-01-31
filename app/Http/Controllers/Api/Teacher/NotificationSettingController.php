<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\TeacherNotificationSetting;
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
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $setting = TeacherNotificationSetting::where('teacher_id', $user->id)->first();

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
            'receiving_review_noti'      => 'required|in:0,1',
            'receiving_assignments_noti' => 'required|in:0,1',
            'global_noti'                => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data',
                'errors' => $validator->errors()
            ], 400);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $setting = TeacherNotificationSetting::find($id);
        if(!$setting){
            $setting = new TeacherNotificationSetting;
        }
        $setting->receiving_review_noti      = $request->receiving_review_noti;
        $setting->receiving_assignments_noti = $request->receiving_assignments_noti;
        $setting->global_noti                = $request->global_noti;
        $setting->teacher_id                 = $user->id;
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
