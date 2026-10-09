<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php
        $n = fn ($value) => $value === null ? '–' : number_format($value);
        $pct = fn ($value, $digits = 1) => $value === null ? '–' : number_format($value, $digits).'%';
        $money = fn ($value) => $value === null ? '–' : number_format($value, 2);
        $signed = fn ($value) => $value === null ? '–' : ($value > 0 ? '+' : '').number_format($value);
        $creditHint = 'A negative balance is treated as a customer credit and a positive one as owed. The sign convention is still to be confirmed with the Commercial team.';
        $listUrl = fn (array $extra = []) => route('commercial.customers.list', array_filter(['district' => $districtId ?? null, 'route' => $route ?? null, 'group' => $group ?: null, 'status' => $status ?: null, 'meter' => $meter ?: null, ...$extra], fn ($v) => $v !== null && $v !== ''));
        $drill = fn ($key) => route('commercial.customers', array_filter(['tab' => $activeTab, 'district' => $key, 'period' => $periodKey]));
    @endphp

    <x-ui.page-header title="Customer List" description="Who the customers are and how they behave: from the weekly or monthly customer-list uploads. Every figure is read from pre-calculated totals, so it stays fast however many customers there are.">
        <x-slot:actions>
            @if (! ($empty ?? true) && $actorCanExport)
                <a href="{{ route('commercial.customers.export', ['report' => 'summary', 'format' => 'excel', 'district' => $districtId ?? '', 'period' => $periodKey ?? '']) }}" class="btn btn-secondary"><x-ui.icon name="file-spreadsheet" /> Excel</a>
            @endif
            <a href="{{ route('commercial.customers.list') }}" class="btn btn-secondary"><x-ui.icon name="users" /> Find customers</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($empty)
        <x-ui.card>
            <x-ui.empty-state icon="users" title="No customer list has been loaded yet." description="Upload the customer list report (rptCustomerDetails) for a district and the analysis appears here.">
                @if ($canUpload)
                    <a href="{{ route('commercial.customers.uploads') }}" class="btn btn-primary">Go to customer uploads</a>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="ui-toolbar dash-row" role="search" aria-label="Customer filters">
            <select wire:model.live="period" class="form-input" aria-label="Period">
                <option value="">Newest data of each district</option>
                <optgroup label="Month-end (each district's last upload in the month)">
                    @foreach ($monthEnds as $m)<option value="{{ $m }}">{{ \Illuminate\Support\Carbon::parse($m.'-01')->format('F Y') }}</option>@endforeach
                </optgroup>
                <optgroup label="One upload period">
                @foreach ($periods as $p)
                    <option value="{{ $p['key'] }}">{{ $p['key'] }} ({{ $p['type'] }}, as of {{ \Illuminate\Support\Carbon::parse($p['as_of'])->format('d M Y') }})</option>
                @endforeach
                </optgroup>
            </select>
            @if ($seesAllRegions && $regionOptions->count() > 1)
                <select wire:model.live="region" class="form-input" aria-label="Region">
                    <option value="">All regions</option>
                    @foreach ($regionOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="district" class="form-input" aria-label="District">
                <option value="">All districts ({{ $districtOptions->count() }})</option>
                @foreach ($districtOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            @if ($districtId)
                <select wire:model.live="route" class="form-input" aria-label="Route">
                    <option value="">All routes</option>
                    @foreach ($routeOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="group" class="form-input" aria-label="Category group">
                <option value="">All categories</option>
                @foreach ($groupOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="status" class="form-input" aria-label="Account status">
                <option value="">Any status</option>
                @foreach ($statusOptions as $s)<option value="{{ $s->id }}">{{ $s->meaning_confirmed ? $s->label.' ('.$s->code.')' : $s->code }}</option>@endforeach
            </select>
            <select wire:model.live="meter" class="form-input" aria-label="Meter status">
                <option value="">Any meter status</option>
                @foreach ($meterOptions as $m)<option value="{{ $m->id }}">{{ $m->label }}</option>@endforeach
            </select>
            <label class="ui-check"><input type="checkbox" wire:model.live="billingOnly"> Billing accounts only</label>
            @if ($filtersActive)
                <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear filters</button>
            @endif
        </div>

        <x-ui.segmented label="Customer list section" wire:model.live="tab" :options="$tabs" :value="$activeTab" class="dash-row" />

        <p class="ui-hint dash-row">
            {{ $batches->count() }} {{ \Illuminate\Support\Str::plural('district', $batches->count()) }} in view, data as of
            <strong>{{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}</strong>@if ($oldest !== $asOf) (the oldest is {{ \Illuminate\Support\Carbon::parse($oldest)->format('d M Y') }})@endif.
            Dates such as "no payment in 90 days" are counted from each district's own as-of date.
        </p>

        {{-- ================================================ A. overview --}}
        @if ($activeTab === 'overview')
            @php $o = $overview; $series = $spark ?? []; @endphp
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Customers" :value="$n($o['total'])" icon="users" tone="primary" :sparkline="array_column($series, 'customers')" :meta="$o['route_count'].' routes · '.$o['batches'].' district(s)'" />
                <x-ui.stat-tile label="Billing accounts (ACTB)" :value="$n($o['billing'])" icon="receipt" tone="success" :meta="$pct($o['billing_share']).' of customers'" :href="$listUrl()" />
                <x-ui.stat-tile label="Active, not billing (ACTN)" :value="$n($o['active_non_billing'])" icon="user-check" tone="info" />
                <x-ui.stat-tile label="Disconnected / suspended" :value="$n($o['disconnected'] + $o['suspended'])" icon="plug-zap" tone="warning" :meta="$n($o['disconnected']).' disconnected · '.$n($o['suspended']).' suspended'" />
                <x-ui.stat-tile label="Other status codes" :value="$n($o['other_status'])" icon="circle-help" :meta="'Meaning not confirmed for some codes'" />
                <x-ui.stat-tile label="Not in the latest file" :value="$n($o['not_in_file'])" icon="file-minus" tone="warning" :meta="$n($o['moved']).' moved in from another district'" />
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="By category group" description="The grouping of category codes is a proposal until the Commercial team confirms it.">
                    <x-ui.bar-list label="Customers by category group" :items="array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['customers'], 'display' => $n($r['customers']).' · '.$pct($r['share'])], $o['by_group'])" />
                </x-ui.card>
                <x-ui.card title="By account status" description="A code whose meaning is not confirmed is shown as its raw code.">
                    <x-ui.bar-list label="Customers by status" :items="array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['customers'], 'display' => $n($r['customers']).' · '.$pct($r['share'])], $o['by_status'])" />
                </x-ui.card>
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="By category">
                    <x-ui.bar-list label="Customers by category" :items="array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['customers'], 'display' => $n($r['customers']).' · '.$pct($r['share'])], array_slice($o['by_category'], 0, 12))" />
                </x-ui.card>
                <x-ui.card title="By meter status">
                    <x-ui.bar-list label="Customers by meter status" :items="array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['customers'], 'display' => $n($r['customers']).' · '.$pct($r['share'])], $o['by_meter'])" />
                </x-ui.card>
            </div>

            <x-ui.card title="Districts" :description="'Customers per route is a workload measure: '.($o['per_route_average'] === null ? '–' : $n($o['per_route_average'])).' on average across the routes in view.'" :padded="false" class="dash-row">
                <x-ui.table label="Customers by district" :sticky="false">
                    <x-slot:head><tr><th>District</th><th class="num">Customers</th><th class="num">Share</th><th class="num">Routes</th><th class="num">Customers per route</th></tr></x-slot:head>
                    @forelse ($o['by_district'] as $row)
                        <tr wire:key="od-{{ $row['key'] }}">
                            <td><a href="{{ $drill($row['key']) }}">{{ $row['label'] }}</a></td>
                            <td class="num">{{ $n($row['customers']) }}</td><td class="num">{{ $pct($row['share']) }}</td>
                            <td class="num">{{ $n($row['routes']) }}</td><td class="num">{{ $n($row['per_route']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="5" icon="map" title="No districts." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Busiest routes" description="The 25 routes with the most customers." :padded="false" class="dash-row">
                <x-ui.table label="Routes by customers" :sticky="true">
                    <x-slot:head><tr><th>Route</th><th class="num">Customers</th><th></th></tr></x-slot:head>
                    @foreach ($o['top_routes'] as $row)
                        <tr wire:key="or-{{ $row['key'] }}">
                            <td class="mono">{{ $row['label'] }}</td><td class="num">{{ $n($row['customers']) }}</td>
                            <td><a href="{{ route('commercial.customers.list', ['district' => $row['district_id'], 'route' => $row['key']]) }}" class="btn btn-ghost btn-sm">Customers</a></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

        {{-- ================================================ B. meters --}}
        @elseif ($activeTab === 'meters')
            @php $m = $meters; $t = $m['totals']; @endphp
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Working meters" :value="$pct($t['working_pct'])" icon="gauge" tone="success" :meta="$n($t['working']).' customers'" />
                <x-ui.stat-tile label="Faulty meters" :value="$pct($t['faulty_pct'])" icon="wrench" tone="warning" :meta="$n($t['faulty']).' customers · '.$n($t['faulty_billing']).' still billing'" />
                <x-ui.stat-tile label="No meter" :value="$pct($t['no_meter_pct'])" icon="plug-zap" tone="danger" :meta="$n($t['no_meter']).' customers · '.$n($t['no_meter_billing']).' still billing'" />
                <x-ui.stat-tile label="Billing customers on estimates" :value="$pct($m['on_estimate_pct'])" icon="calculator" tone="info" :meta="'Share of billing accounts with an estimated consumption'" />
            </div>

            <div class="ui-toolbar dash-row">
                <select wire:model.live="by" class="form-input" aria-label="Break down by">
                    <option value="district">By district</option>@if ($districtId)<option value="route">By route</option>@endif<option value="category">By category</option>
                </select>
            </div>

            <x-ui.card title="Meter health" :description="'Share of each '.($m['by'] === 'category' ? 'category' : $m['by']).'’s customers.'" :padded="false" class="dash-row">
                <x-ui.table label="Meter status" :sticky="true">
                    <x-slot:head><tr><th>{{ ucfirst($m['by']) }}</th><th class="num">Customers</th><th class="num">Working</th><th class="num">Faulty</th><th class="num">Faulty %</th><th class="num">No meter</th><th class="num">No meter %</th><th class="num">Faulty + billing</th><th class="num">No meter + billing</th><th class="num">On estimate</th></tr></x-slot:head>
                    @forelse (array_slice($m['rows'], 0, 100) as $row)
                        <tr wire:key="mt-{{ $row['key'] }}">
                            <td>{{ $row['label'] }}</td><td class="num">{{ $n($row['customers']) }}</td><td class="num">{{ $n($row['working']) }}</td>
                            <td class="num">{{ $n($row['faulty']) }}</td><td class="num">{{ $pct($row['faulty_pct']) }}</td>
                            <td class="num">{{ $n($row['no_meter']) }}</td><td class="num">{{ $pct($row['no_meter_pct']) }}</td>
                            <td class="num">{{ $n($row['faulty_billing']) }}</td><td class="num">{{ $n($row['no_meter_billing']) }}</td><td class="num">{{ $pct($row['on_estimate_pct']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="10" icon="gauge" title="No customers match." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="How long faulty meters have gone unread" :description="$n($m['faulty_ageing']['faulty']).' customers have a faulty meter. The bands overlap: 90+ days includes 180+.'" class="dash-row">
                <x-ui.bar-list label="Faulty meters by days since the last read" :items="array_map(fn ($b) => ['label' => $b['label'], 'value' => $b['customers'], 'display' => $n($b['customers'])], $m['faulty_ageing']['bands'])" />
            </x-ui.card>

        {{-- ================================================ C. receivables --}}
        @elseif ($activeTab === 'receivables')
            @php $r = $receivables; $c = $r['concentration']; @endphp
            <x-ui.alert tone="info" class="dash-row">{{ $creditHint }} The file has no age of debt, so the buckets below say how many of a customer's last bills are owed.</x-ui.alert>
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Owed (debit balances)" :value="'GH¢ '.$money($r['debit'])" icon="banknote" tone="danger" :meta="$n($r['debit_count']).' customers owe · average GH¢ '.$money($r['average_debit'])" />
                <x-ui.stat-tile label="Credit balances" :value="'GH¢ '.$money($r['credit'])" icon="piggy-bank" tone="success" :meta="$n($r['credit_count']).' customers · sign to be confirmed'" />
                <x-ui.stat-tile label="Net balance" :value="'GH¢ '.$money($r['net'])" icon="scale" :meta="'Average GH¢ '.$money($r['average_balance']).' per customer'" />
                <x-ui.stat-tile label="Owed by disconnected or suspended" :value="'GH¢ '.$money($r['owing_after_leaving']['debit'])" icon="plug-zap" tone="warning" :meta="$n($r['owing_after_leaving']['debtors']).' customers'" />
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="How many bills are owed" description="Customers by the size of their balance relative to their last bill.">
                    <x-ui.bar-list label="Customers by arrears bucket" :items="array_map(fn ($b) => ['label' => $b['label'], 'value' => $b['customers'], 'display' => $n($b['customers']).' · '.$pct($b['share'])], $r['buckets'])" />
                </x-ui.card>
                <x-ui.card title="Debt concentration" description="Within each district: the share of the total owed that the biggest debtors hold.">
                    <div class="ui-stat-grid">
                        <x-ui.stat-tile label="Top 1% of debtors" :value="$pct($c['top1_share'])" icon="percent" />
                        <x-ui.stat-tile label="Top 5%" :value="$pct($c['top5_share'])" icon="percent" />
                        <x-ui.stat-tile label="Top 10%" :value="$pct($c['top10_share'])" icon="percent" />
                    </div>
                    <p class="ui-hint">{{ $n($c['debtors']) }} debtors in view.@if ($r['median_debit'] !== null) Median amount owed GH¢ {{ $money($r['median_debit']) }}; median balance GH¢ {{ $money($r['median_balance']) }}.@else Medians are shown per district below.@endif</p>
                    <a href="{{ route('commercial.customers.list', array_filter(['district' => $districtId, 'sort' => 'balance_desc'])) }}" class="btn btn-secondary btn-sm">Top debtors</a>
                </x-ui.card>
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="Owed by status" :padded="false">
                    <x-ui.table label="Arrears by status" :sticky="false">
                        <x-slot:head><tr><th>Status</th><th class="num">Customers</th><th class="num">Owing</th><th class="num">Owed (GH¢)</th><th class="num">Share</th></tr></x-slot:head>
                        @foreach ($r['by_status'] as $row)
                            <tr wire:key="rs-{{ $row['code'] }}"><td>{{ $row['label'] }}</td><td class="num">{{ $n($row['customers']) }}</td><td class="num">{{ $n($row['debtors']) }}</td><td class="num">{{ $money($row['debit']) }}</td><td class="num">{{ $pct($row['debit_share']) }}</td></tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
                <x-ui.card title="Owed by category" :padded="false">
                    <x-ui.table label="Arrears by category" :sticky="false">
                        <x-slot:head><tr><th>Category</th><th class="num">Owing</th><th class="num">Owed (GH¢)</th><th class="num">Average (GH¢)</th></tr></x-slot:head>
                        @foreach (array_slice($r['by_category'], 0, 15) as $row)
                            <tr wire:key="rc-{{ $loop->index }}"><td>{{ $row['label'] }}</td><td class="num">{{ $n($row['debtors']) }}</td><td class="num">{{ $money($row['debit']) }}</td><td class="num">{{ $money($row['average_debit']) }}</td></tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            </div>

            <x-ui.card title="By district" :padded="false" class="dash-row">
                <x-ui.table label="Receivables by district" :sticky="true">
                    <x-slot:head><tr><th>District</th><th class="num">Customers</th><th class="num">Owing</th><th class="num">Owed (GH¢)</th><th class="num">Credits (GH¢)</th><th class="num">Median balance</th><th class="num">Median owed</th></tr></x-slot:head>
                    @foreach ($r['by_district'] as $row)
                        <tr wire:key="rd-{{ $row['key'] }}">
                            <td><a href="{{ $drill($row['key']) }}">{{ $row['label'] }}</a></td><td class="num">{{ $n($row['customers']) }}</td><td class="num">{{ $n($row['debtors']) }}</td>
                            <td class="num">{{ $money($row['debit']) }}</td><td class="num">{{ $money($row['credit']) }}</td><td class="num">{{ $money($row['median_balance']) }}</td><td class="num">{{ $money($row['median_debit']) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

        {{-- ================================================ D. billing and collection --}}
        @elseif ($activeTab === 'collection')
            @php $c = $collection; @endphp
            <x-ui.alert tone="info" class="dash-row">These figures are each customer's LAST bill and LAST payment as printed in the file, not a month's totals. For billing and cash for a period use the Billing screens.</x-ui.alert>
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Paid ÷ billed (last cycle)" :value="$pct($c['paid_to_billed'])" icon="trending-up" tone="primary" :meta="'GH¢ '.$money($c['paid']).' of GH¢ '.$money($c['billed'])" />
                <x-ui.stat-tile label="Average last bill" :value="'GH¢ '.$money($c['average_bill'])" icon="receipt" :meta="$n($c['billed_customers']).' customers with a bill'" />
                <x-ui.stat-tile label="Billed, no payment in 90 days" :value="$n($c['unpaid'][2]['customers'])" icon="clock-alert" tone="warning" :meta="$pct($c['unpaid'][2]['share']).' of billed customers'" />
                <x-ui.stat-tile label="Billed GH¢ 0" :value="$n($c['zero_bill_active'])" icon="circle-slash" tone="info" :meta="'Customers with a bill date and a zero amount'" />
            </div>

            <x-ui.card title="Billed but not paying" description="Customers whose last bill is above zero and who have paid nothing within the window." class="dash-row">
                <x-ui.bar-list label="Billed customers with no payment" :items="array_map(fn ($u) => ['label' => 'No payment in '.$u['days'].' days', 'value' => $u['customers'], 'display' => $n($u['customers']).' · '.$pct($u['share'])], $c['unpaid'])" />
            </x-ui.card>

            <div class="ui-toolbar dash-row">
                <select wire:model.live="by" class="form-input" aria-label="Break down by">
                    <option value="category">By category</option><option value="district">By district</option>@if ($districtId)<option value="route">By route</option>@endif
                </select>
            </div>
            <x-ui.card title="Last bill and last payment" :padded="false" class="dash-row">
                <x-ui.table label="Billing behaviour" :sticky="true">
                    <x-slot:head><tr><th>{{ ucfirst($c['by']) }}</th><th class="num">Customers</th><th class="num">Billed (GH¢)</th><th class="num">Paid (GH¢)</th><th class="num">Paid ÷ billed</th><th class="num">Average bill</th><th class="num">Unpaid 90d</th><th class="num">Billed 0</th></tr></x-slot:head>
                    @forelse (array_slice($c['rows'], 0, 100) as $row)
                        <tr wire:key="cl-{{ $row['key'] }}">
                            <td>{{ $row['label'] }}</td><td class="num">{{ $n($row['customers']) }}</td><td class="num">{{ $money($row['billed']) }}</td><td class="num">{{ $money($row['paid']) }}</td>
                            <td class="num">{{ $pct($row['paid_to_billed']) }}</td><td class="num">{{ $money($row['average_bill']) }}</td><td class="num">{{ $n($row['unpaid_90']) }}</td><td class="num">{{ $n($row['zero_bill']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="8" icon="receipt" title="No customers match." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

        {{-- ================================================ E. dormancy --}}
        @elseif ($activeTab === 'dormancy')
            @php $d = $dormancy; @endphp
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Never read" :value="$n($d['never_read'])" icon="eye-off" tone="warning" />
                <x-ui.stat-tile label="Never billed" :value="$n($d['never_billed'])" icon="receipt" tone="warning" :meta="'Connected, but no bill date'" />
                <x-ui.stat-tile label="Never paid" :value="$n($d['never_paid'])" icon="banknote" tone="info" />
                <x-ui.stat-tile label="Ghost candidates" :value="$n($d['ghost'])" icon="ghost" tone="danger" :meta="$pct($d['ghost_pct']).' · billing accounts with no bill and no read in 180 days'" />
            </div>
            <x-ui.card title="No activity in N days" description="Customers with nothing recorded in the window (or ever), against all customers in view." :padded="false" class="dash-row">
                <x-ui.table label="Dormancy windows" :sticky="false">
                    <x-slot:head><tr><th>Window</th><th class="num">No read</th><th class="num">%</th><th class="num">No bill</th><th class="num">%</th><th class="num">No payment</th><th class="num">%</th></tr></x-slot:head>
                    @foreach ($d['windows'] as $w)
                        <tr wire:key="dw-{{ $w['days'] }}">
                            <td>{{ $w['days'] }} days</td><td class="num">{{ $n($w['no_read']) }}</td><td class="num">{{ $pct($w['no_read_pct']) }}</td>
                            <td class="num">{{ $n($w['no_bill']) }}</td><td class="num">{{ $pct($w['no_bill_pct']) }}</td><td class="num">{{ $n($w['no_pay']) }}</td><td class="num">{{ $pct($w['no_pay_pct']) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
            <x-ui.card title="By district" :padded="false" class="dash-row">
                <x-ui.table label="Dormant customers by district" :sticky="true">
                    <x-slot:head><tr><th>District</th><th class="num">Customers</th><th class="num">Ghost candidates</th><th class="num">Never read</th><th class="num">No read 90d</th><th class="num">No payment 90d</th></tr></x-slot:head>
                    @foreach ($d['by_district'] as $row)
                        <tr wire:key="dd-{{ $row['key'] }}"><td><a href="{{ $drill($row['key']) }}">{{ $row['label'] }}</a></td><td class="num">{{ $n($row['customers']) }}</td><td class="num">{{ $n($row['ghost']) }}</td><td class="num">{{ $n($row['never_read']) }}</td><td class="num">{{ $n($row['no_read_90']) }}</td><td class="num">{{ $n($row['no_pay_90']) }}</td></tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

        {{-- ================================================ F. growth --}}
        @elseif ($activeTab === 'growth')
            @php $g = $growth; @endphp
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="New accounts in this upload" :value="$n($g['new'])" icon="user-plus" tone="success" />
                <x-ui.stat-tile label="Not in the latest file" :value="$n($g['missing'])" icon="file-minus" tone="warning" :meta="'Flagged, never deleted'" />
                <x-ui.stat-tile label="Net change" :value="$signed($g['net'])" icon="scale" tone="primary" />
                <x-ui.stat-tile label="Moved between districts" :value="$n($g['moved'])" icon="shuffle" tone="info" />
                <x-ui.stat-tile label="Reconnections (DISC → ACTB)" :value="$n($g['reconnections'])" icon="plug" tone="success" />
            </div>
            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="New connections by month" description="Customers by connect date, the last 24 months.">
                    <div wire:key="gr-{{ md5(json_encode($g['connections_by_month'])) }}">
                        <x-ui.chart type="bar" label="New connections by month" unit="customers" :labels="array_map(fn ($r) => \Illuminate\Support\Carbon::parse($r['month'])->format('M Y'), $g['connections_by_month'])"
                            :series="[['label' => 'Connected', 'data' => array_column($g['connections_by_month'], 'customers'), 'color' => 'series-1'], ['label' => 'Billing now', 'data' => array_column($g['connections_by_month'], 'billing'), 'color' => 'success']]" height="260" />
                    </div>
                </x-ui.card>
                <x-ui.card title="Status migration" description="Accounts whose status changed in this upload, from → to." :padded="false">
                    <x-ui.table label="Status migration" :sticky="false">
                        <x-slot:head><tr><th>From</th><th>To</th><th class="num">Accounts</th></tr></x-slot:head>
                        @forelse (array_slice($g['migration'], 0, 30) as $row)
                            <tr wire:key="gm-{{ $loop->index }}"><td>{{ $row['from'] }}</td><td>{{ $row['to'] }}</td><td class="num">{{ $n($row['customers']) }}</td></tr>
                        @empty
                            <x-ui.empty-row :colspan="3" icon="shuffle" title="No status changed in this upload." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            </div>

        {{-- ================================================ G. consumption --}}
        @elseif ($activeTab === 'consumption')
            @php $k = $consumption; @endphp
            <x-ui.alert tone="info" class="dash-row">The unit of "consume" in the file is not confirmed; outliers are customers above {{ rtrim(rtrim(number_format($k['multiple'], 2), '0'), '.') }} times their category's median.</x-ui.alert>
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Billing accounts with an average" :value="$n($k['customers'])" icon="gauge" />
                <x-ui.stat-tile label="Outliers" :value="$n($k['outliers'])" icon="triangle-alert" tone="warning" />
                <x-ui.stat-tile label="Billed, zero average consumption" :value="$n($k['zero_consume'])" icon="circle-slash" tone="danger" />
                <x-ui.stat-tile label="Meter factor not 1" :value="$n($k['factor_not_one'])" icon="sliders-horizontal" tone="info" :meta="$n($k['factor_odd']).' look implausible'" />
            </div>
            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="Distribution of average consumption">
                    <x-ui.bar-list label="Billing customers by average consumption" :items="array_map(fn ($b) => ['label' => $b['label'], 'value' => $b['customers'], 'display' => $n($b['customers'])], $k['distribution'])" />
                </x-ui.card>
                <x-ui.card title="By category" :padded="false">
                    <x-ui.table label="Consumption by category" :sticky="false">
                        <x-slot:head><tr><th>Category</th><th class="num">Customers</th><th class="num">Typical (median)</th><th class="num">Outliers</th><th class="num">Zero</th></tr></x-slot:head>
                        @forelse ($k['rows'] as $row)
                            <tr wire:key="kc-{{ $loop->index }}"><td>{{ $row['label'] }}</td><td class="num">{{ $n($row['customers']) }}</td><td class="num">{{ $money($row['typical']) }}</td><td class="num">{{ $n($row['outliers']) }} ({{ $pct($row['outlier_pct']) }})</td><td class="num">{{ $n($row['zero_consume']) }}</td></tr>
                        @empty
                            <x-ui.empty-row :colspan="5" icon="gauge" title="No consumption data." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            </div>

        {{-- ================================================ H. data quality --}}
        @elseif ($activeTab === 'quality')
            @php $q = $quality; @endphp
            <x-ui.card title="Issues found" :description="'Counted in the latest upload of each district, against '.$n($q['customers']).' customers. Pick a single district to list the accounts behind a count.'" :padded="false" class="dash-row">
                <x-ui.table label="Data quality issues" :sticky="false">
                    <x-slot:head><tr><th>Issue</th><th class="num">Accounts</th><th class="num">Share</th><th></th></tr></x-slot:head>
                    @foreach ($q['issues'] as $row)
                        <tr wire:key="qi-{{ $row['issue'] }}">
                            <td>{{ $row['label'] }}</td><td class="num">{{ $n($row['count']) }}</td><td class="num">{{ $pct($row['share']) }}</td>
                            <td>
                                @if ($districtId && $row['count'] > 0 && $row['issue'] !== 'reachable')
                                    <a href="{{ route('commercial.customers.list', array_filter(['district' => $districtId, 'issue' => $row['issue'], 'missing' => $row['issue'] === 'not_in_file' ? 1 : null])) }}" class="btn btn-ghost btn-sm">List</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
            <x-ui.card title="Contact completeness by district" description="Mobile, address, account name and valid phone number present, as a share of the possible." :padded="false" class="dash-row">
                <x-ui.table label="Completeness by district" :sticky="true">
                    <x-slot:head><tr><th>District</th><th class="num">Customers</th><th class="num">Completeness</th><th class="num">No mobile</th><th class="num">No e-mail</th><th class="num">No address</th><th class="num">Shared meter</th><th class="num">Shared mobile</th><th class="num">Two numbers</th><th class="num">Reachable</th></tr></x-slot:head>
                    @foreach ($q['districts'] as $row)
                        <tr wire:key="qd-{{ $row['key'] }}">
                            <td><a href="{{ $drill($row['key']) }}">{{ $row['label'] }}</a></td><td class="num">{{ $n($row['customers']) }}</td><td class="num"><strong>{{ $pct($row['completeness']) }}</strong></td>
                            <td class="num">{{ $n($row['issues']['missing_mobile'] ?? 0) }}</td><td class="num">{{ $n($row['issues']['missing_email'] ?? 0) }}</td><td class="num">{{ $n($row['issues']['missing_address'] ?? 0) }}</td>
                            <td class="num">{{ $n($row['issues']['shared_meter'] ?? 0) }}</td><td class="num">{{ $n($row['issues']['shared_mobile'] ?? 0) }}</td><td class="num">{{ $n($row['issues']['multiple_phones'] ?? 0) }}</td><td class="num">{{ $pct($row['customers'] > 0 ? round(($row['issues']['reachable'] ?? 0) / $row['customers'] * 100, 1) : null) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

        {{-- ================================================ I. trends and compare --}}
        @elseif ($activeTab === 'compare')
            @php $series = $trend; $l = $league; $last = end($series) ?: null; @endphp
            <div class="ui-toolbar dash-row">
                <select wire:model.live="trendBy" class="form-input" aria-label="Points">
                    <option value="period">One point per upload period</option>
                    <option value="month">Month-end (each district's last upload in the month)</option>
                </select>
            </div>
            <x-ui.card title="Over time" description="Districts that did not upload in a period are simply missing from that point." class="dash-row">
                <div wire:key="tr-{{ md5(json_encode($series)) }}">
                    <x-ui.chart type="line" label="Customers and amount owed by period" :labels="array_column($series, 'period')"
                        :series="[['label' => 'Customers', 'data' => array_column($series, 'customers'), 'color' => 'series-1']]" height="240" />
                </div>
            </x-ui.card>
            <x-ui.card title="Period by period" :padded="false" class="dash-row">
                <x-ui.table label="Trend" :sticky="false">
                    <x-slot:head><tr><th>Period</th><th class="num">Districts</th><th class="num">Customers</th><th class="num">Change</th><th class="num">Billing</th><th class="num">Owed (GH¢)</th><th class="num">Faulty %</th><th class="num">No payment 90d %</th><th class="num">Paid ÷ billed</th></tr></x-slot:head>
                    @forelse (array_reverse($series) as $p)
                        <tr wire:key="tp-{{ $p['period'] }}">
                            <td>{{ $p['period'] }}<span class="ui-hint"> as of {{ \Illuminate\Support\Carbon::parse($p['as_of'])->format('d M') }}</span></td><td class="num">{{ $p['districts'] }}</td><td class="num">{{ $n($p['customers']) }}</td>
                            <td class="num">{{ $p['change'] ? $signed($p['change']['customers']) : '–' }}</td><td class="num">{{ $n($p['billing']) }}</td><td class="num">{{ $money($p['debit']) }}</td>
                            <td class="num">{{ $pct($p['faulty_pct']) }}</td><td class="num">{{ $pct($p['no_pay_90_pct']) }}</td><td class="num">{{ $pct($p['paid_to_billed']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="9" icon="chart-line" title="Not enough uploads yet." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="District league table" description="Rank 1 is best: lower debt per customer, fewer faulty meters and non-payers, higher paid ÷ billed." :padded="false" class="dash-row">
                <x-ui.table label="District league table" :sticky="true">
                    <x-slot:head><tr><th>District</th><th class="num">Customers</th><th class="num">Owed per customer</th><th class="num">Faulty %</th><th class="num">No payment 90d %</th><th class="num">Paid ÷ billed</th><th class="num">Ghost</th></tr></x-slot:head>
                    @foreach ($l['districts'] as $row)
                        <tr wire:key="lg-{{ $row['key'] }}">
                            <td><a href="{{ $drill($row['key']) }}">{{ $row['label'] }}</a></td><td class="num">{{ $n($row['customers']) }}</td>
                            <td class="num">{{ $money($row['debit_per_customer']) }} <span class="ui-hint">#{{ $row['ranks']['debit_per_customer'] ?? '–' }}</span></td>
                            <td class="num">{{ $pct($row['faulty_pct']) }} <span class="ui-hint">#{{ $row['ranks']['faulty_pct'] ?? '–' }}</span></td>
                            <td class="num">{{ $pct($row['no_pay_90_pct']) }} <span class="ui-hint">#{{ $row['ranks']['no_pay_90_pct'] ?? '–' }}</span></td>
                            <td class="num">{{ $pct($row['paid_to_billed']) }} <span class="ui-hint">#{{ $row['ranks']['paid_to_billed'] ?? '–' }}</span></td>
                            <td class="num">{{ $n($row['ghost']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="ui-total-row"><td>{{ $districtId ? 'This district' : 'All districts in view' }}</td><td class="num">{{ $n($l['selection']['customers']) }}</td><td class="num">{{ $money($l['selection']['debit_per_customer']) }}</td><td class="num">{{ $pct($l['selection']['faulty_pct']) }}</td><td class="num">{{ $pct($l['selection']['no_pay_90_pct']) }}</td><td class="num">{{ $pct($l['selection']['paid_to_billed']) }}</td><td class="num">{{ $n($l['selection']['ghost']) }}</td></tr>
                    @if ($l['company'])
                        <tr class="ui-total-row"><td>Whole company</td><td class="num">{{ $n($l['company']['customers']) }}</td><td class="num">{{ $money($l['company']['debit_per_customer']) }}</td><td class="num">{{ $pct($l['company']['faulty_pct']) }}</td><td class="num">{{ $pct($l['company']['no_pay_90_pct']) }}</td><td class="num">{{ $pct($l['company']['paid_to_billed']) }}</td><td class="num">{{ $n($l['company']['ghost']) }}</td></tr>
                    @endif
                </x-ui.table>
            </x-ui.card>
        @endif
    @endif
</div>
