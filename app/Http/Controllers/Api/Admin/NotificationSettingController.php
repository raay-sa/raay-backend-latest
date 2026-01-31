<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $admin = $request->user();
        if (!$admin || !($admin instanceof Admin)) {
            return response()->json(['success' => false, 'message' => 'admin_access_required'], 403);
        }

        $setting = AdminNotificationSetting::where('admin_id', $admin->id)->first();

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
        $admin = $request->user();
        if (!$admin || !($admin instanceof Admin)) {
            return response()->json(['success' => false, 'message' => 'admin_access_required'], 403);
        }

        $validator = Validator::make($request->all(), [
            'create_account_noti'     => 'required|in:0,1',
            'create_new_program_noti' => 'required|in:0,1',
            'receiving_review_noti'   => 'required|in:0,1',
            'global_noti'             => 'required|in:0,1',
            // 'offers_noti' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data',
                'errors' => $validator->errors()
            ], 400);
        }

        $setting = AdminNotificationSetting::where('admin_id', $admin->id)->first();

        if (!$setting) {
            $setting = new AdminNotificationSetting;
        }

        $setting->create_account_noti = $request->create_account_noti;
        $setting->create_new_program_noti = $request->create_new_program_noti;
        $setting->receiving_review_noti = $request->receiving_review_noti;
        $setting->offers_noti = $request->offers_noti;
        $setting->global_noti = $request->global_noti;
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
