<div class="main">
    <div class="page-head">
        <div class="ph-left">
            <h2>My Team — Leave Overview</h2>
            <p>{{ now()->format('F Y') }}</p>
        </div>

        <div class="ph-right">
            <a href="{{ route('leave.approvals') }}" class="btn btn-primary">
                Pending Approvals ({{ $stats['pending_approvals'] }})
            </a>
        </div>
    </div>

    <div class="content">

        {{-- KPI --}}
        <div class="stats">
            <div class="stat">
                <div class="stat-lbl">Team size</div>
                <div class="stat-val">{{ $stats['team_size'] }}</div>
            </div>

            <div class="stat">
                <div class="stat-lbl">On leave now</div>
                <div class="stat-val">{{ $stats['on_leave_now'] }}</div>
            </div>

            <div class="stat">
                <div class="stat-lbl">Pending approvals</div>
                <div class="stat-val">{{ $stats['pending_approvals'] }}</div>
            </div>

            <div class="stat">
                <div class="stat-lbl">Approved this month</div>
                <div class="stat-val">{{ $stats['approved_this_month'] }}</div>
            </div>
        </div>

        <div class="two">

            {{-- LEFT --}}
            <div>

                <div class="pg">
                    <div class="pg-head">
                        <span class="pg-title">Currently on leave</span>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Ends</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($onLeave as $r)
                                <tr>
                                    <td>{{ $r->requester->full_name }}</td>
                                    <td>{{ $r->leave_type }}</td>
                                    <td>{{ $r->end_date->format('d M') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3">None</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="pg">
                    <div class="pg-head">
                        <span class="pg-title">Upcoming leave (30 days)</span>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Starts</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($upcoming as $r)
                                <tr>
                                    <td>{{ $r->requester->full_name }}</td>
                                    <td>{{ $r->leave_type }}</td>
                                    <td>{{ $r->start_date->format('d M') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3">None</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- RIGHT --}}
            <div>
                <div class="pg">
                    <div class="pg-head">
                        <span class="pg-title">Team leave by type</span>
                    </div>
                    <div style="padding:10px 14px">
                        <div id="teamLeaveTypeChart" style="height:220px"></div>
                    </div>
                </div>

                <div class="pg">
                    <div class="pg-head">
                        <span class="pg-title">Avg approval cycle</span>
                    </div>
                    <div class="stat" style="margin:10px">
                        <div class="stat-val">{{ $slaStats['avg_cycle_hours'] }} hrs</div>
                        <div class="stat-lbl">Submission → Decision</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<a href="{{ route('leave.export.team.excel') }}" class="btn">
    Export Team Leave
</a>
<!------------------------------------- Chart Script ---------------------------------->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('teamLeaveTypeChart');
    if (!el) return;

    const data = @json(array_values($leaveByType));
    const labels = @json(array_keys($leaveByType));

    new ApexCharts(el, {
        chart: { type: 'bar', height: 220, toolbar: { show: false } },
        plotOptions: { bar: { horizontal: true } },
        series: [{ data }],
        xaxis: { categories: labels, max: 100 },
        colors: ['#185FA5'],
    }).render();
});
</script>
