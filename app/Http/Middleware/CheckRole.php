<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole

{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        // Not logged in (should already be handled by auth middleware, but just in case)
        if (! $user) {
            abort(403, 'Unauthorized.');
        }

        // Super admin bypass
        if (method_exists($user, 'hasRoles') && $user->hasRoles('super_admin')) {
            return $next($request);
        }

        // If no roles were passed, allow by default (safety)
        if (empty($roles)) {
            return $next($request);
        }

        // Check if the user has any of the required roles
        if (! method_exists($user, 'hasRoles') || ! $user->hasRoles(...$roles)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }

}