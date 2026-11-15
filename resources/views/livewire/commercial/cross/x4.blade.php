{{-- X4: strength reconciliation --}}
@if ($parts['reconciliation']['data'] === null)
    <x-commercial.cross-part :part="$parts['reconciliation']" />
@else
<x-commercial.cross-part :part="$parts['reconciliation']" description="The verified strength of a route (reading) against the customer list's metered accounts (a working or faulty meter, any account status) and the customers the Detailed Billing Report has on the route.">
    @php $d = $parts['reconciliation']['data']; $m = $d['medians']; $sg = fn ($v) => $v === null ? '–' : ($v > 0 ? '+' : '').number_format($v, 1); @endphp
    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Routes compared" :value="number_format($d['compared_metered'])" icon="map" :meta="number_format($d['totals']['routes_with_all_three']).' have all three counts'" />
        <x-ui.stat-tile label="Median strength − metered" :value="$sg($m['strength_minus_metered'])" icon="scale" meta="accounts, signed" />
        <x-ui.stat-tile label="Median difference" :value="$m['pct_metered'] === null ? '–' : number_format($m['pct_metered'], 1).'%'" icon="percent" meta="of the metered accounts, ignoring sign" />
        <x-ui.stat-tile label="Median strength − billing" :value="$sg($m['strength_minus_billing'])" icon="scale" meta="accounts, signed" />
        <x-ui.stat-tile label="Beyond the limit" :value="number_format(count($d['beyond']))" icon="triangle-alert" tone="warning" :meta="'more than '.number_format($d['mismatch_pct'], 0).'% from the metered accounts'" />
    </div>
    <p class="ui-hint">Over the {{ number_format($d['totals']['routes_with_all_three']) }} routes that have all three counts: strength {{ number_format($d['totals']['strength']) }}, metered accounts {{ number_format($d['totals']['metered']) }}, billing customers {{ number_format($d['totals']['billing_customers']) }}. A code printed under more than one district (marked) may have list accounts that belong to either.</p>
    @include('livewire.commercial.cross.table', ['label' => 'Three counts side by side', 'rows' => $d['rows'], 'routeQuery' => $routeQuery, 'cols' => [
        ['Route', 'label', 't', 'route_id'], ['District', 'district'], ['CCA', 'owner_name'], ['Verified strength', 'strength', 'n'], ['Metered accounts (list)', 'metered', 'n'], ['Billing customers', 'billing_customers', 'n'],
        ['Strength − metered', 'diff_metered', 'n'], ['Off the metered by', 'pct_metered', 'p'], ['Strength − billing', 'diff_billing', 'n'], ['Off the billing by', 'pct_billing', 'p'], ['Ambiguous code', 'aliased_text'],
    ]])
</x-commercial.cross-part>
@endif

@if ($parts['strength']['data'] === null)
    <x-commercial.cross-part :part="$parts['strength']" />
@else
<x-commercial.cross-part :part="$parts['strength']" description="The verified strength alone.">
    @include('livewire.commercial.cross.table', ['label' => 'Verified strength by route', 'rows' => $parts['strength']['data']['rows'], 'cap' => 50, 'cols' => [['Route', 'label'], ['District', 'district'], ['CCA', 'owner_name'], ['Verified strength', 'strength', 'n']]])
</x-commercial.cross-part>
@endif
