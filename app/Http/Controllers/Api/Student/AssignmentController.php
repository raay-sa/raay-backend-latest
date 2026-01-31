<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSolution;
use App\Models\Exam;
use App\Models\ExamStudent;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        // $categories_id = $request->categories_id;
        $categories_id = is_array($request->categories_id)
            ? array_filter($request->categories_id) // remove null, "", 0
            : $request->categories_id;

        $programs_id = is_array($request->programs_id)
            ? array_filter($request->programs_id)
            : $request->programs_id;

        $teachers_id = is_array($request->teachers_id)
            ? array_filter($request->teachers_id)
            : $request->teachers_id;

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscription_programs_id = Subscription::where('student_id', $user->id)->pluck('program_id');

        // Assignments
        $assignmentsQuery = Assignment::whereIn('program_id', $subscription_programs_id)
        ->with('program:id,category_id', 'program.translations', 'program.category:id', 'program.category.translations');

        $completed_assignments_id = Assignment::whereHas('solutions', function ($q) use ($user) {
            $q->where('student_id', $user->id);
        })->pluck('id');

        $total_assignments_count   = (clone $assignmentsQuery)->count();
        $completed_assignments     = (clone $assignmentsQuery)->whereIn('id', $completed_assignments_id)->count();
        $completed_assignments_pct = $total_assignments_count > 0
            ? round(($completed_assignments / $total_assignments_count) * 100, 2)
            : 0;
        $uncompleted_assignments_pct = 100 - $completed_assignments_pct;


        if (is_array($categories_id) && !empty($categories_id)) {
            $assignmentsQuery->whereHas('program', function ($q) use ($categories_id) {
                $q->whereIn('category_id', $categories_id);
            });
        }
        if (is_array($programs_id) && !empty($programs_id)) {
            $assignmentsQuery->whereHas('program', function ($q) use ($programs_id) {
                $q->whereIn('id', $programs_id);
            });
        }
        if (is_array($teachers_id) && !empty($teachers_id)) {
            $assignmentsQuery->whereHas('program.teacher', function ($q) use ($teachers_id) {
                $q->whereIn('id', $teachers_id);
            });
        }
        if ($filter == 'submitted') {
            $assignmentsQuery->whereHas('solutions', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        }
        if ($filter == 'not_submitted') {
            $assignmentsQuery->whereDoesntHave('solutions', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        }
        if($filter == 'evaluated') {
            $assignmentsQuery->whereHas('solutions', function ($q) use ($user) {
                $q->where('student_id', $user->id)->whereNotNull('grade');
            });
        }
        if($filter == 'time_out') {
            $assignmentsQuery->where('date', '<', now());
        }


        // Exams
        $exams_query = Exam::whereIn('program_id', $subscription_programs_id)
            ->where('user_type', $user->type)
            ->with('program:id,category_id', 'program.translations', 'program.category:id', 'program.category.translations');

        // Apply filters to exams
        if (is_array($categories_id) && !empty($categories_id)) {
            $exams_query->whereHas('program', function ($q) use ($categories_id) {
                $q->whereIn('category_id', $categories_id);
            });
        }
        if (is_array($programs_id) && !empty($programs_id)) {
            $exams_query->whereHas('program', function ($q) use ($programs_id) {
                $q->whereIn('id', $programs_id);
            });
        }
        if (is_array($teachers_id) && !empty($teachers_id)) {
            $exams_query->whereHas('program.teacher', function ($q) use ($teachers_id) {
                $q->whereIn('id', $teachers_id);
            });
        }
        if ($filter == 'submitted') {
            $exams_query->whereHas('answers', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        }
        if ($filter == 'not_submitted') {
            $assignmentsQuery->whereDoesntHave('answers', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        }
        if($filter == 'passed') {
            $exams_query->whereHas('answers', function ($q) use ($user) {
                $q->where('student_id', $user->id)
                ->whereColumn('grade', '>=', 'exams.success_rate');
            });
        }
        if($filter == 'not_passed') {
            $exams_query->whereHas('answers', function ($q) use ($user) {
                $q->where('student_id', $user->id)
                ->whereColumn('grade', '<', 'exams.success_rate');
            });
        }
        if($filter == 'evaluated') {
            $exams_query->whereHas('answers', function ($q) use ($user) {
                $q->where('student_id', $user->id)->whereNotNull('grade');
            });
        }
        if($filter == 'time_out') {
            $exams_query->whereRaw('1 = 0'); // يرجع صفر نتائج
        }

        $student_exams_solutions = $exams_query->get();

        // Exam stats
        $success_exams_count = $student_exams_solutions->filter(function ($exam) use ($user) {
            $lastAttempt = $exam->answers()
                ->where('student_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->first();
            return $lastAttempt && $lastAttempt->grade >= $exam->success_rate;
        })->count();

        $total_exams_count = $student_exams_solutions->count();
        $success_percentage= $total_exams_count > 0
            ? round(($success_exams_count / $total_exams_count) * 100, 2)
            : 0;
        $unsuccess_percentage = 100 - $success_percentage;

        $assignment_ids = AssignmentSolution::where('student_id', $user->id)
        ->pluck('assignment_id')->toArray();

        $assignments = $assignmentsQuery
            ->withAggregate('solutions', 'grade')
            ->orderByDesc('id')
            ->get()
            ->map(function ($assignment) use ($assignment_ids) {
                $assignment->is_solved = in_array($assignment->id, $assignment_ids);
                $assignment->is_marked = $assignment->solutions_grade > 0;
                return $assignment;
            });

        $latestAttempts = ExamStudent::select('exam_id', 'grade')
            ->where('student_id', $user->id)
            ->whereIn('exam_id', function ($query) use ($subscription_programs_id) {
                $query->select('id')->from('exams')->whereIn('program_id', $subscription_programs_id);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('exam_id')
            ->keyBy('exam_id');

        $exams = $exams_query
            ->withCount('questions')
            ->get()
            ->map(function ($exam) use ($user, $latestAttempts) {
                $allAttempts = $exam->tries_count;
                $usedAttempts = ExamStudent::where('student_id', $user->id)
                    ->where('exam_id', $exam->id)
                    ->count();

                $exam->remaining_tries = max($allAttempts - $usedAttempts, 0);
                $exam->last_grade = $latestAttempts[$exam->id]->grade ?? null;
                $exam->is_solved = max($allAttempts - $usedAttempts, 0) < $exam->tries_count;

                //  آخر محاولة للطالب
                $lastAttempt = $latestAttempts->firstWhere('exam_id', $exam->id);

                $exam->last_grade = $lastAttempt->grade ?? null;
                // $exam->is_solved = ($lastAttempt && $lastAttempt->grade !== null && $lastAttempt->grade > 0);
                $exam->is_marked = $lastAttempt && $lastAttempt->grade !== null;

                return $exam;
            });

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($assignments, $perPage, $currentPage);
        $exam_paginated = paginationData($exams, $perPage, $currentPage);

        return response()->json([
            'success'                 => true,
            'completed_assignments'   => $completed_assignments_pct,
            'uncompleted_assignments' => $uncompleted_assignments_pct,
            'success_exams'           => $success_percentage,
            'unsuccess_exams'         => $unsuccess_percentage,
            'data' => [
                'assignments' => $paginated,
                'exams'       => $exam_paginated
            ]
        ]);
    }

    public function program_assignments(Request $request, string $id)
    {
        $program_assignments = Assignment::where('program_id', $id)->get();

        return response()->json([
            'success' => true,
            'data' => $program_assignments ?? []
        ]);
    }

    public function teachers_list(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscription_programs_id = Subscription::where('student_id', $user->id)->pluck('program_id');

        $program_teachers = Teacher::whereHas('programs', function ($q) use ($subscription_programs_id) {
            $q->whereIn('programs.id', $subscription_programs_id);
        })
        ->select('id', 'name')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $program_teachers ?? []
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
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $assignment = Assignment::withAggregate('solutions', 'grade')->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $assignment->program_id)
            ->first();

        if (!$subscription) {
            return response()->json([
                'error' => __('trans.alert.error.Student_not_subscribed_to_this_program')
            ], 422);
        }

        if (($subscription->expire_date && $subscription->expire_date->isPast()) || $subscription->status === 'banned')
        {
            return response()->json([
                'error' => __('trans.alert.error.subscription_has_expired')
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $assignment ?? [],
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }
}
