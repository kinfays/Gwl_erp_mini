{{--
    Ranked horizontal bars in plain HTML for a handful of categories — no chart library. Each row
    shows the label, the number written out and a bar scaled to the largest value (one hue: this is
    magnitude, not identity).
        <x-ui.bar-list label="Vehicles by department" :items="[
            ['label' => 'Transport', 'value' => 12],
            ['label' => 'Finance', 'value' => 3, 'display' => '3 vehicles'],
        ]" />
    Use <x-ui.chart> instead when there is a time axis or more than ~10 rows.
    Docs: docs/11-ui-components.md#bar-list
--}}
@props([
    'items' => [],
    'label',
    'empty' => 'Nothing to show yet.',
    'emptyIcon' => 'chart-column',
])

@php
    $rows = collect($items)->map(fn ($item) => [
        'label' => (string) data_get($item, 'label'),
        'value' => (float) data_get($item, 'value', 0),
        'display' => data_get($item, 'display'),
    ])->values();
    $max = max((float) $rows->max('value'), 0.0);
    $format = fn (float $number) => rtrim(rtrim(number_format($number, 2), '0'), '.');
@endphp

@if ($rows->isEmpty())
    <x-ui.empty-state :icon="$emptyIcon" :title="$empty" />
@else
    <ol {{ $attributes->class(['ui-bar-list']) }} aria-label="{{ $label }}">
        @foreach ($rows as $row)
            <li>
                <span class="ui-bar-label">{{ $row['label'] }}</span>
                <span class="ui-bar-value">{{ $row['display'] ?? $format($row['value']) }}</span>
                <span class="ui-bar-track" aria-hidden="true">
                    <span style="width: {{ $max > 0 ? round($row['value'] / $max * 100, 1) : 0 }}%"></span>
                </span>
            </li>
        @endforeach
    </ol>
@endif
