{{--
    Value against a target, e.g. an approval-time SLA. The bar fills to value/target (capped) and
    turns amber over target and red over twice the target; the numbers are always written out.
        <x-ui.meter label="Manager response" :value="31" :target="48" unit="h" />
    Set `lower-is-better=false` for targets you want to reach rather than stay under. A null value
    means "nothing measured yet" and shows `empty-text` instead of a misleading zero. (The default
    must stay null: @props falls back to the default when the passed value is null.)
    Docs: docs/11-ui-components.md#meter
--}}
@props([
    'label',
    'value' => null,
    'target' => 1,
    'unit' => '',
    'lowerIsBetter' => true,
    'emptyText' => __('No data yet'),
])

@php
    $target = max((float) $target, 0.0001);
    $format = fn (float $number) => rtrim(rtrim(number_format($number, 1), '0'), '.').$unit;
    $targetText = ($lowerIsBetter ? __('target ≤') : __('target ≥')).' '.$format($target);
@endphp

@if ($value === null)
    <div {{ $attributes->class(['ui-meter', 'is-empty']) }}>
        <div class="ui-meter-head">
            <span class="ui-meter-label">{{ $label }}</span>
            <span class="ui-meter-value"><small>{{ $emptyText }} · {{ $targetText }}</small></span>
        </div>
        <div class="ui-meter-track" aria-hidden="true"></div>
    </div>
@else
    @php
        $value = (float) $value;
        $ratio = $value / $target;
        $tone = $lowerIsBetter
            ? ($ratio <= 1 ? 'success' : ($ratio <= 2 ? 'warning' : 'danger'))
            : ($ratio >= 1 ? 'success' : ($ratio >= 0.5 ? 'warning' : 'danger'));
        $width = min(100, round($ratio * 100));
    @endphp

    <div {{ $attributes->class(['ui-meter', 'ui-meter-'.$tone]) }}>
        <div class="ui-meter-head">
            <span class="ui-meter-label">{{ $label }}</span>
            <span class="ui-meter-value"><b>{{ $format($value) }}</b> <small>{{ $targetText }}</small></span>
        </div>
        <div
            class="ui-meter-track"
            role="meter"
            aria-label="{{ $label }}"
            aria-valuemin="0"
            aria-valuemax="{{ $target * 2 }}"
            aria-valuenow="{{ $value }}"
            aria-valuetext="{{ $format($value) }} ({{ $targetText }})"
        >
            <span style="width: {{ $width }}%"></span>
        </div>
    </div>
@endif
