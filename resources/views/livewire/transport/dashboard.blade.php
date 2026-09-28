<div>
    <x-ui.page-header title="Transport Dashboard" description="Fleet status, documents, maintenance, issues, and spend.">
        <x-slot:actions>
            @if ($canManage)
                <a href="{{ route('transport.expenses') }}" class="btn btn-secondary">
                    <x-ui.icon name="receipt" />
                    Expenses
                </a>
                <a href="{{ route('transport.vehicles') }}" class="btn btn-primary">
                    <x-ui.icon name="car" />
                    Vehicles
                </a>
            @elseif ($canLogMileage)
                <a href="{{ route('transport.mileage') }}" class="btn btn-primary">
                    <x-ui.icon name="gauge" />
                    Log Mileage
                </a>
            @elseif ($canReportIssue)
                <a href="{{ route('transport.issues') }}" class="btn btn-primary">
                    <x-ui.icon name="triangle-alert" />
                    Report Issue
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $viewer = auth()->user();
        // Tiles only link where the route's permission middleware will let the viewer in.
        $can = fn (string ...$permissions) => $viewer?->hasRoles('super_admin')
            || collect($permissions)->contains(fn (string $permission) => $viewer?->hasPermission($permission));
        $remainingTone = fn ($km) => $km <= 500 ? 'danger' : 'success';
        $daysLeft = fn ($date) => $date ? (int) today()->diffInDays($date, false) : null;
        $expiryTone = fn (?int $days) => $days === null ? 'muted' : ($days < 0 ? 'danger' : ($days <= 30 ? 'danger' : ($days <= 60 ? 'warning' : 'muted')));
        $expiryLabel = fn (?int $days) => $days === null ? null : ($days < 0 ? 'expired' : ($days === 0 ? 'today' : 'in '.$days.' '.\Illuminate\Support\Str::plural('day', $days)));
    @endphp

    @if (! $canManage)
        <x-ui.card title="My Assigned Vehicle">
            @if ($assignedVehicle)
                <dl class="ui-dl vehicle-facts">
                    <div>
                        <dt>Vehicle</dt>
                        <dd>
                            <span class="mono">{{ $assignedVehicle->number_plate }}</span>
                            <span class="ui-person-sub">{{ $assignedVehicle->brand }} {{ $assignedVehicle->model }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Mileage</dt>
                        <dd>
                            {{ number_format($assignedVehicle->current_mileage) }} km
                            <span class="ui-person-sub">Next service {{ number_format($assignedVehicle->next_maintenance_mileage) }} km</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Maintenance Left</dt>
                        <dd>
                            <x-ui.status-pill :tone="$remainingTone($assignedVehicle->maintenance_remaining_km)" :label="number_format($assignedVehicle->maintenance_remaining_km).' km'" />
                            <span class="ui-person-sub">km remaining</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd><x-ui.status-pill domain="vehicle" :status="$assignedVehicle->status" :label="str($assignedVehicle->status)->replace('_', ' ')->title()" /></dd>
                    </div>
                </dl>
            @else
                <x-ui.empty-state icon="car" title="No vehicle is assigned to your account." />
            @endif
        </x-ui.card>
    @else
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Fleet Size" :value="$stats['total']" icon="car" :href="$can('transport.view_vehicles', 'transport.view_own_vehicle') ? route('transport.vehicles') : null" />
            <x-ui.stat-tile label="Active" :value="$stats['active']" icon="circle-check" tone="success" />
            <x-ui.stat-tile label="Maintenance" :value="$stats['maintenance']" icon="wrench" tone="warning" :href="$can('transport.manage_maintenance') ? route('transport.maintenance') : null" />
            <x-ui.stat-tile label="Open Issues" :value="$stats['open_issues']" icon="triangle-alert" tone="danger" meta="Not yet resolved" :href="$can('transport.report_issues', 'transport.manage_issues') ? route('transport.issues') : null" />
            <x-ui.stat-tile label="This Month Spend" :value="number_format($stats['month_spend'], 2)" icon="banknote" tone="lagoon" meta="GHS" :href="$can('transport.manage_expenses') ? route('transport.expenses') : null" />
        </div>

        <div class="ui-grid ui-grid-main dash-row">
            <x-ui.card title="Upcoming Maintenance" description="Vehicles closest to their next service" :padded="false">
                <x-ui.table label="Upcoming maintenance" :sticky="false">
                    <x-slot:head>
                        <tr>
                            <th>Vehicle</th>
                            <th class="num">Current Mileage</th>
                            <th>Remaining</th>
                            <th>Driver</th>
                        </tr>
                    </x-slot:head>

                    @forelse ($upcomingMaintenance as $vehicle)
                        <tr>
                            <td>
                                <span class="ui-cell-stack">
                                    <span class="ui-person-name mono">{{ $vehicle->number_plate }}</span>
                                    <span class="ui-person-sub">{{ $vehicle->brand }} {{ $vehicle->model }}</span>
                                </span>
                            </td>
                            <td class="num nowrap">{{ number_format($vehicle->current_mileage) }} km</td>
                            <td><x-ui.status-pill :tone="$remainingTone($vehicle->maintenance_remaining_km)" :label="number_format($vehicle->maintenance_remaining_km).' km'" /></td>
                            <td @class(['nowrap', 'cell-muted' => ! $vehicle->assignedDriver])>{{ $vehicle->assignedDriver?->full_name ?? $vehicle->assignedDriver?->email ?? 'Unassigned' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="4" icon="wrench" title="No maintenance data." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Fleet By Department">
                <x-ui.bar-list label="Vehicles by department" empty="No vehicles found." empty-icon="car"
                    :items="$departmentBreakdown->map(fn ($row) => ['label' => $row->department_name, 'value' => $row->total])" />
            </x-ui.card>
        </div>

        <div class="ui-grid ui-grid-main">
            <x-ui.card title="Expiring Documents" description="Insurance or road worthiness due within 90 days" :padded="false">
                <x-ui.table label="Expiring documents" :sticky="false">
                    <x-slot:head>
                        <tr>
                            <th>Vehicle</th>
                            <th>Insurance</th>
                            <th>Road Worthiness</th>
                        </tr>
                    </x-slot:head>

                    @forelse ($expiringDocuments as $vehicle)
                        @php
                            $insuranceDays = $daysLeft($vehicle->insurance_expiry_date);
                            $roadDays = $daysLeft($vehicle->road_worthiness_expiry_date);
                        @endphp
                        <tr>
                            <td class="mono nowrap">{{ $vehicle->number_plate }}</td>
                            <td>
                                <span class="doc-date">
                                    <span @class(['cell-muted' => ! $vehicle->insurance_expiry_date])>{{ $vehicle->insurance_expiry_date?->format('d M Y') ?? 'Not set' }}</span>
                                    @if ($insuranceDays !== null && $insuranceDays <= 90)
                                        <x-ui.status-pill :tone="$expiryTone($insuranceDays)" :label="$expiryLabel($insuranceDays)" />
                                    @endif
                                </span>
                            </td>
                            <td>
                                <span class="doc-date">
                                    <span @class(['cell-muted' => ! $vehicle->road_worthiness_expiry_date])>{{ $vehicle->road_worthiness_expiry_date?->format('d M Y') ?? 'Not set' }}</span>
                                    @if ($roadDays !== null && $roadDays <= 90)
                                        <x-ui.status-pill :tone="$expiryTone($roadDays)" :label="$expiryLabel($roadDays)" />
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="3" icon="calendar-x" title="No documents expiring within 90 days." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <div class="ui-stack">
                <x-ui.card title="Issues By Status">
                    <x-ui.bar-list label="Vehicle issues by status" empty="No issues logged." empty-icon="triangle-alert"
                        :items="$issuesByStatus->map(fn ($row) => ['label' => str($row->status)->replace('_', ' ')->title()->toString(), 'value' => $row->total])" />
                </x-ui.card>

                <x-ui.card title="Highest Spend" description="All-time expenses per vehicle, GHS">
                    <x-ui.bar-list label="Highest spending vehicles" empty="No expenses logged." empty-icon="banknote"
                        :items="$mostExpensive->map(fn ($row) => ['label' => $row->number_plate.' · '.$row->brand.' '.$row->model, 'value' => $row->total_spend, 'display' => number_format($row->total_spend, 2)])" />
                </x-ui.card>
            </div>
        </div>
    @endif
</div>
