<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PrivacyTerms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;

class PrivacyTermsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'users_type' => 'required|in:student,teacher',
            'status'     => 'required|string|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = $request->users_type;

        $privacy_terms = getDataFromCache('privacy_terms', PrivacyTerms::class)
            ->where('status', $request->status)
            ->filter(function ($item) use ($type) {
                return in_array($type, is_array($item->users_type) ? $item->users_type : json_decode($item->users_type, true) ?? []);
                // return in_array($type, $item->users_type ?? []);
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $privacy_terms
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
        $validator = Validator::make($request->all(), [
            'title'        => 'required|string|max:255',
            'content'      => 'required|string',
            'type'         => 'required|in:terms,privacy',
            'users_type'   => 'required|array',
            'users_type.*' => 'in:student,teacher',
            'status'       => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = new PrivacyTerms;
        $row->title      = $request->title;
        $row->content    = $request->content;
        $row->type       = $request->type;
        $row->users_type = json_encode($request->users_type);
        $row->status     = $request->status;
        $row->save();

        // save in cache (helper method)
        storeInCache('privacy_terms', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $row = PrivacyTerms::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'row' => $row
        ]);
    }

    public function show_pdf(string $id)
    {
        $row = PrivacyTerms::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $html = '
            <html lang="ar">
            <head>
                <meta charset="UTF-8">
                <style>
                    body { font-family: DejaVu Sans, sans-serif; direction: rtl; }
                    h1 { text-align: center; }
                    .section { margin-bottom: 20px; }
                </style>
            </head>
            <body>
                <h1>' . e($row->title) . '</h1>
                <div class="section">' . nl2br(e($row->content)) . '</div>
            </body>
            </html>
        ';

        $pdf = Pdf::loadHTML($html);
        return $pdf->stream('privacy_terms_' . $row->id . '.pdf');
        // للتحميل بدلاً من العرض:
        // return $pdf->download('privacy_terms_' . $row->id . '.pdf');
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
        $validator = Validator::make($request->all(), [
            'title'         => 'required|string|max:255',
            'content'       => 'required|string|max:255',
            'type'          => 'required|in:terms,privacy',
            'users_type'    => 'required|array',
            'users_type.*'  => 'in:student,teacher',
            'status'        => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = PrivacyTerms::findOrFail($id);
        $row->title      = $request->title;
        $row->content    = $request->content;
        $row->type       = $request->type;
        $row->users_type = json_encode($request->users_type);
        $row->status     = $request->status;
        $row->save();

        // save in cache (helper method)
        updateInCache('privacy_terms', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $row = PrivacyTerms::find($id);

        if(!$row){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        // delete from cache (helper method)
        deleteFromCache('privacy_terms', $row);
        $row->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }
}
