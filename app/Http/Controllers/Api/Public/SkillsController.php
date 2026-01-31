<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Skills;
use Illuminate\Http\Request;
use App\Models\Program;

class SkillsController extends Controller
{
    public function index()
    {
        $skills = Skills::select('id', 'question', 'category_id')
            ->with('category:id', 'category.translations')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $skills
        ]);
    }

    public function store(Request $request)
    {
        $validator = validator($request->all(), [
            'answers'                => 'required|array',
            'answers.*.question_id' => 'required|exists:skills,id|distinct',
            'answers.*.option'      => 'required|in:1,2,3,4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors(),
            ], 422);
        }

        $programs = [];

        foreach ($request->answers as $answer) {
            $skill = Skills::with('category.translations')->find($answer['question_id']);
            $levels = [
                '1' => 'مبتدئ',
                '2' => 'متوسط',
                '3' => 'متقدم',
                '4' => 'خبير',
            ];

            // لو درجة أقل من 3 → رشح برنامج القسم
            $program = Program::where('category_id', $skill->category_id)
                ->select('id', 'level', 'category_id')
                ->with('category:id', 'category.translations', 'translations')
                ->where('level', $levels[$answer['option']])
                ->first();

            if ($program) {
                $programs[] = $program;
            } else {
                $programs[] = [
                    'category_id' => $skill->category_id,
                    'level'       => $levels[$answer['option']],
                    'message'     => 'لا يوجد برنامج متاح لهذا القسم حالياً في هذا المستوى',
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
            'programs' => $programs
        ]);
    }

}
