<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialBillingBand;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\Permission;
use App\Services\Commercial\BillingAnalyticsService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Billing analytics (B1-B12). Everything works on ONE snapshot (an effective billing batch) chosen with the picker at
 * the top; snapshots are never summed. Only snapshots of regions the user may see are ever offered, and a snapshot id
 * typed into the URL that is not one of them falls back to the default rather than saying that it exists elsewhere.
 */
class Billing extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    public const TABS = [
        'overview' => 'Overview',
        'collections' => 'Collections',
        'balances' => 'Balances',
        'estimation' => 'Estimation & unbilled',
        'bands' => 'Consumption bands',
        'exceptions' => 'Exceptions',
        'compare' => 'Compare',
    ];

    public const RANKINGS = ['billing' => 'Billing', 'volume' => 'Volume', 'billed' => 'Customers billed'];

    /** Batch id of the snapshot being analysed; '' = the default. */
    #[Url(as: 'snapshot')]
    public string $snapshot = '';

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    /** A district as the report prints it; '' = every district. */
    #[Url]
    public string $district = '';

    #[Url]
    public string $rank = 'billing';

    /** Batch id of the snapshot to compare with; '' = the one before. */
    #[Url(as: 'compare')]
    public string $compare = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_billing');
    }

    public function clearFilters(): void
    {
        $this->reset('district');
    }

    protected function activeTab(): string
    {
        return array_key_exists($this->tab, self::TABS) ? $this->tab : 'overview';
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return array<string, mixed>|null
     */
    protected function chosen(array $snapshots, BillingAnalyticsService $billing): ?array
    {
        return $this->chooseSnapshot($snapshots, $this->snapshot, $billing->defaultSnapshot($snapshots));
    }

    /** @return Collection<int, CommercialBillingRoute> */
    protected function routesOf(int $batchId): Collection
    {
        return CommercialBillingRoute::query()->where('batch_id', $batchId)->with('district:id,district_name')->get();
    }

    public function render(BillingAnalyticsService $billing)
    {
        $snapshots = $billing->snapshots($this->scopeBatchesForActor(CommercialImportBatch::query()));
        $chosen = $this->chosen($snapshots, $billing);
        $tab = $this->activeTab();

        if (! $chosen) {
            return view('livewire.commercial.billing', [
                'snapshots' => [], 'chosen' => null, 'activeTab' => $tab, 'tabs' => self::TABS,
                'canUpload' => $this->actorCan('commercial.upload_reports'),
            ]);
        }

        $allRoutes = $this->routesOf($chosen['id']);
        $districts = $allRoutes->pluck('district_label_raw')->unique()->sort()->values();
        $districtKey = $this->district !== '' && $districts->contains($this->district) ? $this->district : '';
        $routes = $districtKey === '' ? $allRoutes : $allRoutes->where('district_label_raw', $districtKey)->values();

        $data = match ($tab) {
            'overview' => [
                'overview' => $billing->overview($routes, array_key_exists($this->rank, self::RANKINGS) ? $this->rank : 'billing'),
                'roll' => $billing->rollForward($routes),
            ],
            'collections' => ['collections' => $billing->collections($routes)],
            'balances' => ['balances' => $billing->balances($routes)],
            'estimation' => ['estimation' => $billing->estimationAndUnbilled($routes)],
            'bands' => ['bands' => $billing->bands(CommercialBillingBand::query()->where('batch_id', $chosen['id'])->get())],
            'exceptions' => ['exceptions' => $billing->exceptions($routes)],
            'compare' => $this->compareData($billing, $snapshots, $chosen, $allRoutes),
        };

        return view('livewire.commercial.billing', [
            'snapshots' => $snapshots,
            'chosen' => $chosen,
            'activeTab' => $tab,
            'tabs' => self::TABS,
            'rankings' => self::RANKINGS,
            'districtOptions' => $districts,
            'districtKey' => $districtKey,
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'canExport' => $this->actorCan('commercial.export_reports'),
            'target' => (float) config('gwl.commercial_target_collection_pct'),
            ...$data,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return array<string, mixed>
     */
    protected function compareData(BillingAnalyticsService $billing, array $snapshots, array $chosen, Collection $chosenRoutes): array
    {
        // Same region and segment, single months only: the only snapshots that can be compared or trended.
        $family = array_values(array_filter($snapshots, fn (array $s) => $s['is_single_month'] && $s['region_id'] === $chosen['region_id'] && $s['segment'] === $chosen['segment']));
        $candidates = array_values(array_filter($family, fn (array $s) => $s['id'] !== $chosen['id']));

        $other = null;

        if ($chosen['is_single_month'] && $candidates !== []) {
            foreach ($candidates as $candidate) {
                if ((string) $candidate['id'] === $this->compare) {
                    $other = $candidate;
                }
            }

            // Default: the nearest earlier month, else the nearest later one.
            $other ??= collect($candidates)->filter(fn ($s) => $s['month'] < $chosen['month'])->sortByDesc('month')->first()
                ?? collect($candidates)->sortBy('month')->first();
        }

        $comparison = $other
            ? $billing->compare(['meta' => $chosen, 'routes' => $chosenRoutes], ['meta' => $other, 'routes' => $this->routesOf($other['id'])])
            : null;

        $trend = $billing->trend($this->trendSnapshots($family, $chosen, $chosenRoutes));

        return [
            'comparison' => $comparison,
            'compareWith' => $other,
            'candidates' => $candidates,
            'trend' => $trend,
            'chosenIsSingleMonth' => $chosen['is_single_month'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $family
     * @return list<array{meta: array<string, mixed>, routes: Collection}>
     */
    protected function trendSnapshots(array $family, array $chosen, Collection $chosenRoutes): array
    {
        if (count($family) < 3) {
            return array_map(fn (array $meta) => ['meta' => $meta, 'routes' => collect()], $family);
        }

        $byBatch = CommercialBillingRoute::query()->whereIn('batch_id', array_column($family, 'id'))->get()->groupBy('batch_id');

        return array_map(fn (array $meta) => ['meta' => $meta, 'routes' => $byBatch[$meta['id']] ?? collect()], $family);
    }
}
