<?php

namespace App\Livewire\Commercial\Customers;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomerCategory;
use App\Models\Permission;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerFilters;
use App\Services\Commercial\Customers\CustomerSnapshots;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Customer-list analytics (A to I). Reads only the pre-aggregated rollups of the effective batch of each district, so it is
 * as fast with five million customers as with five thousand. Everything is scoped to the viewer's region by the snapshots
 * and again inside every query; a region or district id typed into the URL that is outside that scope is ignored.
 */
class Dashboard extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    public const TABS = [
        'overview' => 'Overview',
        'meters' => 'Meters',
        'receivables' => 'Receivables',
        'collection' => 'Billing & collection',
        'dormancy' => 'Dormancy',
        'growth' => 'Growth & churn',
        'consumption' => 'Consumption',
        'quality' => 'Data quality',
        'compare' => 'Trends & compare',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    /** Period key (2026-10 / 2026-W41); '' = the newest data of each district. */
    #[Url]
    public string $period = '';

    #[Url]
    public string $region = '';

    #[Url]
    public string $district = '';

    #[Url]
    public string $route = '';

    #[Url]
    public string $group = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $meter = '';

    #[Url]
    public bool $billingOnly = false;

    /** Trend points: one per upload period ('period') or one per month, taking each district's last upload ('month'). */
    #[Url]
    public string $trendBy = 'period';

    /** The dimension a table is broken down by, where a tab offers one. */
    #[Url]
    public string $by = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCustomerList();
        $this->guardCommercialPermission('commercial.view_customer_analytics');
    }

    public function updatedDistrict(): void
    {
        $this->route = '';
    }

    public function updatedRegion(): void
    {
        $this->district = '';
        $this->route = '';
    }

    public function clearFilters(): void
    {
        $this->reset('district', 'route', 'group', 'status', 'meter', 'billingOnly');
    }

    protected function activeTab(): string
    {
        return array_key_exists($this->tab, self::TABS) ? $this->tab : 'overview';
    }

    public function render(CustomerSnapshots $snapshots, CustomerAnalyticsService $analytics)
    {
        $restriction = $this->customerRestriction();
        $tab = $this->activeTab();
        $periods = $snapshots->periods($restriction, $this->intOrNull($this->region));
        $monthEnds = $snapshots->monthEnds($restriction, $this->intOrNull($this->region));
        $periodKey = collect($periods)->pluck('key')->merge($monthEnds)->contains($this->period) ? $this->period : null;

        $everything = $snapshots->current($restriction, null, null, $periodKey);
        $regionId = $restriction === null ? $this->intOrNull($this->region) : null;
        $inRegion = $regionId ? $everything->filter(fn ($b) => (int) $b->region_id === $regionId) : $everything;
        $districtId = $this->intOrNull($this->district);
        $districtId = $districtId && $inRegion->has($districtId) ? $districtId : null;
        $current = $districtId ? $inRegion->only([$districtId]) : $inRegion;

        $base = [
            'actorCanExport' => $this->actorCan('commercial.export_reports'),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'canDetails' => $this->customerDetails(),
            'tabs' => self::TABS,
            'activeTab' => $tab,
            'periods' => $periods,
            'monthEnds' => $monthEnds,
            'periodKey' => $periodKey,
            'seesAllRegions' => $restriction === null,
        ];

        if ($current->isEmpty()) {
            return view('livewire.commercial.customers.dashboard', $base + ['empty' => true, 'batches' => collect()]);
        }

        $routeId = $districtId ? $this->intOrNull($this->route) : null;
        $filters = $snapshots->filters($current, $restriction, [
            'region_id' => $regionId, 'district_id' => $districtId, 'route_id' => $routeId,
            'group' => array_key_exists($this->group, CommercialCustomerCategory::GROUPS) ? $this->group : null,
            'status_id' => $this->intOrNull($this->status), 'meter_status_id' => $this->intOrNull($this->meter), 'billing_only' => $this->billingOnly,
        ]);

        // A per-route breakdown reads the route-level rollups (every route of every district in view): only for one district.
        $by = $this->by === 'route' && ! $districtId ? '' : $this->by;

        $data = match ($tab) {
            'overview' => [
                'overview' => $analytics->overview($filters),
                'spark' => $analytics->trend($restriction, $regionId, $districtId, 12)['series'],
            ],
            'meters' => ['meters' => $analytics->meters($filters, $by ?: 'district')],
            'receivables' => ['receivables' => $analytics->receivables($filters)],
            'collection' => ['collection' => $analytics->collection($filters, $by ?: 'category')],
            'dormancy' => ['dormancy' => $analytics->dormancy($filters)],
            'growth' => ['growth' => $analytics->growth($filters)],
            'consumption' => ['consumption' => $analytics->consumption($filters)],
            'quality' => ['quality' => $analytics->quality($filters)],
            'compare' => [
                'trend' => $analytics->trend($restriction, $regionId, $districtId, 12, $this->trendBy === 'month')['series'],
                'league' => $analytics->league($filters, $restriction === null ? $snapshots->filters($everything, null) : null),
            ],
        };

        $names = DB::table('districts')->whereIn('id', $everything->keys()->all())->pluck('district_name', 'id');
        $routes = $districtId ? DB::table('commercial_routes')->where('district_id', $districtId)->orderBy('name')->pluck('name', 'id') : collect();

        return view('livewire.commercial.customers.dashboard', $base + $data + [
            'empty' => false,
            'batches' => $current,
            'districtOptions' => $inRegion->keys()->mapWithKeys(fn ($id) => [$id => $names[$id] ?? 'District '.$id])->sort(),
            'regionOptions' => $restriction === null ? DB::table('regions')->whereIn('id', $everything->pluck('region_id')->unique()->all())->orderBy('region_name')->pluck('region_name', 'id') : collect(),
            'routeOptions' => $routes,
            'groupOptions' => CommercialCustomerCategory::GROUPS,
            'statusOptions' => DB::table('commercial_customer_statuses')->orderBy('code')->get(['id', 'code', 'label', 'meaning_confirmed']),
            'meterOptions' => DB::table('commercial_meter_statuses')->orderBy('code')->get(['id', 'code', 'label']),
            'districtId' => $districtId,
            'breakdown' => $by,
            'asOf' => $current->max('as_of_date'),
            'oldest' => $current->min('as_of_date'),
            'filtersActive' => $districtId || $routeId || $this->group !== '' || $this->status !== '' || $this->meter !== '' || $this->billingOnly,
        ]);
    }

    protected function intOrNull(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
