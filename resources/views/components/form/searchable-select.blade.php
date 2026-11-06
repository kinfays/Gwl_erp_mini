{{--
    Type-to-search select bound to a Livewire property.

    Static list (searched in the browser; fine for a few hundred options):
        <x-form.searchable-select label="Model" model="form.ict_asset_model_id" :options="$options" />
    Server search (the Livewire method is called with the typed text and returns the options, already scoped and limited):
        <x-form.searchable-select label="Asset" model="form.ict_asset_id" search="searchAssets" :initial-label="$label" :reset-key="$type" />

    An option is ['value' => id, 'label' => text, 'description' => small text, 'image' => url]. `reset-key` is anything
    that, when it changes, should throw away the cached search results (e.g. the chosen asset type). The Livewire side
    must still validate and re-resolve the posted id: the browser can send anything.
--}}
@props([
    'label' => null,
    'model',
    'options' => [],
    'search' => null,
    'initialLabel' => '',
    'resetKey' => '',
    'placeholder' => 'Search…',
    'emptyText' => 'No results',
    'hint' => null,
    'required' => false,
    'disabled' => false,
    'clearable' => true,
    'live' => false,
])

@php
    $inputId = $attributes->get('id') ?: 'ss-'.str_replace(['.', '_'], '-', $model);
    $listId = $inputId.'-listbox';
    $invalid = isset($errors) && $errors->has($model);

    $normalized = collect($options)
        ->map(fn ($option) => [
            'value' => (string) data_get($option, 'value', data_get($option, 'id')),
            'label' => (string) data_get($option, 'label', data_get($option, 'name')),
            'description' => (string) data_get($option, 'description', ''),
            'image' => data_get($option, 'image') ?: null,
        ])
        ->filter(fn (array $option) => $option['value'] !== '' && $option['label'] !== '')
        ->values()
        ->all();

    // The element is rebuilt when the list or the scope changes, so Alpine never keeps a stale list.
    $stateKey = md5(json_encode([$normalized, $resetKey, (bool) $disabled]));
@endphp

<div {{ $attributes->except(['id', 'wire:model'])->class(['ui-field', 'erp-combobox', 'searchable-select']) }}
    wire:key="{{ $inputId }}-{{ $stateKey }}"
    x-data="searchableSelect({
        selected: @entangle($model){{ $live ? '.live' : '' }},
        options: @js($normalized),
        method: @js($search),
        initialLabel: @js((string) $initialLabel),
        listId: @js($listId),
    })"
    x-on:click.outside="close()"
>
    @if ($label)
        <label class="ui-label" for="{{ $inputId }}">{{ $label }}@if ($required)<span class="ui-req" aria-hidden="true">*</span>@endif</label>
    @endif

    <div class="searchable-select-row">
        <img x-show="current && current.image" x-cloak x-bind:src="current && current.image" alt="" class="model-select-thumb">

        <div class="erp-combobox-control">
            <input
                id="{{ $inputId }}"
                x-ref="input"
                type="text"
                @class(['form-input', 'ui-input', 'erp-combobox-input', 'is-invalid' => $invalid])
                x-model="query"
                placeholder="{{ $placeholder }}"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="{{ $listId }}"
                aria-label="{{ $label ?: $placeholder }}"
                @if ($required) aria-required="true" @endif
                @if ($invalid) aria-invalid="true" @endif
                @disabled($disabled)
                x-bind:aria-expanded="open.toString()"
                x-bind:aria-activedescendant="open && activeIndex >= 0 ? '{{ $listId }}-' + activeIndex : null"
                x-on:input="inputChanged()"
                x-on:focus="openMenu()"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter="if (open) { $event.preventDefault(); chooseActive(); }"
                x-on:keydown.escape="if (open) { $event.stopPropagation(); close(); }"
                x-on:keydown.tab="close()"
            >

            @unless ($disabled)
                <div class="erp-combobox-actions">
                    @if ($clearable)
                        <button type="button" class="erp-combobox-clear" x-show="hasValue() || query" x-cloak
                            x-on:click="clear()" aria-label="Clear {{ $label ?: 'selection' }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    @endif
                    <button type="button" class="erp-combobox-toggle" x-bind:class="{ 'open': open }" tabindex="-1"
                        x-on:click="open ? close() : ($refs.input.focus(), openMenu())"
                        aria-label="Show {{ $label ?: 'options' }}"></button>
                </div>
            @endunless
        </div>
    </div>

    <ul id="{{ $listId }}" class="erp-combobox-menu" x-show="open" x-cloak x-transition.opacity role="listbox">
        <template x-for="(option, index) in visible" x-bind:key="option.value">
            <li
                x-bind:id="'{{ $listId }}-' + index"
                class="erp-combobox-option"
                x-bind:class="{ 'active': index === activeIndex, 'selected': current && String(option.value) === String(current.value) }"
                role="option"
                x-bind:aria-selected="current && String(option.value) === String(current.value)"
                x-on:mouseenter="activeIndex = index"
                x-on:mousedown.prevent="choose(option)"
            >
                <span x-text="option.label"></span>
                <small x-show="option.description" x-text="option.description"></small>
            </li>
        </template>

        <li class="erp-combobox-empty" x-show="loading && visible.length === 0">Searching…</li>
        <li class="erp-combobox-empty" x-show="! loading && visible.length === 0">{{ $emptyText }}</li>
    </ul>

    @if ($hint)
        <p class="ui-hint" id="{{ $inputId }}-hint">{{ $hint }}</p>
    @endif

    @error($model)
        <p class="ui-error" id="{{ $inputId }}-error">
            <x-ui.icon name="circle-alert" class="icon-sm" />
            <span>{{ $message }}</span>
        </p>
    @enderror
</div>
