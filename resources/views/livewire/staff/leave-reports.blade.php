@assets
    @vite('resources/js/charts.js')
@endassets

@php
    $statIcons = [
        'Total Staff' => ['users', 'primary'],
        'Female Staff' => ['user-round', 'primary'],
        'Male Staff' => ['user-round', 'primary'],
        'On Leave Now' => ['calendar-days', 'lagoon'],
        'Pending Requests' => ['clock', 'warning'],
    ];
    // Colour a badge only when it reports a non-zero problem ("0 critical" stays neutral).
    $deltaTone = fn (?string $tone, ?string $badge) => preg_match('/^0(\D|$)/', trim((string) $badge)) ? 'neutral' : match ($tone) {
        'red' => 'bad',
        'amber' => 'warn',
        default => 'neutral',
    };
    $genderColours = ['Male' => 'series-1', 'Female' => 'series-3', 'Other / Unspecified' => 'muted'];
    $statusColours = ['Approved' => 'success', 'Pending Approval' => 'warning', 'Denied' => 'danger', 'Planned' => 'muted'];
@endphp

<div>
    <x-ui.page-header title="Staff Leave Reports" :description="$fromLabel.' to '.$toLabel.' · '.($payload['scopeLabel'] ?? 'Visible staff scope')" />

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
                @foreach ($filters['departments'] as $department)
                    <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                @endforeach
            </x-ui.select>

            @if ($filters['regions']->count() > 1)
                <x-ui.select label="Region" wire:model.live="regionId" class="report-filter-select">
                    <option value="">All regions</option>
                    @foreach ($filters['regions'] as $region)
                        <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                    @endforeach
                </x-ui.select>
            @endif

            <x-ui.select label="District" wire:model.live="districtId" class="report-filter-select">
                <option value="">All districts</option>
                @foreach ($filters['districts'] as $district)
                    <option value="{{ $district->id }}">{{ $district->district_name }}</option>
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
        <x-ui.card title="Male Vs Female Total">
            <x-ui.chart type="doughnut" label="Male versus female staff" center center-caption="staff"
                event="staff-leave-report-data-updated" source="genderDistribution"
                :source-data="$payload['genderDistribution'] ?? []" :color-map="$genderColours"
                :series="[['label' => 'Staff', 'key' => 'data']]" height="240" />
        </x-ui.card>

        <x-ui.card title="Leave Status Breakdown">
            <x-ui.chart type="doughnut" label="Leave status breakdown" center center-caption="requests"
                event="staff-leave-report-data-updated" source="leaveStatusBreakdown"
                :source-data="$payload['leaveStatusBreakdown'] ?? []" :color-map="$statusColours"
                :series="[['label' => 'Requests', 'key' => 'data']]" height="240" />
        </x-ui.card>

        <x-ui.card title="Staff By District">
            <x-ui.chart type="hbar" label="Staff by district, with how many are on leave"
                event="staff-leave-report-data-updated" source="staffByDistrict"
                :source-data="$payload['staffByDistrict'] ?? []"
                :series="[['label' => 'Total staff', 'key' => 'staff'], ['label' => 'On leave', 'key' => 'onLeave']]" height="300" />
        </x-ui.card>

        <x-ui.card title="Staff By Department">
            <x-ui.chart type="hbar" label="Staff by department"
                event="staff-leave-report-data-updated" source="staffByDepartment"
                :source-data="$payload['staffByDepartment'] ?? []"
                :series="[['label' => 'Staff', 'key' => 'data']]" height="300" />
        </x-ui.card>

        <x-ui.card title="Approved Leave Days By Type">
            <x-ui.chart type="hbar" label="Approved leave days by type" unit="days"
                event="staff-leave-report-data-updated" source="leaveTypeDays"
                :source-data="$payload['leaveTypeDays'] ?? []"
                :series="[['label' => 'Approved days', 'key' => 'data']]" height="280" />
        </x-ui.card>

        <x-ui.card title="Monthly Leave Requests">
            <x-ui.chart type="area" label="Monthly leave requests"
                event="staff-leave-report-data-updated" source="monthlyLeaveRequests"
                :source-data="$payload['monthlyLeaveRequests'] ?? []"
                :series="[['label' => 'Requests', 'key' => 'data']]" height="280" />
        </x-ui.card>

        <x-ui.card title="District Numbers" :padded="false">
            <x-ui.table label="Staff numbers by district">
                <x-slot:head>
                    <tr>
                        <th>District</th>
                        <th>Region</th>
                        <th class="num">Total</th>
                        <th class="num">Male</th>
                        <th class="num">Female</th>
                        <th class="num">On Leave</th>
                    </tr>
                </x-slot:head>
                @forelse (($payload['districtRows'] ?? []) as $row)
                    <tr>
                        <td>{{ $row['district'] }}</td>
                        <td>{{ $row['region'] }}</td>
                        <td class="num">{{ $row['total'] }}</td>
                        <td class="num">{{ $row['male'] }}</td>
                        <td class="num">{{ $row['female'] }}</td>
                        <td class="num">
                            @if ((int) $row['on_leave'] > 0)
                                <x-ui.badge tone="lagoon">{{ $row['on_leave'] }}</x-ui.badge>
                            @else
                                {{ $row['on_leave'] }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="6" icon="map-pin" title="No district records in this scope." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="On Leave Now" :padded="false">
            <x-ui.table label="Staff on leave now">
                <x-slot:head>
                    <tr>
                        <th>Employee</th>
                        <th>Leave</th>
                        <th>District</th>
                        <th>Returns</th>
                    </tr>
                </x-slot:head>
                @forelse (($payload['currentlyOnLeave'] ?? []) as $row)
                    <tr>
                        <td>
                            <span class="ui-person">
                                <x-ui.avatar :name="$row['employee']" size="sm" />
                                <span class="ui-person-name">{{ $row['employee'] }}</span>
                            </span>
                        </td>
                        <td>{{ $row['leave_type'] }}</td>
                        <td>{{ $row['district'] }}</td>
                        <td>
                            {{ $row['end_date'] ? \Carbon\Carbon::parse($row['end_date'])->format('d M Y') : '-' }}
                            <span class="ui-person-sub">{{ $row['days_remaining'] }} days remaining</span>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" icon="calendar-days" title="No active approved leave in this scope." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>
</div>
