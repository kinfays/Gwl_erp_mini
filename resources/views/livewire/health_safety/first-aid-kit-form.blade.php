<div>
    <x-ui.page-header :title="$editing ? 'Edit first aid kit' : 'Add a first aid kit'" description="A new kit is filled with the usual contents for its type; you can adjust them afterwards.">
        <x-slot:actions>
            <x-ui.button :href="$editing ? route('health_safety.kits.show', $kitId) : route('health_safety.kits.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $editing && ! $hasTemplates)
        <x-ui.alert tone="info" class="dash-row">
            No kit templates exist yet, so this kit will be created empty. <a href="{{ route('health_safety.kit-templates') }}">Set up the kit templates</a> first if you want it filled in.
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="ui-stack" novalidate>
        <x-ui.card title="The kit" class="dash-row">
            <div class="ui-form-grid">
                <x-ui.select label="Kit type" wire:model="kitType" required>
                    <option value="">Select type</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Asset code" wire:model="assetCode" error="asset_code" hint="Leave blank to generate one (FAK-region-number), or type the tag already on the kit." />
                @unless ($editing)
                    <x-ui.input type="date" label="Last checked on (optional)" wire:model="lastCheckedOn" hint="If it was checked recently, so it is not flagged as overdue at once." />
                @endunless
                <div class="span-2"><x-ui.textarea label="Notes" wire:model="notes" rows="2" /></div>
            </div>
        </x-ui.card>

        <x-ui.card title="Location" class="dash-row">
            @include('livewire.health_safety.partials.equipment-location')
        </x-ui.card>

        <div class="ui-form-actions">
            <x-ui.button type="submit" variant="primary" loading="save">Save kit</x-ui.button>
        </div>
    </form>
</div>
