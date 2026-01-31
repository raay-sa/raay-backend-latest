<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
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
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $teacher = Teacher::with('categories:id', 'categories.translations')->findOr($user->id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $teacher->makeHidden('password');

        return response()->json([
            'success' => true,
            'data' => $teacher
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
        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:admins,email|unique:students,email|unique:teachers,email,' . $user->id,
            'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'certificate' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf|max:2048',
            'old_password'=> 'nullable|string|min:6',
            'password'    => 'nullable|string|min:6|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{6,}$/',
            'bio'         => 'nullable|string',
            'site_link'   => 'nullable|url',
            'categories'  => 'nullable|array',
            'categories.*'=> 'exists:categories,id',
            'phone'       => ['required', 'string',
                'regex:/^\+9665\d{8}$/',
                'unique:admins,phone',
                'unique:students,phone',
                'unique:teachers,phone,' . $user->id,
            ],
            'facebook'    => 'nullable|url',
            'instagram'   => 'nullable|url',
            'twitter'     => 'nullable|url',
            'linkedin'    => 'nullable|url',
            'youtube'     => 'nullable|url',
            'whatsapp'    => 'nullable|url',
            'telegram'    => 'nullable|url',
            'snapchat'    => 'nullable|url',
            'tiktok'      => 'nullable|url',
            'threads'     => 'nullable|url',
            'pinterest'   => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        if ($request->filled('password')) {
            if (!Hash::check($request->old_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.old_password_incorrect'),
                ], 422);
            }

            $user->password = bcrypt($request->password);
        }

        $user->name      = $request->name;
        $user->email     = $request->email;
        $user->phone     = $request->phone;
        $user->bio       = $request->bio;
        $user->site_link = $request->site_link;
        $user->facebook  = $request->facebook;
        $user->twitter   = $request->twitter;
        $user->instagram = $request->instagram;
        $user->youtube   = $request->youtube;
        $user->linkedin  = $request->linkedin;
        $user->snapchat  = $request->snapchat;
        $user->tiktok    = $request->tiktok;
        $user->telegram  = $request->telegram;
        $user->whatsapp  = $request->whatsapp;
        $user->threads   = $request->threads;
        $user->pinterest = $request->pinterest;

        if ($request->hasFile('image')) {
            if ($user->image) {
                unlink(public_path($user->image));
            }
            $image = $request->file('image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $path = 'uploads/teacher/profile';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            $image->move($fullPath, $imageName);
            $user->image = $path . '/' . $imageName;
        }

        if ($request->hasFile('certificate')) {
            if ($user->certificate) {
                unlink(public_path($user->certificate));
            }
            $certificate = $request->file('certificate');
            $certificateName = time() . '.' . $certificate->getClientOriginalExtension();
            $path = 'uploads/teacher/certificate';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            $certificate->move($fullPath, $certificateName);
            $user->certificate = $path . '/' . $certificateName;
        }
        $user->save();

        $user->categories()->sync($request->categories);
        $user->load('categories');
        $user->makeHidden(['password']);

        updateInCache('teachers', $user);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $user,
        ], 200);
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
    public function update(Request $request)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

