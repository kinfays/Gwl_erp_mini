{{-- A table of an "Across the files" page from a column spec. Expects $rows (list of arrays) and $cols (list of [label, key (dot path), format, route id key, judged key]). Formats: n number, m money
     (GH¢), p percent, t text. A null value shows a dash. A column with a route id key links the value to the route 360 page; a column with a judged key is coloured good / bad against a target
     (and says so for a reader who cannot see colour). Optional: $cap (rows shown, default 300; the Excel file has every row), $empty, $routeQuery (the range carried to route 360). --}}
@php
    $cap = $cap ?? 300;
    $routeQuery = $routeQuery ?? [];
    $fmt = function ($value, string $f): string {
        if ($value === null || $value === '') {
            return '–';
        }

        return match ($f) {
            'n' => number_format((float) $value),
            'm' => number_format((float) $value, 2),
            'p' => number_format((float) $value, 1).'%',
            default => (string) $value,
        };
    };
    $shown = array_slice($rows, 0, $cap);
@endphp
<x-ui.table :label="$label ?? 'Table'" :sticky="count($rows) > 12">
    <x-slot:head>
        <tr>@foreach ($cols as $col)<th @class(['num' => ($col[2] ?? 't') !== 't'])>{{ $col[0] }}</th>@endforeach</tr>
    </x-slot:head>
    @forelse ($shown as $row)
        <tr>
            @foreach ($cols as $col)
                @php
                    $value = data_get($row, $col[1]);
                    $routeId = isset($col[3]) && $col[3] ? data_get($row, $col[3]) : null;
                    $judged = isset($col[4]) && $col[4] ? data_get($row, $col[4]) : null;
                @endphp
                <td @class(['num' => ($col[2] ?? 't') !== 't']) @if ($judged) style="{{ $judged === 'good' ? 'background:var(--color-success-soft);color:var(--color-success-ink)' : 'background:var(--color-danger-soft);color:var(--color-danger-ink)' }}" data-judged="{{ $judged }}" @endif>
                    @if ($routeId && $value !== null && $value !== '')
                        <a href="{{ route('commercial.cross.route', ['route' => $routeId, ...$routeQuery]) }}">{{ $fmt($value, $col[2] ?? 't') }}</a>
                    @else
                        {{ $fmt($value, $col[2] ?? 't') }}
                    @endif
                    @if ($judged)<span class="sr-only-text"> ({{ $judged === 'good' ? 'within the target' : 'outside the target' }})</span>@endif
                </td>
            @endforeach
        </tr>
    @empty
        <x-ui.empty-row :colspan="count($cols)" icon="file-spreadsheet" :title="$empty ?? 'Nothing to show.'" />
    @endforelse
</x-ui.table>
@if (count($rows) > $cap)
    <p class="ui-hint">The first {{ number_format($cap) }} of {{ number_format(count($rows)) }} rows are shown. The Excel file has every one.</p>
@endif
