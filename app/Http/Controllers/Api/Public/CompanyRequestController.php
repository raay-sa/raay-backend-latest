<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\CompanyRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanyRequestController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'            => 'required|string|max:255',
            'email'           => 'required|email',
            'phone'           => 'required|string',
            'company'         => 'required|string',
            'program'         => 'nullable|string',
            'job_title'       => 'required|string',
            'trainers_count'  => 'required|integer',
            'notes'           => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data',
                'errors' => $validator->errors()
            ], 400);
        }

        // convert data from d-m-Y to Y-m-d
        $date = null;
        if ($request->filled('date')) {
            try {
                $date = \Carbon\Carbon::createFromFormat('d-m-Y', $request->date)->format('Y-m-d');
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date format, expected d-m-Y',
                ], 400);
            }
        }

        $row = new CompanyRequest();
        $row->name             = strip_tags($request->name);
        $row->email            = $request->email;
        $row->phone            = $request->phone;
        $row->company          = strip_tags($request->company);
        $row->program          = strip_tags($request->program);
        $row->job_title        = strip_tags($request->job_title);
        $row->trainers_count   = $request->trainers_count;
        $row->notes            = strip_tags($request->notes); // to remove js code
        $row->status           = 0;

        if (empty($row->name) || empty($row->company) || empty($row->job_title) || empty($row->notes)) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.name_not_valid'),
            ], 400);
        }

        $row->save();
        storeInCache('companies_request', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data' => $row
        ], 200);
    }
}
