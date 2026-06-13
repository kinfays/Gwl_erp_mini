<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Vehicles</h2>
            <p>Fleet records, assignments, documents, and import.</p>
        </div>
        <div class="ph-right">
            @if ($canManage)
                <button type="button" class="btn btn-secondary" wire:click="$toggle('showImport')">Import</button>
                <button type="button" class="btn btn-primary" wire:click="openCreate">Add Vehicle</button>
            @endif
        </div>
    </div>

    @if ($showImport && $canManage)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Vehicle Import</span>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Excel or CSV file</label>
                        <input type="file" class="form-input" wire:model="importFile">
                        @error('importFile') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="importVehicles" wire:loading.attr="disabled">Run Import</button>
                    </div>
                </div>

                @if ($importSummary)
                    <div class="stats" style="margin-top:10px">
                        <div class="stat"><div class="stat-lbl">Created</div><div class="stat-val">{{ $importSummary['created'] }}</div></div>
                        <div class="stat"><div class="stat-lbl">Updated</div><div class="stat-val">{{ $importSummary['updated'] }}</div></div>
                        <div class="stat"><div class="stat-lbl">Failed</div><div class="stat-val">{{ $importSummary['failed'] }}</div></div>
                        <div class="stat"><div class="stat-lbl">Columns</div><div class="stat-sub">number_plate, type, brand, model, assigned_user_email, assigned_driver_email</div></div>
                    </div>
                @endif

                @if ($importErrors)
                    <table>
                        <thead><tr><th>Row</th><th>Error</th></tr></thead>
                        <tbody>
                            @foreach ($importErrors as $error)
                                <tr><td>{{ $error['row'] }}</td><td>{{ $error['message'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endif

    @if ($showForm && $canManage)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">{{ $editingVehicleId ? 'Edit Vehicle' : 'Add Vehicle' }}</span>
                <button type="button" class="btn btn-secondary" wire:click="closeForm">Close</button>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Type</label>
                        <select class="form-input" wire:model="form.type">
                            @foreach ($types as $option)
                                <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                        @error('form.type') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Number Plate</label>
                        <input class="form-input" wire:model.defer="form.number_plate">
                        @error('form.number_plate') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Brand</label>
                        <input class="form-input" wire:model.defer="form.brand">
                        @error('form.brand') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Model</label>
                        <input class="form-input" wire:model.defer="form.model">
                        @error('form.model') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Color</label>
                        <input class="form-input" wire:model.defer="form.color">
                    </div>
                    <div class="form-field">
                        <label class="form-label">Year Purchased</label>
                        <input type="number" class="form-input" wire:model.defer="form.year_purchased">
                        @error('form.year_purchased') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-field" style="flex-direction:row;align-items:center;margin-top:18px">
                        <input type="checkbox" wire:model.live="form.is_pool_car">
                        <span class="form-label">Pool car</span>
                    </label>
                    <div class="form-field">
                        <label class="form-label">Department</label>
                        <select class="form-input" wire:model="form.department_id">
                            <option value="">Unassigned</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @unless ($form['is_pool_car'])
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Assigned Employee</label>
                            <select class="form-input" wire:model="form.assigned_user_id">
                                <option value="">Select employee</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->full_name ?? $user->email }}</option>
                                @endforeach
                            </select>
                            @error('form.assigned_user_id') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Driver Type</label>
                            <select class="form-input" wire:model.live="form.driver_type">
                                @foreach ($driverTypes as $option)
                                    <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endunless

                @if ($form['driver_type'] === 'assigned_driver')
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Assigned Driver</label>
                            <select class="form-input" wire:model="form.assigned_driver_id">
                                <option value="">Select driver</option>
                                @foreach ($drivers as $driver)
                                    <option value="{{ $driver->id }}">{{ $driver->full_name ?? $driver->email }}</option>
                                @endforeach
                            </select>
                            @error('form.assigned_driver_id') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Photo</label>
                            <input type="file" class="form-input" wire:model="photo">
                            @error('photo') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endif

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Current Mileage</label>
                        <input type="number" class="form-input" wire:model.defer="form.current_mileage">
                        @error('form.current_mileage') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Maintenance Interval KM</label>
                        <input type="number" class="form-input" wire:model.defer="form.maintenance_interval_km">
                        @error('form.maintenance_interval_km') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Insurance Expiry</label>
                        <input type="date" class="form-input" wire:model.defer="form.insurance_expiry_date">
                    </div>
                    <div class="form-field">
                        <label class="form-label">Road Worthiness Expiry</label>
                        <input type="date" class="form-input" wire:model.defer="form.road_worthiness_expiry_date">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Status</label>
                        <select class="form-input" wire:model="form.status">
                            @foreach ($statuses as $option)
                                <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save Vehicle</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Fleet Register</span>
            <div class="ph-right">
                <input class="form-input" style="width:190px" placeholder="Search" wire:model.live.debounce.300ms="search">
                <select class="form-input" wire:model.live="type">
                    <option value="">All types</option>
                    @foreach ($types as $option)
                        <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                <select class="form-input" wire:model.live="status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Assigned</th>
                    <th>Mileage</th>
                    <th>Documents</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($vehicles as $vehicle)
                    <tr>
                        <td>
                            <div>{{ $vehicle->number_plate }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $vehicle->brand }} {{ $vehicle->model }} · {{ str($vehicle->type)->replace('_', ' ')->title() }}</div>
                        </td>
                        <td>
                            <div>{{ $vehicle->assignedUser?->full_name ?? $vehicle->assignedUser?->email ?? ($vehicle->is_pool_car ? 'Pool car' : 'Unassigned') }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $vehicle->assignedDriver?->full_name ?? $vehicle->assignedDriver?->email ?? 'Self drive' }}</div>
                        </td>
                        <td>{{ number_format($vehicle->current_mileage) }} km</td>
                        <td>
                            <div style="font-size:10px">Insurance: {{ $vehicle->insurance_expiry_date?->format('d M Y') ?? 'Not set' }}</div>
                            <div style="font-size:10px">Road: {{ $vehicle->road_worthiness_expiry_date?->format('d M Y') ?? 'Not set' }}</div>
                        </td>
                        <td>
                            <span class="pill" style="background:{{ $vehicle->status === 'active' ? '#eaf3de' : ($vehicle->status === 'maintenance' ? '#faeeda' : '#f1f5f9') }};color:var(--color-text-primary)">
                                {{ str($vehicle->status)->replace('_', ' ')->title() }}
                            </span>
                        </td>
                        <td style="text-align:right">
                            <button type="button" class="btn btn-secondary" wire:click="viewVehicle({{ $vehicle->id }})">Details</button>
                            @if ($canManage)
                                <button type="button" class="btn btn-primary" wire:click="openEdit({{ $vehicle->id }})">Edit</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-state">No vehicles found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px 14px">{{ $vehicles->links() }}</div>
    </div>

    @if ($selectedVehicle)
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">{{ $selectedVehicle->number_plate }} Details</span>
                <button type="button" class="btn btn-secondary" wire:click="$set('selectedVehicleId', null)">Close</button>
            </div>
            <div style="padding:14px">
                <div class="stats">
                    <div class="stat"><div class="stat-lbl">Age</div><div class="stat-val">{{ $selectedVehicle->age ?? 'N/A' }}</div><div class="stat-sub">years</div></div>
                    <div class="stat"><div class="stat-lbl">Next Maintenance</div><div class="stat-val">{{ number_format($selectedVehicle->next_maintenance_mileage) }}</div></div>
                    <div class="stat"><div class="stat-lbl">Open Issues</div><div class="stat-val">{{ $selectedVehicle->issues->where('status', '!=', 'resolved')->count() }}</div></div>
                    <div class="stat"><div class="stat-lbl">Department</div><div class="stat-val" style="font-size:14px">{{ $selectedVehicle->department?->department_name ?? 'Unassigned' }}</div></div>
                </div>

                @if ($selectedVehicle->maintenance_remaining_km <= 500)
                    <div class="alert alert-warning" style="margin-bottom:12px">Maintenance is due within {{ number_format($selectedVehicle->maintenance_remaining_km) }} km.</div>
                @endif

                <table>
                    <thead><tr><th>Assigned To</th><th>Driver</th><th>Assigned At</th><th>Unassigned At</th><th>Notes</th></tr></thead>
                    <tbody>
                        @forelse ($selectedVehicle->assignmentHistories->sortByDesc('assigned_at') as $history)
                            <tr>
                                <td>{{ $history->user?->full_name ?? $history->user?->email ?? 'Unassigned' }}</td>
                                <td>{{ $history->driver?->full_name ?? $history->driver?->email ?? 'Self drive' }}</td>
                                <td>{{ $history->assigned_at?->format('d M Y H:i') }}</td>
                                <td>{{ $history->unassigned_at?->format('d M Y H:i') ?? 'Active' }}</td>
                                <td>{{ $history->notes }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="empty-state">No assignment history yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
