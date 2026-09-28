{{--
    Checkbox row with label and optional description; the whole row is the target and it tints when
    checked. Attributes (wire:click, wire:model, value, :checked…) pass to the checkbox.
    Docs: docs/11-ui-components.md#form-controls
--}}
@props([
    'label',
    'description' => null,
    'id' => null,
])

@php
    $inputId = $id ?: 'check-'.\Illuminate\Support\Str::slug($label.'-'.($attributes->get('value') ?? ''));
@endphp

<label class="ui-check" for="{{ $inputId }}">
    <input type="checkbox" id="{{ $inputId }}" {{ $attributes }}>
    <span class="ui-check-text">
        <span class="ui-check-label">{{ $label }}</span>
        @if ($description)
            <span class="ui-check-desc">{{ $description }}</span>
        @endif
    </span>
</label>
