<?php

namespace App\Http\Controllers\Api\Student;

use App\Events\AssignmentSolutionEvent;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSolution;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AssignmentSolutionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'assignment_id' => 'required|exists:assignments,id',
        'file' => 'required|file|mimes:pdf,doc,docx|max:10240', // 10MB max
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ]);
    }

    $user = $request->user();
    if (!$user || !($user instanceof Student)) {
        return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
    }

    $assignment = Assignment::find($request->assignment_id);

    $subscription = Subscription::where('student_id', $user->id)
        ->where('program_id', $assignment->program_id)
        ->first();

    if (!$subscription) {
        return response()->json([
            'error' => __('trans.alert.error.Student_not_subscribed_to_this_program')
        ], 422);
    }

    if (($subscription->expire_date && $subscription->expire_date->isPast()) || $subscription->status === 'banned')
    {
        return response()->json([
            'error' => __('trans.alert.error.subscription_has_expired')
        ], 422);
    }

    $existingSolution = AssignmentSolution::where('student_id', $user->id)
        ->where('assignment_id', $request->assignment_id)
        ->first();

    if ($existingSolution) {
        return response()->json([
            'success' => false,
            'message' => __('trans.alert.error.already_submitted_this_assignment')
        ]);
    }

    $assignment = getDataFromCache('assignments', Assignment::class)
        ->firstWhere('id', $request->assignment_id);

    $file_assigned = null;
    if ($request->hasFile('file') && $request->file('file')->isValid()) {
        $data = $request->file('file');
        $dataName = 'file_' . time() . '_' . uniqid() . '.' . $data->extension();
        $path = 'uploads/programs/program_id_'.$assignment->program_id.'/assignments/assignment_'. $request->assignment_id;

        $fullPath = public_path($path);
        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        $data->move($fullPath, $dataName);
        $file_assigned = $path . '/' . $dataName;
    } else {
        return response()->json([
            'success' => false,
            'message' => __('trans.alert.error.file_failed')
        ]);
    }

    $row = new AssignmentSolution();
    $row->file = $file_assigned;
    $row->student_id = $user->id;
    $row->assignment_id = $request->assignment_id;
    $row->save();

    storeInCache('assignment_solutions', $row);

    $assignment->solutions_count += 1;
    $assignment->save();

    updateInCache('assignments', $assignment);

    $teacher = Program::find($assignment->program_id)->teacher;
    $teacherSetting = $teacher->notification_setting()->first();

    if($teacherSetting && $teacherSetting->receiving_review_noti == 1){
        event(new AssignmentSolutionEvent($assignment, $user, $teacher->id));
    }

    return response()->json([
        'success' => true,
        'message' => __('trans.alert.success.done_create'),
        'data' => $row
    ]);
}

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
