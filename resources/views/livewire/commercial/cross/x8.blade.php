{{-- X8: arrears per CCA portfolio --}}
@if ($parts['portfolio']['data'] === null)
    <x-commercial.cross-part :part="$parts['portfolio']" />
@else
<x-commercial.cross-part :part="$parts['portfolio']" description="The balance owed on the routes each CCA owns for the bill period (the owner in the reading upload that overlaps it the most). A negative balance is a customer credit and is never netted off what customers owe.">
    @php $d = $parts['portfolio']['data']; @endphp
    @if ($r['windows']['billing']['available'] ?? false)<p class="ui-hint">{{ $r['windows']['billing']['sentence'] }}. @if ($r['district_limited']) The share is of your districts' owing balance, not the region's. @endif</p>@endif
    <p class="ui-hint">Owing balance on the routes in view: GH¢ {{ number_format($d['owing_total'], 2) }}.</p>
    @include('livewire.commercial.cross.table', ['label' => 'Arrears per CCA portfolio', 'rows' => $d['rows'], 'cols' => [
        ['CCA', 'label'], ['Routes', 'routes', 'n'], ['Customers', 'customers', 'n'], ['Owing (GH¢)', 'owing_amount', 'm'], ['Owing accounts', 'owing', 'n'], ['Credits (GH¢)', 'credit_amount', 'm'], ['Collection ratio', 'collection_ratio', 'p'], ['Share of the owing balance', 'share_of_owing', 'p'],
    ]])
    <h4 style="margin-top:16px">The routes holding most of each CCA's arrears</h4>
    @foreach ($d['rows'] as $row)
        @if ($row['top_routes'] !== [])
            <p><strong>{{ $row['label'] }}</strong></p>
            @include('livewire.commercial.cross.table', ['label' => 'Routes holding most of '.$row['label'].'\'s arrears', 'rows' => $row['top_routes'], 'routeQuery' => $routeQuery, 'cols' => [['Route', 'label', 't', 'route_id'], ['District', 'district'], ['Owing (GH¢)', 'owing_amount', 'm'], ['Owing accounts', 'owing', 'n'], ['Share of this CCA\'s arrears', 'share_of_cca', 'p']]])
        @endif
    @endforeach
</x-commercial.cross-part>
@endif
