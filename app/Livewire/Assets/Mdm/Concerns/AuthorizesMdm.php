<?php

namespace App\Livewire\Assets\Mdm\Concerns;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\User;
use App\Services\Assets\Mdm\MdmAccessGuard;
use Illuminate\Support\Facades\Auth;

/**
 * The checks every MDM Livewire component starts with, in the same layered style as the rest of Assets
 * (route middleware, then this on mount, then a per-action re-check):
 *
 *   1. the feature flag — a component can be reached without its route (a Livewire update request), so a
 *      disabled feature 404s here too;
 *   2. Assets module access (the Livewire EnforcesModuleAccess trait);
 *   3. an MDM operator role (super_admin / admin / ict_team) and the screen's permission.
 *
 * Region scope is not decided here: components resolve every device / asset through MdmAccessGuard, which is the
 * service-level twin of ScopesAssetsByActor. Ids held in public properties are #[Locked] and re-resolved per action.
 */
trait AuthorizesMdm
{
    use EnforcesModuleAccess;

    protected function bootMdmScreen(string $permission): void
    {
        abort_unless(config('gwl.mdm_enabled'), 404);

        $this->enforceLivewireModule('assets');

        abort_unless($this->mdmGuard()->isOperator($this->mdmUser()), 403, 'Module access denied.');
        $this->authorizeMdm($permission);
    }

    protected function mdmUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function mdmGuard(): MdmAccessGuard
    {
        return app(MdmAccessGuard::class);
    }

    protected function canMdm(string $permission): bool
    {
        return $this->mdmGuard()->has($this->mdmUser(), $permission);
    }

    protected function authorizeMdm(string $permission): void
    {
        abort_unless($this->canMdm($permission), 403, 'You do not have permission to perform this action.');
    }
}
