<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSolution;
use App\Models\Teacher;
use Illuminate\Http\Request;

class AssignmentSolutionController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $category_id = $request->category_id;
        $search = $request->search;

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

        $assignment_solutions = AssignmentSolution::with(['assignment:id,title,program_id' ,
        'assignment.program:id', 'assignment.program.translations', 'student:id,name'])
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($solution) {
                if (!is_null($solution->grade)) {
                    if ($solution->grade > 0) {
                        $status = 'marked';
                    } else {
                        $status = 'not_marked';
                    }
                } elseif (is_null($solution->grade)) {
                    $status = 'not_marked';
                }

                $solution->status = $status;
                return $solution;
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

        // الشهر الحالي
        $current_month_assignments = $assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->month &&
                $a->created_at->year == now()->year;
        })->count();

        // الشهر الماضي
        $last_month_assignments = $assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->subMonth()->month &&
                $a->created_at->year == now()->subMonth()->year;
        })->count();

        // إجمالي المهام للشهر الحالي
        $current_month_total = $current_month_assignments;

        // إجمالي المهام للشهر الماضي
        $last_month_total = $last_month_assignments;

        // حساب النسبة
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
            })->count();

        // إجمالي المكتملين للشهر الماضي
        $last_month_completed = $assignments->filter(function ($a) {
            return $a->created_at &&
                $a->created_at->month == now()->subMonth()->month &&
                $a->created_at->year == now()->subMonth()->year &&
                $a->program &&
                $a->program->subscriptions_count == $a->solutions_count;
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
            $assignment_solutions = $assignment_solutions->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $assignment_solutions = $assignment_solutions->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $assignment_solutions = $assignment_solutions->sortBy(fn($solution) => mb_strtolower($solution->student->name))->values();
        } elseif ($filter === 'evaluated') {
            $assignment_solutions = $assignment_solutions->filter(fn($solution) => $solution->grade != null)->values();
        } elseif ($filter === 'not_evaluated') {
            $assignment_solutions = $assignment_solutions->filter(fn($solution) => $solution->grade == null)->values();
        }

        if ($category_id) {
            $assignments = $assignments->filter(function ($assignment) use ($category_id) {
                return $category_id == $assignment->program->category_id;
            })->values();

            $assignment_solutions = $assignment_solutions
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->get();
        }

        if($search){
            $assignment_solutions = $assignment_solutions->filter(function ($solution) use ($search) {
                return mb_stripos($solution->student->name, $search) !== false ||
                mb_stripos($solution->assignment->program->title, $search) !== false ||
                mb_stripos($solution->assignment->title, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($assignment_solutions, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'total_count' => $total_assignments,
            'assignments_percentage' => abs($total_percentage_change),
            'assignments_status' => $total_percentage_change >= 0 ? 'increase' : 'decrease',

            'completed_count' => $completed_assignments,
            'completed_percentage' => abs($completed_percentage_change),
            'completed_status' => $completed_percentage_change >= 0 ? 'increase' : 'decrease',

            'uncompleted_count' => $not_completed_assignments,
            'uncompleted_percentage' => abs($uncompleted_percentage_change),
            'uncompleted_status' => $uncompleted_percentage_change >= 0 ? 'increase' : 'decrease',

            'data' => $paginated,
        ]);
    }

    public function show(string $id)
    {
        $solution = AssignmentSolution::with([
            'assignment' ,
            'assignment.program.category:id', 'assignment.program.category.translations',
            'assignment.program' => function ($query) {
                $query->select('id','category_id')
                    ->with('translations')
                    ->withCount('subscriptions');
            },
            'student:id,name'
            ])
            ->findOr($id, function () {
                abort(response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.data_not_exist'),
                ], 422));
            });

        return response()->json([
            'success' => true,
            'data' => $solution
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'grade' => 'required|numeric',
        ]);

        $assignment_solution = AssignmentSolution::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $assignment_solution->grade = $validated['grade'];
        $assignment_solution->save();

        // updateInCache('assignment_solutions', $assignment_solution);
        updateInCache('assignment_' . $assignment_solution->assignment_id . '_solutions', $assignment_solution);

        return response()->json([
            'success' => true,
            'data' => $assignment_solution
        ]);
    }

}
