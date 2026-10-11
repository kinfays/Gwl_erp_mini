<div>
    <x-ui.page-header title="G-Water" description="Bottled and sachet water: stock, sales and trips for your location. Amounts are in GH¢; stock is in packs (bottle pack = 15, sachet pack = 30).">
        @if ($canRecord && $location)
            <x-slot:actions>
                <x-ui.button :href="route('gwater.sales')" variant="primary" icon="plus">Record sales</x-ui.button>
                <x-ui.button :href="route('gwater.purchases')" icon="plus">Record a trip</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        <div class="ui-stat-grid dash-row">
            @foreach ($stock as $row)
                <x-ui.stat-tile :label="'In stock: '.$row['product']->name" :value="number_format($row['packs'])" icon="boxes" :meta="$row['low'] ? 'LOW: at or below '.number_format($row['threshold']).' packs' : 'packs'" :tone="$row['low'] ? 'danger' : 'primary'" />
            @endforeach
            <x-ui.stat-tile label="Sales this month" :value="\App\Services\GWater\Money::format($month->sum('sales'))" icon="banknote" :meta="number_format($month->sum('sold_packs')).' packs'" />
            <x-ui.stat-tile label="Bought this month (at cost)" :value="\App\Services\GWater\Money::format($month->sum('purchases'))" icon="receipt" :meta="number_format($month->sum('bought_packs')).' packs'" tone="muted" />
            @if ($report)
                <x-ui.stat-tile label="Net profit this month (so far)" :value="\App\Services\GWater\Money::format($report['combined']['net_profit'])" icon="chart-column"
                    :meta="'In progress · gross margin '.($report['combined']['gross_margin_pct'] === null ? 'n/a' : number_format($report['combined']['gross_margin_pct'], 1).'%')"
                    :href="route('gwater.pnl')" :tone="$report['combined']['net_profit'] < 0 ? 'danger' : 'success'" />
            @endif
            <x-ui.stat-tile label="Owed to G Water" :value="\App\Services\GWater\Money::format($owed)" icon="landmark" meta="opening owed + purchases - payments paid" tone="muted" />
            <x-ui.stat-tile label="Fuel to pay back" :value="\App\Services\GWater\Money::format($pending->sum('amount'))" icon="hand-coins" :meta="$pending->count().' pending'" :href="route('gwater.reimbursements')" tone="muted" />
        </div>

        @if ($stock->contains('low', true) || $damageFlags->isNotEmpty())
            <x-ui.alert tone="warning" role="status" class="dash-row">
                @foreach ($stock->where('low', true) as $row)
                    <div>Low stock: {{ $row['product']->name }}, {{ number_format($row['packs']) }} packs on hand (flagged at {{ number_format($row['threshold']) }} or fewer).</div>
                @endforeach
                @foreach ($damageFlags as $line)
                    <div>High damage rate this month: {{ $line['name'] }}, {{ number_format($line['damage_rate_pct'], 1) }}% of the packs received (flagged above {{ config('gwl.gwater_damage_rate_flag_pct') }}%).</div>
                @endforeach
            </x-ui.alert>
        @endif

        <div class="ui-grid dash-row">
            <x-ui.card title="Latest sales" :padded="false">
                <x-ui.table label="Latest sales" :sticky="false" dense>
                    <x-slot:head>
                        <tr><th>Date</th><th>Product</th><th class="num">Packs</th><th class="num">Amount</th></tr>
                    </x-slot:head>
                    @forelse ($recentSales as $sale)
                        <tr wire:key="ov-sale-{{ $sale->id }}">
                            <td class="nowrap">{{ $sale->sale_date->format('d M Y') }}</td>
                            <td>{{ $sale->product->name }}</td>
                            <td class="num">{{ number_format($sale->packs) }}</td>
                            <td class="num">{{ \App\Services\GWater\Money::format($sale->amount) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="4" icon="banknote" title="No sales recorded yet." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Latest trips" :padded="false">
                <x-ui.table label="Latest trips" :sticky="false" dense>
                    <x-slot:head>
                        <tr><th>Date</th><th>Waybill</th><th class="num">Packs</th><th class="num">Cost</th></tr>
                    </x-slot:head>
                    @forelse ($recentTrips as $trip)
                        <tr wire:key="ov-trip-{{ $trip->id }}">
                            <td class="nowrap">{{ $trip->purchase_date->format('d M Y') }}</td>
                            <td class="mono">{{ $trip->waybill_no }}</td>
                            <td class="num">{{ number_format($trip->totalPacks()) }}</td>
                            <td class="num">{{ \App\Services\GWater\Money::format($trip->totalCost()) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="4" icon="boxes" title="No trips recorded yet." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

    @endif
</div>
