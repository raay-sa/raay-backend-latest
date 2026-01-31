<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Workshop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WorkshopController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:50',
            'organization' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'workshop_title' => 'required|string|max:255',
            'participants_count' => 'nullable|integer|min:1|max:255',
            'special_requests' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $workshop = Workshop::create([
            'full_name' => $request->full_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'organization' => $request->organization,
            'job_title' => $request->job_title,
            'workshop_title' => $request->workshop_title,
            'participants_count' => $request->participants_count ?? 1,
            'special_requests' => $request->special_requests,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.saved'),
            'data' => $workshop,
        ], 201);
    }
}


