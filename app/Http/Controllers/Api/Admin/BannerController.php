<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\BannerInterest;
use App\Models\Program;
use App\Models\Student;
use App\Models\Notification;
use App\Models\StudentNotification;
use App\Events\GeneralNotificationEvent;
use App\Notifications\CourseAvailableNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BannerController extends Controller
{
    /**
     * Display a listing of banners
     */
    public function index(Request $request)
    {
        $query = Banner::with(['program', 'interests.student']);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search by title
        if ($request->has('search') && $request->search) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        $banners = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $banners
        ]);
    }

    /**
     * Store a newly created banner
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'description' => 'nullable|string',
            'min_students' => 'nullable|integer|min:1',
            'max_students' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $banner = Banner::create([
                'title' => $request->title,
                'description' => $request->description,
                'min_students' => $request->min_students,
                'max_students' => $request->max_students,
                'status' => 'active'
            ]);

            // Handle image upload
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileName = 'image_' . time() . '_' . uniqid() . '.' . $file->extension();
                $path = 'uploads/banners/banner_id_'.$banner->id;

                $fullPath = public_path($path);
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }
                $file->move($fullPath, $fileName);
                $banner->image = $path . '/' . $fileName;
                $banner->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Banner created successfully',
                'data' => $banner->load('program')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $banner = Banner::with(['program', 'interests.student'])->find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $banner
        ]);
    }


    public function update(Request $request, $id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'description' => 'nullable|string',
            'min_students' => 'nullable|integer|min:1',
            'max_students' => 'nullable|integer|min:1',
            'status' => 'in:active,inactive,linked'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updateData = [
                'title' => $request->title,
                'description' => $request->description,
                'min_students' => $request->min_students,
                'max_students' => $request->max_students,
                'status' => $request->status ?? $banner->status
            ];

            // Handle image upload if provided
            if ($request->hasFile('image')) {
                // Delete old image
                if ($banner->image && file_exists(public_path($banner->image))) {
                    unlink(public_path($banner->image));
                }
                
                $file = $request->file('image');
                $fileName = 'image_' . time() . '_' . uniqid() . '.' . $file->extension();
                $path = 'uploads/banners/banner_id_'.$banner->id;

                $fullPath = public_path($path);
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }
                $file->move($fullPath, $fileName);
                $updateData['image'] = $path . '/' . $fileName;
            }

            $banner->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Banner updated successfully',
                'data' => $banner->load('program')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        try {
            if ($banner->image && file_exists(public_path($banner->image))) {
                unlink(public_path($banner->image));
            }

            $banner->delete();

            return response()->json([
                'success' => true,
                'message' => 'Banner deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get interested students
     */
    public function getInterestedStudents($id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        $interests = BannerInterest::with('student')
            ->where('banner_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => [
                'banner' => $banner,
                'interests' => $interests,
                'total_interested' => $banner->interested_students_count,
                'can_be_linked' => $banner->can_be_linked
            ]
        ]);
    }

    /**
     * Link banner to a program and notify interested students
     */
    public function linkToProgram(Request $request, $id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'program_id' => 'required|exists:programs,id',
            'email_subject' => 'required|string|max:255',
            'email_body' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        if (!$banner->can_be_linked) {
            return response()->json([
                'success' => false,
                'message' => 'Banner cannot be linked. Minimum students requirement not met.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Get the program
            $program = Program::find($request->program_id);
            if (!$program) {
                return response()->json([
                    'success' => false,
                    'message' => 'Program not found'
                ], 404);
            }

            // Update banner
            $banner->update([
                'program_id' => $request->program_id,
                'status' => 'linked',
                'linked_at' => now()
            ]);

            // Get interested students
            $interestedStudents = BannerInterest::with('student')
                ->where('banner_id', $id)
                ->whereNull('notified_at')
                ->get();

            // Create a general notification record
            $notification = Notification::create([
                'title' => $request->email_subject,
                'content' => $request->email_body,
                'type' => 'offer', 
                'users_type' => ['student'],
                'status' => 'sent'
            ]);

            $emailsSent = 0;
            $notificationsSent = 0;

            // Send emails and notifications to interested students
            foreach ($interestedStudents as $interest) {
                try {
                    $student = $interest->student;
                    
                    // Send email notification 
                    $student->notify(new CourseAvailableNotification(
                        $banner, 
                        $program, 
                        $request->email_subject, 
                        $request->email_body
                    ));
                
                    
                    $emailsSent++;

                    // Create student notification record
                    $studentNotification = new StudentNotification();
                    $studentNotification->notification_id = $notification->id;
                    $studentNotification->student_id = $student->id;
                    $studentNotification->is_read = false;
                    $studentNotification->save();
                    $notificationsSent++;

                    // Fire real-time notification event
                    event(new GeneralNotificationEvent($notification, 'student', $student->id));

                    // Mark as notified
                    $interest->update(['notified_at' => now()]);
                    
                } catch (\Exception $e) {
                    // Log email sending error 
                    Log::error('Failed to send notification to student: ' . $interest->student->id, [
                        'error' => $e->getMessage(),
                        'banner_id' => $banner->id,
                        'program_id' => $program->id
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Banner linked to program successfully. Emails sent to {$emailsSent} students, notifications sent to {$notificationsSent} students.",
                'data' => [
                    'banner' => $banner->load('program'),
                    'emails_sent' => $emailsSent,
                    'notifications_sent' => $notificationsSent,
                    'total_interested' => $interestedStudents->count()
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to link banner to program', [
                'banner_id' => $id,
                'program_id' => $request->program_id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to link banner to program',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get banner statistics
     */
    public function getStatistics()
    {
        $stats = [
            'total_banners' => Banner::count(),
            'active_banners' => Banner::where('status', 'active')->count(),
            'linked_banners' => Banner::where('status', 'linked')->count(),
            'total_interests' => BannerInterest::count(),
            'notified_students' => BannerInterest::whereNotNull('notified_at')->count(),
            'pending_notifications' => BannerInterest::whereNull('notified_at')->count(),
            'banners_ready_to_link' => Banner::active() 
                ->whereHas('interests', function($query) {
                    $query->havingRaw('COUNT(*) >= banners.min_students');
                })->count()
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Send test notification for a banner
     */
    public function sendTestNotification($id)
    {
        $banner = Banner::with('program')->find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        if (!$banner->program) {
            return response()->json([
                'success' => false,
                'message' => 'Banner is not linked to any program'
            ], 400);
        }

        // Get first interested student for testing
        $testInterest = BannerInterest::with('student')
            ->where('banner_id', $id)
            ->first();

        if (!$testInterest) {
            return response()->json([
                'success' => false,
                'message' => 'No interested students found for this banner'
            ], 400);
        }

        try {
            $student = $testInterest->student;
            
            // Send test notification
            $student->notify(new CourseAvailableNotification(
                $banner, 
                $banner->program, 
                'Test: Course Available!', 
                'This is a test notification for the course you showed interest in.'
            ));

            return response()->json([
                'success' => true,
                'message' => 'Test notification sent successfully to ' . $student->name,
                'data' => [
                    'student' => $student->name,
                    'email' => $student->email,
                    'banner' => $banner->title
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
