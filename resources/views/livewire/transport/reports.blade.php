@assets
    @vite('resources/js/charts.js')
@endassets

<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Transport Reports</h2>
            <p>{{ $fromLabel }} to {{ $toLabel }}</p>
        </div>
        <div class="ph-right">
            <a href="{{ route('transport.reports.export.pdf', $exportQuery) }}" class="btn btn-secondary">Export PDF</a>
            <a href="{{ route('transport.reports.export.excel', $exportQuery) }}" class="btn btn-primary">Export Excel</a>
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
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->department_name }}</option>
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
        .transport-report-stats { grid-template-columns: repeat(5, minmax(0, 1fr)); margin-top: 14px; }
        .transport-report-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        @media (max-width: 1000px) {
            .transport-report-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .transport-report-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .transport-report-stats { grid-template-columns: 1fr; }
        }
    </style>

    <div class="stats transport-report-stats">
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

    <div class="transport-report-grid">
        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Monthly Fleet Expenses</span></div>
            <div style="padding:14px;height:280px"><canvas id="transportMonthlyExpensesChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Expense Breakdown By Type</span></div>
            <div style="padding:14px;height:280px"><canvas id="transportExpenseTypeChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Vehicle Status Distribution</span></div>
            <div style="padding:14px;height:260px"><canvas id="transportVehicleStatusChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Vehicles By Department</span></div>
            <div style="padding:14px;height:260px"><canvas id="transportDepartmentChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Mileage Logged Per Month</span></div>
            <div style="padding:14px;height:280px"><canvas id="transportMileageChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Issues Reported Vs Resolved</span></div>
            <div style="padding:14px;height:280px"><canvas id="transportIssuesTrendChart"></canvas></div>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Top 5 Most Expensive Vehicles</span></div>
            <div style="padding:14px;height:260px"><canvas id="transportTopSpendChart"></canvas></div>
        </div>

        <div class="pg">
            <div class="pg-head"><span class="pg-title">Issues By Type</span></div>
            <div style="padding:14px;display:grid;gap:10px">
                @foreach (($payload['issuesByType']['rows'] ?? []) as $row)
                    <div style="display:grid;grid-template-columns:34px 110px 1fr 40px;align-items:center;gap:10px">
                        <span style="width:30px;height:30px;border-radius:8px;background:{{ $row['color'] }};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700">{{ $row['abbr'] }}</span>
                        <span style="font-size:12px;color:var(--color-text-primary)">{{ $row['label'] }}</span>
                        <span style="height:8px;border-radius:999px;background:var(--color-background-secondary);overflow:hidden">
                            <span style="display:block;height:8px;width:{{ $row['percent'] }}%;background:{{ $row['color'] }}"></span>
                        </span>
                        <strong style="font-size:12px;text-align:right">{{ $row['count'] }}</strong>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="pg">
            <div class="pg-head"><span class="pg-title">Upcoming Document Renewals</span></div>
            <table>
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Document</th>
                        <th>Expiry</th>
                        <th>Days</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($payload['upcomingExpiryDocs'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['vehicle'] }}</td>
                            <td>{{ $row['document'] }}</td>
                            <td>{{ \Carbon\Carbon::parse($row['expiry_date'])->format('d M Y') }}</td>
                            <td>
                                <span class="pill" style="background:{{ $row['badge'] === 'red' ? '#fcebeb' : '#faeeda' }};color:{{ $row['badge'] === 'red' ? '#a32d2d' : '#854f0b' }}">
                                    {{ $row['days_remaining'] }} days
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-state">No document renewals due within 60 days.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg" wire:ignore>
            <div class="pg-head"><span class="pg-title">Maintenance Due By Mileage</span></div>
            <div style="padding:14px;height:300px"><canvas id="transportMaintenanceChart"></canvas></div>
        </div>
    </div>

    <script>
        (() => {
            const initialPayload = @js($payload);
            const chartState = window.transportReportCharts || {};
            window.transportReportCharts = chartState;

            const palette = {
                blue: '#185fa5',
                green: '#21633c',
                red: '#a32d2d',
                amber: '#b7791f',
                slate: '#66758b',
                cyan: '#0e7490',
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

                replaceChart('monthlyExpenses', 'transportMonthlyExpensesChart', {
                    type: 'bar',
                    data: {
                        labels: payload.monthlyExpenses?.labels || [],
                        datasets: [{ label: 'GHS', data: payload.monthlyExpenses?.data || [], backgroundColor: palette.blue, borderRadius: 5 }]
                    },
                    options: baseOptions({ plugins: { legend: { display: false } } })
                });

                replaceChart('expenseType', 'transportExpenseTypeChart', {
                    type: 'doughnut',
                    data: {
                        labels: payload.expenseByType?.labels || [],
                        datasets: [{ data: payload.expenseByType?.data || [], backgroundColor: [palette.blue, palette.green, palette.amber, palette.red, palette.cyan, palette.slate, palette.purple, '#0f766e', '#475569'] }]
                    },
                    options: baseOptions({ scales: {}, plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } } })
                });

                replaceChart('vehicleStatus', 'transportVehicleStatusChart', {
                    type: 'doughnut',
                    data: {
                        labels: payload.vehicleStatusCounts?.labels || [],
                        datasets: [{ data: payload.vehicleStatusCounts?.data || [], backgroundColor: [palette.green, palette.amber, palette.slate] }]
                    },
                    options: baseOptions({ scales: {}, plugins: { legend: { position: 'bottom' } } })
                });

                replaceChart('departments', 'transportDepartmentChart', {
                    type: 'bar',
                    data: {
                        labels: payload.vehiclesByDepartment?.labels || [],
                        datasets: [{ label: 'Vehicles', data: payload.vehiclesByDepartment?.data || [], backgroundColor: palette.cyan, borderRadius: 5 }]
                    },
                    options: baseOptions({ indexAxis: 'y', plugins: { legend: { display: false } } })
                });

                replaceChart('mileage', 'transportMileageChart', {
                    type: 'line',
                    data: {
                        labels: payload.mileageByMonth?.labels || [],
                        datasets: [{ label: 'KM', data: payload.mileageByMonth?.data || [], borderColor: palette.green, backgroundColor: 'rgba(33,99,60,.14)', fill: true, tension: .35 }]
                    },
                    options: baseOptions()
                });

                replaceChart('issuesTrend', 'transportIssuesTrendChart', {
                    type: 'line',
                    data: {
                        labels: payload.issuesTrend?.labels || [],
                        datasets: [
                            { label: 'Reported', data: payload.issuesTrend?.reported || [], borderColor: palette.red, backgroundColor: 'rgba(163,45,45,.08)', tension: .35 },
                            { label: 'Resolved', data: payload.issuesTrend?.resolved || [], borderColor: palette.green, backgroundColor: 'rgba(33,99,60,.08)', tension: .35 }
                        ]
                    },
                    options: baseOptions()
                });

                replaceChart('topSpend', 'transportTopSpendChart', {
                    type: 'bar',
                    data: {
                        labels: payload.topExpensiveVehicles?.labels || [],
                        datasets: [{ label: 'GHS', data: payload.topExpensiveVehicles?.data || [], backgroundColor: [palette.blue, palette.green, palette.amber, palette.red, palette.cyan], borderRadius: 5 }]
                    },
                    options: baseOptions({ indexAxis: 'y', plugins: { legend: { display: false } } })
                });

                replaceChart('maintenance', 'transportMaintenanceChart', {
                    type: 'bar',
                    data: {
                        labels: payload.maintenanceDueSoon?.labels || [],
                        datasets: [
                            { label: 'Current mileage', data: payload.maintenanceDueSoon?.current || [], backgroundColor: palette.blue, borderRadius: 5 },
                            { label: 'KM remaining', data: payload.maintenanceDueSoon?.remaining || [], backgroundColor: payload.maintenanceDueSoon?.remainingColors || [], borderRadius: 5 }
                        ]
                    },
                    options: baseOptions({ indexAxis: 'y' })
                });
            }

            window.addEventListener('transport-report-data-updated', event => render(event.detail.charts));

            // Chart.js arrives as a deferred module, which has run by DOMContentLoaded.
            const renderInitial = () => requestAnimationFrame(() => render(initialPayload));

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', renderInitial, { once: true });
            } else {
                renderInitial();
            }
        })();
    </script>
</div>
