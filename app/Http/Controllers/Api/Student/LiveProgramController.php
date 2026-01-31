<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LiveProgramController extends Controller
{
    public function getStream(Request $request, $program_id)
    {
        // Authenticate the student
        $user = $request->user();
        if (!$user || !($user instanceof Student)) {
            return response()->json(['success' => false, 'message' => 'student_access_required'], 403);
        }

        // Validate that the program exists
        $program = Program::find($program_id);
        if (!$program) {
            return response()->json(['success' => false, 'message' => 'Program not found'], 404);
        }

        // Check if the student is subscribed to this program
        $subscription = Subscription::where('student_id', $user->id)
            ->where('program_id', $program_id)
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.Student_not_subscribed_to_this_program')
            ], 403);
        }

        // Check if the subscription is active and not expired
        if (!$subscription->canAccess()) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.subscription_has_expired')
            ], 403);
        }

        // Get the stream from the external API
        $url = "https://media.esol.sa:5443/Raay/rest/v2/broadcasts/list/0/50";

        $response = Http::get($url)->json();
        // $response = Http::withOptions(['verify' => false])->get($url)->json();

        foreach ($response as $broadcast) {
            if ($broadcast['name'] === "Raay-program-$program_id") {
                return response()->json([
                    'success' => true,
                    'streamId' => $broadcast['streamId']
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'streamId' => null
        ]);
    }
}
