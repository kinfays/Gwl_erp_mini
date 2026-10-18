<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets
    @php
        $num = fn ($value, int $dp = 0) => $value === null ? '–' : number_format($value, $dp);
        $exportQuery = array_filter(['region' => $kpis['region_id'] ?? null, 'segment' => $kpis['segment'] ?? null, 'months' => $monthsChosen, 'district' => $district !== '' ? $district : null]);
        $cell = fn (array $row, $value) => $value === null ? '–' : ($row['money'] ? number_format($value, 2) : number_format($value));
    @endphp

    <x-ui.page-header title="KPIs against targets" description="Sales, billing and collection for each district against its yearly target (spread evenly over the twelve months), with the efficiency, the year to date, and the arrears and their age. Each month comes from that month's billing upload.">
        <x-slot:actions>
            @if ($canSetTargets)<a href="{{ route('commercial.kpis.targets') }}" class="btn btn-secondary">Set targets</a>@endif
            @if ($canExport && ! $kpis['empty'])
                <a href="{{ route('commercial.export', ['report' => 'kpis', 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.icon name="file-spreadsheet" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'kpis', 'format' => 'pdf', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.icon name="file-text" /> PDF</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($kpis['regions'] === [])
        <x-ui.alert tone="info" class="dash-row">There is no single-month billing upload yet. Upload the billing summary one month at a time to see this page.</x-ui.alert>
    @else
        <div class="ui-toolbar dash-row">
            @if (count($kpis['regions']) > 1)
                <select wire:model.live="region" class="form-input" aria-label="Region">
                    @foreach ($kpis['regions'] as $id => $name)<option value="{{ $id }}" @selected($id === $kpis['region_id'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            @if (count($kpis['segments']) > 1)
                <select wire:model.live="segment" class="form-input" aria-label="Customer segment">
                    @foreach ($kpis['segments'] as $key => $segment)<option value="{{ $key }}" @selected((string) $key === (string) $kpis['segment'])>{{ $segment['label'] }} ({{ $segment['months'] }} months)</option>@endforeach
                </select>
            @endif
            <select wire:model.live="months" class="form-input" aria-label="Months shown">
                @foreach (['2' => 'Last 2 months', '3' => 'Last 3 months', '4' => 'Last 4 months', '6' => 'Last 6 months', '12' => 'Last 12 months'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            @if (count($kpis['district_options']) > 1)
                <select wire:model.live="district" class="form-input" aria-label="District">
                    <option value="">All districts</option>
                    @foreach ($kpis['district_options'] as $id => $name)@if (is_int($id))<option value="{{ $id }}">{{ $name }}</option>@endif @endforeach
                </select>
            @endif
        </div>

        @if ($kpis['empty'])
            <x-ui.alert tone="info" class="dash-row">No single-month billing upload of this customer segment in this region yet.</x-ui.alert>
        @else
            <p class="ui-hint dash-row">{{ $kpis['region'] }} · {{ $kpis['segments'][$kpis['segment']]['label'] ?? 'All customers' }} · {{ count($kpis['months']) }} month(s). Targets are yearly: a month's target is the annual target ÷ 12 and efficiency = actual ÷ that target × 100. Year to date compares the {{ $charts['ytd_months'] }} month(s) uploaded in {{ $charts['ytd_year'] }} with the annual target × those months ÷ 12. Blank where the year has no target.</p>

            {{-- ============================================ charts --}}
            <div class="ui-grid ui-grid-3 dash-row">
                @foreach ($charts['trend'] as $metric => $chart)
                    <x-ui.card :title="$chart['label'].': actual against target'" :description="$charts['scope']" wire:key="ch-trend-{{ $metric }}">
                        <x-ui.chart type="line" :label="$chart['label'].' by month'" :unit="$chart['unit']" :labels="$charts['labels']" :series="$chart['series']" height="230" />
                    </x-ui.card>
                @endforeach
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                @if ($charts['districts'] !== [] && array_filter(array_merge(...array_values($charts['ytd_efficiency'])), fn ($v) => $v !== null) !== [])
                    <x-ui.card title="Year-to-date efficiency by district" :description="'Actual ÷ the annual target for the '.$charts['ytd_months'].' month(s) uploaded in '.$charts['ytd_year'].'. 100 means on target.'" wire:key="ch-eff">
                        <x-ui.chart type="bar" label="Year-to-date efficiency by district" unit="%" :labels="$charts['districts']"
                            :series="[
                                ['label' => 'Sales', 'data' => array_map(fn ($v) => $v ?? 0, $charts['ytd_efficiency']['sales']), 'color' => 'series-1'],
                                ['label' => 'Billing', 'data' => array_map(fn ($v) => $v ?? 0, $charts['ytd_efficiency']['billing']), 'color' => 'series-2'],
                                ['label' => 'Collection', 'data' => array_map(fn ($v) => $v ?? 0, $charts['ytd_efficiency']['collection']), 'color' => 'series-3'],
                            ]" height="260" />
                    </x-ui.card>

                    <x-ui.card title="Share of the annual target reached" :description="'By the end of the '.$charts['ytd_months'].' month(s) uploaded, an even pace would be '.number_format($charts['ytd_months'] / 12 * 100, 1).'%.'" wire:key="ch-progress">
                        <x-ui.chart type="hbar" label="Share of the annual target reached" unit="%" :labels="$charts['districts']"
                            :series="[
                                ['label' => 'Sales', 'data' => array_map(fn ($v) => $v ?? 0, $charts['ytd_progress'][0]), 'color' => 'series-1'],
                                ['label' => 'Billing', 'data' => array_map(fn ($v) => $v ?? 0, $charts['ytd_progress'][1]), 'color' => 'series-2'],
                                ['label' => 'Collection', 'data' => array_map(fn ($v) => $v ?? 0, $charts['ytd_progress'][2]), 'color' => 'series-3'],
                            ]" height="260" />
                    </x-ui.card>
                @else
                    <x-ui.alert tone="info" class="dash-row">No annual target is set for {{ $charts['ytd_year'] }} yet, so there is no efficiency to chart. Set the targets under "Set targets".</x-ui.alert>
                @endif
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="Arrears and customer credits" :description="$charts['scope'].' (GH¢)'" wire:key="ch-arrears">
                    <x-ui.chart type="area" label="Arrears and customer credits by month" unit="GH¢" :labels="$charts['arrears_labels']"
                        :series="[['label' => 'Arrears (owed)', 'data' => $charts['arrears_owed'], 'color' => 'danger'], ['label' => 'Customer credits', 'data' => $charts['arrears_credit'], 'color' => 'success']]" height="260" />
                </x-ui.card>
                <x-ui.card title="Age of arrears" :description="$charts['scope'].' (arrears ÷ that month\'s billing, in months)'" wire:key="ch-age">
                    <x-ui.chart type="line" label="Age of arrears by month" unit="months" :labels="$charts['arrears_labels']"
                        :series="[['label' => 'Age of arrears', 'data' => $charts['arrears_age'], 'color' => 'warning']]" height="260" />
                </x-ui.card>
            </div>

            @if (count($charts['districts']) > 1)
                <div class="ui-grid ui-grid-2 dash-row">
                    <x-ui.card title="Billing by district, latest month" wire:key="ch-bill-share">
                        <x-ui.chart type="doughnut" label="Billing by district, latest month" unit="GH¢" center center-caption="billed" :table="false" :labels="$charts['districts']"
                            :series="[['label' => 'Billing', 'data' => $charts['latest_billing']]]" height="260" />
                    </x-ui.card>
                    <x-ui.card title="Arrears by district, latest month" description="What customers owe (GH¢)" wire:key="ch-arr-district">
                        <x-ui.chart type="hbar" label="Arrears by district, latest month" unit="GH¢" :labels="$charts['districts']"
                            :series="[['label' => 'Arrears', 'data' => $charts['latest_arrears'], 'color' => 'danger']]" height="260" />
                    </x-ui.card>
                </div>
            @endif

            @foreach (array_filter([$district === '' ? $kpis['total'] : null, ...$kpis['districts']]) as $block)
                @php $months = $kpis['months']; @endphp
                <x-ui.card :title="($block['id'] === null && $block['name'] === 'All districts' ? 'All districts' : 'District: '.$block['name'])" class="dash-row" :padded="false" wire:key="kpi-{{ $block['id'] ?? 'all' }}">
                    <div class="ui-table-wrap"><div class="ui-table-scroll" role="region" tabindex="0" aria-label="KPIs of {{ $block['name'] }}">
                        <table class="ui-table">
                            <thead>
                                <tr><th rowspan="2">KPI</th><th rowspan="2" class="num">Annual target</th><th colspan="{{ count($months) }}" class="num">Actuals</th><th colspan="{{ count($months) }}" class="num">Efficiency (%)</th><th colspan="3" class="num">Year to date</th></tr>
                                <tr>
                                    @foreach ($months as $m)<th class="num">{{ $m['label'] }}</th>@endforeach
                                    @foreach ($months as $m)<th class="num">{{ $m['short'] }}</th>@endforeach
                                    <th class="num">Actual</th><th class="num">Efficiency (%)</th><th class="num">Of annual target (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($block['rows'] as $row)
                                    <tr wire:key="r-{{ $block['id'] ?? 'all' }}-{{ $row['metric'] }}">
                                        <td><strong>{{ $row['label'] }}</strong> <span class="ui-hint">{{ $row['unit'] }}</span></td>
                                        <td class="num">
                                            @if ($row['metric'] === 'arrears') – @else {{ $cell($row, $row['annual']) }}@if ($row['target_changes'])<span class="ui-hint" title="These months fall in two years with different targets: each month's efficiency uses its own year's target."> *</span>@endif @endif
                                        </td>
                                        @foreach ($months as $m)<td class="num">{{ $cell($row, $row['actuals'][$m['key']] ?? null) }}</td>@endforeach
                                        @foreach ($months as $m)
                                            @php $eff = $row['efficiency'][$m['key']] ?? null; @endphp
                                            <td class="num">@if ($eff !== null)<x-ui.badge :tone="$eff >= 100 ? 'success' : 'warning'">{{ number_format($eff, 1) }}</x-ui.badge>@endif</td>
                                        @endforeach
                                        @if ($row['metric'] === 'arrears')
                                            <td class="num">–</td><td class="num"></td><td class="num"></td>
                                        @else
                                            @php $ytdEff = $row['ytd']['efficiency']; @endphp
                                            <td class="num">{{ $cell($row, $row['ytd']['actual']) }}</td>
                                            <td class="num">@if ($ytdEff !== null)<x-ui.badge :tone="$ytdEff >= 100 ? 'success' : 'warning'">{{ number_format($ytdEff, 1) }}</x-ui.badge>@endif</td>
                                            <td class="num">{{ $row['ytd']['progress'] === null ? '–' : number_format($row['ytd']['progress'], 1) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div></div>
                    @if ($block['id'] === null && $block['name'] === 'All districts' && ! ($block['complete'] ?? true))
                        <p class="ui-hint dash-row">Some districts have no target yet, so the region's target and efficiency are left blank rather than shown from a partial sum.</p>
                    @endif
                </x-ui.card>

                <x-ui.card title="Arrears: {{ $block['name'] }}" :description="$kpis['arrears_note']" class="dash-row" :padded="false" wire:key="arr-{{ $block['id'] ?? 'all' }}">
                    <x-ui.table label="Arrears of {{ $block['name'] }}" :sticky="false">
                        <x-slot:head><tr><th>Month</th><th class="num">Arrears: owed (GH¢)</th><th class="num">Age of arrears (months)</th><th class="num">Customer credits (GH¢)</th><th class="num">Net balance (GH¢)</th><th class="num">Change in arrears</th><th>Source</th></tr></x-slot:head>
                        @forelse ($block['arrears'] as $a)
                            <tr wire:key="a-{{ $block['id'] ?? 'all' }}-{{ $a['key'] }}">
                                <td><strong>{{ $a['label'] }}</strong></td>
                                <td class="num">{{ number_format($a['arrears'], 2) }}</td>
                                <td class="num">{{ $a['age'] === null ? '–' : number_format($a['age'], 1) }}</td>
                                <td class="num">{{ number_format($a['credit'], 2) }}@if ($a['source'] === 'routes') <span class="ui-hint">({{ $a['credit_routes'] }} route{{ $a['credit_routes'] === 1 ? '' : 's' }} in credit)</span>@endif</td>
                                <td class="num">{{ number_format($a['net'], 2) }}</td>
                                <td class="num">{{ $a['change'] === null ? '–' : ($a['change'] > 0 ? '+' : '').number_format($a['change'], 2) }}@if ($a['change_pct'] !== null) <span class="ui-hint">({{ $a['change_pct'] > 0 ? '+' : '' }}{{ number_format($a['change_pct'], 1) }}%)</span>@endif</td>
                                <td class="ui-hint">{{ ['customers' => 'Customer list', 'routes' => 'Billing routes', 'mixed' => 'Both'][$a['source']] ?? $a['source'] }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="7" icon="receipt" title="No figures." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            @endforeach

            <p class="ui-hint dash-row">{{ $kpis['age_note'] }}</p>
        @endif
    @endif
</div>
