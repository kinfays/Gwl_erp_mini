<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsIncident;
use App\Models\Permission;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A reporter's own reports: where each one stands and, once closed, the outcome written for them. Never the findings.
 */
class MyReports extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.report_incident');
    }

    public function render()
    {
        $reports = $this->visibility()
            ->scopeOwn(HsIncident::query(), $this->actor())
            ->with(['region', 'district', 'department', 'site'])
            ->latest('hs_incidents.created_at')
            ->paginate(10);

        return view('livewire.health_safety.my-reports', [
            'reports' => $reports,
            'visibility' => $this->visibility(),
            'viewer' => $this->actor(),
        ]);
    }
}
