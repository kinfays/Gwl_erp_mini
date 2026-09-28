{{--
    Multi-line text input. Attributes (wire:model, rows, placeholder…) pass through.
    Docs: docs/11-ui-components.md#form-controls
--}}
@props([
    'label' => null,
    'hint' => null,
    'error' => null,
    'id' => null,
    'required' => false,
])

@php
    $model = $attributes->wire('model')->value();
    $fieldName = $attributes->get('name') ?: $model;
    $inputId = $id ?: 'f-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $fieldName ?: ($label ?: 'text')));
    $errorKey = $error === false ? null : ($error ?: $fieldName);
    $invalid = $errorKey && isset($errors) && $errors->has($errorKey);
    $describedBy = trim(($hint ? $inputId.'-hint ' : '').($invalid ? $inputId.'-error' : ''));
@endphp

@if ($label)
    <x-ui.field :label="$label" :for="$inputId" :hint="$hint" :error="$errorKey" :required="$required">
        <textarea
            id="{{ $inputId }}"
            {{ $attributes->merge(['rows' => 3])->class(['form-input', 'ui-input', 'is-invalid' => $invalid]) }}
            @if ($required) aria-required="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($invalid) aria-invalid="true" @endif
        >{{ $slot }}</textarea>
    </x-ui.field>
@else
    <textarea
        @if ($id) id="{{ $id }}" @endif
        {{ $attributes->merge(['rows' => 3])->class(['form-input', 'ui-input', 'is-invalid' => $invalid]) }}
        @if ($invalid) aria-invalid="true" @endif
    >{{ $slot }}</textarea>
@endif
