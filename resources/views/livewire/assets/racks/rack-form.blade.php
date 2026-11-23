<div>
    <x-ui.page-header :title="$editing ? 'Edit Rack' : 'Add Rack'" description="A network cabinet or server rack. Devices are mounted in it from the Assets and Network forms.">
        <x-slot:actions>
            <a href="{{ $editing ? route('assets.racks.show', $rackId) : route('assets.racks') }}" class="btn btn-ghost">Cancel</a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :title="$editing ? 'Rack details' : 'New rack'">
        <div class="ui-form-grid">
            <x-ui.input label="Rack Name" wire:model.defer="form.name" placeholder="e.g. Server Room Rack A" required />
            <x-ui.input label="Code" wire:model.defer="form.code" class="mono" placeholder="Optional, e.g. HO-RK-01" maxlength="60" />

            <x-ui.select label="District" wire:model.defer="form.district_id" required>
                <option value="">Select district</option>
                @foreach ($districts as $district)
                    <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Location" wire:model.defer="form.location" placeholder="Room or building, e.g. Server Room, 2nd Floor" />

            <x-ui.input label="Height (U)" type="number" min="1" max="{{ App\Models\IctRack::MAX_UNITS }}" inputmode="numeric" wire:model.defer="form.total_units" hint="Usable rack units. 42 is a full-height cabinet." required />
            <x-ui.select label="Status" wire:model.defer="form.status" required>
                @foreach ($statuses as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </x-ui.select>

            <x-assets.actor-region :region="$region" label="Region" />

            <div class="span-2">
                <x-ui.textarea label="Notes" wire:model.defer="form.notes" rows="3" />
            </div>
        </div>

        <div class="ui-form-actions" style="margin-top: 24px;">
            <a href="{{ $editing ? route('assets.racks.show', $rackId) : route('assets.racks') }}" class="btn btn-secondary">Cancel</a>
            <button type="button" wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                {{ $editing ? 'Update Rack' : 'Create Rack' }}
            </button>
        </div>
    </x-ui.card>
</div>
