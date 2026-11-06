{{-- Inline panel (not a second modal) so what is typed in the form stays exactly as it is. Saving selects the new model. Needs CreatesModelInline on the component. --}}
@props(['can' => false, 'open' => false, 'assetType' => '', 'types' => [], 'manufacturers' => []])

@if ($can && $open)
    <div {{ $attributes->class(['span-2', 'ui-subpanel']) }} role="group" aria-label="Add new model">
        <p class="ui-hint"><strong>Add new model</strong>: it joins the shared model catalogue and is selected for this device.</p>
        <div class="ui-form-grid">
            <x-ui.input label="Model Name" wire:model.defer="newModel.name" required />
            @if (filled($assetType))
                <x-ui.input label="Type" id="f-new-model-type" :value="$types[$assetType] ?? $assetType" disabled :error="false" />
            @else
                <x-ui.select label="Type" wire:model.defer="newModel.category" required>
                    <option value="">Select type</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            @endif
            <x-ui.select label="Manufacturer" wire:model.defer="newModel.manufacturer_id">
                <option value="">None</option>
                @foreach ($manufacturers as $manufacturer)
                    <option value="{{ $manufacturer->id }}">{{ $manufacturer->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="ui-subpanel-actions">
            <button type="button" wire:click="closeNewModel" class="btn btn-secondary btn-sm">Cancel</button>
            <button type="button" wire:click="saveNewModel" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveNewModel">Save model</button>
        </div>
    </div>
@endif
