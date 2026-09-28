@assets
    @vite('resources/js/charts.js')
@endassets

@php
    $viewer = auth()->user();
    $canExport = $viewer->hasRoles('super_admin') || $viewer->hasPermission('leave.export');
@endphp

<div class="main">
    <x-ui.page-header title="My Team — Leave Overview" :description="now()->format('F Y')">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('leave.export.team.excel')" icon="download">Export Team Leave</x-ui.button>
            @endif
            <x-ui.button :href="route('leave.approvals')" variant="primary" icon="square-check-big">
                Pending Approvals ({{ $stats['pending_approvals'] }})
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="content">
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Team size" :value="$stats['team_size']" icon="users" />
            <x-ui.stat-tile label="On leave now" :value="$stats['on_leave_now']" icon="calendar-days" tone="lagoon" />
            <x-ui.stat-tile label="Pending approvals" :value="$stats['pending_approvals']" icon="clock"
                :tone="$stats['pending_approvals'] > 0 ? 'warning' : 'muted'" :href="route('leave.approvals')" />
            <x-ui.stat-tile label="Approved this month" :value="$stats['approved_this_month']" icon="circle-check" tone="success" />
        </div>

        <div class="ui-grid ui-grid-main">
            <div class="ui-stack">
                <x-ui.card title="Currently on leave" :padded="false">
                    <x-ui.table label="Team members currently on leave" :sticky="false">
                        <x-slot:head>
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Details</th>
                                <th>Ends</th>
                            </tr>
                        </x-slot:head>
                        @forelse ($onLeave as $r)
                            <tr>
                                <td>
                                    <span class="ui-person">
                                        <x-ui.avatar :name="$r->requester->full_name" />
                                        <span class="ui-person-name">{{ $r->requester->full_name }}</span>
                                    </span>
                                </td>
                                <td>{{ $r->leave_type }}</td>
                                <td class="cell-muted">{{ $r->leave_details ?: 'No details provided.' }}</td>
                                <td class="nowrap">{{ $r->end_date->format('d M') }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="4" icon="users" title="Nobody on your team is on leave today" />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>

                <x-ui.card title="Upcoming leave" description="Next 30 days" :padded="false">
                    <x-ui.table label="Upcoming team leave" :sticky="false">
                        <x-slot:head>
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Details</th>
                                <th>Starts</th>
                            </tr>
                        </x-slot:head>
                        @forelse ($upcoming as $r)
                            <tr>
                                <td>
                                    <span class="ui-person">
                                        <x-ui.avatar :name="$r->requester->full_name" />
                                        <span class="ui-person-name">{{ $r->requester->full_name }}</span>
                                    </span>
                                </td>
                                <td>{{ $r->leave_type }}</td>
                                <td class="cell-muted">{{ $r->leave_details ?: 'No details provided.' }}</td>
                                <td class="nowrap">{{ $r->start_date->format('d M') }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="4" icon="calendar-days" title="No team leave in the next 30 days" />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            </div>

            <div class="ui-stack">
                <x-ui.card title="Team leave by type" description="Share of approved days">
                    <x-ui.chart type="hbar" label="Team leave by type, share of approved days" unit="%" :max="100" :legend="false"
                        :labels="array_keys($leaveByType)" :series="[['label' => 'Share of approved days', 'data' => array_values($leaveByType)]]" height="220" />
                </x-ui.card>

                <x-ui.card title="Avg approval cycle" description="Submission → Decision">
                    <x-ui.meter label="Average cycle" :value="$slaStats['avg_cycle_hours'] ?? null" :target="72" unit="h" />
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
