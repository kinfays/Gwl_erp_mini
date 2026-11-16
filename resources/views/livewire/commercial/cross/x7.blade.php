{{-- X7: the district scorecard from the real routes (also the headline table of X11) --}}
@foreach ($trendBlocks ?? [] as $kind => $block)
    <x-commercial.trend-block :block="$block" :kind="$kind" :query="array_filter(['region' => $r['region_id'], 'district' => $r['district_id'], 'from' => $dateFrom, 'to' => $dateTo])" />
@endforeach
@php
    $scorecard = $parts['scorecard'] ?? $parts['districts'];
@endphp
@if ($scorecard['data'] === null)
    <x-commercial.cross-part :part="$scorecard" />
@else
<x-commercial.cross-part :part="$scorecard" description="One row per district: reading, billing and customer-list figures side by side. A figure is coloured against the targets and thresholds in Settings; there is no composite score. The old home-district scorecard (C1) stays for the old uploads.">
    @php
        $d = $scorecard['data'];
        $t = $d['targets'];
        $has = ['reading' => collect($d['rows'])->contains(fn ($x) => $x['reading'] !== null), 'billing' => collect($d['rows'])->contains(fn ($x) => $x['billing'] !== null), 'customers' => collect($d['rows'])->contains(fn ($x) => $x['customers'] !== null)];
        $cols = array_merge(
            [['District', 'district']],
            $has['reading'] ? [['Coverage', 'reading.coverage', 'p', null, 'judged.coverage'], ['Skip rate', 'reading.skip_rate', 'p', null, 'judged.skip_rate'], ['Unvisited', 'reading.unvisited', 'n']] : [],
            $has['billing'] ? [['Estimated bills', 'billing.estimated_share', 'p', null, 'judged.estimated_share'], ['Unbilled', 'billing.unbilled_share', 'p', null, 'judged.unbilled_share'], ['Collection ratio', 'billing.collection_ratio', 'p', null, 'judged.collection_ratio'], ['Owing (GH¢)', 'billing.owing_amount', 'm'], ['Owing, share of the month total', 'billing.owing_share', 'p']] : [],
            $has['customers'] ? [['Customers', 'customers.base', 'n'], ['Billing accounts', 'customers.billing', 'n'], ['Contactable', 'customers.contactable_share', 'p']] : []
        );
    @endphp
    @include('livewire.commercial.cross.table', ['label' => 'District scorecard', 'rows' => $d['rows'], 'cols' => $cols, 'empty' => 'No district has a figure.'])
    <p class="ui-hint">Targets: skip rate at most {{ number_format($t['skip'], 0) }}%, coverage at least {{ number_format($t['coverage'], 0) }}%, collection ratio at least {{ number_format($t['collection'], 0) }}%; estimated bills above {{ number_format($t['estimated_high'], 0) }}% or unbilled above {{ number_format($t['unbilled_high'], 0) }}% is flagged. Green is within, red is outside.</p>
    @foreach ($d['rows'] as $row)
        @if (($row['reading']['held_back'] ?? false))<p class="ui-hint">{{ $row['district'] }}: the reading figures are held back (too few CCAs to show without naming them).</p>@endif
    @endforeach
</x-commercial.cross-part>
@endif
