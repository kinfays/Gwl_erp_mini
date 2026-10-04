<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\IctAsset;
use App\Models\IctAssetAuditLine;
use App\Models\IctAssetReplacementPolicy;
use App\Services\Assets\AssetDashboardService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only page for one device, whichever category it belongs to: identity, assignment, lifecycle dates, the repair
 * and issue trail, its change history and the stock-checks it has been through. Network passwords are never loaded.
 */
class AssetDetail extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

    #[Locked]
    public int $assetId;

    public function mount(IctAsset $asset): void
    {
        $this->enforceLivewireModule('assets');

        // Same reading rule as the lists: a regional ICT user cannot open another region's device.
        abort_unless($this->scopeAssetsForViewing(IctAsset::query())->whereKey($asset->id)->exists(), 404);

        $this->assetId = $asset->id;
    }

    public function render()
    {
        $asset = IctAsset::query()
            ->with(['assetModel.manufacturer', 'assignedTo', 'previousAssignedTo', 'department', 'district', 'region'])
            ->findOrFail($this->assetId);

        $category = $asset->device_category;
        $purchased = $asset->purchased_at;
        $years = IctAssetReplacementPolicy::yearsFor((string) $asset->asset_type);
        $due = $purchased?->copy()->addYears($years);
        $today = now()->startOfDay();

        return view('livewire.assets.asset-detail', [
            'asset' => $asset,
            'typeLabel' => IctAsset::ASSET_TYPES[$category][$asset->asset_type] ?? $asset->asset_type,
            'listRoute' => AssetDashboardService::CATEGORY_ROUTES[$category] ?? 'assets.assets',
            'ageYears' => $purchased ? (int) $purchased->diffInYears($today, true) : null,
            'replacementYears' => $years,
            'replacementDue' => $due,
            'replacementOverdue' => $due && $due->lt($today),
            'warrantyState' => match (true) {
                ! $asset->warranty_expires_at => null,
                $asset->warranty_expires_at->lt($today) => 'Expired',
                default => 'Active',
            },
            'maintenance' => $asset->maintenanceLogs()->latest()->limit(25)->get(),
            'maintenanceTotal' => $asset->maintenanceLogs()->count(),
            'issues' => $asset->issueReports()->latest()->limit(25)->get(),
            'transfers' => $asset->transfers()->with(['fromEmployee', 'toEmployee', 'fromDistrict', 'toDistrict', 'relatedAsset'])->limit(50)->get(),
            'auditLines' => IctAssetAuditLine::query()
                ->where('ict_asset_id', $asset->id)
                ->whereNotNull('result')
                ->with('audit')
                ->latest('verified_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
