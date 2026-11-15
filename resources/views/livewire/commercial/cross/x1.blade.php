{{-- X1: CCA performance against billing outcomes --}}
@php
    $cca = $r['windows']['reading']['sentence'] ?? null;
    $scatter = fn (array $points) => [['label' => 'CCA', 'data' => array_map(fn (array $p) => ['x' => $p['x'], 'y' => $p['y']], $points)]];
@endphp

@if ($parts['combined']['data'] === null)
    <x-commercial.cross-part :part="$parts['combined']" />
@else
<x-commercial.cross-part :part="$parts['combined']" description="Each CCA is the owner of its routes in the reading upload; the billing figures are those of the same routes. Rates are worked out from the sums of the routes, never averaged. Ranked on skip rate among the CCAs with enough visits.">
    @php $d = $parts['combined']['data']; @endphp
    @include('livewire.commercial.cross.table', ['label' => 'CCA performance against billing outcomes', 'rows' => $d['rows'], 'routeQuery' => $routeQuery, 'cols' => [
        ['CCA', 'label'], ['District', 'district'], ['Routes', 'owned_routes', 'n'], ['Visited', 'visited', 'n'], ['Skip rate', 'skip_rate', 'p'], ['Coverage', 'coverage', 'p'], ['Unvisited', 'unvisited', 'n'], ['Rank', 'rank', 'n'],
        ['Billing customers', 'billing.customers', 'n'], ['Estimated bills', 'billing.estimated_share', 'p'], ['Estimated on a working meter', 'billing.wm_share', 'p'], ['Unbilled', 'billing.unbilled_share', 'p'], ['Collection ratio', 'billing.collection_ratio', 'p'], ['Owing (GH¢)', 'billing.owing_amount', 'm'],
    ], 'empty' => 'No CCA owns a route.'])
    <p class="ui-hint">"Estimated on a working meter" is the share of the billed customers whose bill was estimated although the meter works (a read that was not made). A CCA with no visits has no skip rate.</p>
    @if (count($d['scatter']) > 1)
        <div wire:key="x1-scatter-{{ md5(json_encode($d['scatter'])) }}" style="margin-top:12px">
            <x-ui.chart type="scatter" label="Share estimated on a working meter" x-label="Skip rate (%)" unit="%" :labels="array_column($d['scatter'], 'label')" :series="$scatter($d['scatter'])" height="300" />
        </div>
        <p class="ui-hint">One point per CCA: skip rate across, the share of its routes' bills estimated on a working meter up. A pattern here is a correlation, not a cause.</p>
    @endif
</x-commercial.cross-part>
@endif

@if ($parts['reading']['data'] === null)
    <x-commercial.cross-part :part="$parts['reading']" />
@else
<x-commercial.cross-part :part="$parts['reading']" description="The reading figures alone (no billing needed).">
    @include('livewire.commercial.cross.table', ['label' => 'Reading figures per CCA', 'rows' => $parts['reading']['data']['rows'], 'cols' => [
        ['CCA', 'label'], ['District', 'district'], ['Routes', 'owned_routes', 'n'], ['Visited', 'visited', 'n'], ['Skipped', 'skipped', 'n'], ['Skip rate', 'skip_rate', 'p'], ['Coverage', 'coverage', 'p'], ['Unvisited', 'unvisited', 'n'], ['Rank', 'rank', 'n'],
    ]])
</x-commercial.cross-part>
@endif

@if ($parts['billing']['data'] === null)
    <x-commercial.cross-part :part="$parts['billing']" />
@else
<x-commercial.cross-part :part="$parts['billing']" description="The CCA beside a billing route is the owner of the route in the reading upload that overlaps the bill period the most (RouteCcaResolver); billing figures alone.">
    @if ($r['windows']['billing']['available'] ?? false)
        <p class="ui-hint">{{ $r['windows']['billing']['sentence'] }}</p>
    @endif
    @include('livewire.commercial.cross.table', ['label' => 'Billing figures per CCA', 'rows' => $parts['billing']['data']['rows'], 'cols' => [
        ['CCA', 'label'], ['Routes', 'routes', 'n'], ['Customers', 'customers', 'n'], ['Estimated bills', 'estimated_share', 'p'], ['Estimated on a working meter', 'wm_share', 'p'], ['Unbilled', 'unbilled_share', 'p'], ['Collection ratio', 'collection_ratio', 'p'], ['Owing (GH¢)', 'owing_amount', 'm'], ['Owing accounts', 'owing', 'n'],
    ]])
</x-commercial.cross-part>
@endif
