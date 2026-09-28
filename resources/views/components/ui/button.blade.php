{{--
    Button / link button. Variants: primary · secondary (default) · ghost · danger · danger-solid · warn.
    Sizes: sm · md · lg. `href` renders an <a>. `loading="method"` disables the button and shows a
    spinner while that Livewire action runs. All other attributes (wire:click, x-on:…) pass through.
    Docs: docs/11-ui-components.md#button
--}}
@props([
    'variant' => 'secondary',
    'size' => 'md',
    'href' => null,
    'icon' => null,
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
        'btn-icon' => $icon && $slot->isEmpty(),
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)
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
        @if ($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif
    >
        @if ($loading)
            <x-ui.icon name="loader-circle" class="btn-spinner" wire:loading wire:target="{{ $loading }}" />
        @endif
        @if ($icon && $loading)
            <x-ui.icon :name="$icon" wire:loading.remove wire:target="{{ $loading }}" />
        @elseif ($icon)
            <x-ui.icon :name="$icon" />
        @endif
        {{ $slot }}
        @if ($iconTrailing)
            <x-ui.icon :name="$iconTrailing" />
        @endif
    </button>
@endif
