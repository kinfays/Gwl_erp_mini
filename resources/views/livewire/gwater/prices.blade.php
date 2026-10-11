<div>
    <x-ui.page-header title="Prices" description="Your location's dated price list. A price applies from its date; sales and trips keep the price they were recorded at, so a new price never rewrites history." />

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        <div class="ui-grid dash-row">
            @foreach ($inForce as $row)
                <x-ui.card :title="$row['product']->name">
                    @if ($row['price'])
                        <dl class="ui-dl">
                            <div><dt>Selling price today</dt><dd>GH¢ {{ \App\Services\GWater\Money::format($row['price']->selling_price) }}</dd></div>
                            <div><dt>Cost price today</dt><dd>GH¢ {{ \App\Services\GWater\Money::format($row['price']->cost_price) }}</dd></div>
                            <div><dt>In force since</dt><dd>{{ $row['price']->effective_from->format('d M Y') }}</dd></div>
                        </dl>
                        @if ($row['price']->isDefault())
                            <x-ui.alert tone="info">This is the system placeholder price, not one your location entered. Add your real price below.</x-ui.alert>
                        @else
                            <x-ui.badge tone="success">Your own price</x-ui.badge>
                        @endif
                    @else
                        <x-ui.alert tone="warning">No price on the list yet. Add one below.</x-ui.alert>
                    @endif
                </x-ui.card>
            @endforeach
        </div>

        @if ($canManage)
            <x-ui.card title="Add a price" description="Enter both prices; to change only one, repeat the other. A price dated in the past is refused if entries it would re-price already exist." class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.select label="Product" wire:model.defer="form.product_id">
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Effective from" type="date" wire:model.defer="form.effective_from" />
                    <x-ui.input label="Selling price per pack (GH¢)" wire:model.defer="form.selling_price" inputmode="decimal" placeholder="26.00" />
                    <x-ui.input label="Cost price per pack (GH¢)" wire:model.defer="form.cost_price" inputmode="decimal" placeholder="20.00" />
                    <x-ui.input label="Note (optional)" wire:model.defer="form.note" maxlength="255" />
                    @if ($isSuperAdmin)
                        <div class="field-check">
                            <x-ui.checkbox label="Change the system placeholder instead" description="Only for locations that have no list of their own." wire:model.defer="form.default" id="f-price-default" />
                        </div>
                    @endif
                </div>
                @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Add price</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        @foreach ($products as $product)
            <x-ui.card :title="'History: '.$product->name" :padded="false" class="dash-row">
                <x-ui.table label="Price history" :sticky="false" dense>
                    <x-slot:head>
                        <tr><th>From</th><th class="num">Selling</th><th class="num">Cost</th><th>List</th><th>Note</th><th>Added by</th></tr>
                    </x-slot:head>
                    @forelse ($histories[$product->id] ?? [] as $row)
                        <tr wire:key="price-{{ $row->id }}">
                            <td class="nowrap">{{ $row->effective_from->format('d M Y') }}</td>
                            <td class="num">{{ \App\Services\GWater\Money::format($row->selling_price) }}</td>
                            <td class="num">{{ \App\Services\GWater\Money::format($row->cost_price) }}</td>
                            <td>@if ($row->isDefault()) <x-ui.badge>Placeholder</x-ui.badge> @else <x-ui.badge tone="success">Yours</x-ui.badge> @endif</td>
                            <td class="cell-muted">{{ $row->note }}</td>
                            <td class="cell-muted">{{ $row->creator?->full_name }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="6" icon="percent" title="No prices yet." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @endforeach
    @endif
</div>
