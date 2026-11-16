@foreach ($parts as $part)
    @if ($part['data'] === null)
        <x-commercial.cross-part :part="$part" />
    @else
        <x-commercial.cross-part :part="$part" :description="$part['key'] === 'ccas' ? 'Ranked by skip rate, lowest first; a CCA needs at least the minimum number of visits to be ranked.' : 'Rank 1 is the best. A move is the change of place since the previous comparable period.'">
            @php $p = $part['data']; @endphp
            @if (! $p['available'])
                <p class="ui-hint" data-empty-part>{{ $p['note'] }}</p>
            @else
                @if ($p['note'])<p class="ui-hint" data-no-comparable>{{ $p['note'] }} Every row is new.</p>@endif
                <p class="ui-hint">Now: <strong>{{ $p['current']['label'] }}</strong>@if ($p['compared']). Before: <strong>{{ $p['compared']['label'] }}</strong>@endif.</p>
                @php $ratio = $p['measure'] === 'skip_rate' ? 'Skip rate' : 'Collection ratio'; @endphp
                <x-ui.table :label="$part['title']" :sticky="count($p['rows']) > 12" dense>
                    <x-slot:head><tr><th>{{ $p['noun'] }}</th><th class="num">{{ $ratio }} now</th><th class="num">{{ $ratio }} before</th><th class="num">Rank now</th><th class="num">Rank before</th><th>Move</th></tr></x-slot:head>
                    @forelse (array_slice($p['rows'], 0, 300) as $row)
                        <tr data-direction="{{ $row['direction'] }}">
                            <td>{{ $row['label'] }}</td>
                            <td class="num">{{ $row['value_now'] === null ? '–' : number_format($row['value_now'], 1).'%' }}</td>
                            <td class="num">{{ $row['value_then'] === null ? '–' : number_format($row['value_then'], 1).'%' }}</td>
                            <td class="num">{{ $row['rank_now'] ?? '–' }}</td><td class="num">{{ $row['rank_then'] ?? '–' }}</td>
                            <td @if ($row['direction'] === 'up') style="color:var(--color-success-ink)" @elseif ($row['direction'] === 'down') style="color:var(--color-danger-ink)" @endif>
                                @switch($row['direction'])
                                    @case('up') ▲ up {{ $row['move'] }} @break
                                    @case('down') ▼ down {{ abs($row['move']) }} @break
                                    @case('same') same @break
                                    @case('new') new @break
                                    @case('gone') not ranked now @break
                                    @default –
                                @endswitch
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="6" icon="file-spreadsheet" title="Nothing is ranked." />
                    @endforelse
                </x-ui.table>
                @if (count($p['rows']) > 300)<p class="ui-hint">The first 300 of {{ number_format(count($p['rows'])) }} rows are shown. The Excel file has every one.</p>@endif
            @endif
        </x-commercial.cross-part>
    @endif
@endforeach
