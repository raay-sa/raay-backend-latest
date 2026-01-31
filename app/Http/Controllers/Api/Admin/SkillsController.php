<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Skills;
use Illuminate\Http\Request;

class SkillsController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->filter ?? 'all';
        $search = $request->search;

        $skills = Skills::select('id', 'question', 'category_id', 'created_at')
            ->with('category:id', 'category.translations')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($filter === 'latest') {
            $skills = $skills->sortByDesc('created_at')->values();
        } elseif ($filter === 'oldest') {
            $skills = $skills->sortBy('created_at')->values();
        } elseif ($filter === 'name') {
            $skills = $skills->sortBy(fn($que) => mb_strtolower($que->question))->values();
        }

        if ($search) {
            $skills = $skills->filter(function ($que) use ($search) {
                return $que->question && mb_stripos($que->question, $search) !== false;
            })->values();
        }

        $perPage = (int) $request->input('per_page', 10);
        $currentPage = (int) $request->input('page', 1);
        $paginated = paginationData($skills, $perPage, $currentPage);

        return response()->json([
            'success' => true,
            'data' => $paginated
        ]);
    }

    public function show(Request $request, $id)
    {
        $row = Skills::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        return response()->json([
            'success' => true,
            'row' => $row
        ]);
    }

    public function store(Request $request)
    {
        $validator = validator($request->all(), [
            'category_id' => 'required|exists:categories,id',
            // accept single question or multiple
            'question'    => 'required_without:questions|string',
            'questions'   => 'required_without:question|array',
            'questions.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }
        // Handle multiple questions
        if ($request->filled('questions')) {
            $raw = $request->input('questions', []);
            $normalized = collect($raw)
                ->map(fn($q) => is_string($q) ? trim($q) : $q)
                ->filter(fn($q) => is_string($q) && $q !== '')
                ->unique()
                ->values();

            if ($normalized->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => ['questions' => ['At least one non-empty question is required']]
                ], 422);
            }

            $created = [];
            foreach ($normalized as $q) {
                $row = new Skills();
                $row->category_id = $request->category_id;
                $row->question = $q;
                $row->save();
                $created[] = $row;
            }

            return response()->json([
                'success' => true,
                'message' => __('trans.alert.success.done_create'),
                'count'   => count($created),
                'data'    => $created,
            ]);
        }

        // Single question fallback
        $skills_que = new Skills();
        $skills_que->category_id = $request->category_id;
        $skills_que->question = $request->question;
        $skills_que->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'data'    => $skills_que
        ]);
    }

    public function update(Request $request, $id)
    {
        $validator = validator($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'question'    => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $skills_que = Skills::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });
        $skills_que->category_id = $request->category_id;
        $skills_que->question = $request->question;
        $skills_que->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
            'data'    => $skills_que
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $row = Skills::findOr($id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $row->delete();
        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_delete'),
        ]);
    }
}
