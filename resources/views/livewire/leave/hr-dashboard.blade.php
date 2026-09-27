@assets
    @vite('resources/js/charts.js')
@endassets

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
    <div class="stats" style="margin-bottom:24px">

        <div class="stat">
            <div class="stat-lbl">Regional staff</div>
            <div class="stat-val">{{ $zoneStaffCount ?? '—' }}</div>
        </div>

        <div class="stat">
            <div class="stat-lbl">On leave now</div>
            <div class="stat-val">{{ $onLeaveNowCount }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Pending Request</div>
            <div class="stat-val">{{ $pendingCount }}</div>
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

        {{-- LEAVE BY TYPE --}}

            <div class="pg">
    <div class="pg-head">
        <span class="pg-title">Leave by Type — {{ now()->year }}</span>
    </div>

    <div class="px-4 py-3" wire:ignore>
        <div style="position:relative;height:240px">
            <canvas
                id="leaveByTypeChart"
                data-series='@json(array_values($leaveByType))'
                data-labels='@json(array_keys($leaveByType))'
            ></canvas>
        </div>
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

    <div class="px-4 py-3" wire:ignore>
        <div style="position:relative;height:90px">
            <canvas
                id="genderChart"
                data-male="{{ $genderBreakdown['male'] ?? 0 }}"
                data-female="{{ $genderBreakdown['female'] ?? 0 }}"
            ></canvas>
        </div>
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
    (() => {
        // Chart.js has no built-in data labels, so write each segment's percentage inside the bar.
        const segmentPercentLabels = {
            id: 'segmentPercentLabels',
            afterDatasetsDraw(chart) {
                const { ctx } = chart;

                ctx.save();
                ctx.fillStyle = '#fff';
                ctx.font = '600 11px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    if (! chart.isDatasetVisible(datasetIndex)) {
                        return;
                    }

                    chart.getDatasetMeta(datasetIndex).data.forEach((bar, index) => {
                        const value = dataset.data[index];

                        if (value > 0) {
                            ctx.fillText(`${value}%`, (bar.x + bar.base) / 2, bar.y);
                        }
                    });
                });

                ctx.restore();
            },
        };

        function initLeaveCharts() {
            if (typeof Chart === 'undefined') {
                return;
            }

            /* ===============================
               LEAVE BY TYPE (Horizontal Bar)
            =============================== */
            const typeEl = document.getElementById('leaveByTypeChart');
            if (typeEl) {
                const series = JSON.parse(typeEl.dataset.series || '[]');
                const labels = JSON.parse(typeEl.dataset.labels || '[]');

                typeEl._chart?.destroy();
                typeEl._chart = new Chart(typeEl, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{ label: 'Usage %', data: series, backgroundColor: '#185FA5', borderRadius: 4 }],
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: context => `${context.parsed.x}%` } },
                        },
                        scales: {
                            x: { min: 0, max: 100, ticks: { callback: value => `${value}%` } },
                        },
                    },
                });
            }

            /* ===============================
               GENDER BREAKDOWN (Stacked)
            =============================== */
            const genderEl = document.getElementById('genderChart');
            if (genderEl) {
                // data-male/data-female are approved-request counts; chart each as a share of the total.
                const counts = [parseInt(genderEl.dataset.male || 0), parseInt(genderEl.dataset.female || 0)];
                const total = counts[0] + counts[1];
                const malePercent = total ? Math.round((counts[0] / total) * 100) : 0;
                const femalePercent = total ? 100 - malePercent : 0;

                genderEl._chart?.destroy();
                genderEl._chart = new Chart(genderEl, {
                    type: 'bar',
                    data: {
                        labels: [''],
                        datasets: [
                            { label: 'Male', data: [malePercent], backgroundColor: '#185FA5', maxBarThickness: 24 },
                            { label: 'Female', data: [femalePercent], backgroundColor: '#D4537E', maxBarThickness: 24 },
                        ],
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { color: '#6B7280', boxWidth: 10 } },
                            tooltip: {
                                callbacks: {
                                    label: context => `${context.dataset.label}: ${counts[context.datasetIndex]} requests (${context.parsed.x}%)`,
                                },
                            },
                        },
                        scales: {
                            x: { stacked: true, min: 0, max: 100, ticks: { callback: value => `${value}%` } },
                            y: { stacked: true, grid: { display: false } },
                        },
                    },
                    plugins: [segmentPercentLabels],
                });
            }
        }

        document.addEventListener('livewire:navigated', initLeaveCharts);
        document.addEventListener('DOMContentLoaded', initLeaveCharts);
    })();
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

