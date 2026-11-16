{{-- The measures of a trend (Phase 8f): value now, value then, the change and whether it is better or worse. `groups` is a list of ['label' => string|null, 'measures' => [...]]
     (TrendFigures::measures); with a `noun` (District, CCA, Route) the first column names the group, without one it is the headline (one group). A ratio changes in
     PERCENTAGE POINTS, a count or an amount in absolute terms and in percent; the tone is stated in words as well as colour. --}}
@props(['groups', 'vs' => 'Before', 'noun' => null, 'label' => 'Change', 'cap' => 300])

@php
    $fmt = function ($value, string $unit): string {
        if ($value === null) {
            return '–';
        }

        return match ($unit) {
            'pct' => number_format((float) $value, 1).'%',
            'm' => number_format((float) $value, 2),
            default => number_format((float) $value),
        };
    };
    $delta = function (array $m): string {
        if ($m['change'] === null) {
            return '–';
        }

        $sign = $m['change'] > 0 ? '+' : ($m['change'] < 0 ? '−' : '');
        $abs = abs($m['change']);

        return match ($m['unit']) {
            'pct' => $sign.number_format($abs, 1).' points',
            'm' => $sign.number_format($abs, 2).($m['change_pct'] !== null ? ' ('.$sign.number_format(abs($m['change_pct']), 1).'%)' : ''),
            default => $sign.number_format($abs).($m['change_pct'] !== null ? ' ('.$sign.number_format(abs($m['change_pct']), 1).'%)' : ''),
        };
    };
    $words = ['good' => 'better', 'bad' => 'worse', 'neutral' => 'no direction'];
    $groups = array_slice($groups, 0, $cap);
@endphp
<x-ui.table :label="$label" :sticky="count($groups) > 8" dense>
    <x-slot:head>
        <tr>@if ($noun)<th>{{ $noun }}</th>@endif<th>Measure</th><th class="num">This period</th><th class="num">{{ $vs }}</th><th class="num">Change</th><th>Better or worse</th></tr>
    </x-slot:head>
    @forelse ($groups as $g)
        @foreach ($g['measures'] as $m)
            <tr data-measure="{{ $m['key'] }}">
                @if ($noun)<td>{{ $loop->first ? $g['label'] : '' }}</td>@endif
                <td>{{ $m['label'] }}</td>
                <td class="num">{{ $fmt($m['now'], $m['unit']) }}</td>
                <td class="num">{{ $fmt($m['then'], $m['unit']) }}</td>
                <td class="num" data-change>{{ $delta($m) }}</td>
                <td @if ($m['tone'] === 'good') style="background:var(--color-success-soft);color:var(--color-success-ink)" @elseif ($m['tone'] === 'bad') style="background:var(--color-danger-soft);color:var(--color-danger-ink)" @endif data-tone="{{ $m['tone'] }}">{{ $m['tone'] ? $words[$m['tone']] : '–' }}</td>
            </tr>
        @endforeach
    @empty
        <x-ui.empty-row :colspan="$noun ? 6 : 5" icon="file-spreadsheet" title="Nothing to compare." />
    @endforelse
</x-ui.table>
