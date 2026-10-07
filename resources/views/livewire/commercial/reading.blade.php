<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php
        $pct = fn ($value, $digits = 1) => $value === null ? '–' : number_format($value, $digits).'%';
        $signed = fn ($value, $suffix = '') => $value === null ? '–' : ($value > 0 ? '+' : '').number_format($value, 1).$suffix;
        $monthLabel = fn ($month) => \Illuminate\Support\Carbon::parse($month)->format('M Y');
    @endphp

    @php $exportQuery = array_filter(['region' => $region, 'district' => $district, 'from' => $from, 'to' => $to]); @endphp

    <x-ui.page-header title="Meter Reading" description="Visits, skips and coverage from the reading reports. Rates are worked out from the counts, never taken from the report.">
        <x-slot:actions>
            @if ($hasData && $activeTab === 'trend' && $canExportTrend)
                <a href="{{ route('commercial.export', ['report' => 'reading-trend', 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.icon name="file-spreadsheet" /> Excel</a>
            @elseif ($hasData && $activeTab !== 'trend' && $canReaders && $canExport)
                <a href="{{ route('commercial.export', ['report' => 'readers', 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.icon name="file-spreadsheet" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'readers', 'format' => 'pdf', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.icon name="file-text" /> PDF</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $hasData)
        <x-ui.card>
            <x-ui.empty-state icon="file-spreadsheet" title="No meter reading report has been loaded yet." description="Upload the Customer Meter Reading Report (rptReadingSummDate) and the analysis appears here.">
                @if ($canResolve)
                    <a href="{{ route('commercial.batches') }}" class="btn btn-primary">Go to uploads</a>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="ui-toolbar dash-row" role="search" aria-label="Reading filters">
            @if (count($tabs) > 1)
                <x-ui.segmented label="Reading section" wire:model.live="tab" :options="$tabs" :value="$activeTab" />
            @endif
            @if ($regions->isNotEmpty())
                <select wire:model.live="region" class="form-input" aria-label="Region">
                    <option value="">All regions</option>
                    @foreach ($regions as $r)
                        <option value="{{ $r->id }}">{{ $r->region_name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="district" class="form-input" aria-label="Reader's home district">
                <option value="">Any home district</option>
                @foreach ($districts as $d)
                    <option value="{{ $d->id }}">{{ $d->district_name }}</option>
                @endforeach
            </select>
            <input type="month" wire:model.live="from" class="form-input" aria-label="From month">
            <input type="month" wire:model.live="to" class="form-input" aria-label="To month">
            @if ($filtered)
                <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear filters</button>
            @endif
        </div>

        @if ($district !== '')
            <p class="ui-hint dash-row">Filtered by the reader's <strong>home district</strong> in the staff directory. The report has no district split, so this is where the reader is posted, not where they read.</p>
        @endif

        @if ($activeTab === 'trend')
            @if (! empty($smallGroup))
                <x-ui.card>
                    <x-ui.empty-state icon="users" title="Too few readers to show this district" :description="'This home district has fewer than '.$minReaders.' readers with visits in these months, so its figures would be an individual reader\'s. Users with access to reader-level figures can see it; otherwise pick a wider group or clear the district filter.'" />
                </x-ui.card>
            @elseif (empty($trend))
                <x-ui.card><x-ui.empty-state icon="chart-column" title="No reading data for these filters." description="Widen the months or clear the district filter." /></x-ui.card>
            @else
                @php
                    $labels = array_map(fn ($row) => $row['label'].($row['in_progress'] ? ' (in progress)' : ''), $trend);
                @endphp

                <p class="ui-hint dash-row">
                    Configured targets (placeholders until the Commercial team confirms them): skip rate at most {{ $pct($targets['skip'], 0) }}, coverage at least {{ $pct($targets['coverage'], 0) }}.
                    The current month is <x-ui.badge tone="warning">In progress</x-ui.badge> and still filling up.
                </p>

                <div class="ui-grid ui-grid-2 dash-row">
                    <x-ui.card title="Visits per month" description="Read plus skipped.">
                        <div wire:key="trend-visits-{{ md5(json_encode($trend)) }}">
                            <x-ui.chart type="bar" label="Read and skipped visits per month" :stacked="true"
                                :labels="$labels"
                                :series="[
                                    ['label' => 'Read', 'data' => array_column($trend, 'read'), 'color' => 'series-1'],
                                    ['label' => 'Skipped', 'data' => array_column($trend, 'skipped'), 'color' => 'warning'],
                                ]"
                                height="260" />
                        </div>
                    </x-ui.card>

                    <x-ui.card title="Skip rate and coverage" description="Skipped ÷ visited, and visited ÷ verified strength.">
                        <div wire:key="trend-rates-{{ md5(json_encode($trend)) }}">
                            <x-ui.chart type="line" label="Skip rate and coverage per month" unit="%"
                                :labels="$labels"
                                :series="array_values(array_filter([
                                    ['label' => 'Skip rate', 'data' => array_column($trend, 'skip_rate'), 'color' => 'warning'],
                                    $coverageHidden ? null : ['label' => 'Coverage', 'data' => array_column($trend, 'coverage'), 'color' => 'series-1'],
                                ]))"
                                height="260" />
                        </div>
                    </x-ui.card>
                </div>

                @if (! empty($paceCards))
                    @foreach ($paceCards as $pace)
                        <x-ui.card :title="'Month to date: '.$pace['label']" description="How the month filled up across uploads. The projection is indicative: visits so far ÷ the share of the month elapsed at the upload." :padded="false" class="dash-row" wire:key="pace-{{ $pace['month'] }}">
                            <x-ui.table label="Month-to-date pace at each upload" :sticky="false">
                                <x-slot:head>
                                    <tr><th>Uploaded (upload time)</th><th class="num">Month elapsed</th><th class="num">Visited</th><th class="num">Read</th><th class="num">Skip rate</th><th class="num">Projected visits <x-ui.badge>indicative</x-ui.badge></th><th class="num">vs {{ $pace['previous']['label'] }}</th></tr>
                                </x-slot:head>
                                @foreach ($pace['snapshots'] as $snap)
                                    <tr wire:key="pace-{{ $pace['month'] }}-{{ $snap['batch_id'] }}">
                                        <td>{{ $snap['uploaded_at']->format('d M Y H:i') }} <span class="ui-person-sub">batch #{{ $snap['batch_id'] }}</span></td>
                                        <td class="num">{{ number_format($snap['fraction'], 1) }}%</td>
                                        <td class="num">{{ number_format($snap['visits']) }}</td>
                                        <td class="num">{{ number_format($snap['read']) }}</td>
                                        <td class="num">{{ $pct($snap['skip_rate']) }}</td>
                                        <td class="num">{{ $snap['projected_visits'] === null ? '–' : number_format($snap['projected_visits']) }}</td>
                                        <td class="num">{{ $snap['vs_previous_pct'] === null ? '–' : $signed($snap['vs_previous_pct'], '%') }}</td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                            <p class="ui-hint" style="padding:8px 14px">
                                {{ $pace['previous']['visits'] === null ? 'There is no previous complete month to compare with.' : $pace['previous']['label'].' had '.number_format($pace['previous']['visits']).' visits.' }}
                                Replaced uploads are kept, so the pace of earlier weeks stays visible; voided uploads are left out.
                            </p>
                        </x-ui.card>
                    @endforeach
                @else
                    <p class="ui-hint dash-row">Month-to-date pace appears once a month has been uploaded at least twice (for example weekly).</p>
                @endif

                <x-ui.card title="Month by month" description="Rates are recomputed from the counts." :padded="false" class="dash-row">
                    <x-ui.table label="Meter reading by month" :sticky="false">
                        <x-slot:head>
                            <tr>
                                <th>Month</th>
                                <th class="num">Visited</th>
                                <th class="num">Read</th>
                                <th class="num">Skipped</th>
                                <th class="num">Read rate</th>
                                <th class="num">Skip rate</th>
                                <th class="num">Readers active</th>
                                <th class="num">Verified strength</th>
                                <th class="num">Coverage</th>
                            </tr>
                        </x-slot:head>
                        @foreach ($trend as $row)
                            <tr wire:key="trend-{{ $row['month'] }}">
                                <td>
                                    @if ($canReaders)
                                        <a href="{{ route('commercial.reading', ['tab' => 'readers', 'from' => substr($row['month'], 0, 7), 'to' => substr($row['month'], 0, 7)]) }}">{{ $row['label'] }}</a>
                                    @else
                                        {{ $row['label'] }}
                                    @endif
                                    @if ($row['in_progress'])<x-ui.badge tone="warning">In progress</x-ui.badge>@endif
                                </td>
                                <td class="num">{{ number_format($row['visited']) }}</td>
                                <td class="num">{{ number_format($row['read']) }}</td>
                                <td class="num">{{ number_format($row['skipped']) }}</td>
                                <td class="num">{{ $pct($row['read_rate']) }}</td>
                                <td class="num">{{ $pct($row['skip_rate']) }}</td>
                                <td class="num">{{ number_format($row['readers']) }}</td>
                                <td class="num">{{ $row['strength'] === null ? '–' : number_format($row['strength']) }}</td>
                                <td class="num">{{ $coverageHidden ? '–' : $pct($row['coverage']) }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                    @if ($coverageHidden)
                        <p class="ui-hint" style="padding:8px 14px">Coverage is not shown for one district: the verified strength is region-wide.</p>
                    @endif
                </x-ui.card>

                <x-ui.card title="Customer base" description="Verified strength, month on month." :padded="false" class="dash-row">
                    <x-ui.table label="Verified customer strength by month" :sticky="false">
                        <x-slot:head>
                            <tr><th>Month</th><th class="num">Verified strength</th><th class="num">Change</th><th class="num">Change %</th></tr>
                        </x-slot:head>
                        @forelse ($growth as $row)
                            <tr wire:key="growth-{{ $row['month'] }}">
                                <td>{{ $row['label'] }}</td>
                                <td class="num">{{ number_format($row['strength']) }}</td>
                                <td class="num">{{ $row['change'] === null ? '–' : ($row['change'] > 0 ? '+' : '').number_format($row['change']) }}</td>
                                <td class="num">{{ $signed($row['change_pct'], '%') }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="4" icon="users" title="No verified strength for these filters." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            @endif
        @elseif ($activeTab === 'readers')
            @if (empty($readers))
                <x-ui.card><x-ui.empty-state icon="users" title="No readers for these filters." description="Widen the months or clear the district filter." /></x-ui.card>
            @else
                <x-ui.card title="League table" description="Visits per reader over the months shown, busiest first. The system account is never listed." :padded="false" class="dash-row">
                    <x-ui.table label="Readers ranked by visits" :sticky="false">
                        <x-slot:head>
                            <tr>
                                <th class="num">#</th>
                                <th>Reader</th>
                                <th>Home district</th>
                                <th class="num">Visited</th>
                                <th class="num">Share</th>
                                <th class="num">Read</th>
                                <th class="num">Skipped</th>
                                <th class="num">Skip rate</th>
                                <th class="num">Months active</th>
                            </tr>
                        </x-slot:head>
                        @foreach ($readers as $i => $row)
                            <tr wire:key="reader-{{ $row['staff_id'] }}">
                                <td class="num">{{ $i + 1 }}</td>
                                <td>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</td>
                                <td @class(['cell-muted' => ! $row['district']])>{{ $row['district'] ?? 'Unknown' }}</td>
                                <td class="num"><strong>{{ number_format($row['visited']) }}</strong></td>
                                <td class="num">{{ $pct($row['share']) }}</td>
                                <td class="num">{{ number_format($row['read']) }}</td>
                                <td class="num">{{ number_format($row['skipped']) }}</td>
                                <td class="num">{{ $pct($row['skip_rate']) }}</td>
                                <td class="num">{{ $row['months_active'] }} / {{ $row['months'] }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>

                <div class="ui-grid ui-grid-2 dash-row">
                    <x-ui.card title="Lowest skip rates" :description="'Best '.$quality['decile_size'].' of the readers with at least '.number_format($quality['min_visits']).' visits.'" :padded="false">
                        <x-ui.table label="Readers with the lowest skip rate" :sticky="false">
                            <x-slot:head><tr><th>Reader</th><th class="num">Visited</th><th class="num">Skip rate</th></tr></x-slot:head>
                            @forelse ($quality['best'] as $row)
                                <tr wire:key="best-{{ $row['staff_id'] }}"><td>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</td><td class="num">{{ number_format($row['visited']) }}</td><td class="num">{{ $pct($row['skip_rate']) }}</td></tr>
                            @empty
                                <x-ui.empty-row :colspan="3" icon="users" title="No reader has enough visits to be ranked." />
                            @endforelse
                        </x-ui.table>
                    </x-ui.card>

                    <x-ui.card title="Highest skip rates" :description="'Worst '.$quality['decile_size'].' of the readers with at least '.number_format($quality['min_visits']).' visits.'" :padded="false">
                        <x-ui.table label="Readers with the highest skip rate" :sticky="false">
                            <x-slot:head><tr><th>Reader</th><th class="num">Visited</th><th class="num">Skip rate</th></tr></x-slot:head>
                            @forelse ($quality['worst'] as $row)
                                <tr wire:key="worst-{{ $row['staff_id'] }}"><td>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</td><td class="num">{{ number_format($row['visited']) }}</td><td class="num">{{ $pct($row['skip_rate']) }}</td></tr>
                            @empty
                                <x-ui.empty-row :colspan="3" icon="users" title="No reader has enough visits to be ranked." />
                            @endforelse
                        </x-ui.table>
                    </x-ui.card>
                </div>

                <x-ui.card title="Consistency" description="How steady each reader's monthly visits are (standard deviation ÷ mean; lower is steadier). Complete months only." :padded="false" class="dash-row">
                    @if (! $consistency['enough_months'])
                        <x-ui.empty-state icon="calendar-days" title="Not enough months" :description="'Consistency needs at least '.$consistency['min_months'].' complete months; the months shown have '.$consistency['complete_months'].'. The current month is still in progress and is left out.'" />
                    @else
                        <x-ui.table label="Reader consistency" :sticky="false">
                            <x-slot:head><tr><th>Reader</th><th class="num">Months</th><th class="num">Average visits</th><th class="num">Lowest</th><th class="num">Highest</th><th class="num">Variation</th><th class="num">Months below own average</th></tr></x-slot:head>
                            @foreach ($consistency['rows'] as $row)
                                <tr wire:key="cv-{{ $row['staff_id'] }}">
                                    <td>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</td>
                                    <td class="num">{{ $row['months'] }}</td>
                                    <td class="num">{{ number_format($row['mean']) }}</td>
                                    <td class="num">{{ number_format($row['min']) }}</td>
                                    <td class="num">{{ number_format($row['max']) }}</td>
                                    <td class="num">{{ $pct($row['cv']) }}</td>
                                    <td class="num">{{ $row['months_below_average'] }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    @endif
                </x-ui.card>

                <x-ui.card title="Month-on-month movement" :description="$movement['enough_months'] ? $monthLabel($movement['from_month']).' to '.$monthLabel($movement['to_month']).' (the last two complete months).' : 'Compares the last two complete months.'" :padded="false" class="dash-row">
                    @if (! $movement['enough_months'])
                        <x-ui.empty-state icon="calendar-days" title="Not enough months" description="Movement needs two complete months. The current month is still in progress and is left out." />
                    @else
                        <x-ui.table label="Reader movement between the last two complete months" :sticky="false">
                            <x-slot:head><tr><th>Reader</th><th class="num">Visited before</th><th class="num">Visited now</th><th class="num">Change</th><th class="num">Skip rate before</th><th class="num">Skip rate now</th><th class="num">Change (points)</th><th>Skip-rate trend</th></tr></x-slot:head>
                            @foreach ($movement['rows'] as $row)
                                <tr wire:key="mv-{{ $row['staff_id'] }}">
                                    <td>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</td>
                                    <td class="num">{{ number_format($row['visited_before']) }}</td>
                                    <td class="num">{{ number_format($row['visited_after']) }}</td>
                                    <td class="num">{{ ($row['visited_change'] > 0 ? '+' : '').number_format($row['visited_change']) }}</td>
                                    <td class="num">{{ $pct($row['skip_rate_before']) }}</td>
                                    <td class="num">{{ $pct($row['skip_rate_after']) }}</td>
                                    <td class="num">{{ $signed($row['skip_rate_change']) }}</td>
                                    <td><x-ui.badge :tone="match ($row['trend']) { 'improving' => 'success', 'declining' => 'danger', default => 'neutral' }">{{ ucfirst($row['trend']) }}</x-ui.badge></td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    @endif
                </x-ui.card>
            @endif
        @elseif ($activeTab === 'exceptions')
            <p class="ui-hint dash-row">Exceptions use <strong>complete months</strong> only; the current month is still filling up and is left out.</p>

            <x-ui.card title="Inactive and under-used readers" :description="'No visits at all, or below '.$inactive['low_pct'].'% of the median of the active readers that month.'" :padded="false" class="dash-row">
                @if (! $inactive['enough_months'])
                    <x-ui.empty-state icon="calendar-days" title="No complete month in this range" description="Widen the months to include a finished month." />
                @else
                    <x-ui.table label="Inactive readers by month" :sticky="false">
                        <x-slot:head><tr><th>Month</th><th>No visits</th><th>Well below the median</th></tr></x-slot:head>
                        @foreach ($inactive['months'] as $month)
                            <tr wire:key="inactive-{{ $month['month'] }}">
                                <td>{{ $month['label'] }}<span class="ui-person-sub">median {{ $month['median'] === null ? '–' : number_format($month['median']) }}</span></td>
                                <td>
                                    @forelse ($month['zero'] as $row)
                                        <div>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</div>
                                    @empty
                                        <span class="cell-muted">None</span>
                                    @endforelse
                                </td>
                                <td>
                                    @forelse ($month['low'] as $row)
                                        <div>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to]) <span class="ui-person-sub">{{ number_format($row['visited']) }} visits ({{ $pct($row['pct_of_median'], 0) }} of median)</span></div>
                                    @empty
                                        <span class="cell-muted">None</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            <x-ui.card title="Workload balance" :description="'Visits per reader against the median of active readers: low below '.$workload['low_pct'].'%, high above '.$workload['high_pct'].'%.'" :padded="false" class="dash-row">
                @if (! $workload['enough_months'])
                    <x-ui.empty-state icon="calendar-days" title="No complete month in this range" description="Widen the months to include a finished month." />
                @else
                    <div class="ui-stat-grid" style="padding:14px">
                        <x-ui.stat-tile label="Median visits (active readers)" :value="$workload['median'] === null ? '–' : number_format($workload['median'])" icon="users" />
                        <x-ui.stat-tile label="Busiest reader ÷ median" :value="$workload['max_over_median'] === null ? '–' : number_format($workload['max_over_median'], 2).'×'" icon="trending-up" />
                        <x-ui.stat-tile label="Above the high band" :value="$workload['counts']['high'] ?? 0" icon="triangle-alert" tone="warning" />
                        <x-ui.stat-tile label="Below the low band" :value="$workload['counts']['low'] ?? 0" icon="triangle-alert" tone="warning" />
                        <x-ui.stat-tile label="No visits" :value="$workload['counts']['inactive'] ?? 0" icon="users" tone="danger" />
                    </div>
                    <x-ui.table label="Readers outside the normal workload band" :sticky="false">
                        <x-slot:head><tr><th>Reader</th><th class="num">Visited</th><th class="num">% of median</th><th>Band</th></tr></x-slot:head>
                        @forelse (array_filter($workload['rows'], fn ($row) => $row['band'] !== 'normal') as $row)
                            <tr wire:key="wl-{{ $row['staff_id'] }}">
                                <td>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</td>
                                <td class="num">{{ number_format($row['visited']) }}</td>
                                <td class="num">{{ $pct($row['pct_of_median'], 0) }}</td>
                                <td><x-ui.badge :tone="match ($row['band']) { 'high' => 'warning', 'low' => 'warning', default => 'danger' }">{{ ucfirst($row['band']) }}</x-ui.badge></td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="4" icon="check" title="Every reader is within the normal band." />
                        @endforelse
                    </x-ui.table>
                @endif
            </x-ui.card>

            <x-ui.card title="Skip-rate outliers" :description="'Skip rate more than '.number_format($outliers['threshold'], 1).' standard deviations above the other readers. Only readers with at least '.number_format($outliers['min_visits']).' visits are judged.'" :padded="false" class="dash-row">
                @if (! $outliers['enough_months'])
                    <x-ui.empty-state icon="calendar-days" title="No complete month in this range" description="Widen the months to include a finished month." />
                @else
                    <p class="ui-hint" style="padding:8px 14px">
                        {{ $outliers['eligible'] }} readers judged, {{ $outliers['excluded'] }} left out for too few visits.
                        Average skip rate {{ $pct($outliers['mean']) }}, spread {{ $pct($outliers['stdev']) }}.
                    </p>
                    <x-ui.table label="Readers by skip-rate z-score" :sticky="false">
                        <x-slot:head><tr><th>Reader</th><th class="num">Visited</th><th class="num">Skip rate</th><th class="num">z-score</th><th>Flag</th></tr></x-slot:head>
                        @forelse ($outliers['rows'] as $row)
                            <tr wire:key="out-{{ $row['staff_id'] }}">
                                <td>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</td>
                                <td class="num">{{ number_format($row['visited']) }}</td>
                                <td class="num">{{ $pct($row['skip_rate']) }}</td>
                                <td class="num">{{ $row['z'] === null ? '–' : number_format($row['z'], 2) }}</td>
                                <td>@if ($row['flagged'])<x-ui.badge tone="danger">Outlier</x-ui.badge>@endif</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="5" icon="users" title="No reader has enough visits to be judged." />
                        @endforelse
                    </x-ui.table>
                @endif
            </x-ui.card>
        @elseif ($activeTab === 'scorecard')
            <x-ui.card title="Reader scorecard" description="An indicative 0-100 score from percentile ranks over the complete months. It is a first guess to start conversations, not a verdict." :padded="false" class="dash-row">
                @if (! $scorecard['enough_months'])
                    <x-ui.empty-state icon="calendar-days" title="No complete month in this range" description="Widen the months to include a finished month." />
                @else
                    <p class="ui-hint" style="padding:8px 14px">
                        Indicative weights: volume {{ number_format($scorecard['weights']['volume'] * 100) }}%,
                        skip rate (lower is better) {{ number_format($scorecard['weights']['skip'] * 100) }}%,
                        consistency (steadier is better) {{ number_format($scorecard['weights']['consistency'] * 100) }}%.
                        Where a reader has too few months for consistency the other weights are scaled up.
                    </p>
                    <x-ui.table label="Reader scorecard" :sticky="false">
                        <x-slot:head><tr><th class="num">#</th><th>Reader</th><th class="num">Visited</th><th class="num">Skip rate</th><th class="num">Variation</th><th class="num">Volume</th><th class="num">Skip</th><th class="num">Consistency</th><th class="num">Score</th></tr></x-slot:head>
                        @forelse ($scorecard['rows'] as $i => $row)
                            <tr wire:key="score-{{ $row['staff_id'] }}">
                                <td class="num">{{ $i + 1 }}</td>
                                <td>@include('livewire.commercial.partials.reader-cell', ['row' => $row, 'from' => $from, 'to' => $to])</td>
                                <td class="num">{{ number_format($row['visited']) }}</td>
                                <td class="num">{{ $pct($row['skip_rate']) }}</td>
                                <td class="num">{{ $pct($row['cv']) }}</td>
                                <td class="num">{{ $row['volume_pct'] === null ? '–' : number_format($row['volume_pct'], 0) }}</td>
                                <td class="num">{{ $row['skip_pct'] === null ? '–' : number_format($row['skip_pct'], 0) }}</td>
                                <td class="num">{{ $row['consistency_pct'] === null ? '–' : number_format($row['consistency_pct'], 0) }}</td>
                                <td class="num"><strong>{{ $row['score'] === null ? '–' : number_format($row['score'], 0) }}</strong></td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="9" icon="users" title="No reader has visits in these months." />
                        @endforelse
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endif
    @endif
</div>
