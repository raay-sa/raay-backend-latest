<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workshop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WorkshopController extends Controller
{
    public function index(Request $request)
    {
        $query = Workshop::query();

        if ($request->filled('status') && in_array($request->status, ['pending','approved','rejected'])) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
                  ->orWhere('organization', 'like', "%$search%")
                  ->orWhere('workshop_title', 'like', "%$search%");
            });
        }

        $rows = $query->orderByDesc('created_at')->paginate(15);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show($id)
    {
        $row = Workshop::find($id);
        if (!$row) {
            return response()->json(['success' => false, 'message' => __('trans.alert.error.not_found')], 404);
        }
        return response()->json(['success' => true, 'data' => $row]);
    }

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
            'status' => 'nullable|in:pending,approved,rejected',
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = Workshop::create($request->only([
            'full_name','email','phone','organization','job_title','workshop_title','participants_count','special_requests','status','admin_notes'
        ]));

        return response()->json(['success' => true, 'message' => __('trans.alert.success.saved'), 'data' => $row], 201);
    }

    public function update(Request $request, $id)
    {
        $row = Workshop::find($id);
        if (!$row) {
            return response()->json(['success' => false, 'message' => __('trans.alert.error.not_found')], 404);
        }

        $validator = Validator::make($request->all(), [
            'full_name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email',
            'phone' => 'nullable|string|max:50',
            'organization' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'workshop_title' => 'sometimes|required|string|max:255',
            'participants_count' => 'nullable|integer|min:1|max:255',
            'special_requests' => 'nullable|string',
            'status' => 'nullable|in:pending,approved,rejected',
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row->update($request->only([
            'full_name','email','phone','organization','job_title','workshop_title','participants_count','special_requests','status','admin_notes'
        ]));

        return response()->json(['success' => true, 'message' => __('trans.alert.success.done_update'), 'data' => $row]);
    }

    public function destroy($id)
    {
        $row = Workshop::find($id);
        if (!$row) {
            return response()->json(['success' => false, 'message' => __('trans.alert.error.not_found')], 404);
        }
        $row->delete();
        return response()->json(['success' => true, 'message' => __('trans.alert.success.done_delete')]);
    }
}


