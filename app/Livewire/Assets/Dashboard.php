<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\IctAsset;
use App\Services\Assets\AssetDashboardService;
use Livewire\Component;

class Dashboard extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');
    }

    public function render()
    {
        $baseQuery = $this->scopeAssetsForViewing(IctAsset::query());

        $dashboard = app(AssetDashboardService::class)->build($baseQuery);

        $recentAssets = $this->scopeAssetsForViewing(IctAsset::query())
            ->with(['district', 'assignedTo'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        return view('livewire.assets.dashboard', [
            'cards' => $dashboard['cards'],
            'districtBreakdown' => $dashboard['districtBreakdown'],
            'allocation' => $dashboard['allocation'],
            'recentAssets' => $recentAssets,
        ]);
    }
}
