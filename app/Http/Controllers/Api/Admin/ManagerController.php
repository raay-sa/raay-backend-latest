<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class ManagerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        $managers = Admin::where('id', '!=', 1)->get();
        // getDataFromCache('admins', function () {
        //     return Admin::where('id', '!=', 1)->get();
        // });

        if ($filter === 'latest') {
            $managers = $managers->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $managers = $managers->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $managers = $managers->sortBy(fn($admin) => mb_strtolower($admin->name))->values();
        } elseif ($filter === 'active_status') {
            $managers = $managers->where(fn($admin) => $admin->status === 'active')->values();
        } elseif ($filter === 'inactive_status') {
            $managers = $managers->where(fn($admin) => $admin->status === 'inactive')->values();
        }

        if ($search) {
            $managers = $managers->filter(function ($admin) use ($search) {
                return mb_stripos($admin->name, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($managers, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);

    }

    public function store(Request $request)
    {
        $admin = $request->user();
        if (!$admin || !($admin instanceof Admin)) {
            return response()->json(['success' => false, 'message' => 'admin_access_required'], 403);
        }

        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'phone'    => ['required', 'string',
                'regex:/^\+9665\d{8}$/',
                'unique:students,phone',
                'unique:teachers,phone',
                'unique:admins,phone',
            ],
            'email'    => 'required|email|unique:students,email|unique:teachers,email|unique:admins,email',
            'password' => 'required|string|min:6|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{6,}$/',
            'image'    => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $image = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $fileName = time() . '_' . uniqid() . '.' . $file->extension();
            $path = 'uploads/admin/profile';

            $fullPath = public_path($path);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }

            $file->move($fullPath, $fileName);
            $image = $path . '/' . $fileName;
        }

        $user = new Admin();
        $user->name        = $request->name;
        $user->type        = 'admin';
        $user->phone       = $phone;
        $user->email       = $request->email;
        $user->password    = Hash::make($request->password);
        $user->image       = $image;
        $user->status      = 'active';
        $user->save();

        $new_noti = new AdminNotificationSetting();
        $new_noti->admin_id = $user->id;
        $new_noti->create_account_noti = 1;
        $new_noti->create_new_program_noti = 1;
        $new_noti->receiving_review_noti = 1;
        $new_noti->global_noti = 1;
        $new_noti->save();

        storeInCache('admins', $user);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data'    => $user
        ]);
    }

    public function show(string $id)
    {
        $admin = Admin::select('id', 'name', 'email', 'phone', 'image')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'data'    => $admin,
        ]);
    }

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
            'password'     => 'nullable|string|min:6|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{6,}$/',
            'status'       => 'required|in:active,inactive',
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
            $row->password = bcrypt($request->password);
        }

        $row->name   = $request->name;
        $row->email  = $request->email;
        $row->phone  = $request->phone;
        $row->status = $request->status;
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
        // update cache
        updateInCache('admins', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row,
        ], 200);
    }

    public function destroy(string $id)
    {
        $row = Admin::find($id);
        if ($row) {
            if ($row->image) {
                unlink(public_path($row->image));
            }

            $row->delete();
            deleteFromCache('admins', $row);

            return response()->json([
                'success' => true,
                'message' => __('trans.alert.success.done_delete'),
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => __('trans.alert.error.data_not_exist'),
        ], 422);
    }
}
