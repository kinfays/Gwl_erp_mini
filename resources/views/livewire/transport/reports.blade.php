@assets
    @vite('resources/js/charts.js')
@endassets

@php
    $statIcons = [
        'Total Vehicles' => ['car', 'primary'],
        'In Maintenance' => ['wrench', 'warning'],
        'Open Issues' => ['triangle-alert', 'danger'],
        'Fleet Spend' => ['banknote', 'primary'],
        'Docs Expiring' => ['calendar-x', 'warning'],
    ];
    // Colour a badge only when it reports a non-zero problem ("0 critical" stays neutral).
    $deltaTone = fn (?string $tone, ?string $badge) => preg_match('/^0(\D|$)/', trim((string) $badge)) ? 'neutral' : match ($tone) {
        'red' => 'bad',
        'amber' => 'warn',
        default => 'neutral',
    };
    // Issue types keep the hue family the report service gives them, re-stepped to the chart palette.
    $issueTones = [
        '#a32d2d' => 'series-8',
        '#b7791f' => 'series-4',
        '#185fa5' => 'series-1',
        '#21633c' => 'series-6',
        '#6b46c1' => 'series-7',
        '#0f766e' => 'series-3',
        '#66758b' => 'muted',
        '#475569' => 'muted',
    ];
@endphp

<div>
    <x-ui.page-header title="Transport Reports" :description="$fromLabel.' to '.$toLabel">
        <x-slot:actions>
            <x-ui.button :href="route('transport.reports.export.pdf', $exportQuery)" icon="file-down">Export PDF</x-ui.button>
            <x-ui.button :href="route('transport.reports.export.excel', $exportQuery)" variant="primary" icon="download">Export Excel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="report-filters">
        <div class="report-filter-row">
            <div class="ui-field">
                <span class="ui-label" aria-hidden="true">Date range</span>
                <x-ui.segmented
                    label="Date range"
                    wire:model.live="datePreset"
                    :options="[
                        'this_month' => 'This month',
                        'last_3_months' => 'Last 3 months',
                        'last_6_months' => 'Last 6 months',
                        'last_12_months' => 'Last 12 months',
                        'custom' => 'Custom range',
                    ]"
                />
            </div>

            <x-ui.select label="Department" wire:model.live="departmentId" class="report-filter-select">
                <option value="">All departments</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                @endforeach
            </x-ui.select>

            @if ($datePreset === 'custom')
                <x-ui.input type="date" label="From" wire:model.live="customFrom" />
                <x-ui.input type="date" label="To" wire:model.live="customTo" />
            @endif
        </div>
    </x-ui.card>

    <div class="ui-stat-grid report-stats">
        @foreach (($payload['statCards'] ?? []) as $card)
            @php
                [$icon, $tone] = $statIcons[$card['label']] ?? ['chart-column', 'primary'];
            @endphp
            <x-ui.stat-tile
                :label="$card['label']"
                :value="$card['value']"
                :icon="$icon"
                :tone="$tone"
                :delta="$card['badge'] ?? null"
                :delta-tone="$deltaTone($card['tone'] ?? null, $card['badge'] ?? null)"
            />
        @endforeach
    </div>

    <div class="ui-grid ui-grid-2 report-grid">
        <x-ui.card title="Monthly Fleet Expenses">
            <x-ui.chart type="bar" label="Monthly fleet expenses" unit="GHS"
                event="transport-report-data-updated" source="monthlyExpenses"
                :source-data="$payload['monthlyExpenses'] ?? []"
                :series="[['label' => 'GHS', 'key' => 'data']]" height="260" />
        </x-ui.card>

        <x-ui.card title="Expense Breakdown By Type">
            <x-ui.chart type="doughnut" label="Expense breakdown by type" unit="GHS" center center-caption="GHS"
                event="transport-report-data-updated" source="expenseByType"
                :source-data="$payload['expenseByType'] ?? []"
                :series="[['label' => 'GHS', 'key' => 'data']]" height="260" />
        </x-ui.card>

        <x-ui.card title="Vehicle Status Distribution">
            <x-ui.chart type="doughnut" label="Vehicle status distribution" center center-caption="vehicles"
                event="transport-report-data-updated" source="vehicleStatusCounts"
                :source-data="$payload['vehicleStatusCounts'] ?? []"
                :series="[['label' => 'Vehicles', 'key' => 'data', 'colors' => ['success', 'warning', 'muted']]]" height="240" />
        </x-ui.card>

        <x-ui.card title="Vehicles By Department">
            <x-ui.chart type="hbar" label="Vehicles by department"
                event="transport-report-data-updated" source="vehiclesByDepartment"
                :source-data="$payload['vehiclesByDepartment'] ?? []"
                :series="[['label' => 'Vehicles', 'key' => 'data']]" height="240" />
        </x-ui.card>

        <x-ui.card title="Mileage Logged Per Month">
            <x-ui.chart type="area" label="Mileage logged per month" unit="km"
                event="transport-report-data-updated" source="mileageByMonth"
                :source-data="$payload['mileageByMonth'] ?? []"
                :series="[['label' => 'KM', 'key' => 'data']]" height="260" />
        </x-ui.card>

        <x-ui.card title="Issues Reported Vs Resolved">
            <x-ui.chart type="line" label="Issues reported versus resolved"
                event="transport-report-data-updated" source="issuesTrend"
                :source-data="$payload['issuesTrend'] ?? []"
                :series="[['label' => 'Reported', 'key' => 'reported'], ['label' => 'Resolved', 'key' => 'resolved']]" height="260" />
        </x-ui.card>

        <x-ui.card title="Top 5 Most Expensive Vehicles">
            <x-ui.chart type="hbar" label="Top five most expensive vehicles" unit="GHS"
                event="transport-report-data-updated" source="topExpensiveVehicles"
                :source-data="$payload['topExpensiveVehicles'] ?? []"
                :series="[['label' => 'GHS', 'key' => 'data']]" height="240" />
        </x-ui.card>

        <x-ui.card title="Issues By Type">
            <div class="report-bars">
                @forelse (($payload['issuesByType']['rows'] ?? []) as $row)
                    @php
                        $tone = $issueTones[strtolower($row['color'] ?? '')] ?? 'muted';
                    @endphp
                    <div class="report-bar-row">
                        <span class="report-bar-label">{{ $row['label'] }}</span>
                        <span class="report-bar-track" aria-hidden="true">
                            <span style="width: {{ $row['percent'] }}%; background: var(--color-{{ $tone }})"></span>
                        </span>
                        <strong class="num">{{ $row['count'] }}</strong>
                    </div>
                @empty
                    <x-ui.empty-state icon="triangle-alert" title="No issues reported in this period" />
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card title="Upcoming Document Renewals" description="Due within 60 days" :padded="false">
            <x-ui.table label="Upcoming document renewals" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>Vehicle</th>
                        <th>Document</th>
                        <th>Expiry</th>
                        <th class="num">Days</th>
                    </tr>
                </x-slot:head>
                @forelse (($payload['upcomingExpiryDocs'] ?? []) as $row)
                    <tr>
                        <td class="mono">{{ $row['vehicle'] }}</td>
                        <td>{{ $row['document'] }}</td>
                        <td>{{ \Carbon\Carbon::parse($row['expiry_date'])->format('d M Y') }}</td>
                        <td class="num">
                            <x-ui.status-pill :tone="$row['badge'] === 'red' ? 'danger' : 'warning'" :label="$row['days_remaining'].' days'" />
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" icon="calendar-days" title="No document renewals due within 60 days." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Maintenance Due By Mileage" class="report-span-2">
            <x-ui.chart type="hbar" label="Maintenance due by mileage" unit="km"
                event="transport-report-data-updated" source="maintenanceDueSoon"
                :source-data="$payload['maintenanceDueSoon'] ?? []"
                :series="[
                    ['label' => 'Current mileage', 'key' => 'current'],
                    ['label' => 'KM remaining', 'key' => 'remaining', 'colorsKey' => 'remainingColors'],
                ]" height="300" />
        </x-ui.card>
    </div>
</div>
