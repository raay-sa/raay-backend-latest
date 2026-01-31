<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
            if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $programs_id = Subscription::where('student_id', $user->id)
        ->where('status', 'active')
        ->where(function ($q) {
            $q->where('expire_date', '>', now())
            ->orWhereNull('expire_date');
        })
        ->pluck('program_id');

        $programs = Program::whereIn('id', $programs_id)
        ->where('status', 1)->where('is_approved', 1)
        ->select('id', 'image', 'price', 'type', 'is_live', 'teacher_id', 'category_id')
        ->withAvg('reviews', 'score')
        ->withCount('reviews')
        ->with(['category:id', 'category.translations', 'teacher:id,name,image', 'translations'])
        ->get();

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($programs, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }
}
