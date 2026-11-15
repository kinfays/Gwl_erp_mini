<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php
        $pct = fn ($value, $digits = 1) => $value === null ? '–' : number_format($value, $digits).'%';
        $num = fn ($value) => $value === null ? '–' : number_format($value);
        $money = fn ($value) => $value === null ? '–' : number_format($value, 2);
        $route = $r['route'];
        $parts = $r['parts'];
    @endphp

    <x-ui.page-header :title="'Route 360 · '.$route['label']" :description="$route['district'].' · code '.$route['code'].($route['unassigned'] ? ' (the code that means no route)' : '')">
        <x-slot:actions>
            @if ($canExport && $r['available'])
                <a href="{{ route('commercial.export', ['report' => 'cross-route', 'format' => 'excel', 'route' => $route['id'], ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.file-icon type="excel" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'cross-route', 'format' => 'pdf', 'route' => $route['id'], ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.file-icon type="pdf" /> PDF</a>
            @endif
            <a href="{{ route('commercial.cross') }}" class="btn btn-ghost">Across the files</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-toolbar dash-row" role="search" aria-label="Route 360 range">
        <input type="text" wire:model.live.debounce.500ms="dateFrom" class="form-input" style="max-width:11rem" placeholder="From date 2026-09-01" aria-label="From date (YYYY-MM-DD)" autocomplete="off">
        <input type="text" wire:model.live.debounce.500ms="dateTo" class="form-input" style="max-width:11rem" placeholder="To date 2026-09-30" aria-label="To date (YYYY-MM-DD)" autocomplete="off">
        <select wire:model.live="mode" class="form-input" aria-label="Which uploads each part uses">
            <option value="latest" @selected($r['mode'] === 'latest')>Latest of each part</option>
            <option value="same" @selected($r['mode'] === 'same')>The same range for every part</option>
        </select>
    </div>

    <x-ui.card title="This route" class="dash-row" data-route-head>
        <ul style="padding-left:18px;list-style:disc">
            <li>District: <strong>{{ $route['district'] }}</strong>, route code <strong>{{ $route['code'] }}</strong> (first seen in the {{ str_replace('_', ' ', $route['source'] ?? 'unknown') }}{{ $route['pinned'] ? '; pinned by a manager' : '' }}{{ $route['pending'] ? '; not yet confirmed in the route master' : '' }}).</li>
            @if ($route['opened_as_alias'])<li>The route you opened is an alias: this page shows the route it stands for.</li>@endif
            @if ($route['aliases'] !== [])<li>The same code is also printed under: @foreach ($route['aliases'] as $a){{ $a['district'] }} ({{ str_replace('_', ' ', $a['source']) }}){{ ! $loop->last ? ', ' : '' }}@endforeach. The customer list's accounts for this code may belong to either district.</li>@endif
        </ul>
        <table class="ui-table is-dense" style="margin-top:8px">
            <thead><tr><th>Part</th><th>Period used</th></tr></thead>
            <tbody>
                @foreach ($r['windows'] as $w)
                    <tr data-window="{{ $w['source'] }}"><td>{{ $w['title'] }}</td><td>@if (! $w['allowed'])<span class="ui-hint">Hidden: you do not have the permission for it.</span>@elseif ($w['available']){{ $w['sentence'] }}@else<span class="ui-hint">Not available: {{ $w['reason'] }}</span>@endif</td></tr>
                @endforeach
            </tbody>
        </table>
        <ul style="padding-left:18px;list-style:disc;margin-top:8px">@foreach ($r['notes'] as $note)<li class="ui-hint">{{ $note }}</li>@endforeach</ul>
    </x-ui.card>

    @if ($r['hidden'] !== [])
        <div class="ui-hint dash-row" data-hidden-line><strong>Hidden from you:</strong> @foreach ($r['hidden'] as $h){{ $h['title'] }} ({{ $h['reason'] }}) @endforeach</div>
    @endif

    {{-- ============================================================ READING --}}
    @if ($parts['reading']['data'] === null)
    <x-commercial.cross-part :part="$parts['reading']" />
@else
<x-commercial.cross-part :part="$parts['reading']">
        @php $d = $parts['reading']['data']; @endphp
        @if (! $d['present'])
            <p class="ui-hint">{{ $d['note'] }}</p>
        @else
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Owner CCA" :value="$d['owner'] ? trim($d['owner']['staff_id'].' '.$d['owner']['name']) : '–'" icon="user" :meta="$r['named'] ? 'the CCA with the most visits' : 'names need the CCA performance permission'" />
                <x-ui.stat-tile label="Verified strength" :value="$num($d['strength'])" icon="users" />
                <x-ui.stat-tile label="Visited" :value="$num($d['visited'])" icon="map" :meta="'read '.$num($d['read']).' · skipped '.$num($d['skipped'])" />
                <x-ui.stat-tile label="Coverage" :value="$pct($d['coverage'])" icon="gauge" :meta="'unvisited '.$num($d['unvisited'])" />
                <x-ui.stat-tile label="Skip rate" :value="$pct($d['skip_rate'])" icon="triangle-alert" meta="skipped ÷ visited" />
            </div>
            @if ($d['other_count'] > 0)
                <p class="ui-hint">{{ $d['other_count'] }} other CCA{{ $d['other_count'] === 1 ? '' : 's' }} {{ $d['other_count'] === 1 ? 'is' : 'are' }} listed on this route with no visits {{-- --}}@if ($d['others']): @foreach ($d['others'] as $o){{ trim($o['staff_id'].' '.$o['name']) }} (strength {{ $o['strength'] }}){{ ! $loop->last ? ', ' : '' }}@endforeach @endif. The strength counts once, on the owner.</p>
            @endif
            @if (count($d['trend']) > 1)
                <div wire:key="r360-reading-{{ md5(json_encode($d['trend'])) }}">
                    <x-ui.chart type="line" label="Coverage and skip rate by upload" unit="%" :labels="array_column($d['trend'], 'label')" :series="[['label' => 'Coverage', 'data' => array_column($d['trend'], 'coverage')], ['label' => 'Skip rate', 'data' => array_column($d['trend'], 'skip_rate')]]" height="220" />
                </div>
            @endif
            @include('livewire.commercial.cross.table', ['label' => 'Reading by upload', 'rows' => $d['trend'], 'empty' => 'No other upload names this route.', 'cols' => [['Period', 'label'], ['Strength', 'strength', 'n'], ['Read', 'read', 'n'], ['Skipped', 'skipped', 'n'], ['Visited', 'visited', 'n'], ['Coverage', 'coverage', 'p'], ['Skip rate', 'skip_rate', 'p']]])
        @endif
    </x-commercial.cross-part>
@endif

    {{-- ============================================================ BILLING --}}
    @if ($parts['billing']['data'] === null)
    <x-commercial.cross-part :part="$parts['billing']" />
@else
<x-commercial.cross-part :part="$parts['billing']">
        @php $d = $parts['billing']['data']; @endphp
        @if (! $d['present'])
            <p class="ui-hint">{{ $d['note'] }}</p>
        @else
            @php $b = $d['row']; @endphp
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Customers" :value="$num($b['customers'])" icon="users" :meta="$num($b['billed_customers']).' billed'" />
                <x-ui.stat-tile label="Month total" :value="'GH¢ '.$money($b['month_total'])" icon="banknote" :meta="'paid '.$money($b['paid'])" />
                <x-ui.stat-tile label="Collection ratio" :value="$pct($b['collection_ratio'])" icon="trending-up" />
                <x-ui.stat-tile label="Estimated bills" :value="$pct($b['estimated_share'])" icon="gauge" :meta="$num($b['est']['W']['customers']).' on a working meter'" />
                <x-ui.stat-tile label="Unbilled" :value="$num($b['unbilled'])" icon="triangle-alert" :meta="$pct($b['unbilled_share'])" />
                <x-ui.stat-tile label="Balance due" :value="'GH¢ '.$money($b['balance_due'])" icon="hand-coins" tone="warning" :meta="'owing '.$money($b['owing_amount']).' · credit '.$money($b['credit_amount'])" />
            </div>
            @if ($d['cca'])<p class="ui-hint">The CCA beside this route for the bill period: <strong>{{ trim($d['cca']['staff_id'].' '.$d['cca']['name']) }}</strong>, {{ $d['cca_sentence'] }}.</p>@endif
            <x-ui.table label="Bill status, estimation cause and read recency">
                <x-slot:head><tr><th>Measure</th><th class="num">Customers</th><th class="num">Share</th></tr></x-slot:head>
                @foreach (['REGULAR' => 'Regular', 'ESTIMATED' => 'Estimated', 'ESTIMATE' => 'Estimate', 'UNBILLED' => 'Unbilled'] as $status => $label)
                    <tr><td>Bill status: {{ $label }}</td><td class="num">{{ $num($b['bill'][$status]['customers']) }}</td><td class="num">{{ $pct($b['bill'][$status]['share']) }}</td></tr>
                @endforeach
                @foreach (\App\Services\Commercial\BillingDetails\BillingDetailsAnalytics::CAUSES as $cause => $label)
                    <tr><td>Estimation cause: {{ $label }}</td><td class="num">{{ $num($b['est'][$cause]['customers']) }}</td><td class="num">{{ $pct($b['est'][$cause]['share']) }}</td></tr>
                @endforeach
                @foreach (['read_0_30' => 'Last read 0 to 30 days before the bill', 'read_31_60' => 'Last read 31 to 60 days', 'read_61_90' => 'Last read 61 to 90 days', 'read_over_90' => 'Last read over 90 days', 'read_never' => 'Never read'] as $key => $label)
                    <tr><td>{{ $label }}</td><td class="num">{{ $num($b[$key]) }}</td><td class="num">{{ $pct(\App\Services\Commercial\Cross\CrossStats::pct($b[$key], $b['customers'])) }}</td></tr>
                @endforeach
            </x-ui.table>
            <x-ui.table label="Consumption, charges and collections">
                <x-slot:head><tr><th>Measure</th><th class="num">Value</th></tr></x-slot:head>
                <tr><td>Consumption</td><td class="num">{{ number_format($b['consume'], 1) }}</td></tr>
                <tr><td>Consumption per bill</td><td class="num">{{ $b['consume_per_bill'] === null ? '–' : number_format($b['consume_per_bill'], 1) }}</td></tr>
                <tr><td>Zero-consumption regular bills</td><td class="num">{{ $num($b['zero_regular']) }}</td></tr>
                @foreach (['water' => 'Water charge', 'rural' => 'Rural water', 'fire' => 'Fire fighting', 'sewage' => 'Sewage', 'service' => 'Service charge', 'month_total' => 'Month total'] as $key => $label)
                    <tr><td>{{ $label }} (GH¢)</td><td class="num">{{ $money($b[$key]) }}</td></tr>
                @endforeach
                <tr><td>Paid this month (GH¢)</td><td class="num">{{ $money($b['paid']) }}</td></tr>
                <tr><td>Previous balance (GH¢)</td><td class="num">{{ $money($b['previous_balance']) }}</td></tr>
                <tr><td>Balance owing: {{ $num($b['owing']) }} customers (GH¢)</td><td class="num">{{ $money($b['owing_amount']) }}</td></tr>
                <tr><td>Balance in credit: {{ $num($b['credit']) }} customers (GH¢)</td><td class="num">{{ $money($b['credit_amount']) }}</td></tr>
            </x-ui.table>
            @if (count($d['trend']) > 1)
                <div wire:key="r360-billing-{{ md5(json_encode($d['trend'])) }}">
                    <x-ui.chart type="line" label="Collection ratio and estimated bills by upload" unit="%" :labels="array_column($d['trend'], 'label')" :series="[['label' => 'Collection ratio', 'data' => array_column($d['trend'], 'collection_ratio')], ['label' => 'Estimated bills', 'data' => array_column($d['trend'], 'estimated_share')]]" height="220" />
                </div>
            @endif
            @include('livewire.commercial.cross.table', ['label' => 'Billing by upload', 'rows' => $d['trend'], 'cols' => [['Period', 'label'], ['Customers', 'customers', 'n'], ['Month total', 'month_total', 'm'], ['Paid', 'paid', 'm'], ['Collection ratio', 'collection_ratio', 'p'], ['Estimated bills', 'estimated_share', 'p'], ['Unbilled', 'unbilled_share', 'p'], ['Balance due', 'balance_due', 'm']]])
        @endif
    </x-commercial.cross-part>
@endif

    {{-- ============================================================ CUSTOMER LIST --}}
    @if ($parts['customers']['data'] === null)
    <x-commercial.cross-part :part="$parts['customers']" />
@else
<x-commercial.cross-part :part="$parts['customers']">
        @php $d = $parts['customers']['data']; $labels = $d['labels'] ?? []; @endphp
        @if (! $d['present'])
            <p class="ui-hint">{{ $d['note'] }}</p>
        @else
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Accounts" :value="$num($d['customers'])" icon="users" :meta="$num($d['billing_accounts']).' with a billing status'" />
                <x-ui.stat-tile label="Metered" :value="$num($d['metered'])" icon="gauge" meta="a working or faulty meter" />
                <x-ui.stat-tile label="Faulty or no meter" :value="$num($d['faulty'] + $d['no_meter'])" icon="triangle-alert" :meta="$num($d['faulty']).' faulty · '.$num($d['no_meter']).' none'" />
                <x-ui.stat-tile label="Reachable" :value="$pct($d['reach_share'])" icon="smartphone" :meta="$num($d['reach']['two_numbers']).' have two numbers'" />
            </div>
            @if ($d['aliased'])<p class="ui-hint">This route's code is printed under more than one district: some of these accounts may belong to the other.</p>@endif
            <p class="ui-hint">The list puts these accounts in: @foreach ($d['list_districts'] as $name => $n){{ $name }} ({{ number_format($n) }}){{ ! $loop->last ? ', ' : '' }}@endforeach.</p>
            @foreach (['by_status' => ['Account status', 'status'], 'by_category' => ['Service category', 'category'], 'by_meter' => ['Meter status', 'meter']] as $key => [$title, $lookup])
                <x-ui.table :label="$title">
                    <x-slot:head><tr><th>{{ $title }}</th><th class="num">Accounts</th><th class="num">Share</th></tr></x-slot:head>
                    @foreach ($d[$key] as $code => $n)
                        <tr><td>{{ $code === '' ? '(none)' : $code }}@if (! empty($labels[$lookup][$code])) <span class="ui-hint">{{ $labels[$lookup][$code] }}</span>@endif</td><td class="num">{{ number_format($n) }}</td><td class="num">{{ $pct(\App\Services\Commercial\Cross\CrossStats::pct($n, $d['customers'])) }}</td></tr>
                    @endforeach
                </x-ui.table>
            @endforeach
            <p class="ui-hint">Contact reachability: {{ $num($d['reach']['reachable']) }} of {{ $num($d['reach']['customers']) }} accounts have at least one valid mobile number; {{ $num($d['reach']['no_mobile']) }} have none, {{ $num($d['reach']['invalid_mobile']) }} a number that is not valid, {{ $num($d['reach']['with_email']) }} an e-mail address. Counts only: no number is shown.</p>
        @endif
    </x-commercial.cross-part>
@endif

    {{-- ============================================================ CHECKS --}}
    @if ($parts['checks']['data'] === null)
    <x-commercial.cross-part :part="$parts['checks']" />
@else
<x-commercial.cross-part :part="$parts['checks']">
        @php $d = $parts['checks']['data']; @endphp
        @if ($d['strength'])
            @php $s = $d['strength']; @endphp
            <p><strong>Strength.</strong> Verified strength {{ $num($s['strength']) }}; metered accounts in the list {{ $num($s['metered']) }}@if ($s['pct_metered'] !== null) ({{ $pct($s['pct_metered']) }} apart){{ $s['beyond'] ? ' — beyond the limit' : '' }}@endif; customers in billing {{ $num($s['billing_customers']) }}@if ($s['pct_billing'] !== null) ({{ $pct($s['pct_billing']) }} apart) @endif.</p>
        @endif
        @if ($d['accounts'])
            @php $a = $d['accounts']; @endphp
            <p><strong>Accounts.</strong> {{ $num($a['billing_accounts']) }} accounts on this route's bill; {{ $num($a['in_billing_not_in_list']) }} of them are not in the customer list. The list holds {{ $num($a['list_accounts']) }} accounts on this route, of which {{ $num($a['in_list_not_on_this_bill']) }} are not on this route's bill ({{ $a['link_label'] }}).</p>
        @endif
        <p><strong>Code.</strong> @if ($d['alias']['has_aliases']) The same code is printed under another district (see the top of this page). @else This code is printed under one district only. @endif @if ($route['unassigned']) This is the code that means "no route": its accounts have no reading CCA. @endif</p>
        @if ($d['strength'] === null && $d['accounts'] === null)<p class="ui-hint">The checks need the reading figures, or both the billing figures and the customer list.</p>@endif
    </x-commercial.cross-part>
@endif
</div>
