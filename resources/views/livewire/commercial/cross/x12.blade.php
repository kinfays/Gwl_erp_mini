{{-- X12: the Detailed Billing Report against the billing summary --}}
@if ($parts['compare']['data'] === null)
    <x-commercial.cross-part :part="$parts['compare']" />
@else
<x-commercial.cross-part :part="$parts['compare']" description="Customers billed, billing for the period, payments this month and the closing balance of the Detailed Billing Report against the same measures of the older billing summary. The summary may be a different cut (a customer segment, or a whole month where the details cover part of one): the uploads compared are named, and a difference is not an error until that is understood.">
    @php $d = $parts['compare']['data']; $m = $d['measures']; @endphp
    @if (! $d['available'])
        <p class="ui-hint">No billing summary lies over {{ $d['details_period'] }}, so there is nothing to compare with.</p>
    @else
        <p class="ui-hint">Detailed billing: {{ $d['details_period'] }}. Billing summary uploads used (the newest of each segment that overlaps it):
            @foreach ($d['summary_batches'] as $b)batch #{{ $b['id'] }} ({{ $b['segment'] }}, {{ $b['period'] }}{{ $b['same_dates'] ? ', the same dates' : ', other dates' }}){{ ! $loop->last ? '; ' : '' }}@endforeach.</p>
        <x-ui.table label="Detailed billing against the billing summary">
            <x-slot:head>
                <tr><th>District</th>@foreach ($m as $label)<th class="num">{{ $label }}: details</th><th class="num">summary</th><th class="num">difference</th>@endforeach</tr>
            </x-slot:head>
            @php $lines = array_merge($d['rows'], $d['total'] ? [$d['total']] : []); @endphp
            @forelse ($lines as $line)
                <tr @if ($line['district'] === 'All districts') style="font-weight:600" @endif>
                    <td>{{ $line['district'] }}</td>
                    @foreach (array_keys($m) as $key)
                        @php $c = $line['cells'][$key]; $money = in_array($key, ['billing', 'payments', 'closing'], true); @endphp
                        <td class="num">{{ $c['details'] === null ? '–' : number_format($c['details'], $money ? 2 : 0) }}</td>
                        <td class="num">{{ $c['summary'] === null ? '–' : number_format($c['summary'], $money ? 2 : 0) }}</td>
                        <td class="num">{{ $c['difference'] === null ? '–' : number_format($c['difference'], $money ? 2 : 0) }}@if ($c['difference_pct'] !== null) <span class="ui-hint">({{ number_format($c['difference_pct'], 1) }}%)</span>@endif</td>
                    @endforeach
                </tr>
            @empty
                <x-ui.empty-row :colspan="1 + count($m) * 3" icon="file-spreadsheet" title="No district to compare." />
            @endforelse
        </x-ui.table>
        <p class="ui-hint">Compared: customers billed (REGULAR, ESTIMATED and ESTIMATE) with the summary's billed total; the month total with the billing for the period; paid this month with the month's payments; the balance due with the closing balance. Not compared: volumes, adjustments, and the unbilled counts (the two reports count them differently).</p>
    @endif
</x-commercial.cross-part>
@endif
