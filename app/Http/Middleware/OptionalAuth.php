<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class OptionalAuth
{
    public function handle(Request $request, Closure $next)
    {
        // Try to authenticate the user if a token is provided
        if ($request->bearerToken()) {
            try {
                $token = PersonalAccessToken::findToken($request->bearerToken());
                
                if ($token && $token->tokenable) {
                    // Set the authenticated user in the request
                    $request->setUserResolver(function () use ($token) {
                        return $token->tokenable;
                    });
                    
                    // Also set it in the auth guard for consistency
                    auth()->setUser($token->tokenable);
                }
            } catch (\Exception $e) {
                // Token is invalid, continue without authentication
                // This is expected behavior for optional auth
            }
        }

        return $next($request);
    }
}