<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    public function index(Request $request)
    {
        $programs = Program::where('status', 1)
            ->where('is_approved', 1)
            ->select('id', 'image', 'price', 'type', 'category_id')
            ->with('category:id', 'category.translations', 'translations')
            ->withSum('sessions as program_duration', 'video_duration')
            ->get()
            ->map(function ($program) {
                $program->program_duration = formatDuration($program->program_duration);
                return $program;
            });

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($programs, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function online_programs_3(Request $request)
    {
        $user = $request->user();

        $programs = Program::where('status', 1)
            ->where('is_approved', 1)
            ->select('id', 'date_from', 'date_to', 'time', 'duration')
            ->with('translations')
            ->where('type', 'live')
            ->withSum('sessions as program_duration', 'video_duration')
            ->withCount('subscriptions')
            ->inRandomOrder()
            ->take(3)
            ->get()
            ->map(function ($program) use ($user) {
                $program->program_duration = formatDuration($program->program_duration);

                // Check if user has registered interest in this program
                $program->is_interested = false;
                if ($user && $user instanceof \App\Models\Student && in_array($user->type, ['student', 'trainee'])) {
                    $banner = \App\Models\Banner::where('program_id', $program->id)->first();
                    if ($banner) {
                        $program->is_interested = \App\Models\BannerInterest::where('banner_id', $banner->id)
                            ->where('student_id', $user->id)
                            ->exists();
                    }
                }

                return $program;
            });

        return response()->json([
            'success' => true,
            'data' => $programs
        ]);
    }

    public function online_programs(Request $request)
    {
        $category_id = $request->category_id;
        $user = $request->user();

        $programs = Program::where('status', 1)
            ->where('is_approved', 1)
            ->select('id', 'image', 'price', 'category_id', 'date_from', 'date_to', 'time')
            ->where('type', 'live')
            ->with('category:id', 'category.translations', 'translations')
            ->withSum('sessions as program_duration', 'video_duration')
            ->get()
            ->map(function ($program) use ($user) {
                $program->program_duration = formatDuration($program->program_duration);

                // Check if user has registered interest in this program
                $program->is_interested = false;
                if ($user && $user instanceof \App\Models\Student && in_array($user->type, ['student', 'trainee'])) {
                    $banner = \App\Models\Banner::where('program_id', $program->id)->first();
                    if ($banner) {
                        $program->is_interested = \App\Models\BannerInterest::where('banner_id', $banner->id)
                            ->where('student_id', $user->id)
                            ->exists();
                    }
                }

                return $program;
            });

        if ($category_id) {
            $programs = $programs->where('category_id', $category_id);
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($programs, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function category_programs(Request $request, string $id)
    {
        $programs = Program::where('category_id', $id)
            ->where('status', 1)
            ->where('is_approved', 1)
            ->select('id', 'image', 'price', 'level', 'type', 'date_from', 'date_to', 'time', 'duration', 'category_id')
            ->with('category:id', 'category.translations', 'translations')
            ->withSum('sessions as program_duration', 'video_duration')
            ->get()
            ->map(function ($program) {
                $program->program_duration = formatDuration($program->program_duration);
                return $program;
            });

        return response()->json([
            'success' => true,
            'data' => $programs
        ]);
    }

    public function show(Request $request, string $id)
    {
        $program = Program::where('status', 1)
            ->where('is_approved', 1)
            ->with(['category:id', 'category.translations', 'translations', 'teacher:id,name,image'])
            ->withSum('sessions as program_duration', 'video_duration')
            ->findOr($id, function () {
                abort(response()->json([
                    'success' => false,
                    'message' => __('trans.alert.error.data_not_exist'),
                ], 422));
            });

        $program->program_duration = formatDuration($program->program_duration);

        return response()->json([
            'success' => true,
            'data' => $program,
        ]);
    }

    public function recent_programs(Request $request)
    {
        $programs = Program::where('user_type', 'student')
            ->where('status', 1)
            ->where('is_approved', 1)
            ->select(['id', 'category_id', 'image', 'price', 'type', 'date_from', 'date_to', 'time', 'duration', 'teacher_id'])
            ->with('category:id', 'category.translations', 'translations', 'teacher:id,name,image')
            ->withSum('sessions as program_duration', 'video_duration')
            ->withCount('subscriptions')
            ->latest()
            ->take(3)
            ->get()
            ->map(function ($program) {
                $program->program_duration = formatDuration($program->program_duration);
                return $program;
            });

        return response()->json([
            'success' => true,
            'data' => $programs,
        ]);
    }

    public function registered_programs(Request $request)
    {
        $category_id = $request->category_id;

        $programs = Program::where('status', 1)
            ->where('is_approved', 1)
            ->select('id', 'image', 'price', 'category_id', 'duration')
            ->where('type', 'registered')
            ->with('category:id', 'category.translations', 'translations')
            ->withSum('sessions as program_duration', 'video_duration')
            ->get()
            ->map(function ($program) {
                $program->program_duration = formatDuration($program->program_duration);
                return $program;
            });

        if ($category_id) {
            $programs = $programs->where('category_id', $category_id);
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($programs, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function onsite_programs(Request $request)
    {
        $category_id = $request->category_id;
        $user = $request->user();

        $programs = Program::where('status', 1)
            ->where('is_approved', 1)
            ->select('id', 'image', 'price', 'category_id', 'address', 'url', 'date_from', 'date_to')
            ->where('type', 'onsite')
            ->with('category:id', 'category.translations', 'translations')
            ->withSum('sessions as program_duration', 'video_duration')
            ->get()
            ->map(function ($program) use ($user) {
                $program->program_duration = formatDuration($program->program_duration);

                // Check if user has registered interest in this program
                $program->is_interested = false;
                if ($user && $user instanceof \App\Models\Student && in_array($user->type, ['student', 'trainee'])) {
                    $banner = \App\Models\Banner::where('program_id', $program->id)->first();
                    if ($banner) {
                        $program->is_interested = \App\Models\BannerInterest::where('banner_id', $banner->id)
                            ->where('student_id', $user->id)
                            ->exists();
                    }
                }

                return $program;
            });

        if ($category_id) {
            $programs = $programs->where('category_id', $category_id);
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($programs, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }
}