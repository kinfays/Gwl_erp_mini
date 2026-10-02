<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\IctAsset;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only rollup of everything one employee holds, across the Assets, Phones and Network screens.
 * Visibility follows the lists (scopeAssetsForViewing), so a regional ICT user only sees in-region devices.
 */
class EmployeeAssets extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

    #[Locked]
    public int $employeeId;

    public function mount(Employee $employee): void
    {
        $this->enforceLivewireModule('assets');

        $this->employeeId = $employee->id;

        // A regional ICT user has no business with an out-of-region employee who holds nothing they can see.
        if (! $this->actorSeesAllRegions()
            && (int) $employee->region_id !== (int) $this->actorRegionId()
            && ! $this->visibleAssets()->exists()) {
            abort(404);
        }
    }

    protected function visibleAssets()
    {
        return $this->scopeAssetsForViewing(IctAsset::query())
            ->where('assigned_to_employee_id', $this->employeeId);
    }

    public function render()
    {
        $employee = Employee::query()->with(['department', 'district', 'jobTitle'])->findOrFail($this->employeeId);

        $assets = $this->visibleAssets()
            ->with(['assetModel', 'district'])
            ->orderBy('asset_name')
            ->get()
            ->groupBy('device_category');

        return view('livewire.assets.employee-assets', [
            'employee' => $employee,
            'groups' => [
                IctAsset::DEVICE_CATEGORY_ASSET => ['Assets', $assets->get(IctAsset::DEVICE_CATEGORY_ASSET, collect())],
                IctAsset::DEVICE_CATEGORY_PHONE => ['Phones', $assets->get(IctAsset::DEVICE_CATEGORY_PHONE, collect())],
                IctAsset::DEVICE_CATEGORY_NETWORK => ['Network', $assets->get(IctAsset::DEVICE_CATEGORY_NETWORK, collect())],
            ],
            'total' => $assets->flatten()->count(),
        ]);
    }
}
