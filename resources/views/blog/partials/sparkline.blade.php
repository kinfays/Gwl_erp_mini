{{-- A tiny line of one number per day (views of the last days). $values: list of ints, oldest first. $label: what it counts. --}}
@php
    $values = array_values($values);
    $n = count($values);
    $w = 72;
    $h = 20;
    $max = max(1, ...($values ?: [0]));
    $points = collect($values)->map(function ($v, $i) use ($n, $w, $h, $max) {
        $x = $n > 1 ? round($i * ($w - 4) / ($n - 1) + 2, 1) : $w / 2;
        $y = round($h - 3 - ($v / $max) * ($h - 6), 1);

        return $x.','.$y;
    });
    $last = $points->last();
@endphp
<svg class="b-spark" width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" role="img"
     aria-label="{{ $label }}: {{ trans_choice(':count view|:count views', array_sum($values)) }}, {{ __('last :days days', ['days' => $n]) }}">
    <polyline points="{{ $points->implode(' ') }}"></polyline>
    @if ($last)<circle class="b-spark-dot" cx="{{ explode(',', $last)[0] }}" cy="{{ explode(',', $last)[1] }}" r="2"></circle>@endif
</svg>
