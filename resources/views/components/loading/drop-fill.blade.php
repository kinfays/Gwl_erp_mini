{{--
    B: Filling drop. Large, page or panel level loading: a veil over a table, a whole-page wait during an
    import or export, the centre of a "loading" empty state.
    Props: size (sm | md | lg), label (read by screen readers; default "Loading"), overlay (true: a fixed
    full-screen veil that also shows the label as a caption; pair it with `wire:loading.flex wire:target="…"`).
    Each instance gets its own clipPath id, because several can be on one page.
    Styles: app.css, "Water loaders". Docs: docs/11-ui-components.md#loaders
--}}
@props([
    'size' => 'md',
    'label' => 'Loading',
    'overlay' => false,
])

@php
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
    $clipId = 'wl-clip-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));
    // One wave period is 32 units; the group is wider than the 64-unit drop so sliding by one period never shows an edge.
    $wave = 'M0 4Q8 -2 16 4T32 4T48 4T64 4T80 4T96 4T112 4T128 4V100H0Z';
    // The lagoon wave is the same shape half a period out of step, so the two never move as one.
    $waveBack = 'M-16 4Q-8 -2 0 4T16 4T32 4T48 4T64 4T80 4T96 4T112 4V100H-16Z';
@endphp

<div {{ $attributes->class(['wl-fill', 'wl-fill-'.$size, 'wl-overlay' => $overlay])->merge(['role' => 'status', 'aria-live' => 'polite']) }}>
    <svg viewBox="0 0 64 80" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <clipPath id="{{ $clipId }}">
                <path d="M32 4C32 4 8 34 8 54a24 24 0 0 0 48 0C56 34 32 4 32 4Z"/>
            </clipPath>
        </defs>
        <g clip-path="url(#{{ $clipId }})">
            <g class="wl-fill-rise">
                <path class="wl-fill-back" d="{{ $waveBack }}"/>
                <path class="wl-fill-front" d="{{ $wave }}"/>
            </g>
        </g>
        <path class="wl-fill-outline" d="M32 4C32 4 8 34 8 54a24 24 0 0 0 48 0C56 34 32 4 32 4Z"/>
    </svg>
    <span @class(['wl-caption' => $overlay, 'sr-only-text' => ! $overlay])>{{ $label }}</span>
</div>
