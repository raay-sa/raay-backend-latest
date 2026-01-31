<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Program;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $students_count = Student::count();
        $teachers_count = Teacher::count();
        $programs_count = Program::count();
        $categories_count = Category::count();

        return response()->json([
            'success' => true,
            'data'    => [
                'students_count'  => $students_count,
                'teachers_count'  => $teachers_count,
                'programs_count'  => $programs_count,
                'categories_count' => $categories_count
            ]
        ]);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
       //
    }

    public function destroy($id)
    {
        //
    }
}
