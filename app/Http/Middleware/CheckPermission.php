<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, ...$permissions)
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized.');
        }

        // Super admin bypass
        if (method_exists($user, 'hasRoles') && $user->hasRoles('super_admin')) {
            return $next($request);
        }

        // Must have at least ONE of the permissions provided
        foreach ($permissions as $perm) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($perm)) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}