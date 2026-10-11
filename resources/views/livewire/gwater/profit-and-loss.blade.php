@assets
    @vite('resources/js/charts.js')
@endassets

@php($money = fn ($pesewas) => \App\Services\GWater\Money::format($pesewas))
@php($pct = fn ($value) => $value === null ? 'n/a' : number_format($value, 1).'%')

<div>
    <x-ui.page-header title="Profit and loss" description="Revenue less the cost of the water sold, damages, fuel, loading and offloading and other costs, for your location. Amounts are in GH¢.">
        @if ($report && $canExport)
            <x-slot:actions>
                <x-ui.button :href="route('gwater.export', ['report' => 'pnl', 'format' => 'excel'] + $exportQuery)" icon="file-spreadsheet">Excel</x-ui.button>
                <x-ui.button :href="route('gwater.export', ['report' => 'pnl', 'format' => 'pdf'] + $exportQuery)" icon="file-text">PDF</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.gwater.partials.location-picker')

    @if ($location && $report)
        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.select label="Period" wire:model.live="preset">
                    <option value="this_month">This month</option>
                    <option value="last_month">Last month</option>
                    <option value="this_quarter">This quarter</option>
                    <option value="last_quarter">Last quarter</option>
                    <option value="custom">Custom range</option>
                </x-ui.select>
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
                <x-ui.select label="Product" wire:model.live="product">
                    <option value="">All products</option>
                    @foreach ($productChoices as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <p class="cell-muted" style="margin-top:8px">
                {{ $report['period']['label'] }} ·
                @if ($report['period']['in_progress'])
                    <x-ui.badge tone="warning">In progress</x-ui.badge> figures to date, the period is not over
                @else
                    <x-ui.badge tone="success">Complete</x-ui.badge>
                @endif
            </p>
        </x-ui.card>

        @php($all = $report['combined'])
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Revenue" :value="$money($all['revenue'])" icon="banknote" :meta="number_format($all['packs_sold']).' packs sold'" />
            <x-ui.stat-tile label="Gross profit" :value="$money($all['gross_profit'])" icon="trending-up" :meta="'Margin '.$pct($all['gross_margin_pct'])" :tone="$all['gross_profit'] < 0 ? 'danger' : 'primary'" />
            <x-ui.stat-tile label="Net profit" :value="$money($all['net_profit'])" icon="chart-column" :meta="'Margin '.$pct($all['net_margin_pct'])" :tone="$all['net_profit'] < 0 ? 'danger' : 'success'" />
            <x-ui.stat-tile label="Owed to G Water" :value="$money($report['owed'])" icon="landmark" meta="at the end of the period" tone="muted" />
        </div>

        <x-ui.card title="Profit and loss" :padded="false" class="dash-row">
            <x-ui.table label="Profit and loss" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>Line</th><th class="num">Combined</th>
                        @foreach ($products as $line)<th class="num">{{ $line['name'] }}</th>@endforeach
                    </tr>
                </x-slot:head>
                @foreach ([
                    ['Packs sold', 'packs_sold', false, false],
                    ['Revenue', 'revenue', true, false],
                    ['Cost of water sold', 'cost_of_sales', true, false],
                    ['Gross profit', 'gross_profit', true, false],
                    ['Damages (written off at cost)', 'damages', true, false],
                    ['Fuel', 'fuel', true, true],
                    ['Loading', 'loading', true, true],
                    ['Offloading', 'offloading', true, true],
                    ['Other expenses', 'other', true, true],
                    ['Net profit', 'net_profit', true, true],
                ] as [$label, $key, $isMoney, $combinedOnly])
                    <tr wire:key="pl-{{ $key }}" @class(['is-total' => in_array($key, ['gross_profit', 'net_profit'], true)])>
                        <td>@if (in_array($key, ['gross_profit', 'net_profit'], true))<strong>{{ $label }}</strong>@else{{ $label }}@endif</td>
                        <td class="num">{{ $isMoney ? $money($all[$key]) : number_format($all[$key]) }}</td>
                        @foreach ($products as $line)
                            <td class="num">{{ $combinedOnly ? '' : ($isMoney ? $money($line[$key]) : number_format($line[$key])) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </x-ui.table>
            <p class="cell-muted" style="padding:8px 14px">Fuel, loading, offloading and other costs belong to trips, not to products, so net profit is a combined figure.</p>
        </x-ui.card>

        <x-ui.card title="Measures" :padded="false" class="dash-row">
            <x-ui.table label="Measures" :sticky="false" dense>
                <x-slot:head>
                    <tr><th>Measure</th><th class="num">Combined</th>@foreach ($products as $line)<th class="num">{{ $line['name'] }}</th>@endforeach</tr>
                </x-slot:head>
                @foreach ([
                    ['Gross margin', 'gross_margin_pct', 'pct'],
                    ['Packs received', 'received_packs', 'int'],
                    ['Packs damaged', 'damaged_packs', 'int'],
                    ['Damage rate (of packs received)', 'damage_rate_pct', 'pct'],
                    ['Average selling price per pack', 'average_price', 'money'],
                    ['Average cost per pack sold', 'average_cost', 'money'],
                ] as [$label, $key, $kind])
                    @php($show = fn ($value) => $value === null ? 'n/a' : match ($kind) { 'pct' => $pct($value), 'money' => $money($value), default => number_format($value) })
                    <tr wire:key="m-{{ $key }}">
                        <td>{{ $label }}</td>
                        <td class="num">{{ $show($all[$key]) }}</td>
                        @foreach ($products as $line)<td class="num">{{ $show($line[$key]) }}</td>@endforeach
                    </tr>
                @endforeach
                <tr wire:key="m-be">
                    <td>Break-even packs <span class="cell-muted">(to cover damages and trip costs)</span></td>
                    <td class="num">{{ $all['break_even_packs'] === null ? 'n/a' : number_format($all['break_even_packs']) }}</td>
                    @foreach ($products as $line)<td class="num"></td>@endforeach
                </tr>
            </x-ui.table>
        </x-ui.card>

        <div class="ui-grid dash-row">
            <x-ui.card title="Revenue and costs by month" description="GH¢">
                <x-ui.chart type="bar" label="Revenue, cost of water sold and costs by month" unit="GH¢"
                    event="gwater-pnl-updated" source="months"
                    :source-data="$report['months']"
                    :series="[['label' => 'Revenue', 'key' => 'revenue'], ['label' => 'Cost of water sold', 'key' => 'cost_of_sales'], ['label' => 'Damages and costs', 'key' => 'costs']]" height="260" />
            </x-ui.card>
            <x-ui.card title="Net profit by month" description="GH¢">
                <x-ui.chart type="line" label="Net profit by month" unit="GH¢"
                    event="gwater-pnl-updated" source="months"
                    :source-data="$report['months']"
                    :series="[['label' => 'Net profit', 'key' => 'net_profit']]" height="260" />
            </x-ui.card>
        </div>

        <x-ui.card title="Trip costs" description="What each trip cost to bring here, per pack and per km where a Mileage entry is linked." :padded="false" class="dash-row">
            <x-ui.table label="Trip costs">
                <x-slot:head>
                    <tr>
                        <th>Date</th><th>Waybill</th><th>Vehicle</th><th class="num">Packs</th><th class="num">Bought</th><th class="num">Fuel</th><th class="num">Loading + offloading</th>
                        <th class="num">Landed cost per pack</th><th class="num">Trip cost per pack</th><th class="num">Trip cost per km</th>
                    </tr>
                </x-slot:head>
                @forelse ($report['trips'] as $trip)
                    <tr wire:key="trip-{{ $trip['id'] }}">
                        <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($trip['date'])->format('d M Y') }}</td>
                        <td class="mono">{{ $trip['waybill'] }}</td>
                        <td class="mono">{{ $trip['vehicle'] ?? '' }}</td>
                        <td class="num">{{ number_format($trip['packs']) }}</td>
                        <td class="num">{{ $money($trip['purchase_cost']) }}</td>
                        <td class="num">
                            {{ $money($trip['fuel']) }}
                            @if ($trip['fuel_source'] === 'Transport')<div><x-ui.badge tone="lagoon">From Transport</x-ui.badge></div>@endif
                            @if ($trip['changed_in_transport'])<div class="cell-muted">changed in Transport</div>@endif
                        </td>
                        <td class="num">{{ $money($trip['loading'] + $trip['offloading']) }}</td>
                        <td class="num">{{ $trip['landed_cost_per_pack'] === null ? 'n/a' : $money($trip['landed_cost_per_pack']) }}</td>
                        <td class="num">{{ $trip['trip_cost_per_pack'] === null ? 'n/a' : $money($trip['trip_cost_per_pack']) }}</td>
                        <td class="num">{{ $trip['trip_cost_per_km'] === null ? 'n/a' : $money($trip['trip_cost_per_km']).' ('.number_format($trip['km']).' km)' }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="10" icon="boxes" title="No trips in this period." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="How these figures are worked out" class="dash-row">
            <ul class="ui-list">
                @foreach ($report['notes'] as $note)<li>{{ $note }}</li>@endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
