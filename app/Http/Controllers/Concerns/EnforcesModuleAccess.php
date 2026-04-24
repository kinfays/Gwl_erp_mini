<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait EnforcesModuleAccess
{
    protected function enforceModule(Request $request, string $module): void
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized.');
        }

        if (method_exists($user, 'hasRoles') && $user->hasRoles('super_admin')) {
            return;
        }

        if (! method_exists($user, 'getAccessibleModules')) {
            abort(403, 'Unauthorized.');
        }

        if (! in_array($module, $user->getAccessibleModules(), true)) {
            abort(403, 'Module access denied.');
        }
    }
}