<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Support;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupportController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'content'    => 'required|string',
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

        $row = new Support();
        $row->content = $request->content;
        $row->user()->associate($user); // Laravel يسجل user_id و user_type تلقائيًا
        $row->save();

        storeInCache('supports_student', Support::class);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
        ], 200);
    }
}
