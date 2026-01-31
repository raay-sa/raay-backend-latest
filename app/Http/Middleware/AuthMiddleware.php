<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class AuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // if (!Auth::check()) {
        //     return redirect()->route('admin.login');
        // }
        // if (!Auth::guard('admin')->check() && !Auth::guard('company')->check() && !Auth::guard('user')->check()) {
        //     return redirect()->route('admin.login');
        // }


        return $next($request);
    }
}
