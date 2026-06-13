<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Staff Leave Reports</h2>
            <p>{{ $fromLabel }} to {{ $toLabel }} · {{ $payload['scopeLabel'] ?? 'Visible staff scope' }}</p>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Filters</span>
        </div>
        <div style="padding:14px">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Date Range</label>
                    <select class="form-input" wire:model.live="datePreset">
                        <option value="this_month">This month</option>
                        <option value="last_3_months">Last 3 months</option>
                        <option value="last_6_months">Last 6 months</option>
                        <option value="last_12_months">Last 12 months</option>
                        <option value="custom">Custom range</option>
                    </select>
                </div>

                <div class="form-field">
                    <label class="form-label">Department</label>
                    <select class="form-input" wire:model.live="departmentId">
                        <option value="">All departments</option>
                        @foreach ($filters['departments'] as $department)
                            <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($filters['regions']->count() > 1)
                    <div class="form-field">
                        <label class="form-label">Region</label>
                        <select class="form-input" wire:model.live="regionId">
                            <option value="">All regions</option>
                            @foreach ($filters['regions'] as $region)
                                <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="form-field">
                    <label class="form-label">District</label>
                    <select class="form-input" wire:model.live="districtId">
                        <option value="">All districts</option>
                        @foreach ($filters['districts'] as $district)
                            <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($datePreset === 'custom')
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">From</label>
                        <input type="date" class="form-input" wire:model.live="customFrom">
                    </div>
                    <div class="form-field">
                        <label class="form-label">To</label>
                        <input type="date" class="form-input" wire:model.live="customTo">
                    </div>
                </div>
            @endif
        </div>
    </div>

    <style>
        .staff-report-stats { grid-template-columns: repeat(5, minmax(0, 1fr)); margin-top: 14px; }
        .staff-report-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .staff-report-table-wrap { overflow-x: auto; }
        @media (max-width: 1100px) {
            .staff-report-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .staff-report-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .staff-report-stats { grid-template-columns: 1fr; }
        }
    </style>

    <div class="stats staff-report-stats">
        @foreach (($payload['statCards'] ?? []) as $card)
            @php
                $tone = $card['tone'] ?? 'blue';
                $badgeBg = match ($tone) {
                    'red' => '#fcebeb',
                    'amber' => '#faeeda',
                    'green' => '#eaf3de',
                    default => '#e6f1fb',
                };
                $badgeColor = match ($tone) {
                    'red' => '#a32d2d',
                    'amber' => '#854f0b',
                    'green' => '#3b6d11',
                    default => '#185fa5',
                };
            @endphp
            <div class="stat">
                <div class="stat-lbl">{{ $card['label'] }}</div>
                <div class="stat-val">{{ $card['value'] }}</div>
                <div class="stat-sub">
                    <span class="pill" style="background:{{ $badgeBg }};color:{{ $badgeColor }}">{{ $card['badge'] }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="staff-report-grid">
        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Male Vs Female Total</span></div>
            <div style="padding:14px;height:280px"><canvas id="staffGenderChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Staff By District</span></div>
            <div style="padding:14px;height:300px"><canvas id="staffDistrictChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Staff By Department</span></div>
            <div style="padding:14px;height:300px"><canvas id="staffDepartmentChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Leave Status Breakdown</span></div>
            <div style="padding:14px;height:280px"><canvas id="staffLeaveStatusChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Approved Leave Days By Type</span></div>
            <div style="padding:14px;height:300px"><canvas id="staffLeaveTypeChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Monthly Leave Requests</span></div>
            <div style="padding:14px;height:300px"><canvas id="staffMonthlyLeaveChart"></canvas></div>
        </div>

        <div class="pg">
            <div class="pg-head"><span class="pg-title">District Numbers</span></div>
            <div class="staff-report-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>District</th>
                            <th>Region</th>
                            <th>Total</th>
                            <th>Male</th>
                            <th>Female</th>
                            <th>On Leave</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($payload['districtRows'] ?? []) as $row)
                            <tr>
                                <td>{{ $row['district'] }}</td>
                                <td>{{ $row['region'] }}</td>
                                <td>{{ $row['total'] }}</td>
                                <td>{{ $row['male'] }}</td>
                                <td>{{ $row['female'] }}</td>
                                <td>
                                    <span class="pill" style="background:#faeeda;color:#854f0b">{{ $row['on_leave'] }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="empty-state">No district records in this scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pg">
            <div class="pg-head"><span class="pg-title">On Leave Now</span></div>
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Leave</th>
                        <th>District</th>
                        <th>Returns</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($payload['currentlyOnLeave'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['employee'] }}</td>
                            <td>{{ $row['leave_type'] }}</td>
                            <td>{{ $row['district'] }}</td>
                            <td>
                                {{ $row['end_date'] ? \Carbon\Carbon::parse($row['end_date'])->format('d M Y') : '-' }}
                                <div style="font-size:10px;color:var(--color-text-secondary)">{{ $row['days_remaining'] }} days remaining</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-state">No active approved leave in this scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (() => {
            const initialPayload = @js($payload);
            const chartState = window.staffLeaveReportCharts || {};
            window.staffLeaveReportCharts = chartState;

            const palette = {
                blue: '#185fa5',
                green: '#21633c',
                red: '#a32d2d',
                amber: '#b7791f',
                cyan: '#0e7490',
                slate: '#66758b',
                purple: '#6b46c1'
            };

            function ctx(id) {
                const canvas = document.getElementById(id);
                return canvas ? canvas.getContext('2d') : null;
            }

            function replaceChart(key, id, config) {
                const context = ctx(id);

                if (! context || typeof Chart === 'undefined') {
                    return;
                }

                if (chartState[key]) {
                    chartState[key].destroy();
                }

                chartState[key] = new Chart(context, config);
            }

            function baseOptions(extra = {}) {
                return {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { boxWidth: 10, font: { size: 10 } } }
                    },
                    scales: {
                        x: { ticks: { font: { size: 10 } }, grid: { color: 'rgba(102,117,139,.12)' } },
                        y: { ticks: { font: { size: 10 } }, grid: { color: 'rgba(102,117,139,.12)' } }
                    },
                    ...extra
                };
            }

            function render(payload) {
                if (! payload) {
                    return;
                }

                replaceChart('gender', 'staffGenderChart', {
                    type: 'doughnut',
                    data: {
                        labels: payload.genderDistribution?.labels || [],
                        datasets: [{ data: payload.genderDistribution?.data || [], backgroundColor: [palette.green, palette.blue, palette.slate] }]
                    },
                    options: baseOptions({ scales: {}, plugins: { legend: { position: 'bottom' } } })
                });

                replaceChart('district', 'staffDistrictChart', {
                    type: 'bar',
                    data: {
                        labels: payload.staffByDistrict?.labels || [],
                        datasets: [
                            { label: 'Total staff', data: payload.staffByDistrict?.staff || [], backgroundColor: palette.blue, borderRadius: 5 },
                            { label: 'On leave', data: payload.staffByDistrict?.onLeave || [], backgroundColor: palette.amber, borderRadius: 5 }
                        ]
                    },
                    options: baseOptions({ indexAxis: 'y' })
                });

                replaceChart('department', 'staffDepartmentChart', {
                    type: 'bar',
                    data: {
                        labels: payload.staffByDepartment?.labels || [],
                        datasets: [{ label: 'Staff', data: payload.staffByDepartment?.data || [], backgroundColor: palette.cyan, borderRadius: 5 }]
                    },
                    options: baseOptions({ indexAxis: 'y', plugins: { legend: { display: false } } })
                });

                replaceChart('leaveStatus', 'staffLeaveStatusChart', {
                    type: 'doughnut',
                    data: {
                        labels: payload.leaveStatusBreakdown?.labels || [],
                        datasets: [{ data: payload.leaveStatusBreakdown?.data || [], backgroundColor: [palette.green, palette.amber, palette.red, palette.blue, palette.slate, palette.purple] }]
                    },
                    options: baseOptions({ scales: {}, plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } } })
                });

                replaceChart('leaveType', 'staffLeaveTypeChart', {
                    type: 'bar',
                    data: {
                        labels: payload.leaveTypeDays?.labels || [],
                        datasets: [{ label: 'Approved days', data: payload.leaveTypeDays?.data || [], backgroundColor: palette.green, borderRadius: 5 }]
                    },
                    options: baseOptions({ indexAxis: 'y', plugins: { legend: { display: false } } })
                });

                replaceChart('monthlyLeave', 'staffMonthlyLeaveChart', {
                    type: 'line',
                    data: {
                        labels: payload.monthlyLeaveRequests?.labels || [],
                        datasets: [{ label: 'Requests', data: payload.monthlyLeaveRequests?.data || [], borderColor: palette.blue, backgroundColor: 'rgba(24,95,165,.12)', fill: true, tension: .35 }]
                    },
                    options: baseOptions()
                });
            }

            window.addEventListener('staff-leave-report-data-updated', event => render(event.detail.charts));
            requestAnimationFrame(() => render(initialPayload));
        })();
    </script>
</div>
