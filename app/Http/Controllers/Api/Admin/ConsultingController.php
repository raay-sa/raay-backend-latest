<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consulting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConsultingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        $consulting_data = getDataFromCache('consulting',  Consulting::class);

        if ($filter === 'latest') {
            $consulting_data = $consulting_data->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $consulting_data = $consulting_data->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $consulting_data = $consulting_data->sortBy(fn($data) => mb_strtolower($data->name))->values();
        } elseif ($filter === 'readable') {
            $consulting_data = $consulting_data->filter(fn($data) => $data->status === 1)->values();
        } elseif ($filter === 'not_readable') {
            $consulting_data = $consulting_data->filter(fn($data) => $data->status === 0)->values();
        }

        if ($search) {
            $consulting_data = $consulting_data->filter(function ($data) use ($search) {
                return mb_stripos($data->name, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10); // عدد العناصر في كل صفحة
        $currentPage = (int) $request->input('page', 1); // الصفحة الحالية
        $paginated = paginationData($consulting_data, $perPage, $currentPage);

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
        $consulting = Consulting::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data' => $consulting,
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

        $consulting = Consulting::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $consulting->status = $request->status;
        $consulting->save();

        updateInCache('consulting', $consulting);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $consulting,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $consulting = Consulting::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        if($consulting->files){
            foreach ($consulting->files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        $consulting->delete();
        deleteFromCache('consulting', $consulting);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ], 200);
    }
}
