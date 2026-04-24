<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;

trait EnforcesModuleAccess
{
    protected function enforceLivewireModule(string $module): void
    {
       /** @var User $user */
        $user = Auth::user();

        if (! $user) {
            abort(403, 'Unauthorized.');
        }

        // super_admin bypass
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