<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\IctAsset;
use Illuminate\Support\Facades\DB;
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
        $baseQuery = $this->scopeAssetsForActor(IctAsset::query());

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', 'Active')->count(),
            'in_repair' => (clone $baseQuery)->where('status', 'In Repair')->count(),
            'unassigned' => (clone $baseQuery)->whereNull('assigned_to_employee_id')->count(),
            'recently_seen' => (clone $baseQuery)->where('agent_last_report_at', '>=', now()->subDay())->count(),
        ];

        $districtBreakdown = (clone $baseQuery)
            ->leftJoin('districts', 'districts.id', '=', 'ict_assets.district_id')
            ->groupBy('ict_assets.district_id', 'districts.district_name')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->get([
                DB::raw("COALESCE(districts.district_name, 'Unassigned') as district_name"),
                DB::raw('COUNT(*) as total'),
            ]);

        $statusBreakdown = (clone $baseQuery)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        $recentAssets = (clone $baseQuery)
            ->with(['district', 'assignedTo'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        return view('livewire.assets.dashboard', [
            'stats' => $stats,
            'districtBreakdown' => $districtBreakdown,
            'statusBreakdown' => $statusBreakdown,
            'recentAssets' => $recentAssets,
        ]);
    }
}

