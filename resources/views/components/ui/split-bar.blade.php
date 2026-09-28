{{--
    Part-to-whole bar for 2–4 parts, with every part labelled in text (share and count). Prefer it
    to a chart when there are only a couple of numbers, e.g. a male/female split.
        <x-ui.split-bar label="Gender split of approved requests"
            :parts="[['label' => 'Male', 'value' => $male], ['label' => 'Female', 'value' => $female, 'color' => 'series-5']]" />
    Docs: docs/11-ui-components.md#chart
--}}
@props([
    'label',
    'parts' => [],
    'emptyText' => 'No data yet.',
])

@php
    $items = collect($parts)->values()->map(fn (array $part, int $index) => [
        'label' => $part['label'] ?? '',
        'value' => (float) ($part['value'] ?? 0),
        'color' => $part['color'] ?? 'series-'.(($index % 8) + 1),
    ]);
    $total = $items->sum('value');
@endphp

<figure {{ $attributes->class(['ui-split']) }} aria-label="{{ $label }}">
    @if ($total > 0)
        <div class="ui-split-bar" aria-hidden="true">
            @foreach ($items as $item)
                @if ($item['value'] > 0)
                    <span style="width: {{ round($item['value'] / $total * 100, 2) }}%; background: var(--color-{{ $item['color'] }})"></span>
                @endif
            @endforeach
        </div>
        <figcaption class="ui-split-legend">
            @foreach ($items as $item)
                <span>
                    <i style="background: var(--color-{{ $item['color'] }})" aria-hidden="true"></i>
                    {{ $item['label'] }}
                    <b>{{ round($item['value'] / $total * 100) }}%</b>
                    <small>({{ number_format($item['value']) }})</small>
                </span>
            @endforeach
        </figcaption>
    @else
        <figcaption class="ui-hint">{{ $emptyText }}</figcaption>
    @endif
</figure>
