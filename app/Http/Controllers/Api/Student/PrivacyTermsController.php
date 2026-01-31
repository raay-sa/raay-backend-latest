<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\PrivacyTerms;
use Illuminate\Http\Request;

class PrivacyTermsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $studentPrivacyTerms = PrivacyTerms::where('status', 1)
            ->where('users_type', 'like', '%student%')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $studentPrivacyTerms
        ]);
    }
}
