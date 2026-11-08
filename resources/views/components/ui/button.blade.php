{{--
    Button / link button. Variants: primary · secondary (default) · ghost · danger · danger-solid · warn.
    Sizes: sm · md · lg. `href` renders an <a>. `loading="method"` disables the button and shows the small
    bouncing drops (<x-loading.drops>) centred over it while that Livewire action runs; the label and icon
    are hidden meanwhile (not removed), so the button keeps its width. All other attributes (wire:click, x-on:…) pass through.
    Docs: docs/11-ui-components.md#button
--}}
@props([
    'variant' => 'secondary',
    'size' => 'md',
    'href' => null,
    'icon' => null,
    'file' => null,
    'iconTrailing' => null,
    'type' => 'button',
    'loading' => null,
])

@php
    $classes = [
        'btn',
        'btn-'.$variant => $variant !== 'secondary',
        'btn-sm' => $size === 'sm',
        'btn-lg' => $size === 'lg',
        'btn-icon' => ($icon || $file) && $slot->isEmpty(),
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($file)
            <x-ui.file-icon :type="$file" />
        @elseif ($icon)
            <x-ui.icon :name="$icon" />
        @endif
        {{ $slot }}
        @if ($iconTrailing)
            <x-ui.icon :name="$iconTrailing" />
        @endif
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class($classes) }}
        @if ($loading) wire:loading.attr="disabled" wire:loading.class="is-loading" wire:target="{{ $loading }}" @endif
    >
        @if ($loading)
            <x-loading.drops size="sm" label="Working" class="wl-in-btn" wire:loading.inline-flex wire:target="{{ $loading }}" />
        @endif
        {{-- Icons stay in the layout while loading (hidden by .is-loading in app.css), so the width never changes. --}}
        @if ($file)
            <x-ui.file-icon :type="$file" />
        @elseif ($icon)
            <x-ui.icon :name="$icon" />
        @endif
        {{ $slot }}
        @if ($iconTrailing)
            <x-ui.icon :name="$iconTrailing" />
        @endif
    </button>
@endif
