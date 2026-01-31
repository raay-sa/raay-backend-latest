<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $favorites = Favorite::with([
            'program' => function ($q) {
                $q->select('id', 'price', 'image', 'category_id', 'teacher_id')
                ->withAvg('reviews', 'score')
                ->withCount('reviews')
                ->with([
                    'translations',
                    'category:id', 'category.translations',
                    'teacher:id,name,image',
                ]);
            }
        ])
        ->where('student_id', $user->id)
        ->select('id', 'program_id')
        ->orderBy('created_at', 'desc')
        ->get();

        $perPage = (int) $request->input('per_page', 6);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($favorites, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data',
                'errors' => $validator->errors()
            ], 400);
        }

        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        $favorite = Favorite::where('student_id', $user->id)
        ->where('program_id', $request->program_id)->first();

        if(!$favorite){
            $favorite = new Favorite();
            $favorite->student_id = $user->id;
            $favorite->program_id = $request->program_id;
            $favorite->save();

            return response()->json([
                'success' => true,
                'message' => __('trans.alert.success.done_update'),
            ], 200);
        }

        $favorite->delete();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ], 200);
    }

}
