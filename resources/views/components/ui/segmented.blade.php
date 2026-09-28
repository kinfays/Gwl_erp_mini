{{--
    Segmented control for a small set of mutually exclusive options (e.g. a report range). Built on
    native radios, so arrow keys work and the choice is announced. Bind with wire:model(.live).
        <x-ui.segmented label="Range" wire:model.live="range" :options="['7d' => '7 days', '30d' => '30 days']" />
    Docs: docs/11-ui-components.md#segmented
--}}
@props([
    'options' => [],
    'label' => 'Options',
    'name' => null,
    'value' => null,
])

@php
    $model = $attributes->wire('model');
    $group = $name ?: 'seg-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $model->value() ?: $label));
@endphp

<fieldset {{ $attributes->whereDoesntStartWith('wire:model')->class(['ui-segmented']) }}>
    <legend class="sr-only-text">{{ $label }}</legend>
    @foreach ($options as $optionValue => $optionLabel)
        <label class="ui-segmented-option">
            <input
                type="radio"
                name="{{ $group }}"
                value="{{ $optionValue }}"
                {{ $model }}
                @checked(! $model->value() && (string) $value === (string) $optionValue)
            >
            <span>{{ $optionLabel }}</span>
        </label>
    @endforeach
</fieldset>
