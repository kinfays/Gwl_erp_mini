<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\CommercialReadingStrength;
use App\Models\District;
use App\Models\Permission;
use App\Models\Region;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\ReadingAnalyticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Meter-reading analytics. "Trend & coverage" (R1-R5) needs commercial.view_reading; the per-reader tabs (R6-R13) are
 * individual staff performance and need commercial.view_reader_performance. A tab the user may not open is never
 * computed: editing ?tab= in the URL falls back to the first tab they are allowed.
 */
class Reading extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    public const TABS = [
        'trend' => 'Trend & coverage',
        'readers' => 'CCAs',
        'exceptions' => 'Exceptions',
        'scorecard' => 'Scorecard',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'trend';

    /** Region id; only honoured for users who see every region. */
    #[Url]
    public string $region = '';

    /** District id: the reader's HOME district in the staff directory (the report has no district split). */
    #[Url]
    public string $district = '';

    /** First / last month shown, as Y-m. */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /**
     * An exact date range (Phase 8a): YYYY-MM-DD. When typed it replaces the month shortcuts above. A month counts only when the WHOLE
     * month lies inside the range (CommercialPeriods rule 5); a month partly outside is excluded and listed under the filters.
     */
    #[Url(as: 'df')]
    public string $dateFrom = '';

    #[Url(as: 'dt')]
    public string $dateTo = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_reading', 'commercial.view_reader_performance');
    }

    public function clearFilters(): void
    {
        $this->reset('region', 'district', 'from', 'to', 'dateFrom', 'dateTo');
    }

    /** The range in force: the typed dates, else the month shortcuts (Y-m), else none. */
    protected function rangeFrom(): string
    {
        return trim($this->dateFrom) !== '' ? trim($this->dateFrom) : $this->from;
    }

    protected function rangeTo(): string
    {
        return trim($this->dateTo) !== '' ? trim($this->dateTo) : $this->to;
    }

    /**
     * What the typed range counts and what it leaves out: the months of data inside it, the days of it that have no data, and the
     * months partly outside it (excluded, listed). Null when no range is typed.
     *
     * @return array<string, mixed>|null
     */
    protected function rangeSummary(): ?array
    {
        $start = CommercialPeriods::bound($this->rangeFrom(), false);
        $end = CommercialPeriods::bound($this->rangeTo(), true);

        if ($start === null && $end === null) {
            return null;
        }

        $months = $this->filteredReadingStats($this->regionId(), $this->districtId())->distinct()->orderBy('month')->pluck('month')
            ->map(fn ($month) => Carbon::parse($month)->startOfMonth())->unique(fn (Carbon $m) => $m->toDateString())->values();

        if ($months->isEmpty()) {
            return null;
        }

        $units = $months->map(fn (Carbon $m) => ['id' => $m->format('Y-m'), 'from' => $m->toDateString(), 'to' => $m->copy()->endOfMonth()->toDateString(), 'order' => 1])->all();
        $selection = (new CommercialPeriods)->select($units, $start?->toDateString() ?? $months->first()->toDateString(), $end?->toDateString() ?? $months->last()->copy()->endOfMonth()->toDateString());

        return [
            'range' => CommercialPeriods::describe($selection['from'], $selection['to']),
            'counted' => array_map(fn (string $id) => Carbon::parse($id.'-01')->format('M Y'), array_map('strval', $selection['chosen'])),
            'partly_outside' => array_map(fn (string $id) => Carbon::parse($id.'-01')->format('M Y'), array_map('strval', $selection['partly_outside'])),
            'gaps' => $selection['gaps'] === [] ? null : CommercialPeriods::describeIntervals($selection['gaps']),
            'covered_days' => $selection['covered_days'],
            'range_days' => $selection['range_days'],
        ];
    }

    /** @return array<string, string> the tabs this user may open */
    protected function allowedTabs(): array
    {
        $tabs = [];

        if ($this->actorCan('commercial.view_reading')) {
            $tabs['trend'] = self::TABS['trend'];
        }

        if ($this->actorCan('commercial.view_reader_performance')) {
            $tabs += array_diff_key(self::TABS, ['trend' => true]);
        }

        return $tabs;
    }

    protected function activeTab(): string
    {
        $allowed = $this->allowedTabs();

        return array_key_exists($this->tab, $allowed) ? $this->tab : (string) array_key_first($allowed);
    }

    protected function regionId(): ?int
    {
        return $this->actorSeesAllRegions() && ctype_digit($this->region) && $this->region !== '' ? (int) $this->region : null;
    }

    protected function districtId(): ?int
    {
        return ctype_digit($this->district) && $this->district !== '' ? (int) $this->district : null;
    }

    protected function statsQuery(): Builder
    {
        return $this->filteredReadingStats($this->regionId(), $this->districtId(), $this->rangeFrom(), $this->rangeTo());
    }

    protected function strengthsQuery(): Builder
    {
        return $this->filteredStrengths($this->regionId(), $this->rangeFrom(), $this->rangeTo());
    }

    /** @return array<string, string> "2026-09" => "Sep 2026" for each month the user's uploads cover, oldest first, plus any month already chosen */
    protected function monthOptions(): array
    {
        $months = $this->filteredReadingStats($this->regionId(), null)->distinct()->orderBy('month')->pluck('month')
            ->map(fn ($month) => Carbon::parse($month)->format('Y-m'))->unique()->values();

        foreach ([$this->from, $this->to] as $chosen) {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $chosen) && ! $months->contains($chosen)) {
                $months->push($chosen);
            }
        }

        return $months->sort()->mapWithKeys(fn (string $month) => [$month => Carbon::parse($month.'-01')->format('M Y')])->all();
    }

    public function render(ReadingAnalyticsService $analytics, CommercialReportData $reportData)
    {
        $tab = $this->activeTab();
        $asOf = now();
        $hasData = $this->scopeBatchesForActor(CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_READING_SUMMARY)->notVoided())->exists();

        $data = [];

        if ($hasData && $tab === 'trend') {
            // The same path the reading-trend export takes, so the page and the file cannot disagree (and a small district
            // is held back from a user who may not see individual readers).
            $result = $reportData->readingTrend($this->regionId(), $this->districtId(), $this->rangeFrom(), $this->rangeTo());

            $data = [
                'trend' => $result['trend'],
                'growth' => $result['growth'],
                'coverageHidden' => $result['coverage_hidden'],
                'smallGroup' => $result['small_group'],
                'minReaders' => $result['min_readers'],
                // R14: months uploaded more than once (weekly uploads), at each upload.
                'paceCards' => $reportData->paceCards($this->regionId(), $this->rangeFrom(), $this->rangeTo()),
            ];
        } elseif ($hasData) {
            // Reader screens: names and home districts are loaded once, so a table of 70 readers is not 70 queries.
            $stats = $this->statsQuery()->with(['employee:id,full_name', 'district:id,district_name'])->get();
            $table = $analytics->readerTable($stats);

            $data = match ($tab) {
                'readers' => [
                    'readers' => $table,
                    'quality' => $analytics->quality($table),
                    'consistency' => $analytics->consistency($stats, $asOf),
                    'movement' => $analytics->movement($stats, $asOf),
                ],
                'exceptions' => [
                    'inactive' => $analytics->inactive($stats, $asOf),
                    'workload' => $analytics->workload($stats, $asOf),
                    'outliers' => $analytics->outliers($stats, $asOf),
                ],
                'scorecard' => ['scorecard' => $analytics->scorecard($stats, $asOf)],
            };
        }

        $districts = $this->scopeDistrictsForActor(District::query())
            ->when(! $this->actorSeesAllRegions(), fn ($query) => $query->where('region_id', $this->actorRegionId() ?? 0))
            ->when($this->regionId(), fn ($query, int $region) => $query->where('region_id', $region))
            ->orderBy('district_name')
            ->get();

        return view('livewire.commercial.reading', [
            'activeTab' => $tab,
            'tabs' => $this->allowedTabs(),
            'hasData' => $hasData,
            'canReaders' => $this->actorCan('commercial.view_reader_performance'),
            'canExport' => $this->actorCan('commercial.export_reports'),
            'canExportTrend' => $this->actorCan('commercial.export_reports') && $this->actorCan('commercial.view_reading'),
            'canResolve' => $this->actorCan('commercial.resolve_matches') || $this->actorCan('commercial.upload_reports'),
            // A plain list of the months that have data (a native month picker does not open in every browser).
            'monthOptions' => $this->monthOptions(),
            'regions' => $this->actorSeesAllRegions() ? Region::query()->orderBy('region_name')->get() : collect(),
            'districts' => $districts,
            'targets' => [
                'skip' => (float) config('gwl.commercial_target_skip_rate_pct'),
                'coverage' => (float) config('gwl.commercial_target_coverage_pct'),
            ],
            'filtered' => $this->region !== '' || $this->district !== '' || $this->from !== '' || $this->to !== '' || trim($this->dateFrom) !== '' || trim($this->dateTo) !== '',
            'rangeFrom' => $this->rangeFrom(),
            'rangeTo' => $this->rangeTo(),
            'rangeSummary' => $this->rangeSummary(),
            ...$data,
        ]);
    }
}
