@foreach (['reading', 'billing'] as $kind)
    @php $part = $parts[$kind]; @endphp
    @if ($part['data'] === null)
        <x-commercial.cross-part :part="$part" />
    @else
        <x-commercial.cross-part :part="$part" description="A run rate on the days covered (the total of the covered days, per covered day, times the days in the month): a projection, not a forecast. Only flows are projected, never a balance or a strength.">
            @php $p = $part['data']; @endphp
            @if (! $p['available'])
                <p class="ui-hint" data-empty-part>{{ $p['note'] }}</p>
            @else
                <p class="ui-hint"><strong>{{ $p['month_label'] }}</strong>: {{ $p['coverage_text'] }}. <em>{{ ucfirst($p['label']) }}.</em></p>
                <x-ui.table :label="$part['title'].' projection'" dense>
                    <x-slot:head><tr><th>Measure</th><th class="num">Actual so far</th><th class="num">Per covered day</th><th class="num">Projection to month end</th><th>Target</th><th>State</th></tr></x-slot:head>
                    @foreach ($p['measures'] as $m)
                        <tr data-state="{{ $m['state'] }}">
                            <td>{{ $m['label'] }}</td>
                            <td class="num">{{ number_format($m['actual'], $kind === 'reading' ? 0 : 2) }}</td>
                            <td class="num">{{ $m['per_day'] === null ? '–' : number_format($m['per_day'], $kind === 'reading' ? 1 : 2) }}</td>
                            <td class="num">{{ $m['projection'] === null ? '–' : number_format($m['projection'], $kind === 'reading' ? 0 : 2) }}</td>
                            <td>{{ $m['target'] === null ? 'no target set' : number_format($m['target'], 2) }}</td>
                            <td>{{ ['projected' => 'projection, not a forecast', 'complete' => 'month fully covered: the actual is the month', 'withheld' => 'withheld: fewer than '.$p['min_days'].' days covered', 'none' => 'no days covered'][$m['state']] ?? $m['state'] }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-commercial.cross-part>
    @endif
@endforeach
