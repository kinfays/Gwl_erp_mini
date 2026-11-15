{{-- X3: skip against estimation, route by route --}}
@if ($parts['correlation']['data'] === null)
    <x-commercial.cross-part :part="$parts['correlation']" />
@else
<x-commercial.cross-part :part="$parts['correlation']" description="Routes that were read AND billed, with enough billing customers. A correlation says two figures move together; it does not say that one causes the other.">
    @php $d = $parts['correlation']['data']; $r2 = fn ($v) => $v === null ? '–' : number_format($v, 3); @endphp
    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Routes used" :value="number_format($d['overall']['routes'])" icon="map" :meta="'at least '.number_format($d['min_customers']).' billing customers each'" />
        <x-ui.stat-tile label="Skip rate against working-meter estimates" :value="$r2($d['overall']['r_wm'])" icon="activity" meta="Pearson correlation, -1 to 1" />
        <x-ui.stat-tile label="Skip rate against all estimates" :value="$r2($d['overall']['r_est'])" icon="activity" meta="Pearson correlation, -1 to 1" />
    </div>
    <p class="ui-hint">Left out: {{ number_format($d['left_out_small']) }} route(s) with fewer than {{ number_format($d['min_customers']) }} billing customers, {{ number_format($d['left_out_no_visits']) }} with no visits (no skip rate). A coefficient is shown only with at least 3 routes.</p>

    @if (count($d['points']) > 1)
        <div wire:key="x3-scatter-{{ md5(json_encode($d['points'])) }}" style="margin-top:12px">
            <x-ui.chart type="scatter" label="Share of bills estimated on a working meter" x-label="Skip rate (%)" unit="%" :labels="array_column($d['points'], 'label')"
                :series="[['label' => 'Route', 'data' => array_map(fn (array $p) => ['x' => $p['skip_rate'], 'y' => $p['wm_share']], $d['points'])]]" height="320" />
        </div>
    @endif

    @include('livewire.commercial.cross.table', ['label' => 'Correlation by district', 'rows' => $d['by_district'], 'empty' => 'No district has routes to correlate.', 'cols' => [
        ['District', 'district'], ['Routes', 'routes', 'n'], ['Skip rate vs working-meter estimates', 'r_wm', 't'], ['Skip rate vs all estimates', 'r_est', 't'],
    ]])
    <p class="ui-hint">A district needs at least {{ $d['min_district_routes'] }} routes before a coefficient is worked out for it on its own (shown as a dash below that).</p>

    <h4 style="margin-top:16px">Routes high on one and low on the other</h4>
    @if ($d['divergent']['thresholds'] === null)
        <p class="ui-hint">At least 4 routes are needed to call a route high or low.</p>
    @else
        <p class="ui-hint">High = the top quarter, low = the bottom quarter (the 75th and 25th percentiles of the routes shown).</p>
        @include('livewire.commercial.cross.table', ['label' => 'Skip rate high, estimates on working meters low', 'rows' => $d['divergent']['skip_high_estimate_low'], 'routeQuery' => $routeQuery, 'empty' => 'None.', 'cols' => [['Route', 'label', 't', 'route_id'], ['District', 'district'], ['Skip rate', 'skip_rate', 'p'], ['Estimated on a working meter', 'wm_share', 'p']]])
        @include('livewire.commercial.cross.table', ['label' => 'Skip rate low, estimates on working meters high', 'rows' => $d['divergent']['skip_low_estimate_high'], 'routeQuery' => $routeQuery, 'empty' => 'None.', 'cols' => [['Route', 'label', 't', 'route_id'], ['District', 'district'], ['Skip rate', 'skip_rate', 'p'], ['Estimated on a working meter', 'wm_share', 'p']]])
    @endif

    @include('livewire.commercial.cross.table', ['label' => 'The routes used', 'rows' => $d['points'], 'routeQuery' => $routeQuery, 'cols' => [['Route', 'label', 't', 'route_id'], ['District', 'district'], ['Customers', 'customers', 'n'], ['Billed', 'billed', 'n'], ['Skip rate', 'skip_rate', 'p'], ['Estimated on a working meter', 'wm_share', 'p'], ['All estimates', 'est_share', 'p']]])
</x-commercial.cross-part>
@endif

@if ($parts['skip']['data'] === null)
    <x-commercial.cross-part :part="$parts['skip']" />
@else
<x-commercial.cross-part :part="$parts['skip']" description="Reading alone.">
    @include('livewire.commercial.cross.table', ['label' => 'Skip rate by route', 'rows' => $parts['skip']['data']['rows'], 'cap' => 50, 'cols' => [['Route', 'label'], ['District', 'district'], ['Visited', 'visited', 'n'], ['Skip rate', 'skip_rate', 'p']]])
</x-commercial.cross-part>
@endif

@if ($parts['estimates']['data'] === null)
    <x-commercial.cross-part :part="$parts['estimates']" />
@else
<x-commercial.cross-part :part="$parts['estimates']" description="Billing alone.">
    @include('livewire.commercial.cross.table', ['label' => 'Estimates on working meters by route', 'rows' => $parts['estimates']['data']['rows'], 'cap' => 50, 'cols' => [['Route', 'label'], ['District', 'district'], ['Billed', 'billed', 'n'], ['Estimated on a working meter', 'wm_share', 'p'], ['All estimates', 'est_share', 'p']]])
</x-commercial.cross-part>
@endif
