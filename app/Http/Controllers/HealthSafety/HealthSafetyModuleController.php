<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\HsIncident;
use App\Models\Permission;
use App\Services\HealthSafety\IncidentVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin: route middleware and the Livewire components do the real access checks (module, permission, scope).
 */
class HealthSafetyModuleController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(protected IncidentVisibility $visibility) {}

    /** Officers and managers get the overview; everyone else goes straight to the report form. */
    public function home(Request $request): View|RedirectResponse
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        $user = $request->user();

        if (! $this->visibility->can($user, 'health_safety.view_dashboard') && ! $this->visibility->can($user, 'health_safety.view_incidents')) {
            return redirect()->route('health_safety.report');
        }

        return view('health_safety.home');
    }

    public function report(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.report');
    }

    public function mine(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.mine');
    }

    public function incidents(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.incidents');
    }

    public function show(Request $request, HsIncident $incident): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);
        abort_unless($this->visibility->canView($request->user(), $incident), 403, 'This incident is not available to you.');

        return view('health_safety.show', ['incident' => $incident]);
    }

    public function actions(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.actions');
    }

    public function sites(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.sites');
    }

    public function settings(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.settings');
    }

    public function expiryRegister(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.expiry-register');
    }
}
