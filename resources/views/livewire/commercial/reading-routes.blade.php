<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php
        $pct = fn ($value, $digits = 1) => $value === null ? '–' : number_format($value, $digits).'%';
        $num = fn ($value) => $value === null ? '–' : number_format($value);
        $a = $r['analysis'] ?? null;
        $named = $r['named'];
        $min = $r['min_readers'];
        $screenCap = 300;
        $who = fn ($staffId, $name) => trim($staffId.' '.($name ?? ''));
    @endphp

    <x-ui.page-header title="Meter reading by route and CCA" description="From the Customer Meter Reading Report by route. Each route's verified strength is counted once, for the CCA who owns the route. Rates are worked out from the counts, never taken from the report.">
        <x-slot:actions>
            @if ($r['available'] && $canExport)
                <a href="{{ route('commercial.export', ['report' => 'reading-routes', 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.file-icon type="excel" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'reading-routes', 'format' => 'pdf', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.file-icon type="pdf" /> PDF</a>
            @endif
            <a href="{{ route('commercial.reading') }}" class="btn btn-ghost">By month</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $r['available'])
        <x-ui.card>
            <x-ui.empty-state icon="file-spreadsheet" title="No reading report by route has been loaded yet." description="Upload the Customer Meter Reading Report - CCA Summary - By Route (rptReadingSumm) and the analysis appears here.">
                @if ($canUpload)
                    <a href="{{ route('commercial.batches') }}" class="btn btn-primary">Go to uploads</a>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="ui-toolbar dash-row" role="search" aria-label="Reading by route filters">
            <x-ui.segmented label="Section" wire:model.live="tab" :options="$tabs" :value="$activeTab" />
            @if (count($r['regions']) > 1)
                <select wire:model.live="region" class="form-input" aria-label="Region">
                    @foreach ($r['regions'] as $id => $name)
                        <option value="{{ $id }}" @selected($id === $r['region_id'])>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="preset" class="form-input" aria-label="A whole month">
                <option value="">A whole month…</option>
                @foreach ($r['month_options'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <input type="text" wire:model.live.debounce.500ms="dateFrom" class="form-input" style="max-width:11rem" placeholder="From date 2026-07-15" aria-label="From date (YYYY-MM-DD)" autocomplete="off">
            <input type="text" wire:model.live.debounce.500ms="dateTo" class="form-input" style="max-width:11rem" placeholder="To date 2026-08-31" aria-label="To date (YYYY-MM-DD)" autocomplete="off">
            @if ($dateFrom !== '' || $dateTo !== '')<button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear dates</button>@endif
            @if (count($r['districts']) > 1 || $r['district_id'])
                <select wire:model.live="district" class="form-input" aria-label="District">
                    <option value="">All districts</option>
                    @foreach ($r['districts'] as $id => $name)
                        <option value="{{ $id }}" @selected($id === $r['district_id'])>{{ $name }}</option>
                    @endforeach
                </select>
                @if ($r['district_id'])<button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear district</button>@endif
            @endif
        </div>

        <div class="ui-hint dash-row" data-range-notes>
            <strong>{{ $r['region'] }} · {{ $r['range']['label'] }}</strong>
            @if ($r['range']['range_days'])
                ({{ number_format($r['range']['covered_days']) }} of {{ number_format($r['range']['range_days']) }} days covered{{ $r['range']['gaps'] !== [] ? ', not covered: '.\App\Services\Commercial\CommercialPeriods::describeIntervals($r['range']['gaps']) : '' }})
            @endif
            <ul style="padding-left:18px;list-style:disc">@foreach ($r['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>
        </div>

        @if ($r['small_group'])
            <x-ui.card>
                <x-ui.empty-state icon="shield-check" title="These figures are held back." :description="'Your district has fewer than '.$min.' CCAs with visits, so its figures would be an individual CCA\'s own. They are shown only with the CCA performance permission.'" />
            </x-ui.card>
        @elseif (! $a || $a['rows'] === 0)
            <x-ui.card>
                <x-ui.empty-state icon="file-spreadsheet" title="No upload lies inside this range." description="Pick other dates, or clear them to see the latest upload." />
            </x-ui.card>
        @else
            @if (! $named)
                <p class="ui-hint dash-row">No CCA is named on this page: individual CCA figures need the CCA performance permission. Districts, routes and counts are shown.</p>
            @endif
            @if ($r['held_back'] !== [])
                <p class="ui-hint dash-row">Figures are held back for {{ implode(', ', $r['held_back']) }}: fewer than {{ $min }} CCAs had visits there.</p>
            @endif

            {{-- ============================================================ OVERVIEW --}}
            @if ($activeTab === 'overview')
                <x-commercial.trend-block :block="$trendBlock" kind="reading" :query="array_filter(['region' => $r['region_id'], 'district' => $r['district_id'], 'from' => $dateFrom, 'to' => $dateTo])" />
                @php $t = $a['totals']; $c = $r['counts']; @endphp
                <div class="ui-stat-grid dash-row">
                    <x-ui.stat-tile label="Routes" :value="number_format($c['routes'])" icon="map" :meta="number_format($c['ccas']).' CCAs listed'" />
                    <x-ui.stat-tile label="Verified strength" :value="number_format($t['strength'])" icon="users" tone="info" meta="each route counted once" />
                    <x-ui.stat-tile label="Visited" :value="number_format($t['visited'])" icon="activity" tone="success" :meta="number_format($t['read']).' read · '.number_format($t['skipped']).' skipped'" />
                    <x-ui.stat-tile label="Coverage" :value="$pct($t['coverage'])" icon="trending-up" tone="primary" meta="visited ÷ strength, not capped" />
                    <x-ui.stat-tile label="Skip rate" :value="$pct($t['skip_rate'])" icon="gauge" :meta="'skipped ÷ visited'" />
                    <x-ui.stat-tile label="Unvisited" :value="number_format($t['unvisited'])" icon="triangle-alert" :tone="$t['unvisited'] > 0 ? 'warning' : 'success'" meta="strength − visited" />
                </div>

                @if (count($r['trend']) > 1)
                    <x-ui.card title="Skip rate and coverage by upload" description="One point per upload, labelled with the dates it covers." class="dash-row">
                        <div wire:key="rr-trend-{{ md5(json_encode(array_column($r['trend'], 'visited'))) }}">
                            <x-ui.chart type="line" label="Skip rate and coverage by upload" unit="%"
                                :labels="array_column($r['trend'], 'label')"
                                :series="[['label' => 'Skip rate', 'data' => array_column($r['trend'], 'skip_rate')], ['label' => 'Coverage', 'data' => array_column($r['trend'], 'coverage')]]" height="260" />
                        </div>
                    </x-ui.card>
                @endif

                <x-ui.card title="Districts" description="A district is the sum of its routes (R3)." :padded="false" class="dash-row">
                    <x-ui.table label="Districts" :sticky="false">
                        <x-slot:head>
                            <tr><th>District</th><th class="num">Routes</th><th class="num">CCAs with visits</th><th class="num">Verified strength</th><th class="num">Read</th><th class="num">Skipped</th><th class="num">Visited</th><th class="num">Unvisited</th><th class="num">Coverage</th><th class="num">Skip rate</th></tr>
                        </x-slot:head>
                        @forelse ($a['districts'] as $d)
                            <tr wire:key="rr-d-{{ $d['id'] }}">
                                <td>{{ $d['name'] }}@if ($d['held_back'] ?? false) <x-ui.badge tone="neutral">Held back: fewer than {{ $min }} CCAs</x-ui.badge>@endif</td>
                                <td class="num">{{ $d['routes'] }}</td>
                                <td class="num">{{ $d['active_ccas'] }}</td>
                                <td class="num">{{ $num($d['strength']) }}</td>
                                <td class="num">{{ $num($d['read']) }}</td>
                                <td class="num">{{ $num($d['skipped']) }}</td>
                                <td class="num">{{ $num($d['visited']) }}</td>
                                <td class="num">{{ $num($d['unvisited']) }}</td>
                                <td class="num">{{ $pct($d['coverage']) }}</td>
                                <td class="num">{{ $pct($d['skip_rate']) }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="10" icon="map" title="No route has a district yet." description="Rows whose district could not be matched are listed on the upload; resolve them there." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>

                @if ($a['coverage']['unplaced_rows'] > 0)
                    <p class="ui-hint dash-row">{{ number_format($a['coverage']['unplaced_rows']) }} route rows have a district the system could not match yet. They are counted in the totals but in no district's figures; resolve them on the upload's page.</p>
                @endif

                @if (count($r['trend']) > 0)
                    <x-ui.card title="By upload" description="Each upload on its own." :padded="false" class="dash-row">
                        <x-ui.table label="Trend by upload" :sticky="false">
                            <x-slot:head>
                                <tr><th>Period</th><th class="num">Routes</th><th class="num">CCAs with visits</th><th class="num">Verified strength</th><th class="num">Visited</th><th class="num">Unvisited</th><th class="num">Coverage</th><th class="num">Skip rate</th></tr>
                            </x-slot:head>
                            @foreach ($r['trend'] as $p)
                                <tr wire:key="rr-p-{{ $p['batch_id'] }}">
                                    <td>{{ $p['label'] }} <span class="muted">(batch #{{ $p['batch_id'] }})</span></td>
                                    <td class="num">{{ $p['routes'] }}</td>
                                    <td class="num">{{ $p['ccas_active'] }}</td>
                                    <td class="num">{{ $num($p['strength']) }}</td>
                                    <td class="num">{{ $num($p['visited']) }}</td>
                                    <td class="num">{{ $num($p['unvisited']) }}</td>
                                    <td class="num">{{ $pct($p['coverage']) }}</td>
                                    <td class="num">{{ $pct($p['skip_rate']) }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @endif

            {{-- ============================================================ CCAs: R1, R4, R7 --}}
            @elseif ($activeTab === 'ccas')
                @php $w = $a['workload']; $o = $a['outliers']; $c = $r['counts']; @endphp

                @if ($named)
                    <x-ui.card title="CCA scorecard" :description="'Ranked on skip rate, lowest first, among CCAs with at least '.number_format($a['min_visits']).' visits (R1). Routes owned are the routes where the CCA has the most visits.'" :padded="false" class="dash-row">
                        <x-ui.table label="CCA scorecard" :sticky="true">
                            <x-slot:head>
                                <tr><th class="num">Rank</th><th>CCA</th><th>District</th><th class="num">Routes owned</th><th class="num">Strength owned</th><th class="num">Read</th><th class="num">Skipped</th><th class="num">Visited</th><th class="num">Unvisited</th><th class="num">Coverage</th><th class="num">Skip rate</th></tr>
                            </x-slot:head>
                            @forelse (array_slice($a['ccas'], 0, $screenCap) as $cca)
                                <tr wire:key="rr-c-{{ $cca['staff_id'] }}">
                                    <td class="num">{{ $cca['rank'] ?? '–' }}</td>
                                    <td>{{ $who($cca['staff_id'], $cca['name']) }}
                                        @if ($cca['no_routes'])<x-ui.badge tone="warning">No route</x-ui.badge>
                                        @elseif ($cca['zero_visits'])<x-ui.badge tone="warning">No visits</x-ui.badge>
                                        @elseif (! $cca['eligible'])<x-ui.badge tone="neutral">Under {{ number_format($a['min_visits']) }} visits</x-ui.badge>@endif
                                    </td>
                                    <td>{{ $cca['district'] ?? '–' }}</td>
                                    <td class="num">{{ $cca['owned_routes'] }}</td>
                                    <td class="num">{{ $num($cca['owned_strength']) }}</td>
                                    <td class="num">{{ $num($cca['read']) }}</td>
                                    <td class="num">{{ $num($cca['skipped']) }}</td>
                                    <td class="num">{{ $num($cca['visited']) }}</td>
                                    <td class="num">{{ $num($cca['unvisited']) }}</td>
                                    <td class="num">{{ $pct($cca['coverage']) }}</td>
                                    <td class="num">{{ $pct($cca['skip_rate']) }}</td>
                                </tr>
                            @empty
                                <x-ui.empty-row :colspan="11" icon="users" title="No CCA in this range." />
                            @endforelse
                        </x-ui.table>
                    </x-ui.card>
                    @if (count($a['ccas']) > $screenCap)<p class="ui-hint dash-row">The first {{ $screenCap }} of {{ number_format(count($a['ccas'])) }} CCAs are shown. The Excel file has every one.</p>@endif
                @else
                    <x-ui.card title="CCAs" description="Counts only: individual CCA figures need the CCA performance permission." class="dash-row">
                        <ul style="padding-left:18px;list-style:disc">
                            <li>{{ number_format($c['ccas']) }} CCAs are listed, {{ number_format($c['ccas_with_routes']) }} of them with routes.</li>
                            <li>{{ number_format($c['ccas_zero_visits']) }} have routes but no visits; {{ number_format($c['ccas_no_routes']) }} are listed with no route.</li>
                            <li>{{ number_format($c['outliers']) }} skip more than the others in their district.</li>
                        </ul>
                    </x-ui.card>
                @endif

                <div class="ui-stat-grid dash-row">
                    <x-ui.stat-tile label="CCAs owning routes" :value="number_format($w['ccas'])" icon="users" :meta="number_format($w['routes']).' routes'" />
                    <x-ui.stat-tile label="Median strength owned" :value="$w['median'] === null ? '–' : number_format($w['median'])" icon="gauge" :meta="'largest '.($w['largest'] === null ? '–' : number_format($w['largest']))" />
                    <x-ui.stat-tile label="Spread (coefficient of variation)" :value="$pct($w['cv'])" icon="activity" meta="standard deviation ÷ mean of strength owned" />
                    <x-ui.stat-tile label="Far from the median" :value="number_format($c['workload_high'] + $c['workload_low'])" icon="triangle-alert" :meta="number_format($c['workload_high']).' above '.$w['high_pct'].'% · '.number_format($c['workload_low']).' below '.$w['low_pct'].'%'" />
                </div>

                @if ($named && ($w['high'] !== [] || $w['low'] !== []))
                    <x-ui.card title="Workload far from the median" description="Strength owned against the median CCA (R4)." :padded="false" class="dash-row">
                        <x-ui.table label="Workload outliers" :sticky="false">
                            <x-slot:head><tr><th>CCA</th><th class="num">Routes owned</th><th class="num">Strength owned</th><th class="num">% of the median</th><th>Band</th></tr></x-slot:head>
                            @foreach (array_merge($w['high'], $w['low']) as $row)
                                <tr wire:key="rr-w-{{ $row['staff_id'] }}"><td>{{ $who($row['staff_id'], $row['name']) }}</td><td class="num">{{ $row['owned_routes'] }}</td><td class="num">{{ number_format($row['owned_strength']) }}</td><td class="num">{{ $pct($row['pct_of_median']) }}</td><td>{{ $row['band'] === 'high' ? 'Above' : 'Below' }}</td></tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @endif

                <x-ui.card :title="'Skip-rate outliers against district peers'" :description="'A CCA more than '.$o['sigma'].' standard deviations above the mean skip rate of the CCAs of the same district with at least '.number_format($o['min_visits']).' visits. A district needs at least '.$o['min_peers'].' of them (R7).'" :padded="false" class="dash-row">
                    <x-ui.table label="Outliers by district" :sticky="false">
                        <x-slot:head><tr><th>District</th><th class="num">CCAs with enough visits</th><th class="num">Mean skip rate</th><th class="num">Standard deviation</th><th class="num">Outlier above</th><th class="num">Outliers</th></tr></x-slot:head>
                        @forelse ($o['districts'] as $row)
                            <tr wire:key="rr-o-{{ $row['id'] }}">
                                <td>{{ $row['name'] }}</td>
                                <td class="num">{{ $row['eligible'] }}</td>
                                @if ($row['enough'])
                                    <td class="num">{{ $pct($row['mean']) }}</td><td class="num">{{ number_format($row['stdev'], 2) }}</td><td class="num">{{ $pct($row['threshold']) }}</td><td class="num">{{ $row['flagged'] }}</td>
                                @else
                                    <td colspan="4" class="muted">Too few CCAs to judge (fewer than {{ $o['min_peers'] }}).</td>
                                @endif
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="6" icon="users" title="No district has a CCA with enough visits." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>

                @if ($named && $o['flagged'] !== [])
                    <x-ui.card title="CCAs above their district" :padded="false" class="dash-row">
                        <x-ui.table label="Skip-rate outliers" :sticky="false">
                            <x-slot:head><tr><th>CCA</th><th>District</th><th class="num">Visited</th><th class="num">Skip rate</th><th class="num">District mean</th><th class="num">Standard deviations above</th></tr></x-slot:head>
                            @foreach ($o['flagged'] as $row)
                                <tr wire:key="rr-of-{{ $row['staff_id'] }}"><td>{{ $who($row['staff_id'], $row['name']) }}</td><td>{{ $row['district'] }}</td><td class="num">{{ number_format($row['visited']) }}</td><td class="num">{{ $pct($row['skip_rate']) }}</td><td class="num">{{ $pct($row['district_mean']) }}</td><td class="num">{{ number_format($row['z'], 2) }}</td></tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @endif

            {{-- ============================================================ ROUTES: R2, R5 --}}
            @elseif ($activeTab === 'routes')
                @php
                    $placed = collect($a['routes'])->filter(fn ($route) => $route['placed'])->sortBy([['skip_rate', 'desc'], ['visited', 'desc'], ['label', 'asc']])->values();
                    $b = $a['backlog'];
                @endphp
                <x-ui.card title="Route league table" description="Every route in the range, highest skip rate first (R2). Verified strength is the owner's, counted once." :padded="false" class="dash-row">
                    <x-ui.table label="Routes" :sticky="true">
                        <x-slot:head>
                            <tr><th>Route</th><th>District</th>@if ($named)<th>Owner</th>@endif<th class="num">Verified strength</th><th class="num">Read</th><th class="num">Skipped</th><th class="num">Visited</th><th class="num">Unvisited</th><th class="num">Coverage</th><th class="num">Skip rate</th></tr>
                        </x-slot:head>
                        @forelse ($placed->take($screenCap) as $route)
                            <tr wire:key="rr-r-{{ $route['key'] }}">
                                <td class="mono">@if (! empty($route['route_id']))<a href="{{ route('commercial.cross.route', $route['route_id']) }}">{{ $route['label'] }}</a>@else{{ $route['label'] }}@endif</td>
                                <td>{{ $route['district'] }}</td>
                                @if ($named)<td>{{ $who($route['owner_cca'], $route['owner_name']) }}</td>@endif
                                <td class="num">{{ number_format($route['strength']) }}</td>
                                <td class="num">{{ number_format($route['read']) }}</td>
                                <td class="num">{{ number_format($route['skipped']) }}</td>
                                <td class="num">{{ number_format($route['visited']) }}</td>
                                <td class="num">{{ number_format($route['unvisited']) }}</td>
                                <td class="num">{{ $pct($route['coverage']) }}</td>
                                <td class="num">{{ $pct($route['skip_rate']) }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="$named ? 10 : 9" icon="map" title="No route with a district in this range." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
                @if ($placed->count() > $screenCap)<p class="ui-hint dash-row">The first {{ $screenCap }} of {{ number_format($placed->count()) }} routes are shown. The Excel file has every one.</p>@endif

                <div class="ui-grid ui-grid-2 dash-row">
                    <x-ui.card :title="'Routes with the most unvisited customers ('.number_format($b['unvisited_total']).' in all)'" description="Verified strength minus visited, only where it is above zero (R5)." :padded="false">
                        <x-ui.table label="Unvisited backlog by route" :sticky="false">
                            <x-slot:head><tr><th>Route</th><th>District</th><th class="num">Unvisited</th><th class="num">Coverage</th></tr></x-slot:head>
                            @forelse (array_slice($b['routes'], 0, 25) as $route)
                                <tr wire:key="rr-b-{{ $route['key'] }}"><td class="mono">@if (! empty($route['route_id']))<a href="{{ route('commercial.cross.route', $route['route_id']) }}">{{ $route['label'] }}</a>@else{{ $route['label'] }}@endif</td><td>{{ $route['district'] }}</td><td class="num">{{ number_format($route['unvisited']) }}</td><td class="num">{{ $pct($route['coverage']) }}</td></tr>
                            @empty
                                <x-ui.empty-row :colspan="4" icon="check" title="No route has customers left unvisited." />
                            @endforelse
                        </x-ui.table>
                    </x-ui.card>

                    <x-ui.card title="Routes and CCAs with no visits" description="Routes with strength and no visit at all, and the CCAs who own routes but made no visit (R5).">
                        @if ($b['routes_zero_visits'] === [] && $b['ccas_zero_visits'] === [] && $r['counts']['ccas_zero_visits'] === 0)
                            <p class="muted">None.</p>
                        @else
                            <p><strong>{{ number_format(count($b['routes_zero_visits'])) }}</strong> routes have no visit.</p>
                            @if ($b['routes_zero_visits'] !== [])
                                <p class="mono">{{ collect($b['routes_zero_visits'])->take(30)->pluck('label')->implode(', ') }}@if (count($b['routes_zero_visits']) > 30) … @endif</p>
                            @endif
                            <p><strong>{{ number_format($r['counts']['ccas_zero_visits']) }}</strong> CCAs have routes but no visits @if ($named && $b['ccas_zero_visits'] !== []): {{ collect($b['ccas_zero_visits'])->map(fn ($cca) => $who($cca['staff_id'], $cca['name']))->take(30)->implode(', ') }}@endif.</p>
                        @endif
                    </x-ui.card>
                </div>

            {{-- ============================================================ CHECKS: R6, R8 --}}
            @else
                @php $cov = $a['coverage']; $u = $r['unread_routes']; $s = $r['strength']; $c = $r['counts']; @endphp
                <x-ui.card title="Routes with more than one CCA" description="The owner (most visits) carries the verified strength; the others are kept as assigned, no visits (R6)." :padded="false" class="dash-row">
                    <x-ui.table label="Routes with several CCAs" :sticky="false">
                        <x-slot:head><tr><th>Route</th><th>District</th><th>On the route</th><th class="num">Verified strength (once)</th><th class="num">Visited</th></tr></x-slot:head>
                        @forelse (array_slice($cov['multi_cca_routes'], 0, 100) as $route)
                            <tr wire:key="rr-m-{{ $route['key'] }}">
                                <td class="mono">@if (! empty($route['route_id']))<a href="{{ route('commercial.cross.route', $route['route_id']) }}">{{ $route['label'] }}</a>@else{{ $route['label'] }}@endif</td>
                                <td>{{ $route['district'] }}</td>
                                <td>@if ($named)@foreach ($route['multi_rows'] as $row){{ $who($row['cca'], $row['name']) }} ({{ number_format($row['visited']) }} visits){{ $row['owner'] ? ', owner' : '' }}@if (! $loop->last); @endif @endforeach @else {{ count($route['ccas']) }} CCAs @endif</td>
                                <td class="num">{{ number_format($route['strength']) }}</td>
                                <td class="num">{{ number_format($route['visited']) }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="5" icon="check" title="No route is listed under more than one CCA in one upload." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>

                <div class="ui-grid ui-grid-2 dash-row">
                    <x-ui.card title="CCAs listed with no route" description="Kept on record: a CCA the report lists with no route (R6).">
                        @if ($named)
                            @if ($cov['ccas_no_routes'] === [])<p class="muted">None.</p>@else<p>{{ collect($cov['ccas_no_routes'])->map(fn ($cca) => $who($cca['staff_id'], $cca['name']))->implode(', ') }}</p>@endif
                        @else
                            <p>{{ number_format($c['ccas_no_routes']) }} CCAs.</p>
                        @endif
                    </x-ui.card>

                    <x-ui.card title="Routes with no reading row" description="Known from billing, the customer list or a manager, with no reading row in this range (R6).">
                        @if ($u && $u['total'] > 0)
                            <p><strong>{{ number_format($u['total']) }}</strong> routes @if ($u['capped']) (the first {{ number_format(count($u['shown'])) }} are listed in the file)@endif.</p>
                            <p class="mono">{{ collect($u['shown'])->take(40)->map(fn ($route) => $route['label'].($route['district'] ? ' ('.$route['district'].')' : ''))->implode(', ') }}@if ($u['total'] > 40) … @endif</p>
                        @else
                            <p class="muted">None.</p>
                        @endif
                    </x-ui.card>
                </div>

                <x-ui.card title="Verified strength against the customer list" description="Each route's verified strength against its accounts with a working or faulty meter in the newest customer list (R8)." :padded="false" class="dash-row">
                    @if (! ($s['available'] ?? false))
                        <div style="padding:16px"><p class="muted">{{ $s['reason'] ?? 'Not available.' }}</p></div>
                    @else
                        <div style="padding:16px">
                            <p>{{ number_format($s['compared']) }} routes compared (customer list of {{ $s['as_of'] ?? 'no date' }}). Median difference <strong>{{ $pct($s['median_abs_pct']) }}</strong> in either direction, <strong>{{ $s['median_signed_pct'] === null ? '–' : (($s['median_signed_pct'] > 0 ? '+' : '').number_format($s['median_signed_pct'], 1).'%') }}</strong> signed. {{ number_format(count($s['beyond'])) }} routes differ by more than {{ $s['threshold'] }}%; {{ number_format(count($s['ambiguous'])) }} have an ambiguous code (the same code under more than one district) and are left out; {{ number_format($s['no_match']) }} have no metered accounts on the list.</p>
                        </div>
                        <x-ui.table label="Routes beyond the threshold" :sticky="false">
                            <x-slot:head><tr><th>Route</th><th>District</th><th class="num">Verified strength</th><th class="num">Metered accounts</th><th class="num">Difference</th><th class="num">Difference %</th></tr></x-slot:head>
                            @forelse (array_slice($s['beyond'], 0, 100) as $route)
                                <tr wire:key="rr-s-{{ $route['route_id'] }}"><td class="mono">@if (! empty($route['route_id']))<a href="{{ route('commercial.cross.route', $route['route_id']) }}">{{ $route['label'] }}</a>@else{{ $route['label'] }}@endif</td><td>{{ $route['district'] }}</td><td class="num">{{ number_format($route['strength']) }}</td><td class="num">{{ number_format($route['metered']) }}</td><td class="num">{{ ($route['difference'] > 0 ? '+' : '').number_format($route['difference']) }}</td><td class="num">{{ ($route['relative_pct'] > 0 ? '+' : '').number_format($route['relative_pct'], 1) }}%</td></tr>
                            @empty
                                <x-ui.empty-row :colspan="6" icon="check" title="No route is beyond the threshold." />
                            @endforelse
                        </x-ui.table>
                    @endif
                </x-ui.card>
            @endif
        @endif
    @endif
</div>
