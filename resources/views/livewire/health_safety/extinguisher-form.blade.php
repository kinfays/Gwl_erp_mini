<div>
    <x-ui.page-header :title="$editing ? 'Edit extinguisher' : 'Add an extinguisher'" description="Every date you enter here is watched: expiry, next service and next hydrostatic test.">
        <x-slot:actions>
            <x-ui.button :href="$editing ? route('health_safety.extinguishers.show', $extinguisherId) : route('health_safety.extinguishers.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="ui-stack" novalidate>
        <x-ui.card title="The extinguisher" class="dash-row">
            <div class="ui-form-grid">
                <x-ui.select label="Type" wire:model="extinguisherType" required>
                    <option value="">Select type</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Asset code" wire:model="assetCode" error="asset_code" hint="Leave blank to generate one (FE-region-number), or type the tag already on the unit." />
                <x-ui.input label="Serial number" wire:model="serialNumber" />
                <x-ui.input label="Capacity" wire:model="capacity" hint="For example 9 L or 6 kg." />
                <x-ui.input label="Manufacturer" wire:model="manufacturer" />
                <x-ui.input type="date" label="Manufactured on" wire:model="manufacturedOn" />
            </div>
        </x-ui.card>

        <x-ui.card title="Location" class="dash-row">
            @include('livewire.health_safety.partials.equipment-location')
        </x-ui.card>

        <x-ui.card title="Dates" description="Expiry, service and hydrostatic test dates drive the state. Typing a last service or test date fills the next one in for you; change it if it is wrong." class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input type="date" label="Expiry date" wire:model="expiryDate" hint="The date printed on the unit or its latest certificate." />
                <div></div>
                <x-ui.input type="date" label="Last serviced on" wire:model.live="lastServicedOn" />
                <x-ui.input type="date" label="Next service due" wire:model="nextServiceDue" hint="Pre-filled {{ $serviceMonths }} months after the last service." />
                <x-ui.input type="date" label="Last hydrostatic test" wire:model.live="lastHydroTestOn" />
                <x-ui.input type="date" label="Next hydrostatic test due" wire:model="nextHydroTestDue" hint="Pre-filled {{ $hydroYears }} years after the last test. The real interval depends on the type: confirm it." />
                <div class="span-2"><x-ui.textarea label="Notes" wire:model="notes" rows="2" /></div>
            </div>
        </x-ui.card>

        <div class="ui-form-actions">
            <x-ui.button type="submit" variant="primary" loading="save">Save extinguisher</x-ui.button>
        </div>
    </form>
</div>
