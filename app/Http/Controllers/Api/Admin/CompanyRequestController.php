<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyRequest;
use Faker\Provider\ar_EG\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanyRequestController extends Controller
{
        /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        $company_data = getDataFromCache('companies_request', CompanyRequest::class);

        if ($filter === 'latest') {
            $company_data = $company_data->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $company_data = $company_data->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $company_data = $company_data->sortBy(fn($data) => mb_strtolower($data->name))->values();
        } elseif ($filter === 'readable') {
            $company_data = $company_data->filter(fn($data) => $data->status === 1)->values();
        } elseif ($filter === 'not_readable') {
            $company_data = $company_data->filter(fn($data) => $data->status === 0)->values();
        }

        if ($search) {
            $company_data = $company_data->filter(function ($data) use ($search) {
                return mb_stripos($data->name, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10); // عدد العناصر في كل صفحة
        $currentPage = (int) $request->input('page', 1); // الصفحة الحالية
        $paginated = paginationData($company_data, $perPage, $currentPage);

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
        $company_data = CompanyRequest::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data' => $company_data,
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

        $company_data = CompanyRequest::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $company_data->status = $request->status;
        $company_data->save();

        updateInCache('companies_request', $company_data);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $company_data,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $company_data = CompanyRequest::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        if($company_data->files){
            foreach ($company_data->files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        $company_data->delete();
        deleteFromCache('companies_request', $company_data);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ], 200);
    }
}
