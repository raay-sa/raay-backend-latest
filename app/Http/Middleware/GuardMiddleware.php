<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class GuardMiddleware
{
    public function handle(Request $request, Closure $next, $guard)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // Check if user is the correct type
        $userType = class_basename(get_class($user));

        if (strtolower($userType) !== strtolower($guard)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Please log in as the correct user type (' . $guard . ').'
            ], 403);
        }

        return $next($request);
    }
}
