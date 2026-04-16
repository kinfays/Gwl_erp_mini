<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        // Checks if the module name exists in the user's access list
        // This assumes module_access is cast as an array in your User model
        if (!$user || !is_array($user->module_access) || !in_array($module, $user->module_access)) {
            return redirect('/dashboard')->with('error', "Access denied to the {$module} module.");
        }

        return $next($request);
    }
}