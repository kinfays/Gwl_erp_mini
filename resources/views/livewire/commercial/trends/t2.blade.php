@php $vs = $r['key'] === 't6' ? 'Same dates a year earlier' : 'Previous period'; @endphp
@foreach (['reading', 'billing', 'customers'] as $kind)
    @if (isset($parts[$kind]))
        @php $part = $parts[$kind]; @endphp
        @if ($part['data'] === null)
            <x-commercial.cross-part :part="$part" />
        @else
            <x-commercial.cross-part :part="$part" :description="$kind === 'customers' ? 'Billing accounts and the contactable share, between the snapshots of each district. A daily snapshot is labelled by its date.' : null">
                @if ($part['data']['available'])
                    @if ($kind === 'customers')
                        @php $p = $part['data']; @endphp
                        <p class="ui-hint">This period: <strong>{{ $p['current']['label'] }}</strong>. @if ($p['compared']) {{ $vs }}: <strong>{{ $p['compared']['label'] }}</strong>. @endif</p>
                        @if (! $p['compared'])<p class="ui-hint" data-no-comparable><x-ui.icon name="info" class="icon-sm" /> {{ $p['note'] }} No change is shown.</p>@endif
                        @if ($p['measures'] !== [])<x-commercial.trend-table :groups="[['label' => null, 'measures' => $p['measures']]]" :vs="$vs" label="Region figures" />@endif
                        <h4 style="margin-top:14px">By district</h4>
                        <x-ui.table label="Customer list by district" dense>
                            <x-slot:head><tr><th>District</th><th>Snapshots</th><th>Measure</th><th class="num">This period</th><th class="num">{{ $vs }}</th><th class="num">Change</th><th>Better or worse</th></tr></x-slot:head>
                            @foreach ($p['districts'] as $row)
                                @foreach ($row['measures'] as $m)
                                    @php
                                        $f = fn ($v) => $v === null ? '–' : ($m['unit'] === 'pct' ? number_format($v, 1).'%' : number_format($v));
                                        $c = $m['change'] === null ? '–' : (($m['change'] > 0 ? '+' : ($m['change'] < 0 ? '−' : '')).number_format(abs($m['change']), $m['unit'] === 'pct' ? 1 : 0).($m['unit'] === 'pct' ? ' points' : ($m['change_pct'] !== null ? ' ('.($m['change'] > 0 ? '+' : '−').number_format(abs($m['change_pct']), 1).'%)' : '')));
                                    @endphp
                                    <tr>
                                        <td>{{ $loop->first ? $row['label'] : '' }}</td>
                                        <td>{{ $loop->first ? $row['now_label'].($row['then_label'] ? ' against '.$row['then_label'] : ' (no earlier snapshot)') : '' }}</td>
                                        <td>{{ $m['label'] }}</td><td class="num">{{ $f($m['now']) }}</td><td class="num">{{ $f($m['then']) }}</td><td class="num">{{ $c }}</td>
                                        <td @if ($m['tone'] === 'good') style="background:var(--color-success-soft);color:var(--color-success-ink)" @elseif ($m['tone'] === 'bad') style="background:var(--color-danger-soft);color:var(--color-danger-ink)" @endif data-tone="{{ $m['tone'] }}">{{ $m['tone'] ? ['good' => 'better', 'bad' => 'worse', 'neutral' => 'no direction'][$m['tone']] : '–' }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </x-ui.table>
                    @else
                        @include('livewire.commercial.trends.pair', ['p' => $part['data'], 'vs' => $vs])
                    @endif
                @else
                    <p class="ui-hint" data-empty-part>{{ $part['data']['note'] }}</p>
                @endif
            </x-commercial.cross-part>
        @endif
    @endif
@endforeach
