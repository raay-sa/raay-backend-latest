<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\BannerInterest;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BannerController extends Controller
{
    /**
     * Display a listing of active banners
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = Banner::active()->with(['program']);

        // Search by title
        if ($request->has('search') && $request->search) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        // Get all banners 
        $banners = $query->orderBy('created_at', 'desc')->get();

        // Add interest status for each banner
        $banners->transform(function ($banner) use ($user) {
            // Check if user is authenticated student before checking interest
            $banner->has_registered_interest = ($user && $user instanceof Student) ? $banner->interests()
                ->where('student_id', $user->id)
                ->exists() : false;
            $banner->interested_students_count = $banner->interests()->count();
            return $banner;
        });

        return response()->json([
            'success' => true,
            'data' => $banners
        ]);
    }

    /**
     * Display the specified banner
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        
        $banner = Banner::active()->with(['program'])->find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        // Add interest status
        $banner->has_registered_interest = ($user && $user instanceof Student) ? $banner->interests()
            ->where('student_id', $user->id)
            ->exists() : false;
        $banner->interested_students_count = $banner->interests()->count();

        return response()->json([
            'success' => true,
            'data' => $banner
        ]);
    }

    /**
     * Register interest in a banner
     */
    public function registerInterest(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student) || !in_array($user->type, ['student', 'trainee'])) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }
        
        $banner = Banner::active()->find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        // Check if student already registered interest
        $existingInterest = BannerInterest::where('banner_id', $id)
            ->where('student_id', $user->id)
            ->first();

        if ($existingInterest) {
            return response()->json([
                'success' => false,
                'message' => 'You have already registered interest in this banner'
            ], 400);
        }

        // Check if banner has reached maximum students limit
        if ($banner->max_students && $banner->interests()->count() >= $banner->max_students) {
            return response()->json([
                'success' => false,
                'message' => 'This banner has reached the maximum number of interested students'
            ], 400);
        }

        try {
            BannerInterest::create([
                'banner_id' => $id,
                'student_id' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Interest registered successfully',
                'data' => [
                    'banner_id' => $id,
                    'interested_students_count' => $banner->interests()->count() + 1,
                    'min_students_needed' => $banner->min_students,
                    'can_be_linked' => ($banner->interests()->count() + 1) >= $banner->min_students
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register interest',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove interest from a banner
     */
    public function removeInterest(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student) || !in_array($user->type, ['student', 'trainee'])) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }
        
        $banner = Banner::active()->find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        $interest = BannerInterest::where('banner_id', $id)
            ->where('student_id', $user->id)
            ->first();

        if (!$interest) {
            return response()->json([
                'success' => false,
                'message' => 'You have not registered interest in this banner'
            ], 400);
        }

        try {
            $interest->delete();

            return response()->json([
                'success' => true,
                'message' => 'Interest removed successfully',
                'data' => [
                    'banner_id' => $id,
                    'interested_students_count' => $banner->interests()->count() - 1
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove interest',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get student's interested banners
     */
    public function myInterests(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student) || !in_array($user->type, ['student', 'trainee'])) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }
        
        $interests = BannerInterest::with(['banner.program'])
            ->where('student_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $interests
        ]);
    }

    /**
     * Get banner details with interest status
     */
    public function getBannerWithInterest(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student) || !in_array($user->type, ['student', 'trainee'])) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }
        
        $banner = Banner::with(['program', 'interests' => function($query) use ($user) {
            $query->where('student_id', $user->id);
        }])->find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        $banner->has_registered_interest = $banner->interests->isNotEmpty();
        $banner->interested_students_count = $banner->interests()->count();
        $banner->can_be_linked = $banner->can_be_linked;

        return response()->json([
            'success' => true,
            'data' => $banner
        ]);
    }
}
