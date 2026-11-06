{{--
    A filter that takes several values: looks like a <select>, opens a checklist that stays open while you tick.
    The Livewire component supplies two actions, one that toggles a value and one that clears the choice.
        <x-ui.multi-select label="Asset type" all-label="All types" :options="['PC' => 'Desktop', ...]"
            :selected="$selectedTypes" toggle="toggleAssetType" clear="clearAssetTypes" />
    Nothing selected means "all".
--}}
@props([
    'options' => [],
    'selected' => [],
    'label',
    'allLabel' => 'All',
    'toggle',
    'clear',
])

@php
    $count = count($selected);
    $summary = match (true) {
        $count === 0 => $allLabel,
        $count === 1 => $options[$selected[0]] ?? $selected[0],
        default => $count.' selected',
    };
@endphp

<div class="ui-multiselect" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <button type="button" class="form-input ui-multiselect-button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="true" aria-label="{{ $label }}">
        <span>{{ $summary }}</span>
        <x-ui.icon name="chevron-down" class="icon-sm" />
    </button>

    <div class="ui-multiselect-panel" x-show="open" x-cloak role="group" aria-label="{{ $label }}">
        <label class="ui-column-option">
            <input type="checkbox" @checked($count === 0) wire:click="{{ $clear }}">
            <span>{{ $allLabel }}</span>
        </label>

        @foreach ($options as $value => $optionLabel)
            <label class="ui-column-option" wire:key="multi-{{ $toggle }}-{{ $value }}">
                <input type="checkbox" @checked(in_array((string) $value, $selected, true)) wire:click="{{ $toggle }}('{{ $value }}')">
                <span>{{ $optionLabel }}</span>
            </label>
        @endforeach
    </div>
</div>
