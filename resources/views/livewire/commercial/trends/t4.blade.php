@php
    $labels = [
        'coverage' => ['Coverage', 'p'], 'skip_rate' => ['Skip rate', 'p'], 'unvisited' => ['Unvisited', 'n'], 'visited' => ['Visited', 'n'],
        'estimated_share' => ['Estimated bills', 'p'], 'unbilled_share' => ['Unbilled', 'p'], 'collection_ratio' => ['Collection ratio', 'p'], 'owing_amount' => ['Owing (GH¢)', 'm'], 'month_total' => ['Month total (GH¢)', 'm'], 'paid' => ['Paid this month (GH¢)', 'm'],
    ];
    $fmt = fn ($v, $f) => $v === null ? '–' : match ($f) { 'p' => number_format($v, 1).'%', 'm' => number_format($v, 2), default => number_format($v) };
@endphp
@foreach (['reading', 'billing'] as $kind)
    @php $part = $parts[$kind]; @endphp
    @if ($part['data'] === null)
        <x-commercial.cross-part :part="$part" />
    @else
        <x-commercial.cross-part :part="$part" description="The trailing 7, 30 and 90 days ending at the latest upload's last day. Uploads wholly inside a window that do not overlap are added up; the window says how many of its days they cover.">
            @if (! $part['data']['available'])
                <p class="ui-hint" data-empty-part>{{ $part['data']['note'] }}</p>
            @else
                <p class="ui-hint">Windows end on <strong>{{ $part['data']['end_label'] }}</strong>.@if ($kind === 'reading') Coverage is the visits of every upload in the window against the verified strength of the latest one, so it can pass 100% when a window holds several uploads (the reading screen works it the same way); the skip rate is the reliable figure across uploads.@endif</p>
                <x-ui.table :label="$part['title'].' rolling windows'" dense>
                    <x-slot:head><tr><th>Window</th><th>Period</th><th>Covered</th>@foreach ($part['data']['measures'] as $k)<th class="num">{{ $labels[$k][0] }}</th>@endforeach</tr></x-slot:head>
                    @foreach ($part['data']['windows'] as $w)
                        <tr data-window-days="{{ $w['days'] }}">
                            <td>{{ $w['days'] }} days</td><td>{{ $w['label'] }}</td><td>{{ $w['coverage_text'] }}</td>
                            @foreach ($part['data']['measures'] as $k)<td class="num">{{ $fmt($w['figures'][$k] ?? null, $labels[$k][1]) }}</td>@endforeach
                        </tr>
                    @endforeach
                </x-ui.table>
                @if (collect($part['data']['windows'])->contains(fn ($w) => $w['held_back']))<p class="ui-hint">Some reading figures are held back: too few CCAs to show them without naming them.</p>@endif
            @endif
        </x-commercial.cross-part>
    @endif
@endforeach
