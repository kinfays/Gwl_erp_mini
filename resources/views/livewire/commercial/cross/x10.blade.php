{{-- X10: district and route evidence (Q12: which system owns a route's district?) --}}
@if ($parts['matrix']['data'] === null)
    <x-commercial.cross-part :part="$parts['matrix']" />
@else
<x-commercial.cross-part :part="$parts['matrix']" :description="'How many accounts the customer list puts in one district and the Detailed Billing Report puts in another. Rows are the district the LIST holds the account in, columns the district of its BILLING route; the diagonal is agreement. This is the evidence for the question: which system owns a route\'s district?'">
    @php $d = $parts['matrix']['data']; @endphp
    <p class="ui-hint">Customers are currently seen by a district user by the customer list's district: this page changes nothing about who sees which customer. {{ $d['link_label'] }}.</p>
    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Linked accounts" :value="number_format($d['total'])" icon="layers" />
        <x-ui.stat-tile label="Same district in both" :value="number_format($d['agree'])" icon="check" :meta="$d['agree_share'] === null ? '' : number_format($d['agree_share'], 1).'% of the linked accounts'" />
        <x-ui.stat-tile label="Different district" :value="number_format($d['disagree'])" icon="triangle-alert" tone="warning" :meta="$d['disagree_share'] === null ? '' : number_format($d['disagree_share'], 1).'% of the linked accounts'" />
    </div>
    <x-ui.table label="The district disagreement matrix">
        <x-slot:head>
            <tr><th>List district ↓ / billing district →</th>@foreach ($d['columns'] as $c)<th class="num">{{ $c['district'] }}</th>@endforeach<th class="num">Accounts</th><th class="num">Agree</th></tr>
        </x-slot:head>
        @forelse ($d['rows'] as $row)
            <tr>
                <td>{{ $row['list_district'] }}</td>
                @foreach ($row['cells'] as $cell)
                    <td class="num" @if ($cell['diagonal']) style="background:var(--color-success-soft);color:var(--color-success-ink);font-weight:600" data-diagonal @endif>{{ number_format($cell['customers']) }}@if ($cell['share'] !== null) <span class="ui-hint">({{ number_format($cell['share'], 1) }}%)</span>@endif</td>
                @endforeach
                <td class="num">{{ number_format($row['total']) }}</td>
                <td class="num">{{ $row['agree_share'] === null ? '–' : number_format($row['agree_share'], 1).'%' }}</td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="count($d['columns']) + 3" icon="layers" title="No account was linked." />
        @endforelse
    </x-ui.table>
</x-commercial.cross-part>
@endif

@if ($parts['master']['data'] === null)
    <x-commercial.cross-part :part="$parts['master']" />
@else
<x-commercial.cross-part :part="$parts['master']" :description="'From the route master: the codes printed under more than one district. The district the system uses for a route follows the '.($parts['master']['data']['authority'] ?? 'billing and reading').' labels (Settings), unless a manager pinned it.'">
    @php $d = $parts['master']['data']; @endphp
    @include('livewire.commercial.cross.table', ['label' => 'Codes printed under more than one district', 'rows' => $d['codes'], 'routeQuery' => $routeQuery, 'empty' => 'No code is printed under two districts.', 'cols' => [
        ['Code', 'code', 't', 'route_id'], ['District used', 'canonical_district'], ['Also printed under', 'other_districts_text'], ['Decided by', 'decided_by'], ['Customers in billing', 'billing_customers', 'n'], ['Accounts in the list', 'list_accounts', 'n'],
    ]])
    <p class="ui-hint">Route 0000 (the code that means "no route"): {{ number_format($d['zero']['billing_customers']) }} customers in billing, {{ number_format($d['zero']['list_accounts']) }} accounts in the list.</p>
</x-commercial.cross-part>
@endif

@if ($parts['coverage']['data'] === null)
    <x-commercial.cross-part :part="$parts['coverage']" />
@else
<x-commercial.cross-part :part="$parts['coverage']" description="Routes that one file knows and another does not (canonical routes: an alias is counted under the route it stands for).">
    @php $d = $parts['coverage']['data']; @endphp
    <p class="ui-hint">Routes in a district nobody could place yet: {{ number_format($d['unplaced']['reading']) }} in the reading upload, {{ number_format($d['unplaced']['billing']) }} in the billing upload (resolve them on the upload's own page).</p>
    @foreach (['billing_without_reading' => ['Routes in billing without a reading row (no CCA reads them)', 'No such route.'], 'reading_without_billing' => ['Reading routes absent from billing', 'No such route.'], 'list_in_neither' => ['Customer-list routes in neither billing nor reading', 'No such route.']] as $key => [$title, $empty])
        <h4 style="margin-top:12px">{{ $title }} ({{ number_format(count($d[$key])) }})</h4>
        @include('livewire.commercial.cross.table', ['label' => $title, 'rows' => $d[$key], 'cap' => 100, 'routeQuery' => $routeQuery, 'empty' => $empty, 'cols' => [['Route', 'label', 't', 'route_id'], ['District', 'district']]])
    @endforeach
</x-commercial.cross-part>
@endif
