@assets
    @vite('resources/js/charts.js')
@endassets

@php($money = fn ($pesewas) => \App\Services\GWater\Money::format($pesewas))
@php($pct = fn ($value) => $value === null ? 'n/a' : number_format($value, 1).'%')
@php($daysText = fn (array $sample) => $sample['days'] === null ? 'n/a' : number_format($sample['days'], 1).' days')

<div>
    <x-ui.page-header title="Reports" description="Payment vouchers of {{ $isSuperAdmin ? 'the location you choose' : 'your location' }}: spend, how often Audit queried and why, how long each step takes, and what is waiting. Amounts are in GH¢.">
        @if ($report && $canExport)
            <x-slot:actions>
                <x-ui.button :href="route($exportRoute, ['report' => 'summary', 'format' => 'excel'] + $exportQuery)" file="excel">Summary (Excel)</x-ui.button>
                <x-ui.button :href="route($exportRoute, ['report' => 'summary', 'format' => 'pdf'] + $exportQuery)" file="pdf">Summary (PDF)</x-ui.button>
                <x-ui.button :href="route($exportRoute, ['report' => 'register', 'format' => 'excel'] + $exportQuery)" file="excel">Register (Excel)</x-ui.button>
                <x-ui.button :href="route($exportRoute, ['report' => 'waiting', 'format' => 'excel'] + $exportQuery)" file="excel">Waiting (Excel)</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.finance.partials.location-picker')

    @if (session('error'))
        <x-ui.alert tone="danger" role="alert" class="dash-row">{{ session('error') }}</x-ui.alert>
    @endif

    @if ($location && $report)
        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.select label="Period (by PV date)" wire:model.live="preset">
                    @foreach ($presets as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
            </div>
            <p class="cell-muted" style="margin-top:8px">{{ $report['period']['label'] }}. Drafts and voided vouchers are never counted as spend.</p>
        </x-ui.card>

        @php($tiles = $report['tiles'])
        @php($audit = $report['audit'])
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Vouchers" :value="number_format($tiles['vouchers'])" icon="receipt" :meta="$tiles['completed_vouchers'].' completed, '.$tiles['open_vouchers'].' still open'" />
            <x-ui.stat-tile label="Total amount" :value="$money($tiles['amount'])" icon="banknote" :meta="$tiles['average_amount'] === null ? 'No vouchers yet' : 'Average '.$money($tiles['average_amount'])" />
            <x-ui.stat-tile label="Completed" :value="$money($tiles['completed_amount'])" icon="circle-check" tone="success" :meta="'Still open: '.$money($tiles['open_amount'])" />
            <x-ui.stat-tile label="Queried by Audit" :value="$pct($audit['queried_rate'])" icon="triangle-alert" :tone="($audit['queried_rate'] ?? 0) >= 30 ? 'warning' : 'muted'" :meta="$audit['queried_vouchers'].' vouchers; '.($audit['average_rounds'] === null ? 'no rounds yet' : number_format($audit['average_rounds'], 2).' rounds on average')" />
            <x-ui.stat-tile label="Audit responds in" :value="$daysText($audit['turnaround']['audit_response'])" icon="clock" tone="muted" :meta="'Average over '.$audit['turnaround']['audit_response']['count'].' submissions'" />
            <x-ui.stat-tile label="Waiting right now" :value="number_format(collect($report['waiting']['kinds'])->sum('count'))" icon="hourglass" :tone="collect($report['waiting']['kinds'])->sum('overdue') ? 'danger' : 'muted'" :meta="collect($report['waiting']['kinds'])->sum('overdue').' overdue'" />
        </div>

        <div class="ui-grid dash-row">
            <x-ui.card title="Spend by month" description="GH¢, by PV date">
                <x-ui.chart type="bar" label="Spend by month" unit="GH¢"
                    event="fin-reports-updated" source="months"
                    :source-data="$charts['months']"
                    :series="[['label' => 'Amount', 'key' => 'amount']]" height="260" />
            </x-ui.card>
            <x-ui.card title="Vouchers by month">
                <x-ui.chart type="line" label="Vouchers by month"
                    event="fin-reports-updated" source="months"
                    :source-data="$charts['months']"
                    :series="[['label' => 'Vouchers', 'key' => 'vouchers']]" height="260" />
            </x-ui.card>
        </div>

        <div class="ui-grid dash-row">
            <x-ui.card title="Spend by category" description="GH¢">
                <x-ui.chart type="hbar" label="Spend by category" unit="GH¢"
                    event="fin-reports-updated" source="categories"
                    :source-data="$charts['categories']"
                    :series="[['label' => 'Amount', 'key' => 'amount']]" height="260" />
            </x-ui.card>
            <x-ui.card title="Vouchers by status">
                <x-ui.chart type="hbar" label="Vouchers by status"
                    event="fin-reports-updated" source="statuses"
                    :source-data="$charts['statuses']"
                    :series="[['label' => 'Vouchers', 'key' => 'count']]" height="260" />
            </x-ui.card>
        </div>

        <div class="ui-grid dash-row">
            <x-ui.card title="Why Audit queried" description="Every query of a voucher that went ahead">
                <x-ui.chart type="hbar" label="Why Audit queried"
                    event="fin-reports-updated" source="reasons"
                    :source-data="$charts['reasons']"
                    :series="[['label' => 'Queries', 'key' => 'count']]" height="260" empty-text="Audit has not queried any voucher in this period." />
            </x-ui.card>
            <x-ui.card title="How long vouchers have waited with Audit" description="Right now, whatever the period">
                <x-ui.chart type="bar" label="Vouchers With Audit by days waiting"
                    event="fin-reports-updated" source="aging"
                    :source-data="$charts['aging']"
                    :series="[['label' => 'Vouchers', 'key' => 'count']]" height="260" empty-text="Nothing is waiting with Audit." />
            </x-ui.card>
        </div>

        <x-ui.card title="Waiting right now" description="Overdue uses this location's reminder settings." :padded="false" class="dash-row">
            <x-ui.table label="Waiting right now" :sticky="false">
                <x-slot:head><tr><th>Waiting for</th><th class="num">Vouchers</th><th class="num">Overdue</th><th class="num">Longest wait (days)</th><th class="num">Remind after (days)</th></tr></x-slot:head>
                @foreach ($report['waiting']['kinds'] as $kind)
                    <tr>
                        <td>{{ $kind['label'] }}</td>
                        <td class="num">{{ $kind['count'] }}</td>
                        <td class="num">{{ $kind['overdue'] }}@if ($kind['overdue']) <span class="cell-muted">overdue</span>@endif</td>
                        <td class="num">{{ $kind['oldest_days'] ?? '' }}</td>
                        <td class="num">{{ $kind['threshold'] ?? '' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @if ($report['waiting']['items'])
                <x-ui.table label="Longest-waiting vouchers" :sticky="false" dense>
                    <x-slot:head><tr><th>PV number</th><th>Waiting for</th><th>Since</th><th class="num">Days</th><th><span class="sr-only-text">Open</span></th></tr></x-slot:head>
                    @foreach ($report['waiting']['items'] as $item)
                        <tr wire:key="wait-{{ $item['pv_id'] }}">
                            <td class="mono">{{ $item['pv_no'] }}</td>
                            <td>{{ $item['label'] }}</td>
                            <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($item['since'])->format('d M Y') }}</td>
                            <td class="num">{{ $item['days'] }}@if ($item['overdue']) <span class="cell-muted">overdue</span>@endif</td>
                            <td class="nowrap"><x-ui.button size="sm" variant="ghost" :href="route($showRoute, $item['pv_id'])">Open</x-ui.button></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <div class="ui-grid dash-row">
            <x-ui.card title="Spend by category" :padded="false">
                <x-ui.table label="Spend by category" :sticky="false" dense>
                    <x-slot:head><tr><th>Category</th><th class="num">Vouchers</th><th class="num">Amount (GH¢)</th></tr></x-slot:head>
                    @forelse ($report['categories'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $money($row['amount']) }}</td></tr>
                    @empty
                        <x-ui.empty-row :colspan="3" icon="receipt" title="No vouchers in this period." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
            <x-ui.card title="Spend by type (top 10)" :padded="false">
                <x-ui.table label="Spend by type" :sticky="false" dense>
                    <x-slot:head><tr><th>Type</th><th class="num">Vouchers</th><th class="num">Amount (GH¢)</th></tr></x-slot:head>
                    @forelse ($report['types'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $money($row['amount']) }}</td></tr>
                    @empty
                        <x-ui.empty-row :colspan="3" icon="receipt" title="No vouchers in this period." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="ui-grid dash-row">
            <x-ui.card title="Spend by payee type" :padded="false">
                <x-ui.table label="Spend by payee type" :sticky="false" dense>
                    <x-slot:head><tr><th>Payee type</th><th class="num">Vouchers</th><th class="num">Amount (GH¢)</th></tr></x-slot:head>
                    @forelse ($report['payee_kinds'] as $row)
                        <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $money($row['amount']) }}</td></tr>
                    @empty
                        <x-ui.empty-row :colspan="3" icon="receipt" title="No vouchers in this period." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
            <x-ui.card title="Suppliers paid most (top 10)" description="The vouchers of this location only." :padded="false">
                <x-ui.table label="Suppliers paid most" :sticky="false" dense>
                    <x-slot:head><tr><th>Supplier</th><th class="num">Vouchers</th><th class="num">Amount (GH¢)</th></tr></x-slot:head>
                    @forelse ($report['suppliers'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $money($row['amount']) }}</td></tr>
                    @empty
                        <x-ui.empty-row :colspan="3" icon="building-2" title="No supplier was paid in this period." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

        <x-ui.card title="How long each step takes" description="Average over every such step in the period." :padded="false" class="dash-row">
            <x-ui.table label="Turnaround" :sticky="false" dense>
                <x-slot:head><tr><th>Step</th><th class="num">Average</th><th class="num">Based on</th></tr></x-slot:head>
                @foreach ([
                    'audit_response' => 'Audit: sent to tick or query',
                    'finance_response' => 'Finance: query to resubmission',
                    'acknowledge' => 'Finance: Audit tick to acknowledgement',
                    'end_to_end' => 'First sent to Finance acknowledged',
                ] as $key => $label)
                    <tr><td>{{ $label }}</td><td class="num">{{ $daysText($audit['turnaround'][$key]) }}</td><td class="num">{{ $audit['turnaround'][$key]['count'] }}</td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
