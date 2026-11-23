<?php

namespace App\Livewire\Assets\Racks;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\IctAsset;
use App\Models\IctRack;
use App\Services\Assets\AssetDashboardService;
use App\Services\Assets\RackService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only page for one rack: where it is, how full it is (used, free and the free stretches) and the devices mounted
 * in it, bottom to top. Reading follows the asset lists (own region, or every region for Head Office ICT and unscoped
 * roles); a rack the person may not see answers 404. Editing a device happens on its own list, like everywhere else in Assets.
 */
class RackShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

    #[Locked]
    public int $rackId;

    public function mount(int $rack): void
    {
        $this->enforceLivewireModule('assets');

        abort_unless(RackService::mayView($this->actor()), 403, 'You do not have permission to view racks.');
        abort_unless($this->scopeAssetsForViewing(IctRack::query())->whereKey($rack)->exists(), 404);

        $this->rackId = $rack;
    }

    public function render()
    {
        $rack = IctRack::query()->with(['district', 'region'])->findOrFail($this->rackId);

        // The same viewing scope as the asset lists, so a device the person may not see is never listed.
        $devices = $this->scopeAssetsForViewing(IctAsset::query())
            ->where('rack_id', $rack->id)
            ->with(['assetModel.manufacturer'])
            ->orderBy('rack_unit')
            ->orderBy('id')
            ->get();

        $used = count($rack->occupiedUnits($devices));
        $user = $this->actor();

        return view('livewire.assets.racks.rack-show', [
            'rack' => $rack,
            'devices' => $devices,
            'usedUnits' => $used,
            'freeUnits' => max(0, (int) $rack->total_units - $used),
            'freeRanges' => $rack->freeRanges(),
            'canManage' => RackService::mayManage($user) && RackService::mayWrite($user, $rack),
            'canEditDevices' => $user->hasRoles('super_admin') || $user->hasPermission('assets.edit'),
            'categoryRoutes' => AssetDashboardService::CATEGORY_ROUTES,
            'typeLabels' => collect(IctAsset::ASSET_TYPES)->collapse()->all(),
        ]);
    }
}
