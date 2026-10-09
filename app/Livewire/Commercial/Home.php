<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\CommercialReadingStrength;
use App\Models\Permission;
use App\Services\Commercial\BillingAnalyticsService;
use App\Services\Commercial\ReadingAnalyticsService;
use Livewire\Component;

/**
 * The overview: headline reading numbers for the latest COMPLETE month (with the change on the month before and the
 * configured target as a muted reference), the monthly trend, and which reports have been loaded.
 */
class Home extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission(
            'commercial.view_dashboard',
            'commercial.view_billing',
            'commercial.view_reading',
            'commercial.view_customer_analytics',
            'commercial.upload_reports',
            'commercial.resolve_matches',
            'commercial.void_batches',
        );
    }

    /**
     * Tiles for the latest complete month against the one before it. The in-progress month is never a tile: it is
     * still filling up and would always look like a collapse.
     *
     * @param  list<array<string, mixed>>  $trend
     * @return array{month: string, tiles: list<array<string, mixed>>}|null
     */
    protected function tiles(array $trend): ?array
    {
        $complete = array_values(array_filter($trend, fn (array $row) => ! $row['in_progress']));

        if ($complete === []) {
            return null;
        }

        $now = $complete[count($complete) - 1];
        $before = count($complete) > 1 ? $complete[count($complete) - 2] : null;

        $change = function (string $key, bool $isRate, bool $higherIsBetter) use ($now, $before): array {
            if (! $before || $now[$key] === null || $before[$key] === null || ($before[$key] == 0 && ! $isRate)) {
                return ['delta' => null, 'delta_tone' => 'neutral', 'direction' => null];
            }

            $diff = $isRate ? round($now[$key] - $before[$key], 1) : round(($now[$key] - $before[$key]) / $before[$key] * 100, 1);
            $text = ($diff > 0 ? '+' : '').number_format($diff, 1).($isRate ? ' pts' : '%').' on '.$before['label'];

            return [
                'delta' => $text,
                'delta_tone' => $diff == 0 ? 'neutral' : (($diff > 0) === $higherIsBetter ? 'good' : 'bad'),
                'direction' => $diff == 0 ? null : ($diff > 0 ? 'up' : 'down'),
            ];
        };

        $pct = fn ($value) => $value === null ? '–' : number_format($value, 1).'%';

        return [
            'month' => $now['label'],
            'tiles' => [
                ['label' => 'Visited', 'value' => number_format($now['visited']), 'icon' => 'users', 'tone' => 'primary', 'meta' => null, ...$change('visited', false, true)],
                ['label' => 'Read', 'value' => number_format($now['read']), 'icon' => 'check', 'tone' => 'success', 'meta' => null, ...$change('read', false, true)],
                ['label' => 'Skip rate', 'value' => $pct($now['skip_rate']), 'icon' => 'triangle-alert', 'tone' => 'warning', 'meta' => 'Configured target: '.number_format((float) config('gwl.commercial_target_skip_rate_pct'), 0).'%', ...$change('skip_rate', true, false)],
                ['label' => 'Coverage', 'value' => $pct($now['coverage']), 'icon' => 'map', 'tone' => 'primary', 'meta' => 'Configured target: '.number_format((float) config('gwl.commercial_target_coverage_pct'), 0).'%', ...$change('coverage', true, true)],
            ],
        ];
    }

    /**
     * Billing headline numbers for the default snapshot (the latest single month, else the latest period).
     *
     * @return array{label: string, period: string, tiles: list<array<string, mixed>>}|null
     */
    protected function billingTiles(BillingAnalyticsService $billing): ?array
    {
        $snapshot = $billing->defaultSnapshot($billing->snapshots($this->scopeBatchesForActor(CommercialImportBatch::query())));

        if (! $snapshot) {
            return null;
        }

        $routes = CommercialBillingRoute::query()->where('batch_id', $snapshot['id'])->get();
        $totals = $billing->totals($routes);
        $metrics = $billing->metrics($totals);
        $pct = fn ($value) => $value === null ? '–' : number_format($value, 1).'%';

        return [
            'label' => $snapshot['label'],
            'period' => $snapshot['period_label'],
            'tiles' => [
                ['label' => 'Billing', 'value' => 'GH¢ '.number_format($totals['billing_for_period'], 2), 'icon' => 'receipt', 'tone' => 'primary', 'meta' => null],
                ['label' => 'Payments', 'value' => 'GH¢ '.number_format($totals['total_payments'], 2), 'icon' => 'banknote', 'tone' => 'success', 'meta' => null],
                ['label' => 'Cash collection ratio', 'value' => $pct($metrics['cash_ratio']), 'icon' => 'trending-up', 'tone' => 'primary', 'meta' => 'Configured target: '.number_format((float) config('gwl.commercial_target_collection_pct'), 0).'%'],
                ['label' => 'Unbilled rate', 'value' => $pct($metrics['unbilled_rate']), 'icon' => 'triangle-alert', 'tone' => 'warning', 'meta' => null],
            ],
        ];
    }

    public function render(ReadingAnalyticsService $analytics, BillingAnalyticsService $billing)
    {
        $trend = [];
        $kpis = null;

        if ($this->actorCan('commercial.view_reading') || $this->actorCan('commercial.view_dashboard')) {
            $since = now()->subMonths(11)->startOfMonth()->toDateString();

            $stats = $this->scopeReadingStatsForActor(CommercialReadingStat::query()->effective()->readers())->whereDate('month', '>=', $since)->get();
            $strengths = $this->scopeStrengthsForActor(CommercialReadingStrength::query()->effective())->whereDate('month', '>=', $since)->get();

            $trend = $analytics->monthlyTrend($stats, $strengths, now());
            $kpis = $this->tiles($trend);
        }

        $billingKpis = $this->actorCan('commercial.view_billing') || $this->actorCan('commercial.view_dashboard')
            ? $this->billingTiles($billing)
            : null;

        // Latest batch that still counts, per report type and region.
        $latest = $this->scopeBatchesForActor(CommercialImportBatch::query())
            ->notVoided()
            ->with('region')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (CommercialImportBatch $batch) => $batch->report_type.'|'.$batch->region_id)
            ->sortBy([['report_type', 'asc'], ['region_id', 'asc']])
            ->values();

        return view('livewire.commercial.home', [
            'latest' => $latest,
            'trend' => $trend,
            'kpis' => $kpis,
            'billingKpis' => $billingKpis,
            'canSeeBilling' => $this->actorCan('commercial.view_billing'),
            'canSeeReading' => $this->actorCan('commercial.view_reading') || $this->actorCan('commercial.view_reader_performance'),
            'types' => CommercialImportBatch::TYPES,
            'canSeeUploads' => $this->actorCan('commercial.upload_reports')
                || $this->actorCan('commercial.resolve_matches')
                || $this->actorCan('commercial.void_batches'),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'canSeeCustomers' => (bool) config('gwl.commercial_customer_list_enabled') && $this->actorCan('commercial.view_customer_analytics'),
        ]);
    }
}
