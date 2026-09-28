{{--
    Label + control + hint + validation message. <x-ui.input>, <x-ui.select> and <x-ui.textarea>
    use it automatically when given a `label`; wrap custom controls in it directly.
    `required` only adds the visual marker (no native validation is switched on).
    Docs: docs/11-ui-components.md#form-controls
--}}
@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
])

<div {{ $attributes->class(['ui-field']) }}>
    @if ($label)
        <label class="ui-label" @if ($for) for="{{ $for }}" @endif>
            {{ $label }}@if ($required)<span class="ui-req" aria-hidden="true">*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint)
        <p class="ui-hint" @if ($for) id="{{ $for }}-hint" @endif>{{ $hint }}</p>
    @endif

    @if ($error && isset($errors))
        @error($error)
            <p class="ui-error" @if ($for) id="{{ $for }}-error" @endif>
                <x-ui.icon name="circle-alert" class="icon-sm" />
                <span>{{ $message }}</span>
            </p>
        @enderror
    @endif
</div>
