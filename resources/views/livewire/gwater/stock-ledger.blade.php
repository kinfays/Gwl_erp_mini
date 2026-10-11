<div>
    <x-ui.page-header title="Stock" description="Packs on hand and every movement. The ledger is never edited: a mistake is put right with a correction line." />

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        <div class="ui-stat-grid dash-row">
            @foreach ($onHand as $row)
                <x-ui.stat-tile :label="$row['product']->name" :value="number_format($row['packs'])" icon="boxes" meta="packs on hand" :tone="$row['packs'] === 0 ? 'muted' : 'primary'" />
            @endforeach
        </div>

        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.select label="Product" wire:model.live="productId">
                    @foreach ($products as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
            </div>
        </x-ui.card>

        <x-ui.card :padded="false" class="dash-row">
            <x-ui.table label="Stock ledger">
                <x-slot:head>
                    <tr><th>Date</th><th>Type</th><th class="num">Packs</th><th class="num">Balance</th><th>Note</th><th>By</th></tr>
                </x-slot:head>
                @forelse ($ledger as $line)
                    <tr wire:key="mv-{{ $line['movement']->id }}">
                        <td class="nowrap">{{ $line['movement']->movement_date->format('d M Y') }}</td>
                        <td>{{ ucfirst($line['movement']->type) }}</td>
                        <td class="num {{ $line['movement']->packs < 0 ? 'text-danger' : '' }}">{{ $line['movement']->packs > 0 ? '+' : '' }}{{ number_format($line['movement']->packs) }}</td>
                        <td class="num"><strong>{{ number_format($line['balance']) }}</strong></td>
                        <td class="cell-muted">{{ $line['movement']->note }}</td>
                        <td class="cell-muted">{{ $line['movement']->creator?->full_name }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="6" icon="boxes" title="No movements in this period." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        @if ($canRecord)
            <x-ui.card title="Correct the stock" description="Adds or takes away packs with a reason. The balance cannot go below zero." class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.select label="Product" wire:model.defer="form.product_id">
                        @foreach ($products as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Packs (negative takes away)" type="number" step="1" inputmode="numeric" wire:model.defer="form.packs" />
                    <x-ui.input label="Date" type="date" wire:model.defer="form.movement_date" :max="today()->toDateString()" />
                    <x-ui.input label="Reason" wire:model.defer="form.reason" maxlength="255" />
                </div>
                @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                <div class="ui-form-actions">
                    <x-ui.button icon="check" wire:click="correct" loading="correct">Record correction</x-ui.button>
                </div>
            </x-ui.card>
        @endif
    @endif
</div>
