@assets
    @vite('resources/js/charts.js')
@endassets

@php
    $a = $analytics;
    $m = $a['milestones'];
    $h = $a['headcount'];
    $g = $a['grades'];
    $asOf = \Carbon\Carbon::parse($a['as_of']);
    $limit = \App\Services\Hr\HrAnalyticsService::LIST_LIMIT;

    // Links into the staff list keep the page's own region and department filter.
    // Global Admin may open this page but not the staff list, so for them the counts stay plain numbers (null = no link).
    $canOpenStaff = app(\App\Support\ErpNavigation::class)->canViewStaff($viewer = auth()->user());
    $staffList = fn (array $filter = []) => $canOpenStaff ? route('staff.index', array_filter(
        $filter + ['status' => 'active', 'region_id' => $regionId, 'department_id' => $departmentId],
        fn ($value) => $value !== '' && $value !== null
    )) : null;
    $percent = fn (?float $value) => $value === null ? 'n/a' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    $locationColours = ['Head Office' => 'series-1', 'Regional Office' => 'series-2', 'District' => 'series-3'];
    $employmentColours = ['Permanent' => 'series-1', 'Contract' => 'series-4'];
@endphp

<div>
    <x-ui.page-header
        title="HR Analytics"
        :description="$filters['scope_label'].' · as at '.$asOf->format('d M Y')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('leave.hr-dashboard')" icon="layout-dashboard">HR Dashboard</x-ui.button>
            @if ($viewer->hasRoles('super_admin') || $viewer->hasPermission('staff.view_reports'))
                <x-ui.button :href="route('staff.reports')" icon="chart-column">Staff Reports</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="report-filters">
        <div class="report-filter-row">
            @if (count($filters['regions']) > 1)
                <x-ui.select label="Region" wire:model.live="regionId" class="report-filter-select">
                    <option value="">All regions</option>
                    @foreach ($filters['regions'] as $region)
                        <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                    @endforeach
                </x-ui.select>
            @endif

            <x-ui.select label="Department" wire:model.live="departmentId" class="report-filter-select">
                <option value="">All departments</option>
                @foreach ($filters['departments'] as $department)
                    <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </x-ui.card>

    {{-- (b) Headcount --}}
    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Active staff" :value="number_format($h['total_active'])" icon="users" :href="$staffList()" meta="On the books today" />
        <x-ui.stat-tile label="New hires" :value="$h['new_hires_month']" icon="user-plus" tone="success" :meta="$h['new_hires_year'].' so far in '.$asOf->year" />
        <x-ui.stat-tile label="Exits" :value="$h['exits_month']" icon="user-round" tone="muted" :meta="$h['exits_year'].' so far in '.$asOf->year.' (transfers not counted)'" />
        <x-ui.stat-tile label="Turnover, year to date" :value="$percent($h['turnover_year']['rate'])" icon="trending-up" tone="warning"
            :meta="'This month '.$percent($h['turnover_month']['rate']).' · average headcount '.$h['turnover_year']['average_headcount']" />
        <x-ui.stat-tile label="Average tenure" :value="$h['average_tenure_years'] === null ? 'n/a' : $h['average_tenure_years'].' yrs'" icon="clock"
            :meta="$h['tenure_unknown'] > 0 ? $h['tenure_unknown'].' with no hire date' : 'Active staff'" />
        <x-ui.stat-tile label="Average age" :value="$a['age']['average'] === null ? 'n/a' : $a['age']['average'].' yrs'" icon="user-round"
            :meta="$a['age']['unknown'] > 0 ? $a['age']['unknown'].' with no date of birth' : 'Active staff'" />
    </div>

    {{-- (a) Milestones --}}
    <div class="ui-grid ui-grid-2 report-grid dash-row">
        <x-ui.card :title="'Birthdays in '.$asOf->format('F')" :description="$m['birthdays']['count'].' '.\Illuminate\Support\Str::plural('person', $m['birthdays']['count'])" :padded="false">
            <x-ui.table label="Birthdays this month" :sticky="false">
                <x-slot:head><tr><th>Staff</th><th>Date</th><th class="num">Turning</th></tr></x-slot:head>
                @forelse ($m['birthdays']['items'] as $row)
                    <tr>
                        <td>{{ $row['name'] }} <span class="ui-person-sub">#{{ $row['staff_id'] }}</span></td>
                        <td class="nowrap">{{ \Carbon\Carbon::parse($row['date'])->format('d M') }}</td>
                        <td class="num">{{ $row['turning'] }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="3" icon="calendar-days" title="No birthdays this month." />
                @endforelse
            </x-ui.table>
            @if ($m['birthdays']['count'] > $limit)
                <p class="ui-hint">Showing the first {{ $limit }} of {{ $m['birthdays']['count'] }}.</p>
            @endif
        </x-ui.card>

        <x-ui.card :title="'Work anniversaries in '.$asOf->format('F')" :description="$m['anniversaries']['count'].' '.\Illuminate\Support\Str::plural('person', $m['anniversaries']['count'])" :padded="false">
            <x-ui.table label="Work anniversaries this month" :sticky="false">
                <x-slot:head><tr><th>Staff</th><th>Date</th><th class="num">Years</th></tr></x-slot:head>
                @forelse ($m['anniversaries']['items'] as $row)
                    <tr>
                        <td>{{ $row['name'] }} <span class="ui-person-sub">#{{ $row['staff_id'] }}</span></td>
                        <td class="nowrap">{{ \Carbon\Carbon::parse($row['date'])->format('d M') }}</td>
                        <td class="num">{{ $row['years'] }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="3" icon="calendar-days" title="No work anniversaries this month." />
                @endforelse
            </x-ui.table>
            @if ($m['anniversaries']['count'] > $limit)
                <p class="ui-hint">Showing the first {{ $limit }} of {{ $m['anniversaries']['count'] }}.</p>
            @endif
        </x-ui.card>

        <x-ui.card :title="'Completing 5, 10, 15 or 20 years in '.$asOf->year" description="Anniversary falls this year" :padded="false">
            <x-ui.table label="Service milestones this year" :sticky="false">
                <x-slot:head><tr><th>Milestone</th><th>Staff</th><th>Date</th></tr></x-slot:head>
                @php($anyMilestone = false)
                @foreach ($m['service_years'] as $group)
                    @foreach ($group['items'] as $row)
                        @php($anyMilestone = true)
                        <tr>
                            <td class="nowrap"><x-ui.badge>{{ $group['years'] }} years</x-ui.badge></td>
                            <td>{{ $row['name'] }} <span class="ui-person-sub">#{{ $row['staff_id'] }}</span></td>
                            <td class="nowrap">{{ \Carbon\Carbon::parse($row['date'])->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                @endforeach
                @unless ($anyMilestone)
                    <x-ui.empty-row :colspan="3" icon="calendar-days" title="Nobody reaches 5, 10, 15 or 20 years this year." />
                @endunless
            </x-ui.table>
            <p class="ui-hint">
                @foreach ($m['service_years'] as $group)
                    {{ $group['years'] }} yrs: {{ $group['count'] }}@unless ($loop->last) · @endunless
                @endforeach
            </p>
        </x-ui.card>

        <x-ui.card title="Approaching retirement" :description="'Retirement age '.$m['retirement']['age'].' · next '.$m['retirement']['window_months'].' months · '.$m['retirement']['count'].' '.\Illuminate\Support\Str::plural('person', $m['retirement']['count'])" :padded="false">
            <x-ui.table label="Staff approaching retirement" :sticky="false">
                <x-slot:head><tr><th>Staff</th><th>Retires</th></tr></x-slot:head>
                @forelse ($m['retirement']['items'] as $row)
                    <tr>
                        <td>{{ $row['name'] }} <span class="ui-person-sub">#{{ $row['staff_id'] }}</span></td>
                        <td class="nowrap">{{ \Carbon\Carbon::parse($row['date'])->format('d M Y') }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="2" icon="calendar-days" title="Nobody retires in this window." />
                @endforelse
            </x-ui.table>
            @if ($m['retirement']['overdue'] > 0)
                <x-ui.alert tone="warning">
                    {{ $m['retirement']['overdue'] }} active {{ \Illuminate\Support\Str::plural('employee', $m['retirement']['overdue']) }} {{ $m['retirement']['overdue'] === 1 ? 'is' : 'are' }} already past the retirement age of {{ $m['retirement']['age'] }}.
                </x-ui.alert>
            @endif
            <p class="ui-hint">The age comes from the retirement age setting ({{ $m['retirement']['age'] }}); the window is {{ $m['retirement']['window_months'] }} months.</p>
        </x-ui.card>
    </div>

    {{-- (c) Distribution --}}
    <div class="ui-grid ui-grid-2 report-grid dash-row">
        <x-ui.card title="Staff by department" description="Active staff, top 12">
            <x-ui.chart type="hbar" label="Active staff by department"
                event="hr-analytics-updated" source="departments" :source-data="$charts['departments']"
                :series="[['label' => 'Staff', 'key' => 'data']]" height="320" />
        </x-ui.card>

        <x-ui.card title="Staff by region">
            <x-ui.chart type="hbar" label="Active staff by region"
                event="hr-analytics-updated" source="regions" :source-data="$charts['regions']"
                :series="[['label' => 'Staff', 'key' => 'data']]" height="320" />
        </x-ui.card>

        <x-ui.card title="Head Office, regional offices and districts">
            <x-ui.chart type="doughnut" label="Active staff by location type" center center-caption="staff"
                event="hr-analytics-updated" source="locations" :source-data="$charts['locations']" :color-map="$locationColours"
                :series="[['label' => 'Staff', 'key' => 'data']]" height="260" />
        </x-ui.card>

        <x-ui.card title="Permanent and contract staff">
            <x-ui.chart type="doughnut" label="Permanent versus contract staff" center center-caption="staff"
                event="hr-analytics-updated" source="employment" :source-data="$charts['employment']" :color-map="$employmentColours"
                :series="[['label' => 'Staff', 'key' => 'data']]" height="260" />
        </x-ui.card>

        <x-ui.card title="Staff by category" description="Select a category to open the staff list" :padded="false">
            <x-ui.table label="Staff by category" :sticky="false">
                <x-slot:head><tr><th>Category</th><th class="num">Staff</th></tr></x-slot:head>
                @foreach ($a['distribution']['categories'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num"><a @if ($href = $staffList($row['filter'])) href="{{ $href }}" @endif>{{ $row['count'] }}</a></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Staff by age" :description="'Average '.($a['age']['average'] ?? 'n/a').' years'">
            <x-ui.chart type="bar" label="Active staff by age band"
                event="hr-analytics-updated" source="ageBands" :source-data="$charts['ageBands']"
                :series="[['label' => 'Staff', 'key' => 'data']]" height="260" />
        </x-ui.card>

        <x-ui.card title="Staff by grade" description="Active staff" :padded="false">
            <x-ui.table label="Staff by grade" :sticky="false">
                <x-slot:head><tr><th>Grade</th><th class="num">Staff</th></tr></x-slot:head>
                @foreach ($a['distribution']['grades'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num"><a @if ($href = $staffList($row['filter'])) href="{{ $href }}" @endif>{{ $row['count'] }}</a></td>
                    </tr>
                @endforeach
                <tr @class(['is-warning' => $g['missing_grade'] > 0])>
                    <td>No grade set</td>
                    <td class="num"><a @if ($href = $staffList(['grade' => 'none'])) href="{{ $href }}" @endif>{{ $g['missing_grade'] }}</a></td>
                </tr>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Staff by district" description="Busiest 15" :padded="false">
            <x-ui.table label="Staff by district" :sticky="false">
                <x-slot:head><tr><th>District</th><th class="num">Staff</th></tr></x-slot:head>
                @forelse ($a['distribution']['districts'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ $row['count'] }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="2" icon="map-pin" title="No district records in this scope." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>

    {{-- (d) Exit reasons --}}
    <div class="ui-grid ui-grid-2 report-grid dash-row">
        <x-ui.card :title="'Why staff left in '.$a['exit_reasons']['year']" :description="$a['exit_reasons']['departures'].' '.\Illuminate\Support\Str::plural('departure', $a['exit_reasons']['departures']).' so far'">
            <x-ui.chart type="doughnut" label="Exit reasons" center center-caption="departures"
                event="hr-analytics-updated" source="exitReasons" :source-data="$charts['exitReasons']"
                :series="[['label' => 'Departures', 'key' => 'data']]" height="260" />
        </x-ui.card>

        <x-ui.card title="Exit reasons" :padded="false">
            <x-ui.table label="Exit reasons" :sticky="false">
                <x-slot:head><tr><th>Reason</th><th class="num">Staff</th><th class="num">Share</th></tr></x-slot:head>
                @foreach ($a['exit_reasons']['rows'] as $row)
                    <tr>
                        <td>
                            {{ $row['label'] }}
                            @unless ($row['counts_as_exit'])
                                <span class="ui-person-sub">internal move, not counted as an exit</span>
                            @endunless
                        </td>
                        <td class="num">{{ $row['count'] }}</td>
                        <td class="num">{{ $percent($row['percent']) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            <p class="ui-hint">Shares are of all departures, transfers included. Exits ({{ $a['exit_reasons']['exits'] }}) and turnover leave transfers out.</p>
        </x-ui.card>
    </div>

    {{-- (e) Grade and entitlement --}}
    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card :title="'Annual leave entitlement, '.$g['year']" :description="'Gross days less the '.$g['compulsory_days'].' compulsory days for Head Office and regional office staff'" :padded="false">
            <x-ui.table label="Annual leave entitlement by category" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>Category</th>
                        <th class="num">Staff</th>
                        <th class="num">Gross entitlement</th>
                        <th class="num">Compulsory leave</th>
                        <th class="num">Available</th>
                    </tr>
                </x-slot:head>
                @foreach ([...$g['entitlement'], $g['entitlement_total']] as $row)
                    <tr @class(['is-total' => $row['label'] === 'All staff'])>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ $row['staff'] }}</td>
                        <td class="num">{{ $row['gross'] }}</td>
                        <td class="num">{{ $row['compulsory'] }}</td>
                        <td class="num">{{ $row['net'] }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Staff missing grade">
            <p class="ui-stat-value">{{ $g['missing_grade'] }}</p>
            <p class="ui-hint">
                Active staff with no grade yet keep the flat {{ config('gwl.leave_annual_days.ungraded', 31) }}-day entitlement and have no compulsory leave until they are graded.
            </p>
            @if ($g['missing_grade'] > 0 && $staffList(['grade' => 'none']))
                <x-ui.button :href="$staffList(['grade' => 'none'])" icon="users">Open the list</x-ui.button>
            @endif
        </x-ui.card>
    </div>
</div>
