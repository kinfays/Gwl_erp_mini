{{-- X9: read recency against visits --}}
@php $named = $r['named']; @endphp
@if ($parts['routes']['data'] === null)
    <x-commercial.cross-part :part="$parts['routes']" />
@else
<x-commercial.cross-part :part="$parts['routes']" description="The billing's read recency (days from the last read to the end of the bill period) beside the reading coverage and unvisited of the same route. A route with old reads and low coverage is not being reached.">
    @php $d = $parts['routes']['data']; @endphp
    @include('livewire.commercial.cross.table', ['label' => 'Read recency by route', 'rows' => $d['routes'], 'routeQuery' => $routeQuery, 'cols' => array_merge(
        [['Route', 'label', 't', 'route_id'], ['District', 'district']],
        $named ? [['CCA (reading)', 'owner_name']] : [],
        [['Customers', 'customers', 'n'], ['0-30 days', 'read_0_30', 'n'], ['31-60', 'read_31_60', 'n'], ['61-90', 'read_61_90', 'n'], ['Over 90', 'read_over_90', 'n'], ['Never read', 'read_never', 'n'], ['Over 90 or never', 'stale_share', 'p'], ['Reading coverage', 'coverage', 'p'], ['Unvisited', 'unvisited', 'n']]
    )])
</x-commercial.cross-part>
@endif

@if ($parts['ccas']['data'] === null)
    <x-commercial.cross-part :part="$parts['ccas']" />
@else
<x-commercial.cross-part :part="$parts['ccas']" description="The same per CCA (the owner of the route in the reading upload).">
    @include('livewire.commercial.cross.table', ['label' => 'Read recency per CCA', 'rows' => $parts['ccas']['data']['rows'], 'cols' => [['CCA', 'label'], ['Routes', 'routes', 'n'], ['Customers', 'customers', 'n'], ['Over 90 days', 'read_over_90', 'n'], ['Never read', 'read_never', 'n'], ['Over 90 or never', 'stale_share', 'p'], ['Reading coverage', 'coverage', 'p'], ['Unvisited', 'unvisited', 'n']]])
</x-commercial.cross-part>
@endif
