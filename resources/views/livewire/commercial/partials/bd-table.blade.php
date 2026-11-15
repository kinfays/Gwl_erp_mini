{{-- A Billing details table from a column spec. Expects $title, $rows (list of arrays), $cols (list of [label, key (dot path), format]) and optional $description,
     $cap (rows shown on the screen; the Excel file has every one), $empty (the text for no rows), $total (a row shown last, in bold).
     Formats: n number, m money (GH¢), p percent, c consumption (1 decimal), t text. A null value shows a dash. --}}
@php
    $cap = $cap ?? 300;
    $fmt = function ($value, string $f): string {
        if ($value === null || $value === '') {
            return '–';
        }

        return match ($f) {
            'n' => number_format((float) $value),
            'm' => number_format((float) $value, 2),
            'p' => number_format((float) $value, 1).'%',
            'c' => number_format((float) $value, 1),
            default => (string) $value,
        };
    };
    $shown = array_slice($rows, 0, $cap);
@endphp
<x-ui.card :title="$title" :description="$description ?? null" :padded="false" class="dash-row">
    <x-ui.table :label="$title" :sticky="count($rows) > 12">
        <x-slot:head>
            <tr>@foreach ($cols as $col)<th @class(['num' => ($col[2] ?? 't') !== 't'])>{{ $col[0] }}</th>@endforeach</tr>
        </x-slot:head>
        @forelse ($shown as $row)
            <tr>
                @foreach ($cols as $col)
                    <td @class(['num' => ($col[2] ?? 't') !== 't'])>@if (! empty($col[3]) && data_get($row, $col[3]) !== null)<a href="{{ route('commercial.cross.route', data_get($row, $col[3])) }}">{{ $fmt(data_get($row, $col[1]), $col[2] ?? 't') }}</a>@else{{ $fmt(data_get($row, $col[1]), $col[2] ?? 't') }}@endif</td>
                @endforeach
            </tr>
        @empty
            <x-ui.empty-row :colspan="count($cols)" icon="file-spreadsheet" :title="$empty ?? 'Nothing to show.'" />
        @endforelse
        @if (! empty($total))
            <tr style="font-weight:600">
                @foreach ($cols as $col)
                    <td @class(['num' => ($col[2] ?? 't') !== 't'])>{{ $col[1] === ($cols[0][1]) ? 'All' : $fmt(data_get($total, $col[1]), $col[2] ?? 't') }}</td>
                @endforeach
            </tr>
        @endif
    </x-ui.table>
</x-ui.card>
@if (count($rows) > $cap)
    <p class="ui-hint dash-row">The first {{ number_format($cap) }} of {{ number_format(count($rows)) }} rows are shown. The Excel file has every one.</p>
@endif
