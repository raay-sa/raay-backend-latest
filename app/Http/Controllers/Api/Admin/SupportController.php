<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Support;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupportController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'users_type' => 'required|in:student,teacher',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $users_type = $request->users_type;

        $support_data = getDataFromCache('supports_' . $users_type, function () use($users_type) {
            return Support::with('user:id,name,email,phone')
                ->where('user_type', 'App\\Models\\' . ucfirst($users_type)) // مهم
                ->get();
        });


        if ($filter === 'latest') {
            $support_data = $support_data->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $support_data = $support_data->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $support_data = $support_data->sortBy(fn($support) => mb_strtolower($support->user->name))->values();
        }

        if ($search) {
            $support_data = $support_data->filter(function ($support) use ($search) {
                return mb_stripos($support->user->name, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($support_data, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated,
        ]);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $support = Support::find($id);
        if(!$support){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $support,
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $support = Support::with('user:id,name,email,phone')->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $support->status = $request->status;
        $support->save();

        // updateInCache('supports', $support);
        $type = class_basename($support->user_type);
        updateInCache('supports_' . strtolower($type), $support);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $support,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $support = Support::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $support->delete();
        deleteFromCache('supports', $support);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ], 200);
    }
}
