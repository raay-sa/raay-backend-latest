<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Events\LiveProgramEvent;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\StudentNotificationSetting;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LiveProgramController extends Controller
{
    public function createStream(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ]);
        }

        try {
            // Check if stream already exists for this program
            $existingStream = $this->findExistingStream($request->program_id);

            if ($existingStream) {
                // Stream already exists, return existing stream info
                // event(new LiveProgramEvent($program_id, $existingStream['streamId'], 'live'));

                return response()->json([
                    'streamId' => $existingStream['streamId'],
                    'name' => $existingStream['name'],
                    'status' => 'existing_stream_reused',
                    'message' => 'Using existing stream for this program'
                ]);
            }

            // No existing stream found, create a new one
            $url = 'https://media.esol.sa:5443/Raay/rest/v2/broadcasts/create';

            // $response = Http::post($url, [
            //     'name' => 'Raay-program-' . $request->program_id,
            //     'type' => 'liveStream'
            // ]);
            // In your createStream method, modify the HTTP call:
            $response = Http::withOptions([
                'verify' => false, // Disable SSL verification (temporary solution)
                'timeout' => 30, // Increase timeout
            ])->post($url, [
                'name' => 'Raay-program-' . $request->program_id,
                'type' => 'liveStream'
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                // event(new LiveProgramEvent($program_id, $responseData['streamId'], 'live'));

                return response()->json(array_merge($responseData, [
                    'status' => 'new_stream_created',
                    'message' => 'New stream created for this program'
                ]));
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error("ANT MEDIA ERROR: " . $e->getMessage());
            Log::error("Full error: " . $e->getFile() . ":" . $e->getLine());

            return response()->json([
                'success' => false,
                'streamId' => 'fallback-' . uniqid(),
                'status' => 'fallback_mode',
                'warning' => 'Ant Media Server unavailable, using fallback',
                'error_details' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 200);
        }
    }

    private function findExistingStream($program_id)
    {
        $url = "https://media.esol.sa:5443/Raay/rest/v2/broadcasts/list/0/50";

        try {
            // $response = Http::get($url);
            $response = Http::withOptions(['verify' => false])->get($url);
            if (!$response->successful()) {
                return null;
            }

            $broadcasts = $response->json();

            foreach ($broadcasts as $broadcast) {
                if ($broadcast['name'] === "Raay-program-$program_id") {
                    return $broadcast;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error fetching existing streams: ' . $e->getMessage());
        }

        return null;
    }


    public function deleteStream($streamId)
    {
        $antMediaUrl = "https://media.esol.sa:5443/Raay/rest/v2/broadcasts/{$streamId}";
        $ch = curl_init($antMediaUrl);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // $program_id = $request->input('program_id');
        // event(new LiveProgramEvent($program_id, $id, 'closed'));
        return response()->json([
            'success' => $statusCode === 200,
            'status' => $statusCode,
            'response' => json_decode($response, true)
        ]);
    }

    public function online(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $program = Program::with('translations')->findOr($request->program_id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $program->is_live = 1;
        $program->save();

        updateInCache('programs', $program);
        updateInCache('all_programs', $program);

        $students_id = Subscription::where('program_id', $program->id)->pluck('student_id');
        foreach ($students_id as $student_id) {
            $student_setting = StudentNotificationSetting::where('student_id', $student_id)->first();
            if($student_setting && $student_setting->live_program_noti == 1){
                event(new LiveProgramEvent($program, 'student', $student_id));
                // event(new LiveProgramEvent($program, $student));
            }
        }

        $teacher = $program->teacher;
        $teacher_setting = $teacher->notification_setting->first();
        if($teacher_setting && $teacher_setting->live_program_noti == 1){
            event(new LiveProgramEvent($program, 'teacher', $teacher->id));
        }

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }

    public function offline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'program_id' => 'required|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $program = Program::with('translations')->findOr($request->program_id, function () {
            abort(response()->json([
                'success' => false,
                'message' => __('trans.alert.error.data_not_exist'),
            ], 422));
        });

        $program->is_live = 0;
        $program->save();

        updateInCache('programs', $program);
        updateInCache('all_programs', $program);

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_update'),
        ]);
    }

}
