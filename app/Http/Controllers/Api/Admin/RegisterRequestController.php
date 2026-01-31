<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class RegisterRequestController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $users_type = $request->users_type;
        $search = $request->search;
        $category_id = $request->category_id;

        $calcChange = fn($current, $previous) => $previous > 0
            ? round((($current - $previous) / $previous) * 100, 2)
            : 100;

        $students = Student::where('type', 'student')
            ->get()
            ->map(function ($student) {
                $student->makeHidden(['password']);
                return $student;
            });

        $total_students = $students->count();
        $current_students = $students->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $previous_students = $students->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        $percentage_change = $calcChange($current_students, $previous_students);

        // =========================================================

        $teachers = Teacher::where('type', 'teacher')->where('is_approved', 0)
            ->with(['categories:id', 'categories.translations'])
            ->select('id', 'name', 'email', 'phone', 'created_at', 'is_approved')
            ->get()
            ->map(function ($teacher) {
                $teacher->makeHidden(['password']);
                return $teacher;
            });

        $total_teachers = $teachers->count();
        $current_teachers = $teachers->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $previous_teachers = $teachers->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])->count();
        // نسبة الزيادة أو النقصان
        $teacher_percentage_change = $calcChange($current_teachers, $previous_teachers);

        if($users_type == 'teachers')
        {
            if ($filter === 'latest') {
                $teachers = $teachers->sortByDesc('created_at')->values();
            } elseif ($filter === 'oldest') {
                $teachers = $teachers->sortBy('created_at')->values();
            } elseif ($filter === 'name') {
                $teachers = $teachers->sortBy(fn($teacher) => mb_strtolower($teacher->name))->values();
            }

            if ($search) {
                $teachers = $teachers->filter(function ($teacher) use ($search) {
                    return mb_stripos($teacher->name, $search) !== false;
                })->values();
            }

            if ($category_id) {
                $teachers = $teachers->filter(function ($teacher) use ($category_id) {
                    return in_array($category_id, $teacher->categories->pluck('id')->toArray());
                })->values();
            }
        }


        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated_teachers = paginationData($teachers, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_students' => $total_students,
            'student_percentage' => abs($percentage_change),
            'student_status' => $percentage_change >= 0 ? 'increase' : 'decrease',

            'total_teachers' => $total_teachers,
            'teacher_percentage' => abs($teacher_percentage_change),
            'teacher_status' => $teacher_percentage_change >= 0 ? 'increase' : 'decrease',

            'teachers' => $paginated_teachers,
        ]);
    }

    public function update_teacher(Request $request, $id)
    {
        $row = Teacher::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ]), 422);
        });

        $row->is_approved = !$row->is_approved;
        $row->save();

        // update cache
        updateInCache('teachers', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row,
        ], 200);
    }

    public function teacher_excel(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;
        $category_id = $request->category_id;

        $teachers = Teacher::where('type', 'teacher')->where('is_approved', 0)
            ->with(['categories:id', 'categories.translations'])
            ->get()
            ->map(function ($teacher) {
                $teacher->makeHidden(['password']);
                return $teacher;
            });


        if ($filter === 'latest') {
            $teachers = $teachers->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $teachers = $teachers->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $teachers = $teachers->sortBy(fn($teacher) => mb_strtolower($teacher->name))->values();
        }

        if ($search) {
            $teachers = $teachers->filter(function ($teacher) use ($search) {
                return mb_stripos($teacher->name, $search) !== false;
            })->values();
        }

        if ($category_id) {
            $teachers = $teachers->filter(function ($teacher) use ($category_id) {
                return in_array($category_id, $teacher->categories->pluck('id')->toArray());
            })->values();
        }

        $currentDate = Carbon::now();
        // Create a new Spreadsheet object
        $spreadsheet = new Spreadsheet();

        // Get the active sheet
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E'];
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
        $sheet->setCellValue('E1', __('trans.teacher.created_at'));

        foreach ($teachers as $key => $teacher):
            $key = $key+2;
            // $categories = $teacher->categories->pluck('title')->implode(', ');
            $categories = $teacher->categories->map(function ($category) {
                return optional($category->translations->firstWhere('locale', app()->getLocale()))->title;
            })->filter()->implode(', ');

            $sheet->setCellValue('A'.$key, $teacher->name ?? '-');
            $sheet->setCellValueExplicit('B'.$key, (string) $teacher->phone, DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$key, $teacher->email ?? '-');
            $sheet->setCellValue('D'.$key, $categories ?: '-');
            $sheet->setCellValue('E'.$key, Carbon::parse($teacher->created_at)->format('Y/m/d - H:i') ?? '-');
        endforeach;

        $writer = new Xlsx($spreadsheet);
        $today = date('Y-m-d');
        $fileName = __('trans.teacher.title')."- $today".".xlsx";
        $writer->save($fileName);
        return response()->download(public_path($fileName))->deleteFileAfterSend(true);
    }
}
