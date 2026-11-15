{{-- X6: status exceptions with their CCA and route --}}
@php $named = $r['named']; @endphp
@if ($parts['exceptions']['data'] === null)
    <x-commercial.cross-part :part="$parts['exceptions']" />
@else
<x-commercial.cross-part :part="$parts['exceptions']" description="Billing status flags (an account of a billing status, so NSC counts; HOLD means billing is paused). Rates are of the figure that makes sense for each: active accounts, billed accounts, all customers.">
    @php $d = $parts['exceptions']['data']; $t = $d['totals']; @endphp
    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Active, unbilled" :value="number_format($t['unbilled_active'])" icon="triangle-alert" tone="warning" :meta="'of '.number_format($t['active_billing']).' accounts with a billing status'" />
        <x-ui.stat-tile label="Billed, not active" :value="number_format($t['billed_not_active'])" icon="triangle-alert" meta="a disconnected account that was billed" />
        <x-ui.stat-tile label="On HOLD" :value="number_format($t['hold'])" icon="pause" meta="billing paused" />
    </div>
    @if ($named)
        @include('livewire.commercial.cross.table', ['label' => 'Status exceptions per CCA', 'rows' => $d['ccas'], 'cols' => [
            ['CCA', 'staff_id'], ['Name', 'name'], ['Routes', 'routes', 'n'], ['Active, unbilled', 'unbilled_active', 'n'], ['Rate (of active)', 'unbilled_active_rate', 'p'], ['Billed, not active', 'billed_not_active', 'n'], ['Rate (of billed)', 'billed_not_active_rate', 'p'], ['On HOLD', 'hold', 'n'], ['Rate (of customers)', 'hold_rate', 'p'],
        ]])
    @else
        <p class="ui-hint">No CCA is named here: the per-CCA table needs the CCA performance permission.</p>
    @endif
    @include('livewire.commercial.cross.table', ['label' => 'Status exceptions per route', 'rows' => $d['routes'], 'routeQuery' => $routeQuery, 'cols' => array_merge(
        [['Route', 'label', 't', 'route_id'], ['District', 'district']],
        $named ? [['CCA', 'cca_name']] : [],
        [['Customers', 'customers', 'n'], ['Active, unbilled', 'unbilled_active', 'n'], ['Rate', 'unbilled_active_rate', 'p'], ['Billed, not active', 'billed_not_active', 'n'], ['Rate', 'billed_not_active_rate', 'p'], ['On HOLD', 'hold', 'n'], ['Rate', 'hold_rate', 'p']]
    )])
    @foreach (['active_unbilled' => 'Active accounts that are unbilled', 'billed_not_active' => 'Billed accounts that are not active'] as $kind => $title)
        @php $list = $d['lists'][$kind] ?? null; @endphp
        @if ($list)
            <h4 style="margin-top:16px">{{ $title }}</h4>
            @include('livewire.commercial.cross.table', ['label' => $title, 'rows' => $list['rows'], 'cap' => 50, 'empty' => 'No account on this list.', 'cols' => array_merge([['Account', 'account'], ['Route', 'route'], ['District', 'district']], $named ? [['CCA', 'cca']] : [], [[$list['amount_label'], 'amount', 'm']])])
        @endif
    @endforeach
</x-commercial.cross-part>
@endif

@if ($parts['moved']['data'] === null)
    <x-commercial.cross-part :part="$parts['moved']" />
@else
<x-commercial.cross-part :part="$parts['moved']" description="Accounts the bill showed with an active status that the customer list now shows disconnected (DISC or DISN). Counts per route and per CCA from the link; the accounts themselves are on X5.">
    @php $d = $parts['moved']['data']; $t = $d['totals']; @endphp
    <p class="ui-hint">{{ number_format($t['now_disconnected']) }} of {{ number_format($t['compared']) }} linked accounts.</p>
    @if ($named)
        @include('livewire.commercial.cross.table', ['label' => 'Billed as active, now disconnected, per CCA', 'rows' => $d['ccas'], 'cols' => [['CCA', 'staff_id'], ['Name', 'name'], ['Routes', 'routes', 'n'], ['Linked accounts', 'compared', 'n'], ['Now disconnected', 'now_disconnected', 'n'], ['Rate', 'now_disconnected_rate', 'p']]])
    @endif
    @include('livewire.commercial.cross.table', ['label' => 'Billed as active, now disconnected, per route', 'rows' => array_values(array_filter($d['routes'], fn ($x) => ($x['now_disconnected'] ?? 0) > 0)), 'routeQuery' => $routeQuery, 'empty' => 'No route has such an account.', 'cols' => array_merge([['Route', 'label', 't', 'route_id'], ['District', 'district']], $named ? [['CCA', 'cca_name']] : [], [['Linked accounts', 'compared', 'n'], ['Now disconnected', 'now_disconnected', 'n'], ['Rate', 'now_disconnected_rate', 'p']])])
</x-commercial.cross-part>
@endif
