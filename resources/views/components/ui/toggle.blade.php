{{--
    On/off switch (a native checkbox with role="switch"). Attributes such as wire:model or
    wire:click pass to the checkbox. Docs: docs/11-ui-components.md#form-controls
--}}
@props([
    'label',
    'description' => null,
    'id' => null,
])

@php
    $model = $attributes->wire('model')->value();
    $inputId = $id ?: 'toggle-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $model ?: $label));
@endphp

<label class="ui-toggle" for="{{ $inputId }}">
    <input type="checkbox" role="switch" id="{{ $inputId }}" {{ $attributes->class(['ui-toggle-input']) }}>
    <span class="ui-toggle-track" aria-hidden="true"><span class="ui-toggle-thumb"></span></span>
    <span class="ui-toggle-text">
        <span class="ui-toggle-label">{{ $label }}</span>
        @if ($description)
            <span class="ui-toggle-desc">{{ $description }}</span>
        @endif
    </span>
</label>
