<div class="space-y-6">

    {{-- PAGE HEADER --}}
    <div class="page-head">
        <div class="ph-left">
            <h2>HR Dashboard</h2>
            <p>
                {{ auth()->user()->isHeadOfficeHr() ? 'Head Office Zone' : 'Regional Zone' }}
                · {{ now()->format('F Y') }}
            </p>
        </div>

        <div class="ph-right">
            {{-- reserved for Phase 4 --}}
            <button class="btn">Export Excel</button>
            <a href="{{ route('leave.apply') }}" class="btn btn-primary">+ New Request</a>
        </div>
    </div>

    {{-- KPI STATS --}}
    <div class="stats">

        <div class="stat">
            <div class="stat-lbl">Regional staff</div>
            <div class="stat-val">{{ $zoneStaffCount ?? '—' }}</div>
        </div>

        <div class="stat">
            <div class="stat-lbl">On leave now</div>
            <div class="stat-val">{{ $pendingCount }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Pending Request</div>
            <div class="stat-val">{{ $approvedThisMonth }}</div>
        </div>
        

        <div class="stat">
            <div class="stat-lbl">Approved this month</div>
            <div class="stat-val">{{ $approvedThisMonth }}</div>
        </div>

        <div class="stat">
            <div class="stat-lbl">Denied</div>
            <div class="stat-val">{{ $deniedThisMonth }}</div>
        </div>
    </div>

    <div class="two">

        {{-- SLA METRICS --}}
<div class="stats">
    <div class="stat">
        <div class="stat-lbl">Avg. manager response</div>
        <div class="stat-val">{{ $slaStats['avg_manager_hours'] }}h</div>
        <div class="stat-sub">Target ≤ 48h</div>
    </div>

    <div class="stat">
        <div class="stat-lbl">Avg. final approval</div>
        <div class="stat-val">{{ $slaStats['avg_final_hours'] }}h</div>
        <div class="stat-sub">Target ≤ 24h</div>
    </div>

    <div class="stat">
        <div class="stat-lbl">Avg. total cycle</div>
        <div class="stat-val">{{ $slaStats['avg_total_hours'] }}h</div>
        <div class="stat-sub">Target ≤ 72h</div>
    </div>
</div>

        {{-- PENDING APPROVALS --}}
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Pending approvals</span>
                <a href="{{ route('leave.approvals') }}" class="actn">View all</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pendingApprovals as $r)
                        <tr>
                            <td>{{ $r->requester->full_name }}</td>
                            <td>{{ $r->leave_type }}</td>
                            <td>{{ $r->start_date->format('d M') }} – {{ $r->end_date->format('d M') }}</td>
                            <td>{{ $r->total_days_applied }}</td>
                            <td>
                                <span class="pill p-a">Pending</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-slate-500">No pending approvals</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ANALYTICS --}}
        <div class="space-y-4">

            {{-- LEAVE BY TYPE --}}
          <!--  <div class="pg">
                <div class="pg-head">
                    <span class="pg-title">Leave by type — {{ now()->year }}</span>
                </div>

                <div class="px-4 py-3">
                    @foreach($leaveByType as $type => $pct)
                        <div class="bar-row">
                            <div class="bar-lbl">{{ $type }}</div>
                            <div class="bar-track">
                                <div class="bar-fill"
                                     style="width:{{ $pct }}%; background:#185FA5"></div>
                            </div>
                            <div class="bar-val">{{ $pct }}%</div>
                        </div>
                    @endforeach
                </div>
            </div> -->

            <div class="pg">
    <div class="pg-head">
        <span class="pg-title">Leave by Type — {{ now()->year }}</span>
    </div>

    <div class="px-4 py-3">
        <div
            id="leaveByTypeChart"
            data-series='@json(array_values($leaveByType))'
            data-labels='@json(array_keys($leaveByType))'
            style="height:240px"
        ></div>
    </div>
</div>

{{-- SLA BREACHES --}}
<div class="pg">
    <div class="pg-head">
        <span class="pg-title">SLA breaches (slow approvals)</span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Total Time</th>
            </tr>
        </thead>
        <tbody>
            @forelse($slowestApprovals as $r)
                <tr>
                    <td>{{ $r->requester->full_name }}</td>
                    <td>{{ $r->leave_type }}</td>
                    <td>
                        <span class="pill p-r">
                            {{ $r->updated_at->diffInHours($r->created_at) }}h
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-slate-500">
                        No SLA breaches 🎉
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

            {{-- GENDER --}}

            <div class="pg">
            <div class="pg-head">
             <span class="pg-title">Gender Breakdown (Approved)</span>
             </div>

    <div class="px-4 py-3">
        <div
            id="genderChart"
            data-male="{{ $genderBreakdown['male'] ?? 0 }}"
            data-female="{{ $genderBreakdown['female'] ?? 0 }}"
            style="height:90px"
        ></div>
    </div>
</div>
       <!--     <div class="pg">
                <div class="pg-head">
                    <span class="pg-title">Gender breakdown (approved)</span>
                </div>

                <div class="px-4 py-3 text-xs space-y-2">
                    <div class="flex justify-between">
                        <span>Male</span>
                        <span>{{ $genderBreakdown['male'] ?? 0 }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Female</span>
                        <span>{{ $genderBreakdown['female'] ?? 0 }}</span>
                    </div>
                </div>
            </div> -->

        </div>

    </div>
</div>  

<script>
    document.addEventListener('livewire:navigated', initLeaveCharts);
    document.addEventListener('DOMContentLoaded', initLeaveCharts);

    function initLeaveCharts() {

        /* ===============================
           LEAVE BY TYPE (Horizontal Bar)
        =============================== */
        const typeEl = document.getElementById('leaveByTypeChart');
        if (typeEl) {
            const series = JSON.parse(typeEl.dataset.series || '[]');
            const labels = JSON.parse(typeEl.dataset.labels || '[]');

            if (typeEl._chart) {
                typeEl._chart.destroy();
            }

            const options = {
                chart: {
                    type: 'bar',
                    height: 240,
                    toolbar: { show: false },
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        borderRadius: 4,
                    }
                },
                dataLabels: { enabled: false },
                series: [{
                    name: 'Usage %',
                    data: series,
                }],
                xaxis: {
                    categories: labels,
                    max: 100,
                    labels: { formatter: val => `${val}%` },
                },
                colors: ['#185FA5'],
            };

            typeEl._chart = new ApexCharts(typeEl, options);
            typeEl._chart.render();
        }

        /* ===============================
           GENDER BREAKDOWN (Stacked)
        =============================== */
        const genderEl = document.getElementById('genderChart');
        if (genderEl) {
            const male = parseInt(genderEl.dataset.male || 0);
            const female = parseInt(genderEl.dataset.female || 0);

            if (genderEl._chart) {
                genderEl._chart.destroy();
            }

            const options = {
                chart: {
                    type: 'bar',
                    stacked: true,
                    height: 90,
                    toolbar: { show: false },
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        barHeight: '40%',
                    }
                },
                dataLabels: {
                    enabled: true,
                    formatter: val => `${val}%`,
                },
                series: [
                    { name: 'Male', data: [male] },
                    { name: 'Female', data: [female] },
                ],
                xaxis: { categories: [''] },
                colors: ['#185FA5', '#D4537E'],
                legend: {
                    position: 'bottom',
                    labels: { colors: '#6B7280' }
                },
            };

            genderEl._chart = new ApexCharts(genderEl, options);
            genderEl._chart.render();
        }
    }
</script>


<!-- <div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold">HR Dashboard</h2>
        <p class="text-sm text-slate-600">Leave module overview for your scope.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white border rounded-xl p-4">
            <p class="text-xs text-slate-500">Pending Requests</p>
            <p class="text-2xl font-bold">{{ $pendingCount }}</p>
        </div>

        <div class="bg-white border rounded-xl p-4">
            <p class="text-xs text-slate-500">Approved This Month</p>
            <p class="text-2xl font-bold">{{ $approvedThisMonth }}</p>
        </div>

        <div class="bg-white border rounded-xl p-4">
            <p class="text-xs text-slate-500">Denied This Month</p>
            <p class="text-2xl font-bold">{{ $deniedThisMonth }}</p>
        </div>
    </div>

    <div class="bg-white border rounded-xl p-4 text-slate-500">
        Pending approvals table + charts (ApexCharts) will be added next.
    </div>
</div> -->

