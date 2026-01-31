<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Favorite;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\SessionView;
use App\Models\Student;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgramController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $categories_id = is_array($request->categories_id)
            ? array_filter($request->categories_id) // remove null, "", 0
            : $request->categories_id;

        $programs_id = is_array($request->programs_id)
            ? array_filter($request->programs_id) // remove null, "", 0
            : $request->programs_id;

        $score = $request->input('score');
        $price_from = $request->price_from;
        $price_to = $request->price_to;

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscribedPrograms = Subscription::where('student_id', $user->id)
        ->where('status', 'active')
        ->where(function ($q) {
            $q->where('expire_date', '>=', now())
            ->orWhereNull('expire_date');
        })
        ->pluck('program_id')
        ->unique()
        ->toArray();

        $favorites = Favorite::where('student_id', $user->id)
        ->pluck('program_id')->toArray();

        $cart = Cart::where('student_id', $user->id)
        ->pluck('program_id')->toArray();

        $programs = Program::where('user_type', $user->type)->where('status', 1)->where('is_approved', 1)
        ->whereNotIn('id', $subscribedPrograms) // not exist in my subscriptions
        ->select('id', 'image', 'price', 'type', 'is_live', 'teacher_id', 'category_id')
        ->withAvg('reviews', 'score')
        ->withCount('reviews')
        ->with(['category:id', 'category.translations', 'teacher:id,name,image', 'translations'])
        ->get()
        ->map(function ($program) use ($favorites, $cart) {
            $program->is_subscribed = false; // because these programs not in subscriptions
            $program->is_favorited = in_array($program->id, $favorites);
            $program->is_in_cart = in_array($program->id, $cart);
            return $program;
        });

        if($categories_id){
            $programs = $programs->whereIn('category_id', $categories_id);
        }

        if($programs_id){
            $programs = $programs->whereIn('id', $programs_id);
        }

        if($score){
            $programs = $programs->where('reviews_avg_score', $score);
        }

        // فلترة حسب السعر من - إلى
        if ($price_from !== null || $price_to !== null) {
            $programs = $programs->filter(function ($program) use ($price_from, $price_to) {
                $price = $program->price;

                if ($price_from !== null && $price < $price_from) {
                    return false;
                }

                if ($price_to !== null && $price > $price_to) {
                    return false;
                }

                return true;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($programs, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function list(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $subscriptions = Subscription::where('student_id', $user->id)
        ->pluck('program_id')->unique();

        $programs = Program::whereIn('id', $subscriptions)
        ->where('status', 1)->where('is_approved', 1)
        ->select('id', 'type')
        ->with('translations')
        ->get()
        ->map(function ($program) {
            $program->makeHidden(['category', 'teacher']);
            return $program;
        });

        return response()->json([
            'success' => true,
            'data' => $programs
        ]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $favorites = Favorite::where('student_id', $user->id)
        ->pluck('program_id')->toArray();

        $cart = Cart::where('student_id', $user->id)
        ->pluck('program_id')->toArray();

        $program = Program::with([
            'translations',
            'teacher' => function ($q) {
                $q
                ->with('categories:id', 'categories.translations')
                ->withCount('programs')
                ->withCount([
                    'programs as trainees_count' => function ($query) {
                        $query->join('subscriptions', 'programs.id', '=', 'subscriptions.program_id')
                            ->select(DB::raw('COUNT(DISTINCT subscriptions.student_id)'));
                    }
                ]);
            },
            'category:id', 'category.translations',
            'reviews.student:id,name,image',
            'sections' => function ($q) {
                $q->withCount('sessions as video_count')
                ->withSum('sessions as section_duration', 'video_duration')
                ->with('free_materials', 'translations');
            },
            'subscriptions' => function ($q) use ($user) {
                $q->where('student_id', $user->id);
            },
        ])
        ->withCount('sections')
        ->withCount('sessions as video_count')
        ->withSum('sessions as program_duration', 'video_duration')
        ->withAvg('reviews', 'score')
        ->withCount(['reviews', 'subscriptions'])
        ->findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        // $program->is_subscribed = in_array($program->id, $subscriptions);
        $program->is_favorited = in_array($program->id, $favorites);
        $program->is_in_cart = in_array($program->id, $cart);

        $subscription = Subscription::where('student_id', $user->id)
        ->where('program_id', $id)->first();

        $program->is_subscribed = false;
        if($subscription){
            $expireDate = $subscription->expire_date ?? null;
            $status = $subscription->status ?? null;

            if ($status === 'banned') {
                $program->is_subscribed = false;
            } else {
                $program->is_subscribed = !$expireDate || \Carbon\Carbon::parse($expireDate) >= now();
            }
        }

        $program->program_duration = formatDuration($program->program_duration);

        // ============== files ==============
        // حساب عدد الملفات من جميع السيشنز
        $filesCount = 0;
        foreach ($program->sections as $section) {
            foreach ($section->sessions as $session) {
                if (!empty($session->files)) {
                    $files = is_array($session->files)
                        ? $session->files
                        : json_decode($session->files, true);

                    if (is_array($files)) {
                        $filesCount += count($files);
                    }
                }
            }
            $section->section_duration = formatDuration($section->section_duration);
        }

        $program->files_count = $filesCount;

        // ============== reviews ==============
        $totalReviews = $program->reviews->count();
        $starCounts = $program->reviews->groupBy('score')->map->count();
        // النتائج
        $ratings = [];
        for ($i = 5; $i >= 1; $i--) {
            $count = $starCounts[$i] ?? 0;
            $percentage = $totalReviews > 0 ? round(($count / $totalReviews) * 100) : 0;

            $ratings[$i] = [ // $ratings[$i.'_stars'] = [
                'percentage' => $percentage,
            ];
        }
        // حساب متوسط التقييم
        $average = $totalReviews > 0 ? round($program->reviews->avg('score'), 1) : 0;

        // تاريخ الاشتراك
        $program->subscribed_at = $program->subscriptions->first()?->created_at->format('Y-m-d') ?? null;
        $program->expired_at = $program->subscriptions->first()?->expire_date ?? null;

        $program->makeHidden('subscriptions');

        $assignments = $program->assignments;
        $totalAssignments = $assignments->count();

        $exams = $program->exams;
        $totalExams = $exams->count();

        $sessions = $program->sessions;
        $totalSessions = $sessions->count();

        // المهام المحلولة
        $solvedAssignments = $user->assignment_solutions()
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->pluck('assignment_id')
            ->unique()
            ->count();

        // الاختبارات المحلولة
        $solvedExams = $user->exam_answers()
            ->whereIn('exam_id', $exams->pluck('id'))
            ->pluck('exam_id')
            ->unique()
            ->count();

        // الفيديوهات اللي اتشافت
        $watchedSessions = $user->session_views()
            ->whereIn('session_id', $sessions->pluck('id'))
            ->pluck('session_id')
            ->unique()
            ->count();

        // مستوي التقدم
        $totalRequired = $totalAssignments + $totalExams + $totalSessions;
        $totalDone = $solvedAssignments + $solvedExams + $watchedSessions;

        $program->progressPercentage = $totalRequired > 0
            ? round(($totalDone / $totalRequired) * 100, 2)
            : 0;

        $program->showCertificate = (
            $totalAssignments === $solvedAssignments &&
            $totalExams === $solvedExams &&
            $totalSessions === $watchedSessions
        );

        return response()->json([
            'success' => true,
            'totalExams' => $totalExams,
            'data' => $program,
            'reviews_summary' => [
                'total' => $totalReviews,
                'average' => $average,
                'stars' => $ratings,
            ],
        ]);
    }

    public function bestPrograms(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $category_id = $request->category_id;
        $program_ids = Subscription::where('student_id', $user->id)->pluck('program_id')->unique()->toArray();

        $programs = Program::where('user_type', $user->type)
            ->where('category_id', $category_id)
            ->where('status', 1)->where('is_approved', 1)
            ->whereNotIn('id', $program_ids)
            ->with(['teacher:id,name,image', 'category:id', 'category.translations', 'translations'])
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

    public function search_programs(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $favorites = Favorite::where('student_id', $user->id)
        ->pluck('program_id')->toArray();

        $cart = Cart::where('student_id', $user->id)
        ->pluck('program_id')->toArray();

        // $subscriptions = Subscription::where('student_id', $user->id)
        // ->pluck('program_id')->unique()->toArray();

        $subscriptions = Subscription::where('student_id', $user->id)
            ->get(['program_id', 'expire_date']);

        // نخزن الاشتراكات مع تاريخ الانتهاء
        $subscriptionsMap = $subscriptions->mapWithKeys(function ($sub) {
            return [$sub->program_id => $sub->expire_date];
        });

        $programs = Program::where('user_type', $user->type)
            ->where('status', 1)->where('is_approved', 1)
            ->whereHas('translations', function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%');
            })
            ->with(['teacher:id,name,image', 'category:id', 'category.translations', 'translations'])
            ->select('id','image', 'price', 'teacher_id', 'category_id')
            ->withAvg('reviews', 'score')
            ->withCount('reviews')
            ->get()
            ->map(function ($program) use ($favorites, $cart, $subscriptionsMap) {
                $program->is_favorite = in_array($program->id, $favorites);
                $program->is_in_cart = in_array($program->id, $cart);
                $expireDate = $subscriptionsMap[$program->id] ?? null;
                // $program->is_subscribed = in_array($program->id, $subscriptions);
                $program->is_subscribed = $expireDate && \Carbon\Carbon::parse($expireDate)->isFuture();
                return $program;
            });

        return response()->json([
            'success' => true,
            'programs' => $programs,
        ]);

    }

    public function session_views(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $session = ProgramSession::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $session_exists = SessionView::where('session_id', $id)
        ->where('student_id', $user->id)
        ->exists();

        if(!$session_exists){
            $session_view = new SessionView();
            $session_view->session_id = $id;
            $session_view->student_id = $user->id;
            $session_view->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
        ]);
    }
}

