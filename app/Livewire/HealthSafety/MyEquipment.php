<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\Permission;
use Livewire\Component;

/**
 * The equipment the user is named as responsible for, wherever it is. Being responsible lets them open the item and record
 * a check on it, and nothing else; this is how they find it without any Health & Safety permission.
 */
class MyEquipment extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.report_incident');
    }

    public function render()
    {
        $employeeId = $this->actorEmployee()?->id;

        return view('livewire.health_safety.my-equipment', [
            'extinguishers' => $employeeId
                ? HsFireExtinguisher::query()->where('responsible_employee_id', $employeeId)->where('status', '!=', HsFireExtinguisher::STATUS_DECOMMISSIONED)->with(['site', 'vehicle'])->orderBy('asset_code')->get()
                : collect(),
            'kits' => $employeeId
                ? HsFirstAidKit::query()->where('responsible_employee_id', $employeeId)->where('status', '!=', HsFirstAidKit::STATUS_DECOMMISSIONED)->with(['site', 'vehicle', 'items'])->orderBy('asset_code')->get()
                : collect(),
        ]);
    }
}
