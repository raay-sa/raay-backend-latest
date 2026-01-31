<?php

namespace App\Http\Controllers\Api\Auth;

use App\Events\NewRegistrationEvent;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminNotificationSetting;
use App\Models\Otp;
use App\Models\RefreshToken;
use App\Models\Student;
use App\Models\StudentNotificationSetting;
use App\Models\Teacher;
use App\Models\TeacherNotificationSetting;
use App\Services\TaqnyatOTP;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;


class AuthController extends Controller
{
    public function login(Request $request) // old method
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'password' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Student::where('email', $request->email)->first()
                ?? Teacher::where('email', $request->email)->first()
                ?? Admin::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => ['email' => [__('trans.alert.error.invalid_email')]],
                ], 422);
            }

            if ($user->is_approved && $user->is_approved == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => ['email' => [__('trans.alert.error.account_not_approved')]],
                ], 422);
            }

            if ($user->status == 'inactive') {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => ['email' => [__('trans.alert.error.account_not_active')]],
                ], 422);
            }

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => ['password' => [__('trans.alert.error.invalid_password')]],
                ], 422);
            }

            // Revoke all existing tokens for this user (single device login)
            $user->tokens()->delete();

            // Revoke all existing refresh tokens for this user
            $user->refresh_tokens()->delete();

            // Access Token (قصير العمر)
            $accessToken = $user->createToken('access-token', ['*'])->plainTextToken;
            $refreshToken = Str::random(60);

            $refresh_token_row = new RefreshToken();
            // $refresh_token_row->user_id = $user->id;
            $refresh_token_row->user()->associate($user); // Laravel يسجل user_id و user_type تلقائيًا
            $refresh_token_row->token = hash('sha256', $refreshToken);
            $refresh_token_row->expires_at = now()->addDays(30);
            $refresh_token_row->created_at = now();
            $refresh_token_row->updated_at = now();
            $refresh_token_row->save();

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'user' => $user->makeHidden(['password']),
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function refreshToken(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required',
        ]);

        $hashed = hash('sha256', $request->refresh_token);

        $record =  RefreshToken::where('token', $hashed)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token',
            ], 401);
        }

        $user = $record->user;
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 404);
        }

        // Revoke all existing access tokens for this user (single device login)
        $user->tokens()->delete();

        // Generate new access token
        $newAccessToken = $user->createToken('access-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'access_token' => $newAccessToken,
        ], 200);
    }

    public function register(Request $request)
    {
        try {
            $phone = $request->phone;

            if (!str_starts_with($phone, '+966')) {
                $phone = '+966' . ltrim($phone, '0');
            }
            $request->merge(['phone' => $phone]);

            $rules = [
                'name'             => 'required|string|max:255',
                'user_type'        => 'required|in:student,trainee,teacher,admin,consultant',
                'phone'            => ['required', 'string',
                    'regex:/^\+9665\d{8}$/',
                    'unique:students,phone',
                    'unique:teachers,phone',
                    'unique:admins,phone',
                ],
                'education'        => 'required_if:user_type,student,trainee',
                'email'            => 'required|email|unique:students,email|unique:teachers,email|unique:admins,email',
                'password'         => 'required_if:user_type,student,trainee,teacher|string|min:6|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{6,}$/',
                'image'            => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'certificate'      => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf|max:2048',
                'cv'               => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf|max:2048',
                'bio'              => 'nullable|string',
                'categories'       => 'required_if:user_type,teacher|array',
                'categories.*'     => 'exists:categories,id',
                'experience_years' => 'required_if:user_type,consultant',
                'work_hours'       => 'required_if:user_type,consultant',
                'previous_clients' => 'required_if:user_type,consultant',
                'specialization'   => 'required_if:user_type,consultant',
            ];

            $validator = Validator::make($request->all(), $rules);
            $user_type = $request->input('user_type');

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = null;
            if($user_type === 'teacher' || $user_type === 'consultant'){
                $image = null;
                $cv = null;
                $certificate = null;

                if ($request->hasFile('image')) {
                    $file = $request->file('image');
                    $fileName = time() . '_' . uniqid() . '.' . $file->extension();
                    $path = 'uploads/teachers/profile';

                    $fullPath = public_path($path);
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }

                    $file->move($fullPath, $fileName);
                    $image = $path . '/' . $fileName;
                }

                if ($request->hasFile('certificate')) {
                    $file = $request->file('certificate');
                    $fileName = time() . '_' . uniqid() . '.' . $file->extension();
                    $path = 'uploads/teachers/certificate';

                    $fullPath = public_path($path);
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }

                    $file->move($fullPath, $fileName);
                    $certificate = $path . '/' . $fileName;
                }

                if ($request->hasFile('cv')) {
                    $file = $request->file('cv');
                    $fileName = time() . '_' . uniqid() . '.' . $file->extension();
                    $path = 'uploads/teachers/cv';

                    $fullPath = public_path($path);
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }

                    $file->move($fullPath, $fileName);
                    $cv = $path . '/' . $fileName;
                }

                $user = new Teacher();
                $user->name             = $request->name;
                $user->phone            = $phone;
                $user->email            = $request->email;
                $user->password         = Hash::make($request->password);
                $user->cv               = $cv;
                $user->certificate      = $certificate;
                $user->image            = $image;
                $user->bio              = $request->bio;
                $user->experience_years = $request->experience_years;
                $user->work_hours       = $request->work_hours;
                $user->previous_clients = $request->previous_clients;
                $user->specialization   = $request->specialization;
                $user->type             = $user_type;
                $user->status           = 'inactive'; // active after verify otp
                $user->is_approved      = 0; // active after approve from admin
                $user->save();

                $user->categories()->sync($request->categories); // `sync` replaces old ones
                $user->makeHidden('password');

                if($user->type === 'teacher'){
                    // store in cache
                    storeInCache('teachers', $user, ['categories']);

                    $noti_setting = new TeacherNotificationSetting;
                    $noti_setting->teacher_id = $user->id;
                    $noti_setting->save();
                }
                else if($user->type === 'consultant'){
                    storeInCache('consultants', $user, ['categories']);
                    // will save notification row in db after convert to teacher by admin
                }

            }
            else
            {
                $image = null;
                if ($request->hasFile('image')) {
                    $file = $request->file('image');
                    $fileName = time() . '_' . uniqid() . '.' . $file->extension();
                    $path = 'uploads/students/profile';

                    $fullPath = public_path($path);
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }
                    $file->move($fullPath, $fileName);
                    $image = $path . '/' . $fileName;
                }

                $user = new Student();
                $user->name        = $request->name;
                $user->phone       = $phone;
                $user->email       = $request->email;
                $user->password    = Hash::make($request->password);
                $user->image       = $image;
                $user->type        = $user_type; // student or trainee
                $user->education   = $request->education;
                $user->bio         = $request->bio;
                $user->status      = 'inactive'; // active after verify otp
                $user->is_approved = 0; // active after verify otp
                $user->save();

                // store in cache
                $user->makeHidden('password');
                storeInCache('students', $user);

                $noti_setting = new StudentNotificationSetting;
                $noti_setting->student_id = $user->id;
                $noti_setting->save();
            }

            return response()->json([
                'message' => __('trans.alert.success.register'),
                'user' => $user,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendOtp(Request $request, TaqnyatOTP $taqnyat)
    {
        try {
            $phone = $request->phone;

            if (!str_starts_with($phone, '+966')) {
                $phone = '+966' . ltrim($phone, '0');
            }
            $request->merge(['phone' => $phone]);

            $validator = Validator::make($request->all(), [
                'auth_method' => 'required|in:login,register,forgot_password',
                'phone'       => 'required|string|regex:/^\+9665\d{8}$/',
            ]);

            $user = Student::where('phone', $request->phone)->first()
            ?? Teacher::where('phone', $request->phone)->first()
            ?? Admin::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.user_not_exist'),
                ], 422);
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if($request->phone == '+966501234567' || $request->phone == '+966555555555' || $request->phone == '+966501231234'){
                $otp = "123456";
            } else{
                $otp = rand(100000, 999999);
            }

            Otp::where('phone', $request->phone)->delete();

            $row = new Otp();
            $row->phone = $request->phone;
            $row->code = $otp;
            $row->expired_at = Carbon::now()->addMinutes(5);
            $row->save();

            $response = $taqnyat->sendOTP($user->phone, $otp);

            return response()->json([
                'success' => true,
                'message' => 'OTP sent',
                'expired_at' => $row->expired_at->format('Y-m-d H:i:s'),
                'response' => $response,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'auth_method' => 'required|in:login,register,forgot_password',
            'phone'       => 'required|string|regex:/^\+9665\d{8}$/',
            'code'        => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $otp = Otp::where('phone', $request->phone)
            ->where('code', $request->code)
            ->where('expired_at', '>=', now())
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.otp_invalid'),
            ], 422);
        }

        $otp->delete();

        $user = Student::where('phone', $request->phone)->first()
        ?? Teacher::where('phone', $request->phone)->first()
        ?? Admin::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.user_not_exist'),
            ], 422);
        }

        if($request->auth_method == 'forgot_password'){
            return response()->json([
                'success' => true,
                'message' => __('trans.alert.success.otp_verified'),
            ], 200);
        }

        if($request->auth_method == 'register'){
            if(in_array($user->type, ['student', 'trainee'])) {
                $user->status = 'active';
                $user->is_approved = 1;
                $user->save();
            } else{
                // teacher or consultant
                $user->status = 'active';
                $user->save();
            }

            $adminSetting = AdminNotificationSetting::get();
            foreach($adminSetting as $setting){
                if($setting->create_account_noti == 1){
                    event(new NewRegistrationEvent($user));
                }
            }
        }

        if ($user->is_approved && $user->is_approved == 0) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => ['phone' => [__('trans.alert.error.account_not_approved')]],
            ], 422);
        }

        if ($user->status == 'inactive') {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => ['phone' => [__('trans.alert.error.account_not_active')]],
            ], 422);
        }

        Auth::login($user);
        $user->makeHidden(['password']);
        // $token = $user->createToken('api-token')->plainTextToken;

        // Revoke all existing tokens for this user (single device login)
        $user->tokens()->delete();

        // Revoke all existing refresh tokens for this user
        $user->refresh_tokens()->delete();

        // Access Token (قصير العمر)
        $accessToken = $user->createToken('access-token', ['*'])->plainTextToken;
        // Refresh Token
        $refreshToken = Str::random(60);

        $refresh_token_row = new RefreshToken();
        $refresh_token_row->user()->associate($user); // Laravel will register user_id and user_type automatically
        $refresh_token_row->token = hash('sha256', $refreshToken);
        $refresh_token_row->expires_at = now()->addDays(30);
        $refresh_token_row->created_at = now();
        $refresh_token_row->updated_at = now();
        $refresh_token_row->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.login'),
            'data'    => [
                'token' => $accessToken,
                'refresh_token' => $refreshToken,
                'user'  => $user,
            ],
        ], 200);
    }

    public function verifyOtpRegister(Request $request)
    {
        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'phone'       => 'required|string|regex:/^\+9665\d{8}$/',
            'code'        => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $otp = Otp::where('phone', $request->phone)
            ->where('code', $request->code)
            ->where('expired_at', '>=', now())
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.otp_invalid'),
            ], 422);
        }

        $otp->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.otp_verified'),
        ], 200);
    }

    public function forgotPassword(Request $request)
    {
        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'phone'    => 'required|string|regex:/^\+9665\d{8}$/',
            'password' => 'required|min:6|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{6,}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Student::where('phone', $request->phone)->first()
        ?? Teacher::where('phone', $request->phone)->first()
        ?? Admin::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.user_not_exist'),
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.password_reset'),
        ], 200);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.logout'),
        ], 200);
    }
}
