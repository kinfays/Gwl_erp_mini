{{--
    KPI tile: tinted icon chip, label, value, optional meta line and delta pill, optional sparkline.
    Only pass `delta` / `sparkline` when a service computed them from real data. `delta-tone` is
    neutral unless the metric has a known good direction ("good" | "bad").
    Docs: docs/11-ui-components.md#stat-tile
--}}
@props([
    'label',
    'value',
    'icon' => null,
    'tone' => 'primary',
    'meta' => null,
    'delta' => null,
    'deltaTone' => 'neutral',
    'deltaDirection' => null,
    'href' => null,
    'sparkline' => null,
])

@php
    $tag = $href ? 'a' : 'div';
    $points = null;

    if (is_iterable($sparkline)) {
        $series = collect($sparkline)->map(fn ($point) => (float) $point)->values();

        if ($series->count() > 1) {
            $min = $series->min();
            $range = max($series->max() - $min, 1e-9);
            $step = 100 / ($series->count() - 1);
            $points = $series->map(fn ($point, $index) => round($index * $step, 2).','.round(26 - (($point - $min) / $range) * 24, 2))->join(' ');
        }
    }

    $deltaIcon = match ($deltaDirection) {
        'up' => 'trending-up',
        'down' => 'trending-down',
        default => null,
    };
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['ui-stat', 'ui-stat-link' => $href, 'has-spark' => $points]) }}>
    <div class="ui-stat-top">
        @if ($icon)
            <span class="ui-chip ui-chip-{{ $tone }}" aria-hidden="true"><x-ui.icon :name="$icon" /></span>
        @endif
        <span class="ui-stat-label">{{ $label }}</span>
    </div>

    <div class="ui-stat-value">{{ $value }}</div>

    @if ($meta || $delta || $slot->isNotEmpty())
        <div class="ui-stat-meta">
            @if ($delta)
                <span class="ui-delta ui-delta-{{ $deltaTone }}">
                    @if ($deltaIcon)
                        <x-ui.icon :name="$deltaIcon" class="icon-sm" />
                    @endif
                    {{ $delta }}
                </span>
            @endif
            @if ($meta)
                <span>{{ $meta }}</span>
            @endif
            {{ $slot }}
        </div>
    @endif

    @if ($points)
        <svg class="ui-stat-spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <polyline points="{{ $points }}" />
        </svg>
    @endif
</{{ $tag }}>
