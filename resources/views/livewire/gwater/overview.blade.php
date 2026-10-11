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
                <x-ui.stat-tile :label="'In stock: '.$row['product']->name" :value="number_format($row['packs'])" icon="boxes" meta="packs" :tone="$row['packs'] === 0 ? 'muted' : 'primary'" />
            @endforeach
            <x-ui.stat-tile label="Sales this month" :value="\App\Services\GWater\Money::format($month->sum('sales'))" icon="banknote" :meta="number_format($month->sum('sold_packs')).' packs'" />
            <x-ui.stat-tile label="Bought this month (at cost)" :value="\App\Services\GWater\Money::format($month->sum('purchases'))" icon="receipt" :meta="number_format($month->sum('bought_packs')).' packs'" tone="muted" />
            <x-ui.stat-tile label="Owed to G Water" :value="\App\Services\GWater\Money::format($owed)" icon="landmark" meta="opening owed + purchases - payments paid" tone="muted" />
            <x-ui.stat-tile label="Fuel to pay back" :value="\App\Services\GWater\Money::format($pending->sum('amount'))" icon="hand-coins" :meta="$pending->count().' pending'" :href="route('gwater.reimbursements')" tone="muted" />
        </div>

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

        <p class="cell-muted">Profit and loss, charts and exports arrive in a later phase.</p>
    @endif
</div>
