<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\TeacherNotificationSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class ConsultantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $category_id = $request->category_id;

        $calcChange = fn($current, $previous) => $previous > 0
            ? round((($current - $previous) / $previous) * 100, 2)
            : 100;

        $consultants = getDataFromCache('consultants', function () {
            return Teacher::where('type', 'consultant')
                ->with('categories:id','categories.translations')
                ->withCount('programs')
                ->withSum('programs', 'price')
                ->get()
                ->map(function ($consultant) {
                    $consultant->makeHidden(['password']);
                    $consultant->categories->transform(function ($category) {
                        return $category->makeHidden(['pivot']);
                    });
                    return $consultant;
                });
        });

        $total_consultants = $consultants->count();
        $current_consultants = $consultants->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $previous_consultants = $consultants->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        // نسبة الزيادة أو النقصان
        $consultant_percentage_change = $calcChange($current_consultants, $previous_consultants);

        if ($filter === 'latest') {
            $consultants = $consultants->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $consultants = $consultants->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $consultants = $consultants->sortBy(fn($consultant) => mb_strtolower($consultant->name))->values();
        } elseif ($filter === 'active_status') {
            $consultants = $consultants->where(fn($consultant) => $consultant->status === 'active')->values();
        } elseif ($filter === 'inactive_status') {
            $consultants = $consultants->where(fn($consultant) => $consultant->status === 'inactive')->values();
        }

        if ($search) {
            $consultants = $consultants->filter(function ($consultant) use ($search) {
                return mb_stripos($consultant->name, $search) !== false;
            })->values();
        }

        if ($category_id) {
            $consultants = $consultants->filter(function ($consultant) use ($category_id) {
                return $consultant->categories->pluck('id')->contains($category_id);
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($consultants, $perPage, $currentPage);

        return response()->json([
            'success' => true,

            'total_consultants' => $total_consultants,
            'consultants_percentage' => abs($consultant_percentage_change),
            'consultants_status' => $consultant_percentage_change >= 0 ? 'increase' : 'decrease',

            'data' => $paginated,
        ]);
    }


    public function store(Request $request)
    {
        try {
            $phone = $request->phone;

            if (!str_starts_with($phone, '+966')) {
                $phone = '+966' . ltrim($phone, '0');
            }
            $request->merge(['phone' => $phone]);

            $rules = [
                'name'        => 'required|string|max:255',
                'phone'       => ['required', 'string',
                    'regex:/^\+9665\d{8}$/',
                    'unique:students,phone',
                    'unique:teachers,phone',
                    'unique:admins,phone',
                ],
                'email'       => 'required|email|unique:students,email|unique:teachers,email|unique:admins,email',
                'password'    => 'required|string|min:6',
                'status'      => 'required|in:active,inactive',
                'categories.*'=> 'exists:categories,id',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = new Teacher();
            $user->name        = $request->name;
            $user->phone       = $phone;
            $user->email       = $request->email;
            $user->password    = Hash::make($request->password);
            $user->status      = $request->status;
            $user->type        = 'consultant';
            $user->is_approved = 1;
            $user->save();

            $user->categories()->sync($request->categories); // `sync` replaces old ones
            $user->makeHidden(['password']);

            // store in cache
            storeInCache('consultants', $user, ['categories']);

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

    public function show(Request $request, $id)
    {
        $row = Teacher::with('categories:id','categories.translations')
        // with(['categories:id,title'])
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $row->makeHidden('password');
        $row->categories->transform(function ($category) {
            return $category->makeHidden(['pivot']);
        });

        return response()->json([
            'success' => true,
            'row' => $row
        ]);
    }

    public function update(Request $request, string $id)
    {
        $row = Teacher::find($id);
        if(!$row){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:admins,email|unique:students,email|unique:teachers,email,' . $id,
            'categories'  => 'nullable|array',
            'categories.*'=> 'exists:categories,id',
            'phone'       => ['required', 'string',
                'regex:/^\+9665\d{8}$/',
                'unique:admins,phone',
                'unique:students,phone',
                'unique:teachers,phone,' . $id,
            ],
            'password'    => 'nullable|string|min:6',
            'status'      => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row->name        = $request->name;
        $row->email       = $request->email;
        $row->phone       = $request->phone;
        $row->status      = $request->status;
        $row->is_approved = 1;
        if ($request->password) {
            $row->password = Hash::make($request->password);
        }
        $row->save();

        $row->categories()->sync($request->categories);
        $row->load('categories');
        $row->makeHidden(['password']);

        // update cache
        updateInCache('consultants', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row,
        ], 200);
    }

    public function destroy(Request $request, $id)
    {
        $row = Teacher::find($id);
        if(!$row){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        deleteFromCache('consultants', $row);
        $row->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ], 200);
    }

    public function multi_delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'consultants_id' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $allConsultantIds = $request->input('consultants_id', []);
        Teacher::whereIn('id', $allConsultantIds)->delete();
        Cache::forget('consultants');

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }

    public function convert_to_teacher(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'consultant_id' => 'required|exists:teachers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $consultant = Teacher::findOr($request->consultant_id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ]), 422);
        });

        $consultant->type = 'teacher';
        $consultant->save();

        Cache::forget('consultants');
        Cache::forget('teachers');

        $noti_setting = new TeacherNotificationSetting();
        $noti_setting->teacher_id = $consultant->id;
        $noti_setting->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);

    }

    public function consultant_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $category_id = $request->category_id;

        $consultants = getDataFromCache('consultants', function () {
            return Teacher::where('type', 'consultant')
                ->with('categories:id','categories.translations')
                ->withCount('programs')
                ->withSum('programs', 'price') // مجموع أسعار البرامج
                ->get()
                ->map(function ($consultant) {
                    $consultant->makeHidden(['password']);
                    $consultant->categories->transform(function ($category) {
                        return $category->makeHidden(['pivot']);
                    });

                    return $consultant;
                });
        });

        if ($filter === 'latest') {
            $consultants = $consultants->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $consultants = $consultants->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $consultants = $consultants->sortBy(fn($consultant) => mb_strtolower($consultant->name))->values();
        } elseif ($filter === 'active_status') {
            $consultants = $consultants->where(fn($consultant) => $consultant->status === 'active')->values();
        } elseif ($filter === 'inactive_status') {
            $consultants = $consultants->where(fn($consultant) => $consultant->status === 'inactive')->values();
        }

        if ($search) {
            $consultants = $consultants->filter(function ($consultant) use ($search) {
                return mb_stripos($consultant->name, $search) !== false;
            })->values();
        }

        if ($category_id) {
            $consultants = $consultants->filter(function ($consultant) use ($category_id) {
                return in_array($category_id, $consultant->categories->pluck('id')->toArray());
            })->values();
        }

        $currentDate = Carbon::now();
        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
        foreach ($columns as $columnKey => $column):
            $Width = ($columnKey==0||$columnKey==10)? 25 : 15;
            $sheet->getColumnDimension($column)->setWidth($Width);
            $sheet->getStyle($column.'1')->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['rgb' => 'FFFF00'], // Yellow background
                ],
            ]);
        endforeach;
        $sheet->setRightToLeft(true);

        // Set cell values
        $sheet->setCellValue('A1', __('trans.teacher.name'));
        $sheet->setCellValue('B1', __('trans.teacher.phone'));
        $sheet->setCellValue('C1', __('trans.teacher.email'));
        $sheet->setCellValue('D1', __('trans.teacher.category'));
        $sheet->setCellValue('E1', __('trans.teacher.status'));
        $sheet->setCellValue('F1', __('trans.teacher.created_at'));

        foreach ($consultants as $key => $consultant):
            $key = $key+2;
            // $categories = $consultant->categories->pluck('title')->implode(', ');
            $categories = $consultant->categories->map(function ($category) {
                return optional($category->translations->firstWhere('locale', app()->getLocale()))->title;
            })->filter()->implode(', ');

            $sheet->setCellValue('A'.$key, $consultant->name ?? '-');
            $sheet->setCellValueExplicit('B'.$key, (string) $consultant->phone, DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$key, $consultant->email ?? '-');
            $sheet->setCellValue('D'.$key, $categories ?: '-');
            $sheet->setCellValue('E'.$key, $consultant->status == 'active' ? __('trans.teacher.active') : __('trans.teacher.inactive'));
            $sheet->setCellValue('F'.$key, Carbon::parse($consultant->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.teacher.title')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }
}
