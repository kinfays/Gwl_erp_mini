<div>
    <x-ui.page-header title="Opening balance" description="What your location had when you started using the system: packs in stock and the amount owed to G Water. It is recorded once." />

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        @if ($opening)
            <x-ui.card title="Recorded" class="dash-row">
                <dl class="ui-dl">
                    <div><dt>As of</dt><dd>{{ $opening->as_of_date->format('d M Y') }}</dd></div>
                    @foreach ($products as $product)
                        <div><dt>{{ $product->name }}</dt><dd>{{ number_format($openingPacks[$product->id] ?? 0) }} packs</dd></div>
                    @endforeach
                    <div><dt>Owed to G Water</dt><dd>GH¢ {{ \App\Services\GWater\Money::format($opening->owed_amount) }}</dd></div>
                    <div><dt>Recorded by</dt><dd>{{ $opening->creator?->full_name }}</dd></div>
                </dl>
                @if ($canVoid)
                    <div class="ui-form-actions">
                        <x-ui.button variant="danger" wire:click="openVoid">Void to correct it</x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        @else
            <x-ui.card title="Record the opening balance" class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.input label="As of" type="date" wire:model.defer="form.as_of_date" :max="today()->toDateString()" />
                    @foreach ($products as $product)
                        <x-ui.input :label="$product->name.' brought forward (packs)'" type="number" min="0" step="1" inputmode="numeric" wire:model.defer="packs.{{ $product->id }}" />
                    @endforeach
                    <x-ui.input label="Amount owed to G Water (GH¢)" wire:model.defer="form.owed_amount" inputmode="decimal" />
                    <x-ui.input label="Note (optional)" wire:model.defer="form.note" maxlength="255" />
                </div>
                @error('form.packs') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Record opening balance</x-ui.button>
                </div>
            </x-ui.card>
        @endif
    @endif

    @if ($voiding)
        <x-ui.modal title="Void the opening balance" description="Its packs leave stock again (this is refused if they have already been sold) and you can record it afresh." close="cancelVoid">
            <x-ui.textarea label="Reason" wire:model.defer="voidReason" rows="3" maxlength="500" />
            @error('void.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('void.packs') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelVoid">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoid">Void</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
