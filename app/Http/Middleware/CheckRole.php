<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (!$request->user() || !$request->user()->hasRoles($role)) {
            return redirect('/dashboard')->with('error', 'You do not have the required permissions to access this area.');
        }

        return $next($request);
    }
}