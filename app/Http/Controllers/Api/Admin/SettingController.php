<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $settings = Setting::first();

        return response()->json([
            'success' => true,
            'profit_percentage' => $settings->profit_percentage ?? 0
        ]);

    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'profit_percentage' => 'required|numeric',
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

        $setting->profit_percentage = $request->profit_percentage;
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $setting
         ]);
    }
}
