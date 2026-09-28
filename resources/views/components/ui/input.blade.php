{{--
    Text-like input. Every attribute (wire:model, name, placeholder, x-on…) passes to the <input>.
    With `label` it renders inside <x-ui.field>; the validation key defaults to the wire:model / name.
    Docs: docs/11-ui-components.md#form-controls
--}}
@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'id' => null,
    'type' => 'text',
    'required' => false,
    'icon' => null,
])

@php
    $model = $attributes->wire('model')->value();
    $fieldName = $attributes->get('name') ?: $model;
    $inputId = $id ?: 'f-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $fieldName ?: ($label ?: 'field')));
    $errorKey = $error === false ? null : ($error ?: $fieldName);
    $invalid = $errorKey && isset($errors) && $errors->has($errorKey);
    $describedBy = trim(($hint ? $inputId.'-hint ' : '').($invalid ? $inputId.'-error' : ''));
@endphp

@if ($label)
    <x-ui.field :label="$label" :for="$inputId" :hint="$hint" :error="$errorKey" :required="$required">
        <div class="ui-input-wrap">
            @if ($icon)
                <x-ui.icon :name="$icon" class="ui-input-icon" />
            @endif
            <input
                id="{{ $inputId }}"
                type="{{ $type }}"
                {{ $attributes->class(['form-input', 'ui-input', 'has-icon' => $icon, 'is-invalid' => $invalid]) }}
                @if ($required) aria-required="true" @endif
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @if ($invalid) aria-invalid="true" @endif
            >
        </div>
    </x-ui.field>
@else
    <div class="ui-input-wrap">
        @if ($icon)
            <x-ui.icon :name="$icon" class="ui-input-icon" />
        @endif
        <input
            @if ($id) id="{{ $id }}" @endif
            type="{{ $type }}"
            {{ $attributes->class(['form-input', 'ui-input', 'has-icon' => $icon, 'is-invalid' => $invalid]) }}
            @if ($invalid) aria-invalid="true" @endif
        >
    </div>
@endif
