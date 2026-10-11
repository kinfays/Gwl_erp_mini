<div>
    <x-ui.page-header title="Daily sales" description="A day's packs per product at the selling price in force that day. To change a price, add it on the Prices screen." />

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        @if ($canRecord)
            <x-ui.card title="Record sales" class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.input label="Date" type="date" wire:model.live="form.sale_date" :max="today()->toDateString()" />
                    <x-ui.select label="Product" wire:model.live="form.product_id">
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Packs sold" type="number" min="1" step="1" inputmode="numeric" wire:model.live="form.packs" />
                    <x-ui.field label="Selling price per pack (GH¢)" for="f-sale-price" hint="From the price list; not editable here.">
                        <input id="f-sale-price" class="form-input" readonly value="{{ $price !== null ? \App\Services\GWater\Money::format($price) : 'No price on the list' }}">
                    </x-ui.field>
                    <x-ui.field label="Amount (GH¢)" for="f-sale-amount">
                        <input id="f-sale-amount" class="form-input" readonly value="{{ $price !== null && (int) $form['packs'] > 0 ? \App\Services\GWater\Money::format($price * (int) $form['packs']) : '' }}">
                    </x-ui.field>
                    <x-ui.input label="Note (optional)" wire:model.defer="form.note" maxlength="255" />
                </div>
                @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Record sale</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
            </div>
            <div class="ui-stat-grid" style="margin-top:12px">
                @foreach ($products as $product)
                    @php($rows = $totals[$product->id] ?? collect())
                    <x-ui.stat-tile :label="$product->name" :value="number_format($rows->sum('packs'))" icon="banknote" :meta="'packs · GH¢ '.\App\Services\GWater\Money::format($rows->sum('amount'))" tone="muted" />
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card :padded="false" class="dash-row">
            <x-ui.table label="Sales">
                <x-slot:head>
                    <tr>
                        <th>Date</th><th>Product</th><th class="num">Packs</th><th class="num">Price</th><th class="num">Amount</th><th>By</th><th>Status</th>
                        <th><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>
                @forelse ($sales as $sale)
                    <tr wire:key="sale-{{ $sale->id }}" @class(['is-voided' => $sale->isVoided()])>
                        <td class="nowrap">{{ $sale->sale_date->format('d M Y') }}</td>
                        <td>{{ $sale->product->name }}</td>
                        <td class="num">{{ number_format($sale->packs) }}</td>
                        <td class="num">{{ \App\Services\GWater\Money::format($sale->unit_price) }}</td>
                        <td class="num">{{ \App\Services\GWater\Money::format($sale->amount) }}</td>
                        <td class="cell-muted">{{ $sale->creator?->full_name }}</td>
                        <td>
                            @if ($sale->isVoided())
                                <x-ui.badge tone="danger" title="{{ $sale->void_reason }}">Voided</x-ui.badge>
                                <div class="cell-muted">{{ $sale->void_reason }}</div>
                            @else
                                <x-ui.badge tone="success">Posted</x-ui.badge>
                            @endif
                        </td>
                        <td class="nowrap">
                            @if ($canVoid && ! $sale->isVoided())
                                <x-ui.button size="sm" variant="ghost" wire:click="openVoid({{ $sale->id }})">Void</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="8" icon="banknote" title="No sales in this period." />
                @endforelse
                <x-slot:footer>
                    <div>{{ $sales->links() }}</div>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($voidingId)
        <x-ui.modal title="Void this sale" description="The packs go back into stock. The entry stays in the books, marked voided, with your reason." close="cancelVoid">
            <x-ui.textarea label="Reason" wire:model.defer="voidReason" rows="3" maxlength="500" />
            @error('void.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelVoid">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoid">Void sale</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
