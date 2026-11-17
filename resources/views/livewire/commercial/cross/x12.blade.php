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


{{-- Phase 8h: the summary derived from the detailed report against the uploaded one, every column --}}
@if (isset($parts['derived']))
    @if ($parts['derived']['data'] === null)
        <x-commercial.cross-part :part="$parts['derived']" />
    @else
    <x-commercial.cross-part :part="$parts['derived']" description="Where an uploaded billing summary and a billing summary derived from the Detailed Billing Report exist for the same region, customer segment and period, EVERY column is compared route by route and in total. This is how to decide whether the setting can be switched to the detailed report.">
        @php $dd = $parts['derived']['data']; @endphp
        @if (! $dd['available'])
            <p class="ui-hint">No uploaded billing summary has a derived twin for {{ $dd['details_period'] ?? 'this period' }}: nothing to compare. (Upload the billing summary for a period the detailed report covers, or load a detailed report for a period a summary covers.)</p>
        @else
            <p class="ui-hint">Tolerance: money within GH¢ {{ number_format($dd['tolerance']['money'], 2) }} per route, volume within {{ number_format($dd['tolerance']['volume'], 2) }} thousand litres per route, customer counts exactly (Settings, Detailed billing).@if ($dd['district_limited']) You see your own district only: no region-wide total and no band table.@endif</p>
            @foreach ($dd['pairs'] as $pair)
                @php $r = $pair['result']; @endphp
                <div class="dash-row" data-testid="x12-derived-pair">
                    <p><strong data-testid="x12-verdict">{{ $r['verdict'] }}</strong> <span class="ui-hint">{{ $pair['period'] }}, uploaded batch #{{ $pair['uploaded_batch'] }} against derived batch #{{ $pair['derived_batch'] }}; the analyses count {{ $pair['counted'] }}.</span></p>
                    <x-ui.table label="Every column, derived against uploaded">
                        <x-slot:head><tr><th>Column</th><th class="num">Uploaded (total)</th><th class="num">Derived (total)</th><th>Result</th></tr></x-slot:head>
                        @foreach ($r['columns'] as $c)
                            <tr><td>{{ $c['label'] }}</td>
                                <td class="num">{{ $c['uploaded'] === null ? '–' : number_format($c['uploaded'], $c['kind'] === 'count' ? 0 : 2) }}</td>
                                <td class="num">{{ $c['derived'] === null ? '–' : number_format($c['derived'], $c['kind'] === 'count' ? 0 : 2) }}</td>
                                <td>{{ ucfirst($c['text']) }}</td></tr>
                        @endforeach
                        @foreach ($r['bands'] as $c)
                            <tr><td>{{ $c['label'] }}</td><td class="num">–</td><td class="num">–</td><td>{{ ucfirst($c['text']) }}</td></tr>
                        @endforeach
                    </x-ui.table>
                    @if ($r['only_in_uploaded'] !== [] || $r['only_in_derived'] !== [])
                        <p class="ui-hint">Routes only in the uploaded summary: {{ count($r['only_in_uploaded']) }}@if ($r['only_in_uploaded'] !== []) ({{ implode(', ', array_slice($r['only_in_uploaded'], 0, 10)) }})@endif. Only in the derived summary: {{ count($r['only_in_derived']) }}@if ($r['only_in_derived'] !== []) ({{ implode(', ', array_slice($r['only_in_derived'], 0, 10)) }})@endif.</p>
                    @endif
                    @if ($r['worst'] !== [])
                        <p class="ui-hint">The routes that differ most:</p>
                        <ul class="ui-hint">@foreach ($r['worst'] as $w)<li>{{ $w['route'] }}: {{ $w['columns'] }} column(s) ({{ implode('; ', array_map(fn ($d) => $d['label'].' '.number_format($d['difference'], 2), array_slice($w['differences'], 0, 4))) }})</li>@endforeach</ul>
                    @endif
                </div>
            @endforeach
        @endif
    </x-commercial.cross-part>
    @endif
@endif
