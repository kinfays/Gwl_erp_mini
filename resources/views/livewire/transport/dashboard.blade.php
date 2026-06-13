<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Transport Dashboard</h2>
            <p>Fleet status, documents, maintenance, issues, and spend.</p>
        </div>
        <div class="ph-right">
            @if ($canManage)
                <a href="{{ route('transport.vehicles') }}" class="btn btn-primary">Vehicles</a>
                <a href="{{ route('transport.expenses') }}" class="btn btn-secondary">Expenses</a>
            @elseif ($canLogMileage)
                <a href="{{ route('transport.mileage') }}" class="btn btn-primary">Log Mileage</a>
            @elseif ($canReportIssue)
                <a href="{{ route('transport.issues') }}" class="btn btn-primary">Report Issue</a>
            @endif
        </div>
    </div>

    @if (! $canManage)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">My Assigned Vehicle</span>
            </div>
            @if ($assignedVehicle)
                <div style="padding:14px;display:grid;gap:10px">
                    <div class="stats" style="margin:0">
                        <div class="stat">
                            <div class="stat-lbl">Vehicle</div>
                            <div class="stat-val" style="font-size:16px">{{ $assignedVehicle->number_plate }}</div>
                            <div class="stat-sub">{{ $assignedVehicle->brand }} {{ $assignedVehicle->model }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-lbl">Mileage</div>
                            <div class="stat-val">{{ number_format($assignedVehicle->current_mileage) }}</div>
                            <div class="stat-sub">Next service {{ number_format($assignedVehicle->next_maintenance_mileage) }} km</div>
                        </div>
                        <div class="stat">
                            <div class="stat-lbl">Maintenance Left</div>
                            <div class="stat-val">{{ number_format($assignedVehicle->maintenance_remaining_km) }}</div>
                            <div class="stat-sub">km remaining</div>
                        </div>
                        <div class="stat">
                            <div class="stat-lbl">Status</div>
                            <div class="stat-val" style="font-size:16px">{{ str($assignedVehicle->status)->replace('_', ' ')->title() }}</div>
                        </div>
                    </div>
                </div>
            @else
                <div class="empty-state">No vehicle is assigned to your account.</div>
            @endif
        </div>
    @else
        <div class="stats" style="margin-top:14px">
            <div class="stat">
                <div class="stat-lbl">Fleet Size</div>
                <div class="stat-val">{{ $stats['total'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-lbl">Active</div>
                <div class="stat-val">{{ $stats['active'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-lbl">Maintenance</div>
                <div class="stat-val">{{ $stats['maintenance'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-lbl">This Month Spend</div>
                <div class="stat-val">{{ number_format($stats['month_spend'], 2) }}</div>
                <div class="stat-sub">GHS</div>
            </div>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Upcoming Maintenance</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Current Mileage</th>
                        <th>Remaining</th>
                        <th>Driver</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($upcomingMaintenance as $vehicle)
                        <tr>
                            <td>{{ $vehicle->number_plate }} <span style="color:var(--color-text-secondary)">- {{ $vehicle->brand }} {{ $vehicle->model }}</span></td>
                            <td>{{ number_format($vehicle->current_mileage) }} km</td>
                            <td>
                                <span class="pill" style="background:{{ $vehicle->maintenance_remaining_km <= 500 ? '#fcebeb' : '#eaf3de' }};color:{{ $vehicle->maintenance_remaining_km <= 500 ? '#a32d2d' : '#3b6d11' }}">
                                    {{ number_format($vehicle->maintenance_remaining_km) }} km
                                </span>
                            </td>
                            <td>{{ $vehicle->assignedDriver?->full_name ?? $vehicle->assignedDriver?->email ?? 'Unassigned' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-state">No maintenance data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Expiring Documents</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Insurance</th>
                        <th>Road Worthiness</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($expiringDocuments as $vehicle)
                        @php
                            $insuranceDays = $vehicle->insurance_expiry_date?->diffInDays(today(), false);
                            $roadDays = $vehicle->road_worthiness_expiry_date?->diffInDays(today(), false);
                        @endphp
                        <tr>
                            <td>{{ $vehicle->number_plate }}</td>
                            <td>{{ $vehicle->insurance_expiry_date?->format('d M Y') ?? 'Not set' }}</td>
                            <td>{{ $vehicle->road_worthiness_expiry_date?->format('d M Y') ?? 'Not set' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty-state">No documents expiring within 90 days.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Fleet By Department</span>
            </div>
            <table>
                <thead><tr><th>Department</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($departmentBreakdown as $row)
                        <tr><td>{{ $row->department_name }}</td><td>{{ $row->total }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="empty-state">No vehicles found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Issues And Spend</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px">
                <table>
                    <thead><tr><th>Status</th><th>Total</th></tr></thead>
                    <tbody>
                        @forelse ($issuesByStatus as $row)
                            <tr><td>{{ str($row->status)->replace('_', ' ')->title() }}</td><td>{{ $row->total }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="empty-state">No issues logged.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <table>
                    <thead><tr><th>Vehicle</th><th>Total Spend</th></tr></thead>
                    <tbody>
                        @forelse ($mostExpensive as $row)
                            <tr><td>{{ $row->number_plate }} - {{ $row->brand }} {{ $row->model }}</td><td>{{ number_format($row->total_spend, 2) }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="empty-state">No expenses logged.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
