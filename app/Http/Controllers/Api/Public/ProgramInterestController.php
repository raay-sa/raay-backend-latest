<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\BannerInterest;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProgramInterestController extends Controller
{

    public function registerInterest(Request $request, $programId)
    {
        $user = $request->user();
        if (!$user || !($user instanceof \App\Models\Student) || !in_array($user->type, ['student', 'trainee'])) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        // Check if program exists and is online or onsite
        $program = Program::whereIn('type', ['live', 'onsite'])->find($programId);
        
        if (!$program) {
            return response()->json([
                'success' => false,
                'message' => 'Program not found or not available for interest registration'
            ], 404);
        }

        // Create or find a banner for this program
        $banner = $this->getOrCreateProgramBanner($program);

        // Check if student already registered interest
        $existingInterest = BannerInterest::where('banner_id', $banner->id)
            ->where('student_id', $user->id)
            ->first();

        if ($existingInterest) {
            return response()->json([
                'success' => false,
                'message' => 'You have already registered interest in this program'
            ], 400);
        }

        try {
            // Create interest 
            BannerInterest::create([
                'banner_id' => $banner->id,
                'student_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Interest registered successfully',
                'data' => [
                    'program_id' => $program->id,
                    'program_title' => $program->translations->first()->title ?? 'Program',
                    'interested_count' => $banner->interests()->count()
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

    public function removeInterest(Request $request, $programId)
    {
        $user = $request->user();
        if (!$user || !($user instanceof \App\Models\Student) || !in_array($user->type, ['student', 'trainee'])) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $program = Program::whereIn('type', ['live', 'onsite'])->find($programId);
        
        if (!$program) {
            return response()->json([
                'success' => false,
                'message' => 'Program not found'
            ], 404);
        }

        $banner = $this->getOrCreateProgramBanner($program);
        
        $interest = BannerInterest::where('banner_id', $banner->id)
            ->where('student_id', $user->id)
            ->first();

        if (!$interest) {
            return response()->json([
                'success' => false,
                'message' => 'You have not registered interest in this program'
            ], 400);
        }

        $interest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interest removed successfully',
            'data' => [
                'program_id' => $program->id,
                'interested_count' => $banner->interests()->count()
            ]
        ]);
    }

    private function getOrCreateProgramBanner($program)
    {
        // Check if banner already exists for this program
        $banner = Banner::where('program_id', $program->id)->first();
        
        if (!$banner) {
            // Create  banner for this program
            $banner = Banner::create([
                'title' => $program->translations->first()->title ?? 'Program Interest',
                'image' => $program->image,
                'description' => $program->translations->first()->description ?? 'Program description',
                'program_id' => $program->id,
                'min_students' => 5, 
                'max_students' => 50,
                'status' => 'active'
            ]);
        }
        
        return $banner;
    }
}