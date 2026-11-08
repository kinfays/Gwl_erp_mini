{{--
    D: Bouncing drops. Small, inline loading: inside a button, next to "Uploading…", in a card corner.
    Props: size (sm | md | lg), label (read by screen readers; default "Loading"), decorative (true when
    a visible text next to it already says what is happening: the element is then hidden from assistive tech).
    Under wire:loading use `wire:loading.inline-flex` so Livewire keeps the flex layout. Styles: app.css, "Water loaders".
    Docs: docs/11-ui-components.md#loaders
--}}
@props([
    'size' => 'md',
    'label' => 'Loading',
    'decorative' => false,
])

@php
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
@endphp

<span
    {{ $attributes->class(['wl-drops', 'wl-drops-'.$size]) }}
    @if ($decorative)
        aria-hidden="true"
    @else
        role="status" aria-live="polite"
    @endif
>
    @unless ($decorative)
        <span class="sr-only-text">{{ $label }}</span>
    @endunless
    <span class="wl-drop" aria-hidden="true"></span>
    <span class="wl-drop" aria-hidden="true"></span>
    <span class="wl-drop" aria-hidden="true"></span>
</span>
