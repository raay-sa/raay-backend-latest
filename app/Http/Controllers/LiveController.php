<?php

namespace App\Http\Controllers;

use App\Events\LiveProgramEvent;
use App\Events\TestEvent;
use App\Events\ViewerCountLiveProgramEvent;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LiveController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('live-stream-teacher');
    }

    public function sendEvent(Request $request)
    {
        $message = $request->input('message');
        // event(new TestEvent($message));
        return response()->json(['status' => 'Message sent']);
    }

    public function createStream(Request $request)
    {
        $program_id = $request->input('program_id');

        // Check if stream already exists for this program
        $existingStream = $this->findExistingStream($program_id);

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

        $response = Http::post($url, [
            'name' => 'Raay-program-' . $program_id,
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
    }

    private function findExistingStream($program_id)
    {
        $url = "https://media.esol.sa:5443/Raay/rest/v2/broadcasts/list/0/50";

        try {
            $response = Http::get($url);

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


    public function deleteStream(Request $request, $id)
    {
        $program_id = $request->input('program_id');

        $antMediaUrl = "https://media.esol.sa:5443/Raay/rest/v2/broadcasts/{$id}";
        $ch = curl_init($antMediaUrl);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // event(new LiveProgramEvent($program_id, $id, 'closed'));

        return response()->json([
            'success' => $statusCode === 200,
            'status' => $statusCode,
            'response' => json_decode($response, true)
        ]);
    }


    // live_student
    public function live_student()
    {
        return view('live-stream-student');
    }

    public function getStream($program_id)
    {
        $url = "https://media.esol.sa:5443/Raay/rest/v2/broadcasts/list/0/50";

        // $response = Http::get($url)->json();
        $response = Http::withOptions(['verify' => false])->get($url)->json();

        foreach ($response as $broadcast) {
            if ($broadcast['name'] === "Raay-program-$program_id") {
                return response()->json(['streamId' => $broadcast['streamId']]);
            }
        }

        return response()->json(['streamId' => null]);
    }


    public function getViewerCount($streamId)
    {
        $url = "https://media.esol.sa:5443/Raay/rest/broadcasts/{$streamId}";

        $response = Http::get($url);
        if ($response->ok()) {
            return [
                'count' => $response->json()['webRTCViewerCount'] ?? 0
            ];
        }
        return ['count' => 0];
    }

    public function join($programId)
    {
        Cache::increment("viewers_count_{$programId}");
        // event(new ViewerCountLiveProgramEvent($programId, Cache::get("viewers_count_{$programId}")));
    }

    public function leave($programId)
    {
        Cache::decrement("viewers_count_{$programId}");
        // event(new ViewerCountLiveProgramEvent($programId, Cache::get("viewers_count_{$programId}")));
    }

}
