<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php
        $money = fn ($value) => $value === null ? '–' : number_format($value, 2);
        $pct = fn ($value, $digits = 1) => $value === null ? '–' : number_format($value, $digits).'%';
        $signedMoney = fn ($value) => $value === null ? '–' : ($value > 0 ? '+' : '').number_format($value, 2);
        $signedPts = fn ($value) => $value === null ? '–' : ($value > 0 ? '+' : '').number_format($value, 1).' pts';
        $creditHint = 'Negative balances are treated as customer credits (to be confirmed with the Commercial team).';
        $flagLabels = ['zero_activity' => 'No activity', 'heavy_credit' => 'Heavy credit', 'high_unbilled' => 'High unbilled', 'high_estimation' => 'High estimation'];
        $drill = fn ($key, $extra = []) => route('commercial.billing', array_filter(['snapshot' => $chosen['id'] ?? null, 'tab' => $activeTab, 'district' => $key, ...$extra]));
    @endphp

    <x-ui.page-header title="Billing" description="Billing, collections, estimation and unbilled customers from the billing summary reports. Every figure is from one snapshot at a time.">
        <x-slot:actions>
            @if ($chosen && ($canExport ?? false))
                @php $billingExport = array_filter(['snapshot' => $chosen['id'], 'district' => $districtKey ?? '']); @endphp
                <a href="{{ route('commercial.export', ['report' => 'billing', 'format' => 'excel', ...$billingExport]) }}" class="btn btn-secondary"><x-ui.icon name="file-spreadsheet" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'billing', 'format' => 'pdf', ...$billingExport]) }}" class="btn btn-secondary"><x-ui.icon name="file-text" /> PDF</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $chosen)
        <x-ui.card>
            <x-ui.empty-state icon="file-spreadsheet" title="No billing report has been loaded yet." description="Upload the Billing Summary Report By Routes (rptBillingSumm_ExP) and the analysis appears here.">
                @if ($canUpload)
                    <a href="{{ route('commercial.batches') }}" class="btn btn-primary">Go to uploads</a>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="ui-toolbar dash-row" role="search" aria-label="Billing filters">
            <select wire:model.live="snapshot" class="form-input" aria-label="Snapshot" style="min-width:18rem">
                @foreach ($snapshots as $s)
                    <option value="{{ $s['id'] }}" @selected($s['id'] === $chosen['id'])>{{ $s['label'] }} (batch #{{ $s['id'] }})</option>
                @endforeach
            </select>
            <x-ui.segmented label="Billing section" wire:model.live="tab" :options="$tabs" :value="$activeTab" />
            @if (! in_array($activeTab, ['bands', 'compare'], true))
                <select wire:model.live="district" class="form-input" aria-label="District">
                    <option value="">All districts</option>
                    @foreach ($districtOptions as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
                @if ($districtKey !== '')
                    <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear district</button>
                @endif
            @endif
        </div>

        <p class="ui-hint dash-row">
            Snapshot: <strong>{{ $chosen['label'] }}</strong>.
            @unless ($chosen['is_single_month'])
                This is a multi-month period: it is fully usable here, but it cannot be compared or trended against single months.
            @endunless
            @if ($districtKey !== '')
                Showing <strong>{{ $districtKey }}</strong> only.
            @endif
        </p>

        @if ($activeTab === 'overview')
            @php $t = $overview['totals']; @endphp
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Billing for the period" :value="'GH¢ '.$money($t['billing_for_period'])" icon="receipt" />
                <x-ui.stat-tile label="Volume billed ('000 litres)" :value="number_format($t['volume_total'], 0)" icon="activity" tone="info" />
                <x-ui.stat-tile label="Customers billed" :value="number_format($t['billed_total'])" icon="users" tone="success" :meta="$overview['totals']['routes'].' routes'" />
                <x-ui.stat-tile label="Revenue per billed customer" :value="$t['per_customer'] === null ? '–' : 'GH¢ '.$money($t['per_customer'])" icon="banknote" />
                <x-ui.stat-tile label="Revenue per m³" :value="$t['per_m3'] === null ? '–' : 'GH¢ '.$money($t['per_m3'])" icon="gauge" :meta="'GH¢ ÷ volume, 1 m³ per thousand litres'" />
            </div>

            <p class="ui-hint dash-row">
                The top {{ $overview['pareto']['routes'] }} of {{ $overview['pareto']['total_routes'] }} routes carry
                <strong>{{ $pct($overview['pareto']['share']) }}</strong> of the billing.
            </p>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="Billing by district" description="GH¢ billed for the period.">
                    <div wire:key="ov-chart-{{ md5(json_encode($overview['districts'])) }}">
                        <x-ui.chart type="bar" label="Billing by district" unit="GH¢"
                            :labels="array_column($overview['districts'], 'district')"
                            :series="[['label' => 'Billing', 'data' => array_column($overview['districts'], 'billing'), 'color' => 'series-1']]" height="260" />
                    </div>
                </x-ui.card>

                <x-ui.card title="Districts" :description="'Ranked by '.strtolower($rankings[$overview['rank_by']]).'.'" :padded="false">
                    <x-slot:actions>
                        <select wire:model.live="rank" class="form-input" aria-label="Rank by">
                            @foreach ($rankings as $key => $label)
                                <option value="{{ $key }}">Rank by {{ strtolower($label) }}</option>
                            @endforeach
                        </select>
                    </x-slot:actions>
                    <x-ui.table label="Districts ranked" :sticky="false">
                        <x-slot:head>
                            <tr><th>District</th><th class="num">Routes</th><th class="num">Billing (GH¢)</th><th class="num">Share</th><th class="num">Volume</th><th class="num">Billed</th><th class="num">GH¢ / customer</th><th class="num">GH¢ / m³</th></tr>
                        </x-slot:head>
                        @forelse ($overview['districts'] as $row)
                            <tr wire:key="ovd-{{ $row['key'] }}">
                                <td><a href="{{ $drill($row['key']) }}">{{ $row['district'] }}</a></td>
                                <td class="num">{{ $row['routes'] }}</td>
                                <td class="num">{{ $money($row['billing']) }}</td>
                                <td class="num">{{ $pct($row['share']) }}</td>
                                <td class="num">{{ number_format($row['volume'], 0) }}</td>
                                <td class="num">{{ number_format($row['billed']) }}</td>
                                <td class="num">{{ $money($row['per_customer']) }}</td>
                                <td class="num">{{ $money($row['per_m3']) }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="8" icon="map" title="No routes in this snapshot." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            </div>

            <x-ui.card title="Routes" description="Every route in the snapshot, with the running share of billing." :padded="false" class="dash-row">
                <x-ui.table label="Routes ranked" :sticky="true">
                    <x-slot:head>
                        <tr><th class="num">#</th><th>Route</th><th>District</th><th class="num">Billing (GH¢)</th><th class="num">Share</th><th class="num">Volume</th><th class="num">Billed</th><th class="num">GH¢ / customer</th><th class="num">GH¢ / m³</th></tr>
                    </x-slot:head>
                    @forelse ($overview['routes'] as $row)
                        <tr wire:key="ovr-{{ $row['route'] }}">
                            <td class="num">{{ $row['rank'] }}</td>
                            <td class="mono">{{ $row['route'] }}</td>
                            <td><a href="{{ $drill($row['key']) }}">{{ $row['district'] }}</a></td>
                            <td class="num">{{ $money($row['billing']) }}</td>
                            <td class="num">{{ $pct($row['share']) }}</td>
                            <td class="num">{{ number_format($row['volume'], 0) }}</td>
                            <td class="num">{{ number_format($row['billed']) }}</td>
                            <td class="num">{{ $money($row['per_customer']) }}</td>
                            <td class="num">{{ $money($row['per_m3']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="9" icon="map" title="No routes in this snapshot." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Balance roll-forward" description="Opening balance plus billing and adjustments is the amount receivable; less payments is the closing balance. GH¢." :padded="false" class="dash-row">
                <x-ui.table label="Balance roll-forward by district" :sticky="false">
                    <x-slot:head>
                        <tr><th>District</th><th class="num">Opening</th><th class="num">+ Billing</th><th class="num">+ Adjustments</th><th class="num">= Receivable</th><th class="num">− Payments</th><th class="num">= Closing</th></tr>
                    </x-slot:head>
                    @foreach ([...$roll['districts'], $roll['total']] as $row)
                        <tr wire:key="roll-{{ $row['key'] ?: 'all' }}" @class(['ui-total-row' => $row['key'] === ''])>
                            <td>{{ $row['key'] === '' ? 'All shown' : $row['district'] }}@unless ($row['balances'])<x-ui.badge tone="warning">Does not add up</x-ui.badge>@endunless</td>
                            <td class="num">{{ $money($row['opening']) }}</td>
                            <td class="num">{{ $money($row['billing']) }}</td>
                            <td class="num">{{ $signedMoney($row['adjustment']) }}</td>
                            <td class="num">{{ $money($row['receivable']) }}</td>
                            <td class="num">{{ $money($row['payments']) }}</td>
                            <td class="num"><strong>{{ $money($row['closing']) }}</strong></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @elseif ($activeTab === 'collections')
            @php $total = $collections['total']; @endphp
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Cash collection ratio" :value="$pct($total['cash_ratio'])" icon="trending-up" tone="primary" :meta="'Payments ÷ billing. Configured target: '.number_format($collections['target'], 0).'% (placeholder)'" />
                <x-ui.stat-tile label="Collected against this period's billing" :value="$pct($total['current_ratio'])" icon="receipt" :meta="'Payment for the month ÷ billing'" />
                <x-ui.stat-tile label="Cash that was arrears from earlier months" :value="$pct($total['prior_share'])" icon="calendar-days" tone="warning" :meta="'Previous-month payments ÷ total payments'" />
                <x-ui.stat-tile label="Payments" :value="'GH¢ '.$money($total['payments'])" icon="banknote" tone="success" :meta="'Billing GH¢ '.$money($total['billing'])" />
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="Billing and payments by district" description="GH¢.">
                    <div wire:key="col-chart-{{ md5(json_encode($collections['districts'])) }}">
                        <x-ui.chart type="bar" label="Billing and payments by district" unit="GH¢"
                            :labels="array_column($collections['districts'], 'district')"
                            :series="[
                                ['label' => 'Billing', 'data' => array_column($collections['districts'], 'billing'), 'color' => 'series-1'],
                                ['label' => 'Payments', 'data' => array_column($collections['districts'], 'payments'), 'color' => 'success'],
                            ]" height="260" />
                    </div>
                </x-ui.card>

                <x-ui.card title="Where the cash came from" description="This period's payments, earlier months' payments and offsets." :padded="false">
                    <x-ui.table label="Payments by source and district" :sticky="false">
                        <x-slot:head>
                            <tr><th>District</th><th class="num">This month</th><th class="num">Earlier months</th><th class="num">Offsets</th><th class="num">Total</th></tr>
                        </x-slot:head>
                        @foreach ($collections['districts'] as $row)
                            <tr wire:key="colsrc-{{ $row['key'] }}">
                                <td><a href="{{ $drill($row['key']) }}">{{ $row['district'] }}</a></td>
                                <td class="num">{{ $money($row['payment_for_month']) }}</td>
                                <td class="num">{{ $money($row['prev_month_payment']) }}</td>
                                <td class="num">{{ $money($row['offset_payments']) }}</td>
                                <td class="num"><strong>{{ $money($row['payments']) }}</strong></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            </div>

            <x-ui.card title="Collection by district" :padded="false" class="dash-row">
                <x-ui.table label="Collection ratios by district" :sticky="false">
                    <x-slot:head>
                        <tr><th>District</th><th class="num">Billing (GH¢)</th><th class="num">Payments (GH¢)</th><th class="num">Cash collection ratio</th><th class="num">Against this period</th><th class="num">Arrears share of cash</th></tr>
                    </x-slot:head>
                    @forelse ($collections['districts'] as $row)
                        <tr wire:key="colr-{{ $row['key'] }}">
                            <td>{{ $row['district'] }}</td>
                            <td class="num">{{ $money($row['billing']) }}</td>
                            <td class="num">{{ $money($row['payments']) }}</td>
                            <td class="num"><strong>{{ $pct($row['cash_ratio']) }}</strong></td>
                            <td class="num">{{ $pct($row['current_ratio']) }}</td>
                            <td class="num">{{ $pct($row['prior_share']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="6" icon="map" title="No routes in this snapshot." />
                    @endforelse
                    <tr class="ui-total-row">
                        <td><strong>All shown</strong></td>
                        <td class="num">{{ $money($total['billing']) }}</td>
                        <td class="num">{{ $money($total['payments']) }}</td>
                        <td class="num"><strong>{{ $pct($total['cash_ratio']) }}</strong></td>
                        <td class="num">{{ $pct($total['current_ratio']) }}</td>
                        <td class="num">{{ $pct($total['prior_share']) }}</td>
                    </tr>
                </x-ui.table>
            </x-ui.card>
        @elseif ($activeTab === 'balances')
            <p class="ui-hint dash-row">{{ $creditHint }}</p>
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Routes with a credit balance" :value="$balances['total']['credit_routes']" icon="map" tone="info" :meta="$pct($balances['credit_share']).' of '.$balances['total']['routes'].' routes'" />
                <x-ui.stat-tile label="Total credit (GH¢)" :value="$money($balances['total']['credit_amount'])" icon="banknote" tone="warning" />
                <x-ui.stat-tile label="Net closing balance (GH¢)" :value="$money($balances['total']['closing'])" icon="receipt" />
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="Credits by district" :padded="false">
                    <x-ui.table label="Credit balances by district" :sticky="false">
                        <x-slot:head><tr><th>District</th><th class="num">Routes</th><th class="num">With credit</th><th class="num">Credit (GH¢)</th><th class="num">Net closing (GH¢)</th></tr></x-slot:head>
                        @forelse ($balances['districts'] as $row)
                            <tr wire:key="bal-{{ $row['key'] }}">
                                <td><a href="{{ $drill($row['key']) }}">{{ $row['district'] }}</a></td>
                                <td class="num">{{ $row['routes'] }}</td>
                                <td class="num">{{ $row['credit_routes'] }}</td>
                                <td class="num">{{ $money($row['credit_amount']) }}</td>
                                <td class="num">{{ $money($row['closing']) }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="5" icon="map" title="No routes in this snapshot." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>

                <x-ui.card title="Largest credits" description="The 10 routes with the biggest negative closing balance." :padded="false">
                    <x-ui.table label="Largest credit balances" :sticky="false">
                        <x-slot:head><tr><th>Route</th><th>District</th><th class="num">Credit (GH¢)</th></tr></x-slot:head>
                        @forelse ($balances['largest'] as $row)
                            <tr wire:key="cr-{{ $row['route'] }}">
                                <td class="mono">{{ $row['route'] }}</td>
                                <td><a href="{{ $drill($row['key']) }}">{{ $row['district'] }}</a></td>
                                <td class="num">{{ $money($row['credit']) }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="3" icon="check" title="No route has a credit balance." />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            </div>
        @elseif ($activeTab === 'estimation')
            @php $total = $estimation['total']; @endphp
            <div class="ui-stat-grid dash-row">
                <x-ui.stat-tile label="Estimated share of volume" :value="$pct($total['estimation_volume'])" icon="gauge" tone="warning" :meta="'Average-based volume ÷ total volume'" />
                <x-ui.stat-tile label="Estimated bills" :value="$pct($total['estimation_count'])" icon="receipt" tone="warning" :meta="'Average metered + unmetered ÷ customers billed'" />
                <x-ui.stat-tile label="Unbilled rate" :value="$pct($total['unbilled_rate'])" icon="triangle-alert" tone="danger" :meta="number_format($total['unbilled']).' unbilled, '.number_format($total['billed']).' billed'" />
            </div>

            <div class="ui-grid ui-grid-2 dash-row">
                <x-ui.card title="How bills are produced" description="Customers billed by method.">
                    <div wire:key="est-mix-{{ md5(json_encode([$total['avg_metered'], $total['avg_unmetered'], $total['actual']])) }}">
                        <x-ui.chart type="doughnut" label="Billed customers by method" center center-caption="billed" :table="false"
                            :labels="['Average, metered', 'Average, unmetered', 'Actual reading']"
                            :series="[['label' => 'Customers', 'data' => [$total['avg_metered'], $total['avg_unmetered'], $total['actual']]]]" height="240" empty-text="No customers were billed." />
                    </div>
                </x-ui.card>

                <x-ui.card title="Why customers were not billed" description="The five reasons in the report." :padded="false">
                    <x-ui.table label="Unbilled customers by reason" :sticky="false">
                        <x-slot:head><tr><th>Reason</th><th class="num">Customers</th><th class="num">Share of unbilled</th></tr></x-slot:head>
                        @foreach ($total['reasons'] as $reason)
                            <tr wire:key="reason-{{ $reason['label'] }}">
                                <td>{{ $reason['label'] }}</td>
                                <td class="num">{{ number_format($reason['count']) }}</td>
                                <td class="num">{{ $pct($reason['share']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="ui-total-row"><td><strong>Total unbilled</strong></td><td class="num"><strong>{{ number_format($total['unbilled']) }}</strong></td><td class="num"></td></tr>
                    </x-ui.table>
                </x-ui.card>
            </div>

            <x-ui.card title="By district" :padded="false" class="dash-row">
                <x-ui.table label="Estimation and unbilled by district" :sticky="false">
                    <x-slot:head>
                        <tr><th>District</th><th class="num">Billed</th><th class="num">Unbilled</th><th class="num">Unbilled rate</th><th class="num">Estimated volume</th><th class="num">Estimated bills</th><th class="num">Avg metered</th><th class="num">Avg unmetered</th><th class="num">Actual reading</th></tr>
                    </x-slot:head>
                    @forelse ($estimation['districts'] as $row)
                        <tr wire:key="estd-{{ $row['key'] }}">
                            <td><a href="{{ $drill($row['key']) }}">{{ $row['district'] }}</a></td>
                            <td class="num">{{ number_format($row['billed']) }}</td>
                            <td class="num">{{ number_format($row['unbilled']) }}</td>
                            <td class="num">{{ $pct($row['unbilled_rate']) }}</td>
                            <td class="num">{{ $pct($row['estimation_volume']) }}</td>
                            <td class="num">{{ $pct($row['estimation_count']) }}</td>
                            <td class="num">{{ number_format($row['avg_metered']) }} <span class="ui-person-sub">{{ $pct($row['mix']['avg_metered'], 0) }}</span></td>
                            <td class="num">{{ number_format($row['avg_unmetered']) }} <span class="ui-person-sub">{{ $pct($row['mix']['avg_unmetered'], 0) }}</span></td>
                            <td class="num">{{ number_format($row['actual']) }} <span class="ui-person-sub">{{ $pct($row['mix']['actual'], 0) }}</span></td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="9" icon="map" title="No routes in this snapshot." />
                    @endforelse
                </x-ui.table>
                <p class="ui-hint" style="padding:8px 14px">By volume the report only separates actual readings from averages (not metered from unmetered): {{ number_format($total['volume_actual'], 0) }} actual and {{ number_format($total['volume_average'], 0) }} average, in thousand litres.</p>
            </x-ui.card>
        @elseif ($activeTab === 'bands')
            @if (! $bands['has_bands'])
                <x-ui.card><x-ui.empty-state icon="table" title="This snapshot has no consumption-band table." description="The domestic (category 611) breakdown was not in this report." /></x-ui.card>
            @else
                <x-ui.card :title="'Domestic consumption bands (category '.$bands['category'].')'" description="Customers, volume and revenue by band; GH¢ per m³ shows the tariff step." :padded="false" class="dash-row">
                    <x-ui.table label="Domestic consumption bands" :sticky="false">
                        <x-slot:head>
                            <tr><th>Band ('000 litres)</th><th class="num">Customers</th><th class="num">Share</th><th class="num">Volume</th><th class="num">Share</th><th class="num">Amount (GH¢)</th><th class="num">Share</th><th class="num">GH¢ / m³</th><th class="num">GH¢ / customer</th></tr>
                        </x-slot:head>
                        @foreach ($bands['rows'] as $row)
                            <tr wire:key="band-{{ $row['band'] }}">
                                <td>{{ $row['band'] }}</td>
                                <td class="num">{{ number_format($row['customers']) }}</td>
                                <td class="num">{{ $pct($row['customer_share']) }}</td>
                                <td class="num">{{ number_format($row['volume'], 0) }}</td>
                                <td class="num">{{ $pct($row['volume_share']) }}</td>
                                <td class="num">{{ $money($row['amount']) }}</td>
                                <td class="num">{{ $pct($row['amount_share']) }}</td>
                                <td class="num">{{ $money($row['per_m3']) }}</td>
                                <td class="num">{{ $money($row['per_customer']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="ui-total-row">
                            <td><strong>All bands</strong></td>
                            <td class="num">{{ number_format($bands['total']['customers']) }}</td><td></td>
                            <td class="num">{{ number_format($bands['total']['volume'], 0) }}</td><td></td>
                            <td class="num">{{ $money($bands['total']['amount']) }}</td><td></td>
                            <td class="num">{{ $money($bands['total']['per_m3']) }}</td>
                            <td class="num">{{ $money($bands['total']['per_customer']) }}</td>
                        </tr>
                    </x-ui.table>
                </x-ui.card>

                <div class="dash-row">
                    <x-ui.chart type="bar" label="Share of customers and of revenue by band" unit="%"
                        :labels="array_column($bands['rows'], 'band')"
                        :series="[
                            ['label' => 'Share of customers', 'data' => array_column($bands['rows'], 'customer_share'), 'color' => 'series-1'],
                            ['label' => 'Share of revenue', 'data' => array_column($bands['rows'], 'amount_share'), 'color' => 'series-3'],
                        ]" height="240" />
                </div>
            @endif
        @elseif ($activeTab === 'exceptions')
            @php $th = $exceptions['thresholds']; @endphp
            <p class="ui-hint dash-row">
                Placeholder thresholds, to be confirmed: a percentage is only judged where at least {{ $th['min_customers'] }} customers stand behind it;
                high unbilled is above {{ $th['high_unbilled_pct'] }}%, high estimation above {{ $th['high_estimation_pct'] }}% of bills, heavy credit is GH¢ {{ number_format($th['credit_amount'], 0) }} or more.
                {{ $creditHint }}
            </p>
            <div class="ui-stat-grid dash-row">
                @foreach ($flagLabels as $flag => $label)
                    <x-ui.stat-tile :label="$label" :value="$exceptions['counts'][$flag]" icon="triangle-alert" :tone="$exceptions['counts'][$flag] > 0 ? 'warning' : 'success'" />
                @endforeach
            </div>

            <x-ui.card :title="count($exceptions['rows']).' of '.$exceptions['routes'].' routes flagged'" :padded="false" class="dash-row">
                <x-ui.table label="Route exceptions" :sticky="true">
                    <x-slot:head>
                        <tr><th>Route</th><th>District</th><th>Flags</th><th class="num">Billed</th><th class="num">Unbilled</th><th class="num">Unbilled rate</th><th class="num">Estimated bills</th><th class="num">Closing (GH¢)</th></tr>
                    </x-slot:head>
                    @forelse ($exceptions['rows'] as $row)
                        <tr wire:key="exc-{{ $row['route'] }}">
                            <td class="mono">{{ $row['route'] }}</td>
                            <td><a href="{{ $drill($row['key']) }}">{{ $row['district'] }}</a></td>
                            <td><span class="ui-tags">@foreach ($row['flags'] as $flag)<x-ui.badge tone="{{ $flag === 'zero_activity' ? 'neutral' : 'warning' }}">{{ $flagLabels[$flag] }}</x-ui.badge>@endforeach</span></td>
                            <td class="num">{{ number_format($row['billed']) }}</td>
                            <td class="num">{{ number_format($row['unbilled']) }}</td>
                            <td class="num">{{ $pct($row['unbilled_rate']) }}</td>
                            <td class="num">{{ $pct($row['estimation_rate']) }}</td>
                            <td class="num">{{ $money($row['closing']) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="8" icon="check" title="No route is flagged." description="Nothing crosses the thresholds in this snapshot." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        @elseif ($activeTab === 'compare')
            @unless ($chosenIsSingleMonth)
                <x-ui.alert tone="info" class="dash-row">This snapshot is a multi-month period. It overlaps single months, so it cannot be compared or trended; pick a single-month snapshot above to compare.</x-ui.alert>
            @endunless

            @if ($chosenIsSingleMonth)
                <x-ui.card title="Compare two months" description="Same region and segment, single months only. The earlier month is the baseline." :padded="false" class="dash-row">
                    @if ($candidates !== [])
                        <x-slot:actions>
                            <select wire:model.live="compare" class="form-input" aria-label="Compare with">
                                @foreach ($candidates as $c)
                                    <option value="{{ $c['id'] }}" @selected($compareWith && $c['id'] === $compareWith['id'])>{{ $c['period_label'] }} (batch #{{ $c['id'] }})</option>
                                @endforeach
                            </select>
                        </x-slot:actions>
                    @endif

                    @if (! $comparison)
                        <x-ui.empty-state icon="calendar-days" title="Nothing to compare yet" description="Upload another single-month billing report for the same region and segment." />
                    @elseif (! $comparison['ok'])
                        <x-ui.empty-state icon="calendar-days" title="These cannot be compared" :description="$comparison['reason']" />
                    @else
                        <p class="ui-hint" style="padding:8px 14px">{{ $comparison['base']['period_label'] }} to {{ $comparison['latest']['period_label'] }}. Rates change in percentage points.</p>
                        <x-ui.table label="Month-on-month comparison by district" :sticky="false">
                            <x-slot:head>
                                <tr><th>District</th><th class="num">Billing before</th><th class="num">Billing now</th><th class="num">Change</th><th class="num">Change %</th><th class="num">Payments change</th><th class="num">Cash ratio</th><th class="num">Estimated volume</th><th class="num">Unbilled rate</th></tr>
                            </x-slot:head>
                            @foreach ([...$comparison['districts'], $comparison['total']] as $row)
                                <tr wire:key="cmp-{{ $row['key'] ?: 'all' }}" @class(['ui-total-row' => $row['key'] === ''])>
                                    <td>{{ $row['key'] === '' ? 'All districts' : $row['district'] }}</td>
                                    <td class="num">{{ $money($row['billing_before']) }}</td>
                                    <td class="num">{{ $money($row['billing_after']) }}</td>
                                    <td class="num">{{ $signedMoney($row['billing_change']) }}</td>
                                    <td class="num">{{ $row['billing_change_pct'] === null ? '–' : ($row['billing_change_pct'] > 0 ? '+' : '').number_format($row['billing_change_pct'], 1).'%' }}</td>
                                    <td class="num">{{ $signedMoney($row['payments_change']) }}</td>
                                    <td class="num">{{ $pct($row['cash_ratio_after']) }} <span class="ui-person-sub">{{ $signedPts($row['cash_ratio_change']) }}</span></td>
                                    <td class="num">{{ $pct($row['estimation_after']) }} <span class="ui-person-sub">{{ $signedPts($row['estimation_change']) }}</span></td>
                                    <td class="num">{{ $pct($row['unbilled_after']) }} <span class="ui-person-sub">{{ $signedPts($row['unbilled_change']) }}</span></td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    @endif
                </x-ui.card>
            @endif

            <x-ui.card title="Monthly trend" description="Every single-month snapshot of this region and segment." class="dash-row">
                @if (! $trend['ok'])
                    <x-ui.empty-state icon="trending-up" title="No trend yet" :description="$trend['reason']" />
                @else
                    <div wire:key="trend-{{ md5(json_encode($trend['months'])) }}">
                        <x-ui.chart type="line" label="Collection and unbilled rates by month" unit="%"
                            :labels="array_column($trend['months'], 'label')"
                            :series="[
                                ['label' => 'Cash collection ratio', 'data' => array_column($trend['months'], 'cash_ratio'), 'color' => 'series-1'],
                                ['label' => 'Collected against the period', 'data' => array_column($trend['months'], 'current_ratio'), 'color' => 'series-3'],
                                ['label' => 'Unbilled rate', 'data' => array_column($trend['months'], 'unbilled_rate'), 'color' => 'warning'],
                            ]" height="260" />
                    </div>
                @endif
            </x-ui.card>
        @endif
    @endif
</div>
