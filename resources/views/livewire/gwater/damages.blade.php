<div>
    <x-ui.page-header title="Damages" description="Packs damaged on the way, while loading or offloading, or in storage. They leave stock and are written off at the cost price in force that day." />

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        @if ($canRecord)
            <x-ui.card title="Record damaged packs" class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.input label="Date" type="date" wire:model.defer="form.damage_date" :max="today()->toDateString()" />
                    <x-ui.select label="Product" wire:model.defer="form.product_id">
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Packs damaged" type="number" min="1" step="1" inputmode="numeric" wire:model.defer="form.packs" />
                    <x-ui.select label="Where it happened" wire:model.defer="form.stage">
                        @foreach ($stages as $stage)
                            <option value="{{ $stage }}">{{ ucfirst($stage) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Trip (optional)" wire:model.defer="form.purchase_id">
                        <option value="">Not tied to a trip</option>
                        @foreach ($trips as $trip)
                            <option value="{{ $trip->id }}">{{ $trip->purchase_date->format('d M Y') }} · waybill {{ $trip->waybill_no }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="What happened" wire:model.defer="form.reason" maxlength="500" />
                </div>
                @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Record damage</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
            </div>
        </x-ui.card>

        <x-ui.card :padded="false" class="dash-row">
            <x-ui.table label="Damages">
                <x-slot:head>
                    <tr><th>Date</th><th>Product</th><th class="num">Packs</th><th>Stage</th><th class="num">Written off</th><th>Trip</th><th>Reason</th><th>Status</th><th><span class="sr-only-text">Actions</span></th></tr>
                </x-slot:head>
                @forelse ($damages as $damage)
                    <tr wire:key="damage-{{ $damage->id }}" @class(['is-voided' => $damage->isVoided()])>
                        <td class="nowrap">{{ $damage->damage_date->format('d M Y') }}</td>
                        <td>{{ $damage->product->name }}</td>
                        <td class="num">{{ number_format($damage->packs) }}</td>
                        <td>{{ ucfirst($damage->stage) }}</td>
                        <td class="num">{{ \App\Services\GWater\Money::format($damage->packs * $damage->unit_cost) }}</td>
                        <td class="mono">{{ $damage->purchase?->waybill_no ?? '-' }}</td>
                        <td class="cell-muted">{{ $damage->reason }}</td>
                        <td>
                            @if ($damage->isVoided())
                                <x-ui.badge tone="danger">Voided</x-ui.badge>
                                <div class="cell-muted">{{ $damage->void_reason }}</div>
                            @else
                                <x-ui.badge tone="success">Posted</x-ui.badge>
                            @endif
                        </td>
                        <td class="nowrap">
                            @if ($canVoid && ! $damage->isVoided())
                                <x-ui.button size="sm" variant="ghost" wire:click="openVoid({{ $damage->id }})">Void</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="9" icon="triangle-alert" title="No damages in this period." />
                @endforelse
                <x-slot:footer><div>{{ $damages->links() }}</div></x-slot:footer>
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($voidingId)
        <x-ui.modal title="Void this damage" description="The packs go back into stock. The entry stays in the books, marked voided, with your reason." close="cancelVoid">
            <x-ui.textarea label="Reason" wire:model.defer="voidReason" rows="3" maxlength="500" />
            @error('void.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelVoid">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoid">Void damage</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
