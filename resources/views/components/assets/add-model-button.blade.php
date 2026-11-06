{{-- "Add new model" link shown under the Model picker (Assets, Phones, Network forms). Needs CreatesModelInline on the component. --}}
@props(['can' => false, 'open' => false])

@if ($can && ! $open)
    <button type="button" wire:click="openNewModel" class="btn btn-ghost btn-sm" style="margin-top: 4px">
        <x-ui.icon name="plus" />
        Add new model
    </button>
@endif
