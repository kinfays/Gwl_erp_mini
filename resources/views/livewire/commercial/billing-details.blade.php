<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php
        $pct = fn ($value, $digits = 1) => $value === null ? '–' : number_format($value, $digits).'%';
        $num = fn ($value) => $value === null ? '–' : number_format($value);
        $money = fn ($value) => $value === null ? '–' : number_format($value, 2);
        $a = $r['analysis'] ?? null;
        $named = $r['named'];
        $showCca = $named && $r['cca_source'];
        $routeCols = fn (array $cols) => array_merge([['Route', 'label', 't', 'route_id'], ['District', 'district']], $showCca ? [['CCA', 'cca_label']] : [], $cols);
        if ($a) {
            // the CCA beside every route table: "id name" (only for a viewer who may see individual CCAs)
            $a['routes'] = array_map(fn (array $x) => $x + ['cca_label' => $x['cca_staff_id'] ? trim($x['cca_staff_id'].' '.$x['cca_name']) : null], $a['routes']);
        }
        $statusCols = [['Customers', 'customers', 'n'], ['Regular', 'bill.REGULAR.customers', 'n'], ['Estimated', 'bill.ESTIMATED.customers', 'n'], ['Estimate', 'bill.ESTIMATE.customers', 'n'], ['Unbilled', 'bill.UNBILLED.customers', 'n'], ['Month total (GH¢)', 'month_total', 'm'], ['Estimated share', 'estimated_share', 'p'], ['Unbilled share', 'unbilled_share', 'p']];
    @endphp

    <x-ui.page-header title="Billing details" description="From the Detailed Billing Report (one row per customer, kept as figures and capped account lists). Estimated bills are both ESTIMATED and ESTIMATE. A negative balance is a customer credit and is never netted off what customers owe.">
        <x-slot:actions>
            @if ($r['available'] && $canExport)
                <a href="{{ route('commercial.export', ['report' => 'billing-details', 'format' => 'excel', ...$exportQuery, 'section' => $activeTab]) }}" class="btn btn-secondary"><x-ui.file-icon type="excel" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'billing-details', 'format' => 'pdf', ...$exportQuery, 'section' => $activeTab]) }}" class="btn btn-secondary"><x-ui.file-icon type="pdf" /> PDF</a>
            @endif
            <a href="{{ route('commercial.billing') }}" class="btn btn-ghost">Billing summary</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $r['available'])
        <x-ui.card>
            <x-ui.empty-state icon="file-spreadsheet" title="No detailed billing report has been loaded yet." description="Upload the Detailed Billing Report (rptBillingDetails_ExP) and the analysis appears here.">
                @if ($canUpload)
                    <a href="{{ route('commercial.batches') }}" class="btn btn-primary">Go to uploads</a>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="ui-toolbar dash-row" role="search" aria-label="Billing details filters">
            <x-ui.segmented label="Section" wire:model.live="tab" :options="$tabs" :value="$activeTab" />
        </div>
        <div class="ui-toolbar dash-row" role="search" aria-label="Billing details range and filters">
            @if (count($r['regions']) > 1)
                <select wire:model.live="region" class="form-input" aria-label="Region">
                    @foreach ($r['regions'] as $id => $name)<option value="{{ $id }}" @selected($id === $r['region_id'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="preset" class="form-input" aria-label="A whole month">
                <option value="">A whole month…</option>
                @foreach ($r['month_options'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <input type="text" wire:model.live.debounce.500ms="dateFrom" class="form-input" style="max-width:11rem" placeholder="From date 2026-09-01" aria-label="From date (YYYY-MM-DD)" autocomplete="off">
            <input type="text" wire:model.live.debounce.500ms="dateTo" class="form-input" style="max-width:11rem" placeholder="To date 2026-09-30" aria-label="To date (YYYY-MM-DD)" autocomplete="off">
            @if (count($r['districts']) > 1 || $r['district_id'])
                <select wire:model.live="district" class="form-input" aria-label="District">
                    <option value="">All districts</option>
                    @foreach ($r['districts'] as $id => $name)<option value="{{ $id }}" @selected($id === $r['district_id'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            @if (count($r['categories']) > 1 || $r['category'])
                <select wire:model.live="category" class="form-input" aria-label="Service category">
                    <option value="">All categories</option>
                    @foreach ($r['categories'] as $code => $name)<option value="{{ $code }}" @selected((string) $code === (string) $r['category'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            @if ($named && count($r['ccas']) > 0)
                <select wire:model.live="cca" class="form-input" aria-label="CCA">
                    <option value="">All CCAs</option>
                    @foreach ($r['ccas'] as $staff => $name)<option value="{{ $staff }}" @selected((string) $staff === (string) $r['cca'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            @if (count($r['routes_options']) > 1 || $r['route_id'])
                <select wire:model.live="route" class="form-input" aria-label="Route">
                    <option value="">All routes</option>
                    @foreach ($r['routes_options'] as $id => $name)<option value="{{ $id }}" @selected($id === $r['route_id'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            @if ($dateFrom !== '' || $dateTo !== '' || $r['district_id'] || $r['category'] || $r['cca'] || $r['route_id'])
                <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear filters</button>
            @endif
        </div>

        <div class="ui-hint dash-row" data-range-notes>
            <strong>{{ $r['region'] }} · {{ $r['range']['label'] }}</strong>
            @if ($r['range']['range_days'])
                ({{ number_format($r['range']['covered_days']) }} of {{ number_format($r['range']['range_days']) }} days covered{{ $r['range']['gaps'] !== [] ? ', not covered: '.\App\Services\Commercial\CommercialPeriods::describeIntervals($r['range']['gaps']) : '' }})
            @endif
            <ul style="padding-left:18px;list-style:disc">@foreach ($r['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>
            @if ($showCca)<p>The CCA beside each route is {{ $r['cca_source']['sentence'] }}.</p>@endif
            @if (! $named)<p>No CCA is named on this page: individual CCA figures need the CCA performance permission.</p>@endif
        </div>

        @if (! $a || $a['totals']['customers'] === 0)
            <x-ui.card>
                <x-ui.empty-state icon="file-spreadsheet" title="Nothing matches." description="No upload lies inside this range, or the filters leave no customers. Pick other dates or clear the filters." />
            </x-ui.card>
        @else
            @php $t = $a['totals']; @endphp

            {{-- ============================================================ OVERVIEW: B1 --}}
            @if ($activeTab === 'overview')
                <x-commercial.trend-block :block="$trendBlock" kind="billing" :query="array_filter(['region' => $r['region_id'], 'district' => $r['district_id'], 'from' => $dateFrom, 'to' => $dateTo, 'route' => $r['route_id'], 'cca' => $r['cca']])" />
                <div class="ui-stat-grid dash-row">
                    <x-ui.stat-tile label="Customers" :value="number_format($t['customers'])" icon="users" :meta="number_format($t['billed_customers']).' billed'" />
                    <x-ui.stat-tile label="Month total" :value="'GH¢ '.$money($t['month_total'])" icon="banknote" tone="info" meta="billed for the period" />
                    <x-ui.stat-tile label="Collection ratio" :value="$pct($t['collection_ratio'])" icon="trending-up" tone="primary" meta="paid this month ÷ month total" />
                    <x-ui.stat-tile label="Estimated bills" :value="$pct($t['estimated_share'])" icon="gauge" :tone="($t['estimated_share'] ?? 0) > 20 ? 'warning' : 'default'" :meta="number_format($t['estimated_customers']).' of the billed'" />
                    <x-ui.stat-tile label="Unbilled" :value="$pct($t['unbilled_share'])" icon="triangle-alert" :meta="number_format($t['unbilled']).' customers'" />
                    <x-ui.stat-tile label="Balance due" :value="'GH¢ '.$money($t['balance_due'])" icon="hand-coins" tone="warning" :meta="'owing '.$money($t['owing_amount']).' · credit '.$money($t['credit_amount'])" />
                </div>

                @if (count($r['trend']) > 1)
                    <x-ui.card title="Collection and estimated bills by upload" description="One point per upload, labelled with the dates it covers (B13)." class="dash-row">
                        <div wire:key="bd-trend-{{ md5(json_encode(array_column($r['trend'], 'month_total'))) }}">
                            <x-ui.chart type="line" label="Collection ratio and estimated share by upload" unit="%"
                                :labels="array_column($r['trend'], 'label')"
                                :series="[['label' => 'Collection ratio', 'data' => array_column($r['trend'], 'collection_ratio')], ['label' => 'Estimated bills', 'data' => array_column($r['trend'], 'estimated_share')]]" height="260" />
                        </div>
                    </x-ui.card>
                @endif

                @include('livewire.commercial.partials.bd-table', ['title' => 'B1 Bill status by district', 'description' => 'Customers and the month total by bill status. ESTIMATED and ESTIMATE are both estimated bills.', 'rows' => $a['districts'], 'cols' => array_merge([['District', 'name']], $statusCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B1 Bill status by service category', 'rows' => $a['categories'], 'cols' => array_merge([['Category', 'name']], $statusCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B1 Bill status by route', 'rows' => $a['routes'], 'cols' => $routeCols($statusCols), 'empty' => 'No route.'])

            {{-- ============================================================ ESTIMATES AND UNBILLED: B2, B3 --}}
            @elseif ($activeTab === 'estimates')
                @php
                    $causeCols = [['Estimated bills', 'estimated_customers', 'n'], ['Faulty meter (F)', 'est.F.customers', 'n'], ['No meter (N or blank)', 'est.N.customers', 'n'], ['Working meter not read (W)', 'est.W.customers', 'n'], ['Other meter status', 'est.O.customers', 'n'], ['Billed value (GH¢)', 'estimated_value', 'm'], ['Faulty value', 'est.F.month_total', 'm'], ['No meter value', 'est.N.month_total', 'm'], ['Working, not read value', 'est.W.month_total', 'm']];
                @endphp
                <div class="ui-stat-grid dash-row">
                    <x-ui.stat-tile label="Estimated bills" :value="number_format($t['estimated_customers'])" icon="gauge" :meta="$pct($t['estimated_share']).' of the billed'" />
                    <x-ui.stat-tile label="Faulty meter" :value="number_format($t['est']['F']['customers'])" icon="wrench" tone="warning" :meta="$pct($t['est']['F']['share']).' of the estimated'" />
                    <x-ui.stat-tile label="No meter" :value="number_format($t['est']['N']['customers'])" icon="circle-x" tone="warning" :meta="$pct($t['est']['N']['share']).' of the estimated'" />
                    <x-ui.stat-tile label="Working meter not read" :value="number_format($t['est']['W']['customers'])" icon="user-x" tone="danger" :meta="$pct($t['est']['W']['share']).' of the estimated'" />
                </div>
                <p class="ui-hint dash-row">The cause comes from the METER status, never from the spelling: ESTIMATED {{ number_format($t['spelling']['ESTIMATED']['customers']) }} (GH¢ {{ $money($t['spelling']['ESTIMATED']['month_total']) }}), ESTIMATE {{ number_format($t['spelling']['ESTIMATE']['customers']) }} (GH¢ {{ $money($t['spelling']['ESTIMATE']['month_total']) }}).</p>
                @include('livewire.commercial.partials.bd-table', ['title' => 'B2 Estimation cause by district', 'rows' => $a['districts'], 'cols' => array_merge([['District', 'name']], $causeCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B2 Estimation cause by route', 'rows' => array_values(array_filter($a['routes'], fn ($x) => $x['estimated_customers'] > 0)), 'cols' => $routeCols($causeCols), 'empty' => 'No route has an estimated bill.'])

                <x-ui.card title="B3 Unbilled customers by account status and meter status" :description="'Customers with the bill status UNBILLED. An active billing status (NSC counts) should not be unbilled; HOLD is shown apart because billing is paused on it.'" :padded="false" class="dash-row">
                    <x-ui.table label="Unbilled by status" :sticky="false">
                        <x-slot:head><tr><th>Account status</th><th>Meaning</th><th class="num">Unbilled customers</th><th>By meter status</th></tr></x-slot:head>
                        @forelse ($a['unbilled'] as $u)
                            <tr>
                                <td>{{ $u['status'] === '' ? '(blank)' : $u['status'] }}</td>
                                <td>@if ($u['status'] === 'HOLD')<x-ui.badge tone="neutral">Billing paused (shown apart)</x-ui.badge>@elseif ($u['active_billing'])<x-ui.badge tone="warning">Active billing status</x-ui.badge>@elseif (! $u['known'])<x-ui.badge tone="neutral">Not in the status lookups</x-ui.badge>@else<x-ui.badge tone="neutral">Not a billing status</x-ui.badge>@endif</td>
                                <td class="num">{{ number_format($u['customers']) }}</td>
                                <td>{{ collect($u['by_meter'])->map(fn ($n, $m) => ($m === '' ? '(blank)' : $m).' '.number_format($n))->implode(' · ') }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="4" icon="check" title="No unbilled customer." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
                <p class="ui-hint dash-row">Active billing accounts that are unbilled: <strong>{{ number_format($t['unbilled_active']) }}</strong> · on HOLD (unbilled): <strong>{{ number_format($t['unbilled_hold']) }}</strong>.</p>
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['active_unbilled'], 'title' => 'Active billing accounts that are unbilled', 'description' => 'Account numbers only, the largest balance first. Open one to see the customer.', 'named' => $showCca])
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['billed_not_active'], 'title' => 'Billed accounts whose status is not active', 'description' => 'For example a disconnected account that still received a bill.', 'named' => $showCca])

            {{-- ============================================================ CONSUMPTION AND CHARGES: B4, B5 --}}
            @elseif ($activeTab === 'consumption')
                @php
                    $consCols = [['Billed customers', 'billed_customers', 'n'], ['Consumption', 'consume', 'c'], ['Per bill', 'consume_per_bill', 'c'], ['Zero-consumption regular bills', 'zero_regular', 'n'], ['Share of regular bills', 'zero_regular_share', 'p']];
                    $chargeCols = [['Water', 'water', 'm'], ['Rural water', 'rural', 'm'], ['Fire fighting', 'fire', 'm'], ['Sewage', 'sewage', 'm'], ['Service charge', 'service', 'm'], ['Month total', 'month_total', 'm'], ['Water share', 'component_share.water', 'p'], ['Sewage share', 'component_share.sewage', 'p'], ['Service share', 'component_share.service', 'p']];
                @endphp
                <div class="ui-stat-grid dash-row">
                    <x-ui.stat-tile label="Consumption" :value="number_format($t['consume'], 1)" icon="droplets" tone="info" meta="all bills in the range" />
                    <x-ui.stat-tile label="Per billed customer" :value="$t['consume_per_bill'] === null ? '–' : number_format($t['consume_per_bill'], 1)" icon="gauge" meta="consumption ÷ bills" />
                    <x-ui.stat-tile label="Zero-consumption regular bills" :value="number_format($t['zero_regular'])" icon="circle-x" :tone="$t['zero_regular'] > 0 ? 'warning' : 'success'" :meta="$pct($t['zero_regular_share'], 2).' of regular bills'" />
                </div>
                @include('livewire.commercial.partials.bd-table', ['title' => 'B4 Consumption by district', 'rows' => $a['districts'], 'cols' => array_merge([['District', 'name']], $consCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B4 Consumption by service category', 'rows' => $a['categories'], 'cols' => array_merge([['Category', 'name']], $consCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B4 Consumption by route', 'rows' => $a['routes'], 'cols' => $routeCols($consCols)])
                @if ($a['band_note'])
                    <p class="ui-hint dash-row">{{ $a['band_note'] }}</p>
                @else
                    @include('livewire.commercial.partials.bd-table', ['title' => 'B4 Consumption bands (billed customers)', 'description' => 'Customers per band of consumption, from the file\'s own figures.', 'rows' => $a['bands'], 'cols' => [['Band', 'band'], ['Customers', 'customers', 'n'], ['Share', 'share', 'p'], ['Consumption', 'consume', 'c']]])
                    @foreach ($a['bands_by_district'] as $bd)
                        @if (count($a['bands_by_district']) > 1)
                            @include('livewire.commercial.partials.bd-table', ['title' => 'Bands: '.$bd['name'], 'rows' => $bd['bands'], 'cols' => [['Band', 'band'], ['Customers', 'customers', 'n'], ['Share', 'share', 'p'], ['Consumption', 'consume', 'c']]])
                        @endif
                    @endforeach
                @endif
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['zero_consume_regular'], 'title' => 'Zero-consumption regular bills', 'description' => 'A regular bill (read) with nothing consumed.', 'named' => $showCca])

                @include('livewire.commercial.partials.bd-table', ['title' => 'B5 Charge breakdown by district', 'description' => 'Water, rural water, fire fighting, sewage and service charge, and each one\'s share of the five charges.', 'rows' => $a['districts'], 'cols' => array_merge([['District', 'name']], $chargeCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B5 Charge breakdown by service category', 'rows' => $a['categories'], 'cols' => array_merge([['Category', 'name']], $chargeCols), 'total' => $t])

            {{-- ============================================================ MONEY: B6, B7, B8, B9 --}}
            @elseif ($activeTab === 'money')
                @php
                    $colCols = [['Month total', 'month_total', 'm'], ['Paid this month', 'paid', 'm'], ['Previous-month payment', 'prev_payment', 'm'], ['Offset payments', 'offset', 'm'], ['Collection ratio', 'collection_ratio', 'p'], ['Amount due', 'amount_due', 'm'], ['Collected of the amount due', 'collected_of_due', 'p']];
                    $recCols = [['Previous balance', 'previous_balance', 'm'], ['Adjusted', 'adjusted', 'm'], ['Balance due', 'balance_due', 'm'], ['Owing customers', 'owing', 'n'], ['Owing (GH¢)', 'owing_amount', 'm'], ['Credit customers', 'credit', 'n'], ['Credit (GH¢)', 'credit_amount', 'm']];
                    $adjCols = [['Adjusted customers', 'adjusted_count', 'n'], ['Adjusted (GH¢)', 'adjusted', 'm']];
                    $unpaidCols = [['Billed, not paid', 'billed_unpaid', 'n'], ['Value (GH¢)', 'billed_unpaid_amount', 'm'], ['Share of the billed', 'billed_unpaid_share', 'p']];
                    $con = $r['concentration'];
                @endphp
                <p class="ui-hint dash-row">Amount due = previous balance + month total + adjusted. Collected of the amount due = paid this month ÷ amount due. Over several uploads the previous balance is the earliest upload's and the balance due the latest's.</p>
                @include('livewire.commercial.partials.bd-table', ['title' => 'B6 Collections by district', 'rows' => $a['districts'], 'cols' => array_merge([['District', 'name']], $colCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B6 Collections by service category', 'rows' => $a['categories'], 'cols' => array_merge([['Category', 'name']], $colCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B6 Collections by route', 'rows' => $a['routes'], 'cols' => $routeCols($colCols)])

                <div class="ui-stat-grid dash-row">
                    <x-ui.stat-tile label="Balance due" :value="'GH¢ '.$money($t['balance_due'])" icon="hand-coins" tone="warning" meta="credits included" />
                    <x-ui.stat-tile label="Owing" :value="'GH¢ '.$money($t['owing_amount'])" icon="trending-down" tone="danger" :meta="number_format($t['owing']).' customers'" />
                    <x-ui.stat-tile label="In credit" :value="'GH¢ '.$money($t['credit_amount'])" icon="trending-up" tone="success" :meta="number_format($t['credit']).' customers'" />
                    <x-ui.stat-tile label="Top 10% of owing accounts hold" :value="$con['available'] ? $pct($con['share']) : '–'" icon="percent" :meta="$con['available'] ? number_format($con['top_n']).' of '.number_format($con['owing']).' owing accounts, GH¢ '.$money($con['top_amount']) : $con['reason']" />
                </div>
                @include('livewire.commercial.partials.bd-table', ['title' => 'B7 Receivables by district', 'description' => 'The owing and the credit parts are never netted off each other.', 'rows' => $a['districts'], 'cols' => array_merge([['District', 'name']], $recCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B7 Receivables by service category', 'rows' => $a['categories'], 'cols' => array_merge([['Category', 'name']], $recCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['top_debtor'], 'title' => 'B7 Top debtors', 'description' => 'The accounts owing most. Account numbers only.', 'named' => $showCca])

                @include('livewire.commercial.partials.bd-table', ['title' => 'B8 Adjustments by service category', 'rows' => $a['categories'], 'cols' => array_merge([['Category', 'name']], $adjCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B8 Adjustments by route', 'rows' => array_values(array_filter($a['routes'], fn ($x) => $x['adjusted_count'] > 0)), 'cols' => $routeCols($adjCols), 'empty' => 'No adjustment in this range.'])
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['large_adjustment'], 'title' => 'B8 The largest adjustments', 'named' => $showCca])

                @include('livewire.commercial.partials.bd-table', ['title' => 'B9 Billed but not paid by route', 'description' => 'Customers with a month total above zero who paid nothing this month.', 'rows' => array_values(array_filter($a['routes'], fn ($x) => $x['billed_unpaid'] > 0)), 'cols' => $routeCols($unpaidCols), 'empty' => 'Everyone billed paid something.'])
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['billed_unpaid'], 'title' => 'B9 Billed but not paid: the largest bills', 'named' => $showCca])

            {{-- ============================================================ CHECKS: B10, B11, B12 --}}
            @elseif ($activeTab === 'checks')
                @php
                    $tariffCols = [['Bills tested', 'tariff_checked', 'n'], ['Mismatches', 'tariff_mismatch', 'n'], ['Mismatch share', 'tariff_mismatch_pct', 'p']];
                    $recencyCols = [['Customers', 'customers', 'n'], ['0 to 30 days', 'read_0_30', 'n'], ['31 to 60 days', 'read_31_60', 'n'], ['61 to 90 days', 'read_61_90', 'n'], ['Over 90 days', 'read_over_90', 'n'], ['Never read', 'read_never', 'n'], ['Over 90 or never', 'read_over_90_share', 'p']];
                    $integrity = $r['integrity'];
                @endphp
                <p class="ui-hint dash-row">B10 checks the billing system's arithmetic (rate × consume against the water charge, beyond {{ config('gwl.commercial_tariff_tolerance_pct') }}% and at least GH¢ {{ number_format((float) config('gwl.commercial_tariff_tolerance_amount'), 2) }}); it does not check the tariff itself.</p>
                @include('livewire.commercial.partials.bd-table', ['title' => 'B10 Tariff recompute check by district', 'rows' => $a['districts'], 'cols' => array_merge([['District', 'name']], $tariffCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B10 Tariff recompute check by route', 'rows' => array_values(array_filter($a['routes'], fn ($x) => $x['tariff_mismatch'] > 0)), 'cols' => $routeCols($tariffCols), 'empty' => 'No mismatch.'])
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['tariff_mismatch'], 'title' => 'B10 Mismatches', 'named' => $showCca])

                <p class="ui-hint dash-row">B11 counts the days from the last read date to the end of the bill period. A last read date of 2000-01-01 or a blank means the customer was never read.</p>
                @include('livewire.commercial.partials.bd-table', ['title' => 'B11 Read recency at billing by district', 'rows' => $a['districts'], 'cols' => array_merge([['District', 'name']], $recencyCols), 'total' => $t])
                @include('livewire.commercial.partials.bd-table', ['title' => 'B11 Read recency at billing by route', 'rows' => $a['routes'], 'cols' => $routeCols($recencyCols)])
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['stale_read'], 'title' => 'B11 Regular bills with a stale read', 'description' => 'Billed as read, but the last read is over 90 days before the end of the period (or never).', 'named' => $showCca])

                <x-ui.card title="B12 Billing integrity" description="Route totals against the rows, repeated accounts, charges that do not add up, negative consumption and a bill month outside the period." :padded="false" class="dash-row">
                    <x-ui.table label="Billing integrity" :sticky="false">
                        <x-slot:head><tr><th>Upload</th><th class="num">Routes checked</th><th class="num">Routes that disagree with their totals row</th><th class="num">Accounts repeated</th></tr></x-slot:head>
                        @foreach ($integrity['batches'] as $b)
                            <tr>
                                <td>{{ $b['period'] }} <span class="muted">(batch #{{ $b['id'] }})</span></td>
                                <td class="num">{{ $num($b['routes_checked']) }}</td>
                                <td class="num">{{ number_format($b['routes_failed']) }}</td>
                                <td class="num">{{ $num($b['duplicates']) }}</td>
                            </tr>
                            @foreach ($b['flagged'] as $flag)
                                <tr><td colspan="4" class="muted" style="padding-left:28px">{{ $flag['label'] }}: {{ app(\App\Services\Commercial\BillingDetails\BillingDetailsImportService::class)->describeDiffs($flag['diffs']) }}</td></tr>
                            @endforeach
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
                <div class="ui-stat-grid dash-row">
                    <x-ui.stat-tile label="Charges do not add up" :value="number_format($integrity['components_off'])" icon="table" :tone="$integrity['components_off'] > 0 ? 'warning' : 'success'" meta="month total ≠ the five charges (5 pesewas)" />
                    <x-ui.stat-tile label="Negative consumption" :value="number_format($integrity['negative_consume'])" icon="trending-down" :tone="$integrity['negative_consume'] > 0 ? 'warning' : 'success'" />
                    <x-ui.stat-tile label="Bill month outside the period" :value="number_format($integrity['bill_month_outside'])" icon="calendar-x" :tone="$integrity['bill_month_outside'] > 0 ? 'warning' : 'success'" />
                </div>
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['duplicate_account'], 'title' => 'B12 Accounts that appear more than once', 'named' => $showCca])
                @include('livewire.commercial.partials.bd-list', ['list' => $r['lists']['negative_consume'], 'title' => 'B12 Negative consumption', 'named' => $showCca])

            {{-- ============================================================ TREND: B13 --}}
            @else
                @if (count($r['trend']) < 2)
                    <p class="ui-hint dash-row">A trend needs at least two uploads. Each upload is one point, labelled with the dates it covers; use the Route filter for one route's trend.</p>
                @endif
                @if (count($r['trend']) > 1)
                    <x-ui.card title="Money by upload" class="dash-row">
                        <div wire:key="bd-trend-money-{{ md5(json_encode(array_column($r['trend'], 'month_total'))) }}">
                            <x-ui.chart type="bar" label="Month total and paid by upload" unit="GH¢" :labels="array_column($r['trend'], 'label')"
                                :series="[['label' => 'Month total', 'data' => array_column($r['trend'], 'month_total')], ['label' => 'Paid this month', 'data' => array_column($r['trend'], 'paid')]]" height="260" />
                        </div>
                    </x-ui.card>
                @endif
                @include('livewire.commercial.partials.bd-table', ['title' => 'B13 Trend by upload', 'description' => 'Each upload on its own, with the same filters.', 'rows' => $r['trend'], 'cols' => [['Period', 'label'], ['Batch', 'batch_id', 'n'], ['Customers', 'customers', 'n'], ['Billed', 'billed_customers', 'n'], ['Consumption', 'consume', 'c'], ['Month total', 'month_total', 'm'], ['Paid', 'paid', 'm'], ['Collection ratio', 'collection_ratio', 'p'], ['Estimated bills', 'estimated_share', 'p'], ['Unbilled', 'unbilled_share', 'p'], ['Balance due', 'balance_due', 'm'], ['Owing', 'owing_amount', 'm'], ['Billed, not paid', 'billed_unpaid', 'n'], ['Tariff mismatch', 'tariff_mismatch_pct', 'p'], ['Read over 90 days or never', 'read_over_90_share', 'p']]])
                @foreach ($r['trend'] as $point)
                    @if (count($r['trend']) > 1 && count($point['districts']) > 0)
                        @include('livewire.commercial.partials.bd-table', ['title' => 'B13 Districts: '.$point['label'], 'rows' => $point['districts'], 'cols' => [['District', 'name'], ['Customers', 'customers', 'n'], ['Month total', 'month_total', 'm'], ['Paid', 'paid', 'm'], ['Collection ratio', 'collection_ratio', 'p'], ['Estimated bills', 'estimated_share', 'p'], ['Unbilled', 'unbilled_share', 'p'], ['Balance due', 'balance_due', 'm']]])
                    @endif
                @endforeach
            @endif
        @endif
    @endif
</div>
