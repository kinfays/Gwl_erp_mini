<div>
    @php
        $num = fn ($value, int $dp = 0) => $value === null ? '–' : number_format($value, $dp);
        $exportQuery = array_filter(['region' => $kpis['region_id'] ?? null, 'segment' => $kpis['segment'] ?? null, 'months' => $monthsChosen, 'district' => $district !== '' ? $district : null]);
        $cell = fn (array $row, $value) => $value === null ? '–' : ($row['money'] ? number_format($value, 2) : number_format($value));
    @endphp

    <x-ui.page-header title="KPIs against targets" description="Sales, billing and collection for each district against its monthly target, with the efficiency, and the arrears and their age. Each month comes from that month's billing upload.">
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
            <p class="ui-hint dash-row">{{ $kpis['region'] }} · {{ $kpis['segments'][$kpis['segment']]['label'] ?? 'All customers' }} · {{ count($kpis['months']) }} month(s). Efficiency = actual ÷ target × 100; it is blank where no target is set.</p>

            @foreach (array_filter([$district === '' ? $kpis['total'] : null, ...$kpis['districts']]) as $block)
                @php $months = $kpis['months']; @endphp
                <x-ui.card :title="($block['id'] === null && $block['name'] === 'All districts' ? 'All districts' : 'District: '.$block['name'])" class="dash-row" :padded="false" wire:key="kpi-{{ $block['id'] ?? 'all' }}">
                    <div class="ui-table-wrap"><div class="ui-table-scroll" role="region" tabindex="0" aria-label="KPIs of {{ $block['name'] }}">
                        <table class="ui-table">
                            <thead>
                                <tr><th rowspan="2">KPI</th><th rowspan="2" class="num">Target</th><th colspan="{{ count($months) }}" class="num">Actuals</th><th colspan="{{ count($months) }}" class="num">Efficiency (%)</th></tr>
                                <tr>
                                    @foreach ($months as $m)<th class="num">{{ $m['label'] }}</th>@endforeach
                                    @foreach ($months as $m)<th class="num">{{ $m['short'] }}</th>@endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($block['rows'] as $row)
                                    <tr wire:key="r-{{ $block['id'] ?? 'all' }}-{{ $row['metric'] }}">
                                        <td><strong>{{ $row['label'] }}</strong> <span class="ui-hint">{{ $row['unit'] }}</span></td>
                                        <td class="num">
                                            @if ($row['metric'] === 'arrears') – @else {{ $cell($row, $row['target']) }}@if ($row['target_changes'])<span class="ui-hint" title="The target changed during these months: each month's efficiency uses the target in force then."> *</span>@endif @endif
                                        </td>
                                        @foreach ($months as $m)<td class="num">{{ $cell($row, $row['actuals'][$m['key']] ?? null) }}</td>@endforeach
                                        @foreach ($months as $m)
                                            @php $eff = $row['efficiency'][$m['key']] ?? null; @endphp
                                            <td class="num">@if ($eff !== null)<x-ui.badge :tone="$eff >= 100 ? 'success' : 'warning'">{{ number_format($eff, 1) }}</x-ui.badge>@endif</td>
                                        @endforeach
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
