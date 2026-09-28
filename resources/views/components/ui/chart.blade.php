{{--
    Chart.js chart drawn from the design tokens (see resources/js/charts.js). The Livewire view that
    uses it must load the library once with
        @assets @vite('resources/js/charts.js') @endassets

    Static data:
        <x-ui.chart type="hbar" label="Leave usage by type" unit="%" :max="100"
            :labels="array_keys($leaveByType)" :series="[['label' => 'Usage', 'data' => array_values($leaveByType)]]" />

    Data that a Livewire component re-sends in a browser event (e.g. after a filter change):
        <x-ui.chart type="bar" label="Monthly fleet expenses" unit="GHS"
            event="transport-report-data-updated" source="monthlyExpenses"
            :source-data="$payload['monthlyExpenses'] ?? []" :series="[['label' => 'GHS', 'key' => 'data']]" />

    Types: line · area · bar · hbar · doughnut. Series colours follow the fixed slot order unless a
    series sets `color` (a token such as 'series-3' / 'success') or per-point `colors` / `colorsKey`.
    `color-map` ties a colour to a category label (e.g. leave statuses) so it never changes with rank.
    Every chart has a "Table" toggle showing the same numbers as text.
    Docs: docs/11-ui-components.md#chart
--}}
@props([
    'type' => 'line',
    'label',
    'labels' => null,
    'series' => [],
    'sourceData' => null,
    'source' => null,
    'event' => null,
    'watch' => null,
    'unit' => null,
    'min' => null,
    'max' => null,
    'stacked' => false,
    'legend' => null,
    'center' => false,
    'centerCaption' => null,
    'height' => 260,
    'table' => true,
    'colorMap' => null,
    'emptyText' => 'No data for this period yet.',
])

@php
    $rows = is_array($sourceData) ? $sourceData : [];
    $resolvedLabels = array_values($labels ?? ($rows['labels'] ?? []));
    $resolvedSeries = collect($series)->map(function (array $item) use ($rows) {
        if (isset($item['key'])) {
            $item['data'] = array_values($rows[$item['key']] ?? []);
        }

        if (isset($item['colorsKey'])) {
            $item['colors'] = array_values($rows[$item['colorsKey']] ?? []);
        }

        $item['data'] = array_values(array_map(fn ($value) => is_numeric($value) ? $value + 0 : 0, $item['data'] ?? []));

        return $item;
    })->values()->all();

    $config = [
        'type' => $type,
        'labels' => $resolvedLabels,
        'series' => $resolvedSeries,
        'unit' => $unit,
        'min' => $min,
        'max' => $max,
        'stacked' => (bool) $stacked,
        'legend' => $legend,
        'center' => (bool) $center,
        'centerCaption' => $centerCaption,
        'event' => $event,
        'source' => $source,
        'watch' => $watch,
        'colorMap' => $colorMap,
    ];
@endphp

<div
    {{ $attributes->class(['ui-chart']) }}
    x-data="chart(@js($config))"
    wire:ignore
>
    @if ($table)
        <div class="ui-chart-tools">
            <button
                type="button"
                class="btn btn-ghost btn-sm"
                x-on:click="showTable = ! showTable"
                x-bind:aria-pressed="showTable.toString()"
            >
                <x-ui.icon name="table" class="icon-sm" x-show="! showTable" />
                <x-ui.icon name="chart-column" class="icon-sm" x-show="showTable" x-cloak />
                <span x-text="showTable ? 'Chart' : 'Table'">Table</span>
                <span class="sr-only-text">view of {{ $label }}</span>
            </button>
        </div>
    @endif

    <div class="ui-chart-canvas" style="height: {{ (int) $height }}px" x-show="! showTable">
        <canvas x-ref="canvas" role="img" aria-label="{{ $label }}"></canvas>
        <p class="ui-chart-empty" x-show="isEmpty" x-cloak>{{ $emptyText }}</p>
    </div>

    @if ($table)
        <div class="ui-chart-table" x-show="showTable" x-cloak>
            <div class="ui-table-scroll" role="region" tabindex="0" aria-label="{{ $label }} (table)">
                <table class="ui-table is-dense">
                    <caption class="sr-only-text">{{ $label }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Item') }}</th>
                            <template x-for="(item, index) in series" :key="index">
                                <th scope="col" class="num" x-text="item.label || '{{ __('Value') }}'"></th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, rowIndex) in tableRows" :key="rowIndex">
                            <tr>
                                <th scope="row" x-text="row.label"></th>
                                <template x-for="(value, valueIndex) in row.values" :key="valueIndex">
                                    <td class="num" x-text="value"></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
