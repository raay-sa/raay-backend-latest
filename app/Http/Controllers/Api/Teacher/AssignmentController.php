<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Program;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AssignmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $category_id = $request->category_id;

        $user = $request->user();
        if (!$user || !($user instanceof Teacher)) {
            return response()->json(['success' => false, 'message' => 'teacher_access_required'], 403);
        }

        // $assignments = $user->assignments->sortByDesc('id');
        $assignments = $user->assignments()
        ->with([
            'program.category:id', 'program.category.translations',
            'program' => function ($query) {
                $query->select('id','category_id')
                    ->with('translations')
                    ->withCount('subscriptions');
            }
        ])
        ->orderByDesc('id')
        ->get()
        ->each(function ($assignment) {
            if ($assignment->program) {
                $assignment->program->makeHidden(['teacher']);
            }
        });

        $total_assignments = $assignments->count();
        $completed_assignments = $assignments->filter(function ($assignment) {
            return $assignment->program &&
                $assignment->program->subscriptions_count == $assignment->solutions_count;
        })->count();
        $not_completed_assignments = $assignments->filter(function ($assignment) {
            return $assignment->program &&
                $assignment->program->subscriptions_count != $assignment->solutions_count;
        })->count();

        $exams = $user->exams()
        ->with([
            'program.category:id', 'program.category.translations',
            'program' => function ($query) {
                $query->select('id','category_id')
                    ->with('translations')
                    ->withCount('subscriptions');
            }
        ])
        ->select('exams.id', 'exams.title', 'exams.duration', 'exams.program_id')
        ->withCount('questions')
        // ->orderByDesc('id')
        ->orderByDesc('exams.id')
        ->get()
        ->each(function ($exam) {
            if ($exam->program) {
                $exam->program->makeHidden(['teacher']);
                $exam->makeHidden(['answers']);
            }

            $exam->students_answered_count = $exam->answers->pluck('student_id')->unique()->count();
        });

        $total_exams = $exams->count();
        $completed_exams = $exams->filter(function ($exam) {
            return $exam->program &&
                $exam->program->subscriptions_count == $exam->students_answered_count;
        })->count();
        $not_completed_exams = $exams->filter(function ($exam) {
            return $exam->program &&
                $exam->program->subscriptions_count != $exam->students_answered_count;
        })->count();


        $total_count = $total_assignments + $total_exams;
        $completed_count = $completed_assignments + $completed_exams;
        $uncompleted_count = $not_completed_assignments + $not_completed_exams;


        // الشهر الحالي
        $current_month_assignments = $assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->month &&
                $a->created_at->year == now()->year;
        })->count();

        $current_month_exams = $exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->month &&
                $e->created_at->year == now()->year;
        })->count();

        // الشهر الماضي
        $last_month_assignments = $assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->subMonth()->month &&
                $a->created_at->year == now()->subMonth()->year;
        })->count();

        $last_month_exams = $exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->subMonth()->month &&
                $e->created_at->year == now()->subMonth()->year;
        })->count();
        // إجمالي المهام والاختبارات للشهر الحالي
        $current_month_total = $current_month_assignments + $current_month_exams;

        // إجمالي المهام والاختبارات للشهر الماضي
        $last_month_total = $last_month_assignments + $last_month_exams;

        //  النسبة
        $total_percentage_change = $last_month_total > 0
            ? round((($current_month_total - $last_month_total) / $last_month_total) * 100, 2)
            : 100;



        // إجمالي المكتملين للشهر الحالي
        $current_month_completed = $assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->month &&
                $a->created_at->year == now()->year &&
                $a->program &&
                $a->program->subscriptions_count == $a->solutions_count;
            })->count()
        +
        $exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->month &&
                $e->created_at->year == now()->year &&
                $e->program &&
                $e->program->subscriptions_count == $e->students_answered_count;
            })->count();

        // إجمالي المكتملين للشهر الماضي
        $last_month_completed = $assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->subMonth()->month &&
                $a->created_at->year == now()->subMonth()->year &&
                $a->program &&
                $a->program->subscriptions_count == $a->solutions_count;
            })->count()
        +
        $exams->filter(function ($e) {
            return $e->created_at &&
                $e->created_at->month == now()->subMonth()->month &&
                $e->created_at->year == now()->subMonth()->year &&
                $e->program &&
                $e->program->subscriptions_count == $e->students_answered_count;
            })->count();

        // نسبة المكتملين
        $completed_percentage_change = $last_month_completed > 0
            ? round((($current_month_completed - $last_month_completed) / $last_month_completed) * 100, 2)
            : 100;


        // لغير المكتملين
        $current_month_uncompleted = $current_month_total - $current_month_completed;
        $last_month_uncompleted = $last_month_total - $last_month_completed;

        $uncompleted_percentage_change = $last_month_uncompleted > 0
            ? round((($current_month_uncompleted - $last_month_uncompleted) / $last_month_uncompleted) * 100, 2)
            : 100;

        if ($filter === 'latest') {
            $assignments = $assignments->sortByDesc('created_at')->values();
            $exams = $exams->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $assignments = $assignments->sortBy('created_at')->values();
            $exams = $exams->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $assignments = $assignments->sortBy(fn($assignment) => mb_strtolower($assignment->name))->values();
            $exams = $exams->sortBy(fn($exam) => mb_strtolower($exam->name))->values();
        }

        if ($category_id) {
            $assignments = $assignments->filter(function ($assignment) use ($category_id) {
                return $category_id == $assignment->program->category_id;
            })->values();

            $exams = $exams->filter(function ($exam) use ($category_id) {
                return $category_id == $exam->program->category_id;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $assignments_paginated = paginationData($assignments, $perPage, $currentPage);
        $exams_paginated = paginationData($exams, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_count' => $total_count,
            'assignments_percentage' => abs($total_percentage_change),
            'assignments_status' => $total_percentage_change >= 0 ? 'increase' : 'decrease',

            'completed_count' => $completed_count,
            'completed_percentage' => abs($completed_percentage_change),
            'completed_status' => $completed_percentage_change >= 0 ? 'increase' : 'decrease',

            'uncompleted_count' => $uncompleted_count,
            'uncompleted_percentage' => abs($uncompleted_percentage_change),
            'uncompleted_status' => $uncompleted_percentage_change >= 0 ? 'increase' : 'decrease',

            'assignments' => $assignments_paginated,
            'exams' => $exams_paginated,
        ]);
    }

    public function program_assignments(Request $request, string $id)
    {
        $program = Program::with('translations')
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist')
           ], 422));
        });

        if(!$program){
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist')
            ], 422);
        }

        $assignments = getDataFromCache('program_' . $id . '_assignments', Assignment::class)->sortByDesc('id');

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($assignments, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
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
            'title' => 'required|string|max:255',
            'date' => 'required|date',
            'description' => 'required|string',
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'validation_error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = new Assignment();
        $row->title = $request->title;
        $row->date = $request->date;
        $row->description = $request->description;
        $row->program_id = $request->program_id;
        $row->save();

        storeInCache('program_' . $request->program_id . '_assignments', $row);

        return response()->json([
            'success' => true,
            'data' => $row,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $row = Assignment::with([
            'program.category:id', 'program.category.translations',
            'program' => function ($query) {
                $query->select('id','category_id')
                    ->with('translations')
                    ->withCount('subscriptions');
            }
        ])
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $row->program->makeHidden(['teacher']);

        return response()->json([
            'success' => true,
            'data' => $row,
        ]);
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
            'title' => 'required|string|max:255',
            'date' => 'required|date|date_format:Y-m-d H:i:s',
            'description' => 'required|string',
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'validation_error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $assignments = getDataFromCache('program_' . $request->program_id . '_assignments', Assignment::class);
        $row = $assignments->firstWhere('id', $id);

        if (!$row) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $row->title = $request->title;
        $row->date = $request->date;
        $row->description = $request->description;
        $row->program_id = $request->program_id;
        $row->save();

        updateInCache('program_' . $request->program_id . '_assignments', $row);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data' => $row,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $assignment = Assignment::find($id);
        if(!$assignment) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422);
        }

        $assignment->delete();

        deleteFromCache('program_' . $assignment->program_id . '_assignments', $assignment);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }
}
