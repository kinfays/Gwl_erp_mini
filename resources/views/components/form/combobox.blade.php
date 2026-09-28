<!-- component is inspired by the Combobox component from Tailwind UI, used for all search selects in the app-->

@props([
    'label',
    'model',
    'options' => [],
    'placeholder' => 'Select an option',
    'emptyText' => 'No matching results',
    'required' => false,
])

@php
    $inputId = $attributes->get('id') ?: 'combobox-' . str_replace(['.', '_'], '-', $model);
    $listId = $inputId . '-listbox';

    $normalizedOptions = collect($options)
        ->map(function ($option) {
            $value = data_get($option, 'value', data_get($option, 'id'));
            $label = data_get($option, 'label', data_get($option, 'name'));
            $description = data_get($option, 'description', data_get($option, 'meta', ''));

            return [
                'value' => (string) $value,
                'label' => (string) $label,
                'description' => (string) $description,
            ];
        })
        ->filter(fn (array $option) => $option['value'] !== '' && $option['label'] !== '')
        ->values()
        ->all();
@endphp

<div
    {{ $attributes->except('id')->class('form-field erp-combobox') }}
    x-data="{
        open: false,
        query: '',
        selected: @entangle($model).live,
        activeIndex: -1,
        options: @js($normalizedOptions),
        init() {
            this.query = this.selectedLabel();

            this.$watch('selected', () => {
                if (! this.open) {
                    this.query = this.selectedLabel();
                }
            });
        },
        selectedLabel() {
            const selected = this.options.find((option) => String(option.value) === String(this.selected ?? ''));

            return selected ? selected.label : '';
        },
        get filteredOptions() {
            const needle = this.query.trim().toLowerCase();
            const matches = needle
                ? this.options.filter((option) => `${option.label} ${option.description}`.toLowerCase().includes(needle))
                : this.options;

            return matches.slice(0, 50);
        },
        inputChanged() {
            this.open = true;

            if (this.query !== this.selectedLabel()) {
                this.selected = '';
            }

            this.activeIndex = this.filteredOptions.length ? 0 : -1;
        },
        choose(option) {
            this.selected = option.value;
            this.query = option.label;
            this.open = false;
            this.activeIndex = -1;
        },
        clear() {
            this.selected = '';
            this.query = '';
            this.open = false;
            this.activeIndex = -1;
            this.$refs.input.focus();
        },
        move(step) {
            const count = this.filteredOptions.length;

            if (! count) {
                this.activeIndex = -1;
                return;
            }

            this.open = true;
            this.activeIndex = (this.activeIndex + step + count) % count;

            this.$nextTick(() => {
                document.getElementById('{{ $listId }}-' + this.activeIndex)?.scrollIntoView({ block: 'nearest' });
            });
        },
        chooseActive() {
            if (! this.open) {
                this.open = true;
                this.activeIndex = this.filteredOptions.length ? 0 : -1;
                return;
            }

            const option = this.filteredOptions[this.activeIndex];

            if (option) {
                this.choose(option);
            }
        },
    }"
    x-on:click.outside="open = false"
>
    <label for="{{ $inputId }}" class="form-label">{{ $label }}@if ($required)<span class="ui-req" aria-hidden="true">*</span>@endif</label>

    <div class="erp-combobox-control">
        <input
            id="{{ $inputId }}"
            x-ref="input"
            type="text"
            class="form-input erp-combobox-input"
            x-model="query"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            aria-controls="{{ $listId }}"
            @if ($required) aria-required="true" @endif
            x-bind:aria-expanded="open.toString()"
            x-bind:aria-activedescendant="open && activeIndex >= 0 ? '{{ $listId }}-' + activeIndex : null"
            x-on:input="inputChanged()"
            x-on:focus="open = true; activeIndex = filteredOptions.length ? 0 : -1"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.enter="if (open) { $event.preventDefault(); chooseActive(); }"
            x-on:keydown.escape.prevent="open = false"
            x-on:keydown.tab="open = false"
        >

        <div class="erp-combobox-actions">
            <button
                type="button"
                class="erp-combobox-clear"
                x-show="query"
                x-cloak
                x-on:click="clear()"
                aria-label="Clear {{ $label }}"
            >
                <span aria-hidden="true">&times;</span>
            </button>

            <button
                type="button"
                class="erp-combobox-toggle"
                x-bind:class="{ 'open': open }"
                x-on:click="open = ! open; if (open) { $refs.input.focus(); activeIndex = filteredOptions.length ? 0 : -1; }"
                aria-label="Show {{ $label }} options"
                tabindex="-1"
            ></button>
        </div>
    </div>

    <ul
        id="{{ $listId }}"
        class="erp-combobox-menu"
        x-show="open"
        x-transition
        x-cloak
        role="listbox"
    >
        <template x-for="(option, index) in filteredOptions" x-bind:key="option.value">
            <li
                x-bind:id="'{{ $listId }}-' + index"
                class="erp-combobox-option"
                x-bind:class="{ 'active': index === activeIndex, 'selected': String(option.value) === String(selected ?? '') }"
                role="option"
                x-bind:aria-selected="String(option.value) === String(selected ?? '')"
                x-on:mouseenter="activeIndex = index"
                x-on:mousedown.prevent="choose(option)"
            >
                <span x-text="option.label"></span>
                <small x-show="option.description" x-text="option.description"></small>
            </li>
        </template>

        <li class="erp-combobox-empty" x-show="filteredOptions.length === 0">{{ $emptyText }}</li>
    </ul>

    @error($model)
        <span class="form-label form-error">{{ $message }}</span>
    @enderror
</div>
