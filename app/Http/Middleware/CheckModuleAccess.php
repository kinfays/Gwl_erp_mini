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
        if (!$user) {
            return redirect('/dashboard')->with('error', "Access denied to the {$module} module.");
        }

        $modules = $user->getAccessibleModules();
        if (!in_array($module, $modules)) {
            return redirect('/dashboard')->with('error', "Access denied to the {$module} module.");
        }

        return $next($request);
    }
}