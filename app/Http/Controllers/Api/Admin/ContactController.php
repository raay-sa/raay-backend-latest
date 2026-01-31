<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        $contact_data = Contact::with('program.translations', 'program.category:id', 'program.category.translations')->get();

        if ($filter === 'latest') {
            $contact_data = $contact_data->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $contact_data = $contact_data->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $contact_data = $contact_data->sortBy(fn($contact) => mb_strtolower($contact->name))->values();
        } elseif ($filter === 'readable') {
            $contact_data = $contact_data->filter(fn($contact) => $contact->status === 1)->values();
        } elseif ($filter === 'not_readable') {
            $contact_data = $contact_data->filter(fn($contact) => $contact->status === 0)->values();
        }

        if ($search) {
            $contact_data = $contact_data->filter(function ($contact) use ($search) {
                return mb_stripos($contact->name, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($contact_data, $perPage, $currentPage);

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
        $contact = Contact::with('program.translations', 'program.category:id', 'program.category.translations')->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data' => $contact,
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

        $contact = Contact::with('program.translations', 'program.category:id', 'program.category.translations')->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $contact->status = $request->status;
        $contact->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $contact->load('program.translations', 'program.category:id', 'program.category.translations'),
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $contact = Contact::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ], 200);
    }
}
