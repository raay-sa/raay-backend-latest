<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SystemSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $settings = Setting::first();

        return response()->json([
            'success' => true,
            'ant_media' => $settings->ant_media ?? 0
        ]);

    }

    public function update_setting(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ant_media' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $setting = Setting::first();

        if (!$setting) {
            $setting = new Setting();
        }

        $setting->ant_media = $request->ant_media;
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $setting
         ]);
    }
}
