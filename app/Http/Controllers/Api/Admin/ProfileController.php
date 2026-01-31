<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        // Ensure it's a teacher model
        if (!$user || !($user instanceof Admin)) {
            return response()->json(['success' => false, 'message' => __('trans.alert.error.admin_access_required')], 403);
        }

        $user->makeHidden(['password']);

        return response()->json([
            'success' => true,
            'data' => $user
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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
        $row = Admin::find($id);
        if(!$row){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.user_not_exist'),
            ]);
        }

        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:teachers,email|unique:students,email|unique:admins,email,' . $id,
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'old_password' => 'nullable|string|min:6',
            'password'     => 'nullable|string|min:6|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{6,}$/',
            'phone'        => ['required', 'string',
                'regex:/^\+9665\d{8}$/',
                'unique:students,phone',
                'unique:teachers,phone',
                'unique:admins,phone,' . $id,
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->filled('password')) {
            if (!Hash::check($request->old_password, $row->password)) {
                return response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.old_password_incorrect'),
                ], 422);
            }

            $row->password = bcrypt($request->password);
        }

        $row->name = $request->name;
        $row->email = $request->email;
        $row->phone = $request->phone;
        if ($request->hasFile('image')) {
            // remove old image
            if ($row->image) {
                unlink(public_path($row->image));
            }
            $image = $request->file('image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $path = 'uploads/admin/profile';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            $image->move($fullPath, $imageName);
            $row->image = $path . '/' . $imageName;
        }
        $row->save();
        $row->makeHidden(['password']);
        // update cache
        updateInCache('admins', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
