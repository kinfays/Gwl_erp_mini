<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Services\Assets\AssetDashboardService;
use App\Services\Assets\AssetSummaryService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Deeper analytics that used to crowd the dashboard. The lifecycle and assignment numbers come from the same
 * AssetDashboardService methods as before; manufacturers, maintenance and issues come from AssetSummaryService.
 */
class Summary extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

    public const TABS = [
        'lifecycle' => 'Lifecycle',
        'assignment' => 'Assignment',
        'manufacturers' => 'Manufacturers & Models',
        'maintenance' => 'Maintenance',
        'issues' => 'Reported Issues',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'lifecycle';

    /** District id; '' = every district the user can see. */
    #[Url]
    public string $district = '';

    /** Y-m-d; only the Maintenance and Reported Issues tabs use the date range. */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');
    }

    public function clearFilters(): void
    {
        $this->reset('district', 'from', 'to');
    }

    protected function activeTab(): string
    {
        return array_key_exists($this->tab, self::TABS) ? $this->tab : 'lifecycle';
    }

    protected function districtId(): ?int
    {
        return ctype_digit($this->district) && $this->district !== '' ? (int) $this->district : null;
    }

    protected function assets(): Builder
    {
        return app(AssetSummaryService::class)->assetsIn($this->scopeAssetsForViewing(IctAsset::query()), $this->districtId());
    }

    protected function maintenanceQuery(): Builder
    {
        return app(AssetSummaryService::class)->maintenanceQuery($this->assets(), AssetSummaryService::parseDate($this->from), AssetSummaryService::parseDate($this->to));
    }

    protected function issuesQuery(): Builder
    {
        return app(AssetSummaryService::class)->issuesQuery(
            $this->scopeReportsForViewing(IctAssetIssueReport::query()),
            $this->districtId(),
            AssetSummaryService::parseDate($this->from),
            AssetSummaryService::parseDate($this->to),
        );
    }

    public function render(AssetDashboardService $dashboard, AssetSummaryService $summary)
    {
        $tab = $this->activeTab();
        $assets = $this->assets();
        $from = AssetSummaryService::parseDate($this->from);
        $to = AssetSummaryService::parseDate($this->to);

        $data = match ($tab) {
            'lifecycle' => [
                'ageBuckets' => $dashboard->ageBuckets($assets),
                'warrantyBuckets' => $dashboard->warrantyBuckets($assets),
                'replacementBuckets' => app(\App\Services\Assets\AssetReplacementService::class)->buckets($assets),
            ],
            'assignment' => [
                'unassigned' => $dashboard->unassigned($assets),
                'topAssignees' => $dashboard->topAssignees($assets),
            ],
            'manufacturers' => ['manufacturerGroups' => $summary->manufacturerGroups($assets)],
            'maintenance' => ['maintenance' => $summary->maintenance($this->maintenanceQuery(), $from, $to)],
            'issues' => ['issues' => $summary->issues($this->issuesQuery(), $from, $to)],
        };

        $user = $this->actor();

        return view('livewire.assets.summary', [
            'activeTab' => $tab,
            'tabs' => self::TABS,
            'needsAttention' => $summary->needsAttention($assets),
            'districts' => District::query()
                ->when(! $this->actorSeesAllRegions(), fn ($q) => $q->where('region_id', $this->actorRegionId()))
                ->orderBy('district_name')
                ->get(),
            'showDates' => in_array($tab, ['maintenance', 'issues'], true),
            'canEditPolicy' => $user->hasRoles('super_admin') || $user->hasPermission('assets.manage_replacement_policy'),
            ...$data,
        ]);
    }
}
