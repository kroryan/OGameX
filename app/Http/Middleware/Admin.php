<?php

namespace OGame\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param ?string $guard
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string|null $guard = null)
    {
        // If not authenticated, redirect to login
        if (!Auth::check()) {
            return redirect('/login');
        }

        // If authenticated but not admin, redirect to overview
        if (!Auth::user()->hasRole('admin')) {
            return redirect('/overview');
        }

        return $next($request);
    }
}
