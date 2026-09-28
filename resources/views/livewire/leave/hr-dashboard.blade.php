@assets
    @vite('resources/js/charts.js')
@endassets

@php
    $viewer = auth()->user();
    $canExport = $viewer->hasRoles('super_admin') || $viewer->hasPermission('leave.export');
    $statusColours = ['Approved' => 'success', 'Pending Approval' => 'warning', 'Planned' => 'muted', 'Denied' => 'danger'];
    $onLeaveShare = ($zoneStaffCount ?? 0) > 0 ? round($onLeaveNowCount / $zoneStaffCount * 100, 1) : null;
@endphp

<div>
    <x-ui.page-header
        title="HR Dashboard"
        :description="($viewer->isHeadOfficeHr() ? 'Head Office Zone' : 'Regional Zone').' · '.now()->format('F Y')"
    >
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('leave.export.approved.excel')" icon="download">Export Excel</x-ui.button>
            @endif
            <x-ui.button :href="route('leave.apply')" variant="primary" icon="plus">New Request</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Staff in zone" :value="number_format($zoneStaffCount ?? 0)" icon="users" meta="Active employees" />
        <x-ui.stat-tile label="On leave now" :value="$onLeaveNowCount" icon="calendar-days" tone="lagoon"
            :meta="$onLeaveShare !== null ? $onLeaveShare.'% of zone' : null" />
        <x-ui.stat-tile label="Pending requests" :value="$pendingCount" icon="clock" :tone="$pendingCount > 0 ? 'warning' : 'muted'"
            :href="route('leave.approvals')" meta="Awaiting a decision" />
        <x-ui.stat-tile label="Approved this month" :value="$approvedThisMonth" icon="circle-check" tone="success" />
        <x-ui.stat-tile label="Denied this month" :value="$deniedThisMonth" icon="circle-x" tone="muted" />
    </div>

    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card title="Leave days taken per month" :description="now()->format('Y').' · approved leave, by the month it starts'">
            <x-ui.chart type="area" label="Approved leave days per month, {{ now()->format('Y') }}" unit="days"
                :labels="$daysByMonth['labels']" :series="[['label' => 'Leave days', 'data' => $daysByMonth['data']]]" height="260" />
        </x-ui.card>

        <x-ui.card title="Requests by status" :description="now()->format('Y').' · by start date'">
            <x-ui.chart type="doughnut" label="Leave requests by status, {{ now()->format('Y') }}" center center-caption="requests"
                :color-map="$statusColours" :labels="$requestsByStatus['labels']"
                :series="[['label' => 'Requests', 'data' => $requestsByStatus['data']]]" height="260" />
        </x-ui.card>
    </div>

    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card title="Pending approvals" description="Most recent first" :padded="false">
            <x-slot:actions>
                <a href="{{ route('leave.approvals') }}" class="btn btn-ghost btn-sm">View all <x-ui.icon name="arrow-right" class="icon-sm" /></a>
            </x-slot:actions>

            <x-ui.table label="Pending approvals" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th class="num">Days</th>
                        <th>Stage</th>
                    </tr>
                </x-slot:head>
                @forelse ($pendingApprovals as $r)
                    <tr>
                        <td>
                            <span class="ui-person">
                                <x-ui.avatar :name="$r->requester->full_name" />
                                <span>
                                    <span class="ui-person-name">{{ $r->requester->full_name }}</span>
                                    <span class="ui-person-sub mono">{{ $r->requester->staff_id }}</span>
                                </span>
                            </span>
                        </td>
                        <td>{{ $r->leave_type }}</td>
                        <td class="nowrap">{{ $r->start_date->format('d M') }} – {{ $r->end_date->format('d M') }}</td>
                        <td class="num">{{ $r->total_days_applied }}</td>
                        <td><x-ui.status-pill domain="recommendation" :status="$r->manager_recommendation ?: 'Pending'" /></td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="square-check-big" title="No pending approvals" description="New requests will appear here as staff submit them." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Approval turnaround" description="Average hours per stage">
            <div class="ui-stack">
                <x-ui.meter label="Manager response" :value="$slaStats['avg_manager_hours'] ?? null" :target="48" unit="h" />
                <x-ui.meter label="Final approval" :value="$slaStats['avg_final_hours'] ?? null" :target="24" unit="h" />
                <x-ui.meter label="Total cycle" :value="$slaStats['avg_total_hours'] ?? null" :target="72" unit="h" />
                <p class="ui-hint">{{ __('Submission to recommendation, recommendation to decision, and submission to decision.') }}</p>
            </div>
        </x-ui.card>
    </div>

    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card title="Upcoming absences" description="Approved leave starting in the next 14 days" :padded="false">
            <x-ui.table label="Upcoming absences" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th class="num">Days</th>
                    </tr>
                </x-slot:head>
                @forelse ($upcomingAbsences as $absence)
                    <tr>
                        <td>
                            <span class="ui-person">
                                <x-ui.avatar :name="$absence->requester?->full_name ?? ''" />
                                <span>
                                    <span class="ui-person-name">{{ $absence->requester?->full_name ?? 'Unknown employee' }}</span>
                                    <span class="ui-person-sub">{{ $absence->requester?->department?->department_name ?? $absence->requester?->district?->district_name }}</span>
                                </span>
                            </span>
                        </td>
                        <td>{{ $absence->leave_type }}</td>
                        <td class="nowrap">{{ $absence->start_date->format('D d M') }} – {{ $absence->end_date->format('d M') }}</td>
                        <td class="num">{{ $absence->total_days_applied }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" icon="calendar-days" title="No approved leave starts in the next two weeks" />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <div class="ui-stack">
            <x-ui.card title="Leave by type" :description="now()->format('Y').' · share of approved days'">
                <x-ui.chart type="hbar" label="Leave usage by type, {{ now()->year }}" unit="%" :max="100" :legend="false"
                    :labels="array_keys($leaveByType)" :series="[['label' => 'Usage', 'data' => array_values($leaveByType)]]" height="220" />
            </x-ui.card>

            <x-ui.card title="Gender breakdown" description="Approved requests">
                <x-ui.split-bar label="Gender split of approved requests" :parts="[
                    ['label' => 'Male', 'value' => $genderBreakdown['male'] ?? 0, 'color' => 'series-1'],
                    ['label' => 'Female', 'value' => $genderBreakdown['female'] ?? 0, 'color' => 'series-3'],
                ]" />
            </x-ui.card>
        </div>
    </div>

    <x-ui.card title="SLA breaches" description="Approvals that took more than 72 hours from submission" :padded="false">
        <x-ui.table label="SLA breaches" :sticky="false">
            <x-slot:head>
                <tr>
                    <th>Employee</th>
                    <th>Type</th>
                    <th class="num">Total time</th>
                </tr>
            </x-slot:head>
            @forelse ($slowestApprovals as $r)
                <tr>
                    <td>{{ $r->requester->full_name }}</td>
                    <td>{{ $r->leave_type }}</td>
                    <td class="num">
                        {{-- One decimal where there is one, so e.g. 72.4h never reads as "72h" under a "> 72h" rule. --}}
                        <x-ui.status-pill tone="danger" :label="rtrim(rtrim(number_format($r->cycleHours(), 1), '0'), '.').'h'" />
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="3" icon="circle-check" title="No SLA breaches" />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
