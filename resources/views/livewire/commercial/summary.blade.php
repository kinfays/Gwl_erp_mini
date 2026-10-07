<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php
        $s = $summary;
        $pct = fn ($value, $digits = 1) => $value === null ? '–' : number_format($value, $digits).'%';
        $money = fn ($value) => $value === null ? '–' : number_format($value, 2);
        $exportQuery = array_filter(['snapshot' => $snapshot !== '' ? $snapshot : null]);
        $met = fn ($value) => $value === null ? null : ($value ? 'good' : 'bad');
    @endphp

    <x-ui.page-header title="Summary" :description="'The headline numbers, exceptions and data freshness for '.$region_label.'. No individual readers appear on this page.'">
        <x-slot:actions>
            @if ($canExport)
                <a href="{{ route('commercial.export', ['report' => 'summary', 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.icon name="file-spreadsheet" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'summary', 'format' => 'pdf', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.icon name="file-text" /> PDF</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($likeForLikeNote)
        <x-ui.alert tone="warning" class="dash-row">{{ $likeForLikeNote }}</x-ui.alert>
    @endif

    {{-- Reading headline --}}
    @if ($can_reading)
        <div class="dash-row">
            <h3 style="margin-bottom:8px">Meter reading @if (! empty($s['reading']['month'])) <span class="ui-hint">{{ $s['reading']['month'] }}, latest complete month</span>@endif</h3>
            @if (empty($s['reading']['month']))
                <x-ui.card><x-ui.empty-state icon="chart-column" title="No complete month of meter reading yet." description="The current month is still in progress, so it is not summarised." /></x-ui.card>
            @else
                @php $r = $s['reading']; @endphp
                <div class="ui-stat-grid">
                    <x-ui.stat-tile label="Visited" :value="number_format($r['visited'])" icon="users" />
                    <x-ui.stat-tile label="Skip rate" :value="$pct($r['skip_rate'])" icon="triangle-alert" tone="warning" :delta="$r['skip_met'] === null ? null : ($r['skip_met'] ? 'Within target' : 'Above target')" :delta-tone="$met($r['skip_met']) ?? 'neutral'" :meta="'Configured target: at most '.number_format($r['target_skip'], 0).'%'" />
                    <x-ui.stat-tile label="Coverage" :value="$pct($r['coverage'])" icon="map" :delta="$r['coverage_met'] === null ? null : ($r['coverage_met'] ? 'Within target' : 'Below target')" :delta-tone="$met($r['coverage_met']) ?? 'neutral'" :meta="'Configured target: at least '.number_format($r['target_coverage'], 0).'%'" />
                </div>
            @endif
        </div>
    @endif

    {{-- Billing headline --}}
    @if ($can_billing)
        <div class="dash-row">
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                <h3>Billing</h3>
                @if (count($snapshots) > 1)
                    <select wire:model.live="snapshot" class="form-input" aria-label="Billing snapshot" style="min-width:18rem">
                        @foreach ($snapshots as $option)
                            <option value="{{ $option['id'] }}" @selected($chosen && $option['id'] === $chosen['id'])>{{ $option['label'] }} (batch #{{ $option['id'] }})</option>
                        @endforeach
                    </select>
                @elseif ($chosen)
                    <span class="ui-hint">{{ $chosen['label'] }}</span>
                @endif
            </div>

            @if (! $s['billing'])
                <x-ui.card><x-ui.empty-state icon="file-spreadsheet" title="No billing report has been loaded yet." /></x-ui.card>
            @else
                @php $b = $s['billing']; @endphp
                <div class="ui-stat-grid">
                    <x-ui.stat-tile label="Billing" :value="'GH¢ '.$money($b['billing'])" icon="receipt" />
                    <x-ui.stat-tile label="Payments" :value="'GH¢ '.$money($b['payments'])" icon="banknote" tone="success" />
                    <x-ui.stat-tile label="Cash collection ratio" :value="$pct($b['cash_ratio'])" icon="trending-up" :delta="$b['collection_met'] === null ? null : ($b['collection_met'] ? 'Within target' : 'Below target')" :delta-tone="$met($b['collection_met']) ?? 'neutral'" :meta="'Configured target: at least '.number_format($b['target_collection'], 0).'%'" />
                    <x-ui.stat-tile label="Estimated share of volume" :value="$pct($b['estimation'])" icon="gauge" tone="warning" />
                    <x-ui.stat-tile label="Unbilled rate" :value="$pct($b['unbilled_rate'])" icon="triangle-alert" tone="danger" />
                </div>
                <p class="ui-hint" style="margin-top:6px">Snapshot period: {{ $b['snapshot']['period_label'] }} ({{ $b['snapshot']['segment_label'] }}). Targets are placeholders until the Commercial team confirms them.</p>
            @endif
        </div>

        @if ($s['exceptions'])
            @php $e = $s['exceptions']; @endphp
            <x-ui.card title="Route exceptions" :description="$e['flagged'].' of '.$e['routes'].' routes flagged on placeholder thresholds'" class="dash-row">
                @if ($canSeeBillingPage)
                    <x-slot:actions><a href="{{ route('commercial.billing', array_filter(['snapshot' => $chosen['id'] ?? null, 'tab' => 'exceptions'])) }}" class="btn btn-ghost btn-sm">See the routes</a></x-slot:actions>
                @endif
                <div class="ui-stat-grid">
                    <x-ui.stat-tile label="No activity" :value="$e['counts']['zero_activity']" icon="triangle-alert" />
                    <x-ui.stat-tile label="Heavy credit" :value="$e['counts']['heavy_credit']" icon="triangle-alert" tone="warning" />
                    <x-ui.stat-tile label="High unbilled" :value="$e['counts']['high_unbilled']" icon="triangle-alert" tone="warning" />
                    <x-ui.stat-tile label="High estimation" :value="$e['counts']['high_estimation']" icon="triangle-alert" tone="warning" />
                </div>
            </x-ui.card>
        @endif
    @endif

    {{-- Freshness and quality: available to anyone who can open the page --}}
    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="Data freshness" description="Times are upload times: the reports print no run date.">
            @php $f = $s['freshness']; @endphp
            @foreach ($f['overdue'] as $late)
                <x-ui.alert tone="warning" wire:key="late-{{ $late['region_id'] }}-{{ $late['report_type'] }}">
                    {{ ucfirst($late['label']) }} upload overdue for {{ $late['region'] }}: {{ $late['days'] }} days since the last one (reminder limit {{ $late['limit'] }} days).
                </x-ui.alert>
            @endforeach
            <dl class="ui-dl">
                @foreach (['reading' => 'Latest meter reading upload', 'billing' => 'Latest billing upload'] as $key => $label)
                    <dt>{{ $label }}</dt>
                    <dd>
                        @if ($f[$key])
                            {{ $f[$key]['region'] }}, {{ $f[$key]['period'] }}, uploaded {{ \Illuminate\Support\Carbon::parse($f[$key]['uploaded_at'])->format('d M Y H:i') }}
                        @else
                            <span class="cell-muted">None yet</span>
                        @endif
                    </dd>
                @endforeach
                <dt>Reading months loaded</dt>
                <dd>{{ implode(', ', $f['reading_months']) ?: 'None' }}</dd>
                @if ($f['reading_months_missing'])
                    <dt>Reading months missing</dt>
                    <dd><x-ui.badge tone="warning">{{ implode(', ', $f['reading_months_missing']) }}</x-ui.badge></dd>
                @endif
                @if ($f['billing_periods'] !== null)
                    <dt>Billing periods loaded</dt>
                    <dd>{{ implode('; ', $f['billing_periods']) ?: 'None' }}</dd>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Data quality" description="Things to fix before relying on the numbers.">
            @php $q = $s['quality']; @endphp
            <dl class="ui-dl">
                @if ($q['unmatched_readers'] !== null)
                    <dt>Readers not in the staff directory</dt>
                    <dd><x-ui.badge :tone="$q['unmatched_readers'] > 0 ? 'warning' : 'success'">{{ $q['unmatched_readers'] }}</x-ui.badge></dd>
                @endif
                @if ($q['unresolved_districts'] !== null)
                    <dt>Billing districts not matched</dt>
                    <dd><x-ui.badge :tone="$q['unresolved_districts'] > 0 ? 'warning' : 'success'">{{ $q['unresolved_districts'] }}</x-ui.badge></dd>
                @endif
                @if ($q['multi_month_snapshots'] !== null)
                    <dt>Multi-month billing snapshots (not comparable)</dt>
                    <dd><x-ui.badge>{{ $q['multi_month_snapshots'] }}</x-ui.badge></dd>
                @endif
            </dl>
            @if ($canUpload)
                <a href="{{ route('commercial.batches') }}" class="btn btn-ghost btn-sm">Open uploads to resolve</a>
            @endif
        </x-ui.card>
    </div>

    {{-- C1 and C2: billing beside reading --}}
    @if ($can_combine && $scorecard)
        <x-ui.card title="District scorecard" description="Billing from the snapshot above; reading is the complete months inside its period, grouped by the reader's home district." :padded="false" class="dash-row">
            @if ($canExportCombined)
                <x-slot:actions>
                    <a href="{{ route('commercial.export', ['report' => 'scorecard', 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-ghost btn-sm">Excel</a>
                    <a href="{{ route('commercial.export', ['report' => 'scorecard', 'format' => 'pdf', ...$exportQuery]) }}" class="btn btn-ghost btn-sm">PDF</a>
                </x-slot:actions>
            @endif

            <p class="ui-hint" style="padding:8px 14px">
                An approximation: it assumes a reader works in the district they are posted to, as the reading report has no district split.
                @if ($scorecard['reading_available'])
                    Reading months: {{ implode(', ', $scorecard['reading_months']) }}. Coverage is not shown per district (the verified strength is region-wide).
                    @if ($scorecard['hide_small_groups'])
                        A district's reading figures are left out when fewer than {{ $scorecard['min_readers'] }} readers had visits, as they would be one person's own.
                    @endif
                @else
                    No complete reading month falls inside this billing period, so the reading columns are empty.
                @endif
            </p>

            <x-ui.table label="District scorecard" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>District</th><th class="num">Billing (GH¢)</th><th class="num">Cash ratio</th><th class="num">Estimated volume</th><th class="num">Unbilled rate</th>
                        <th class="num">Visits</th><th class="num">Skip rate</th><th class="num">Active readers</th><th class="num">Visits per reader</th>
                    </tr>
                </x-slot:head>
                @forelse ($scorecard['rows'] as $row)
                    <tr wire:key="sc-{{ $row['district'] }}-{{ $row['key'] }}">
                        <td>{{ $row['district'] }}@if ($row['reading_hidden'])<x-ui.badge tone="warning">Fewer than {{ $scorecard['min_readers'] }} readers</x-ui.badge>@endif @unless ($row['billing_side'])<x-ui.badge>Reading only</x-ui.badge>@endunless @unless ($row['reading_side'])<x-ui.badge>Billing only</x-ui.badge>@endunless</td>
                        <td class="num">{{ $money($row['billing']) }}</td>
                        <td class="num">{{ $pct($row['cash_ratio']) }}</td>
                        <td class="num">{{ $pct($row['estimation']) }}</td>
                        <td class="num">{{ $pct($row['unbilled_rate']) }}</td>
                        <td class="num">{{ $row['visits'] === null ? '–' : number_format($row['visits']) }}</td>
                        <td class="num">{{ $pct($row['skip_rate']) }}</td>
                        <td class="num">{{ $row['active_readers'] ?? '–' }}</td>
                        <td class="num">{{ $row['visits_per_reader'] === null ? '–' : number_format($row['visits_per_reader']) }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="9" icon="map" title="No districts in this snapshot." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        @if ($estimation)
            <x-ui.card title="Estimation and skip rate" description="Months with both a single-month billing snapshot and a complete reading month. Indicative, not causal." class="dash-row">
                @if ($estimation['count'] === 0)
                    <x-ui.empty-state icon="trending-up" title="No month has both yet" description="This needs a single-month billing snapshot for a month whose reading is complete." />
                @else
                    <div wire:key="c2-{{ md5(json_encode($estimation['pairs'])) }}">
                        <x-ui.chart type="line" label="Estimated share of volume and skip rate by month" unit="%"
                            :labels="array_column($estimation['pairs'], 'label')"
                            :series="[
                                ['label' => 'Estimated share of volume', 'data' => array_column($estimation['pairs'], 'estimation'), 'color' => 'series-1'],
                                ['label' => 'Skip rate', 'data' => array_column($estimation['pairs'], 'skip_rate'), 'color' => 'warning'],
                            ]" height="240" />
                    </div>
                    <p class="ui-hint" style="margin-top:8px">
                        @if ($estimation['coefficient'] !== null)
                            Correlation over {{ $estimation['count'] }} months: <strong>{{ number_format($estimation['coefficient'], 2) }}</strong> (indicative, not causal).
                        @else
                            {{ $estimation['reason'] }}
                        @endif
                    </p>
                @endif
            </x-ui.card>
        @endif
    @endif

    @if (! $can_reading && ! $can_billing)
        <x-ui.card><x-ui.empty-state icon="info" title="Nothing to summarise for your permissions" description="You can see what has been loaded above; the numbers need the reading or billing permission." /></x-ui.card>
    @endif
</div>
