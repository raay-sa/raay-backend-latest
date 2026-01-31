<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\Exam;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscriptions = Subscription::where('student_id', $user->id)
        ->pluck('program_id')->unique();

        $programs = Program::whereIn('id', $subscriptions)->with('translations')->get();
        $completedProgramsCount = 0;
        $inProgressProgramsCount = 0;
        $certificateProgramsCount = Certificate::where('student_id', $user->id)->count();

        foreach ($programs as $program) {
            $assignments = $program->assignments;
            $totalAssignments = $assignments->count();
            $exams = $program->exams;
            $totalExams = $exams->count();

            $solvedAssignments = $user->assignment_solutions()
                ->whereIn('assignment_id', $assignments->pluck('id'))
                ->pluck('assignment_id')
                ->unique()
                ->count();

            $solvedExams = $user->exam_answers()
                ->whereIn('exam_id', $exams->pluck('id'))
                ->pluck('exam_id')
                ->unique()
                ->count();

            // عدد البرامج الي الطالب اكملها ,,, وعدد البرامج الي لسه بيتقدم فيها
            if (
                $totalAssignments > 0 && $totalExams > 0 &&
                $solvedAssignments === $totalAssignments &&
                $solvedExams === $totalExams
            ) {
                $completedProgramsCount++;
            } elseif ($solvedAssignments > 0 || $solvedExams > 0) {
                $inProgressProgramsCount++;
            }

            if ($totalAssignments === 0 && $totalExams === 0) {
                continue; // تخطي البرامج اللي مالهاش مهام ولا اختبارات
            }

            // مستوي التقدم
            $totalRequired = $totalAssignments + $totalExams;
            $totalDone = $solvedAssignments + $solvedExams;

            $progressPercentage = $totalRequired > 0 ? ($totalDone / $totalRequired) * 100 : 0;
        }

        $notStartedProgramsCount = $programs->count() - $completedProgramsCount - $inProgressProgramsCount;

        return response()->json([
            'success' => true,
            'programs_count' => $programs->count(),
            'completed_programs_count' => $completedProgramsCount,
            'in_progress_programs_count' => $inProgressProgramsCount,
            'not_started_programs_count' => $notStartedProgramsCount,
            'certificates_count' => $certificateProgramsCount,
            // 'progressPercentage' => $progressPercentage
        ]);
    }

    public function student_progress(Request $request)
    {
        $filter = $request->filter; // weekly, monthly, yearly

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $counts = [];

        switch ($filter) {
            case 'weekly':
                for ($i = 6; $i >= 0; $i--) {
                    $startDate = Carbon::now()->subDays($i)->startOfDay();
                    $endDate = $startDate->copy()->endOfDay();
                    $counts[] = $this->getProgressForPeriod($user, $startDate, $endDate, $startDate->translatedFormat('l j M'));
                }
                break;

            case 'monthly':
            default:
                $startOfMonth = Carbon::now()->startOfMonth();
                $endOfMonth = Carbon::now()->endOfMonth();
                $startOfWeek = $startOfMonth->copy();
                $weekIndex = 1;

                while ($startOfWeek <= $endOfMonth) {
                    $endOfWeek = $startOfWeek->copy()->addDays(6)->endOfDay();
                    if ($endOfWeek > $endOfMonth) {
                        $endOfWeek = $endOfMonth->copy()->endOfDay();
                    }
                    $counts[] = $this->getProgressForPeriod($user, $startOfWeek, $endOfWeek, 'الأسبوع ' . $weekIndex);
                    $startOfWeek = $endOfWeek->copy()->addDay()->startOfDay();
                    $weekIndex++;
                }
                break;

            case 'yearly':
                for ($month = 1; $month <= 12; $month++) {
                    $startDate = Carbon::create(null, $month, 1)->startOfMonth();
                    $endDate = $startDate->copy()->endOfMonth();
                    $counts[] = $this->getProgressForPeriod($user, $startDate, $endDate, $startDate->translatedFormat('F'));
                }
                break;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'counts' => $counts,
            ]
        ]);
    }

    private function getProgressForPeriod($user, $startDate, $endDate, $label)
    {
        $subscriptions = Subscription::where('student_id', $user->id)
            ->pluck('program_id')
            ->unique();

        $programs = Program::whereIn('id', $subscriptions)->with('translations')->get();

        $totalRequired = 0;
        $totalDone = 0;

        foreach ($programs as $program) {
            $assignments = $program->assignments;
            $exams = $program->exams;

            $totalAssignments = $assignments->count();
            $totalExams = $exams->count();

            $solvedAssignments = $user->assignment_solutions()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('assignment_id', $assignments->pluck('id'))
                ->pluck('assignment_id')
                ->unique()
                ->count();

            $solvedExams = $user->exam_answers()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('exam_id', $exams->pluck('id'))
                ->pluck('exam_id')
                ->unique()
                ->count();

            $totalRequired += ($totalAssignments + $totalExams);
            $totalDone += ($solvedAssignments + $solvedExams);
        }

        $progressPercentage = $totalRequired > 0 ? round(($totalDone / $totalRequired) * 100, 2) : 0;

        return [
            'label' => $label,
            'progress' => $progressPercentage
        ];
    }

    public function contentStats(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $program_ids = Subscription::where('student_id', $user->id)->pluck('program_id')->unique()->toArray();

        $registered_programs_count = Program::where('type', 'registered')
        ->where('status', 1)->where('is_approved', 1)
        ->where('user_type', $user->type)->count();

        $live_programs_count = Program::where('type', 'live')
        ->where('status', 1)->where('is_approved', 1)
        ->where('user_type', $user->type)->count();

        $assignments_count = Assignment::whereIn('program_id', $program_ids)->count();
        $exams_count       = Exam::whereIn('program_id', $program_ids)->count();

        $total = $registered_programs_count + $live_programs_count + $assignments_count + $exams_count;

        $data = [
            'registered_programs_percentage' => $total > 0 ? round(($registered_programs_count / $total) * 100, 1) : 0,
            'live_programs_percentage'       => $total > 0 ? round(($live_programs_count / $total) * 100, 1) : 0,
            'tasks_percentage'               => $total > 0 ? round((($assignments_count + $exams_count) / $total) * 100, 1) : 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function important_data2(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $programs_id = Subscription::where('student_id', $user->id)->pluck('program_id')->unique()->toArray();
        $programs = Program::whereIn('id', $programs_id)->with('translations')->get();

        $student_exams_query = Exam::whereIn('program_id', $programs_id)
            ->whereHas('answers', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });

        $student_assignments_query = Assignment::whereIn('program_id', $programs_id)
            ->whereHas('solutions', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });

        $programs = [
            'label' => $programs,
        ];

        return response()->json([
            'success' => true,
            'programs' => $programs,
        ]);
    }

    public function important_data(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $programs_id = Subscription::where('student_id', $user->id)
            ->pluck('program_id')
            ->unique()
            ->toArray();

        $locale = $request->lang ?? app()->getLocale();

        $programs = Program::whereIn('id', $programs_id)
            ->with([
                'translations',
                'exams.answers' => function($q) use ($user) {
                    $q->where('student_id', $user->id);
                },
                'assignments.solutions' => function($q) use ($user) {
                    $q->where('student_id', $user->id);
                }
            ])
            ->withCount(['exams', 'assignments'])
            ->get()
            ->map(function ($program) use ($user, $locale) {
                $totalActivities = $program->exams_count + $program->assignments_count;
                $studentActivities = $program->exams->flatMap->answers->count()
                    + $program->assignments->flatMap->solutions->count();

                $interestPercentage = $totalActivities > 0
                    ? round(($studentActivities / $totalActivities) * 100, 2)
                    : 0;

                return [
                    'title' => $program->translations->firstWhere('locale', $locale)->title ?? '-',
                    'interest_percentage' => $interestPercentage,
                ];
            });

        return response()->json([
            'success' => true,
            'programs' => $programs
        ]);
    }

    public function keepWatching(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $program_ids = Subscription::where('student_id', $user->id)->pluck('program_id')->unique()->toArray();

        $programs = Program::with(['teacher:id,name,image', 'category:id', 'category.translations', 'translations'])
            ->whereIn('id', $program_ids)
            ->select('id', 'image', 'teacher_id', 'category_id')
            ->inRandomOrder()
            ->take(3)
            ->get();

        $completedProgramsCount = 0;
        $inProgressProgramsCount = 0;

        foreach ($programs as $program) {
            $assignments = $program->assignments;
            $totalAssignments = $assignments->count();
            $exams = $program->exams;
            $totalExams = $exams->count();

            $solvedAssignments = $user->assignment_solutions()
                ->whereIn('assignment_id', $assignments->pluck('id'))
                ->pluck('assignment_id')
                ->unique()
                ->count();

            $solvedExams = $user->exam_answers()
                ->whereIn('exam_id', $exams->pluck('id'))
                ->pluck('exam_id')
                ->unique()
                ->count();

            if (
                $totalAssignments > 0 && $totalExams > 0 &&
                $solvedAssignments === $totalAssignments &&
                $solvedExams === $totalExams
            ) {
                $completedProgramsCount++;
            } elseif ($solvedAssignments > 0 || $solvedExams > 0) {
                $inProgressProgramsCount++;
            }

            if ($totalAssignments === 0 && $totalExams === 0) {
                continue;
            }

            // مستوي التقدم
            $totalRequired = $totalAssignments + $totalExams;
            $totalDone = $solvedAssignments + $solvedExams;

            $program->progressPercentage = $totalRequired > 0 ? ($totalDone / $totalRequired) * 100 : 0;
        }

        $programs = $programs->map(function ($program) {
                $program->makeHidden(['assignments', 'exams', 'teacher_id', 'category_id']);
                return $program;
        });

        return response()->json([
            'success' => true,
            'programs' => $programs,
        ]);
    }

    public function recentPrograms(Request $request)
    {
        $category_id = $request->input('category_id');
        $user = $request->user();

        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $program_ids = Subscription::where('student_id', $user->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('expire_date', '>=', now())
                ->orWhereNull('expire_date');
            })
            ->pluck('program_id')
            ->unique()
            ->toArray();

        // آخر 2 برامج Registered
        $registered_programs_query = Program::whereNotIn('id', $program_ids)
            ->where('status', 1)->where('is_approved', 1)
            ->where('type', 'registered')
            ->select(['id', 'category_id'])
            ->withCount('sessions')
            ->withSum('sessions as program_duration', 'video_duration')
            ->with('category:id', 'translations')
            ->latest();

        if ($category_id) {
            $registered_programs_query->where('category_id', $category_id);
        }

        $registered_programs = $registered_programs_query->take(2)->get();
        $registered_programs->each(function ($program) {
            $program->program_duration = formatDuration($program->program_duration);
        });


        // آخر 2 برامج Live
        $live_programs_query = Program::whereNotIn('id', $program_ids)
            ->where('status', 1)->where('is_approved', 1)
            ->where('type', 'live')
            ->select(['id', 'category_id'])
            ->withCount('sessions')
            ->with('category:id', 'category.translations', 'translations')
            ->latest();

        if ($category_id) {
            $live_programs_query->where('category_id', $category_id);
        }

        $live_programs = $live_programs_query->take(2)->get();

        $registered_programs->makeHidden('teacher');
        $live_programs->makeHidden('teacher');

        return response()->json([
            'success' => true,
            'registered_programs' => $registered_programs,
            'live_programs' => $live_programs,
        ]);
    }

    
    public function bestPrograms(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $program_ids = Subscription::where('student_id', $user->id)
        ->where('status', 'active')
        ->where(function ($q) {
            $q->where('expire_date', '>=', now())
            ->orWhereNull('expire_date');
        })
        ->pluck('program_id')->unique()->toArray();

        $programs = Program::with(['teacher:id,name,image', 'category:id', 'category.translations', 'translations'])
            ->whereNotIn('id', $program_ids)
            ->where('status', 1)->where('is_approved', 1)
            ->where('user_type', $user->type)
            ->select('id', 'image', 'price', 'teacher_id', 'category_id')
            ->withAvg('reviews', 'score')
            ->withCount('reviews')
            ->inRandomOrder()
            ->take(3)
            ->get();

        return response()->json([
            'success' => true,
            'programs' => $programs,
        ]);
    }

}
