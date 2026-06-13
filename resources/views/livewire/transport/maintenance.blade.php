<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Maintenance</h2>
            <p>Service records, maintenance thresholds, and document renewal.</p>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Upcoming Maintenance</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Current Mileage</th>
                    <th>Remaining</th>
                    <th>Next Mileage</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($upcoming as $vehicle)
                    <tr>
                        <td>{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</td>
                        <td>{{ number_format($vehicle->current_mileage) }} km</td>
                        <td>
                            <span class="pill" style="background:{{ $vehicle->maintenance_remaining_km <= 500 ? '#fcebeb' : '#eaf3de' }};color:var(--color-text-primary)">
                                {{ number_format($vehicle->maintenance_remaining_km) }} km
                            </span>
                        </td>
                        <td>{{ number_format($vehicle->next_maintenance_mileage) }} km</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-state">No vehicles found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Add Maintenance Record</span>
        </div>
        <div style="padding:14px">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Vehicle</label>
                    <select class="form-input" wire:model="form.vehicle_id">
                        <option value="">Select vehicle</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                        @endforeach
                    </select>
                    @error('form.vehicle_id') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-field">
                    <label class="form-label">Maintenance Type</label>
                    <input class="form-input" wire:model.defer="form.maintenance_type">
                    @error('form.maintenance_type') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Performed By</label>
                    <input class="form-input" wire:model.defer="form.performed_by">
                </div>
                <div class="form-field">
                    <label class="form-label">Service Date</label>
                    <input type="date" class="form-input" wire:model.defer="form.service_date">
                    @error('form.service_date') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Mileage At Service</label>
                    <input type="number" class="form-input" wire:model.defer="form.mileage_at_service">
                </div>
                <div class="form-field">
                    <label class="form-label">Cost</label>
                    <input type="number" step="0.01" class="form-input" wire:model.defer="form.cost">
                    @error('form.cost') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Next Service Date</label>
                    <input type="date" class="form-input" wire:model.defer="form.next_service_date">
                </div>
                <div class="form-field">
                    <label class="form-label">Next Service Mileage</label>
                    <input type="number" class="form-input" wire:model.defer="form.next_service_mileage">
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Description</label>
                    <textarea class="form-input" rows="3" wire:model.defer="form.description"></textarea>
                </div>
                <div class="form-field">
                    <label class="form-label">Receipt Photo</label>
                    <input type="file" class="form-input" wire:model="receipt">
                    @error('receipt') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save Maintenance</button>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Renew Documents</span>
        </div>
        <div style="padding:14px">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Vehicle</label>
                    <select class="form-input" wire:model="renewal.vehicle_id">
                        <option value="">Select vehicle</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }}</option>
                        @endforeach
                    </select>
                    @error('renewal.vehicle_id') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-field">
                    <label class="form-label">Document</label>
                    <select class="form-input" wire:model="renewal.type">
                        <option value="insurance">Insurance</option>
                        <option value="road_worthiness">Road Worthiness</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">New Expiry Date</label>
                    <input type="date" class="form-input" wire:model.defer="renewal.expiry_date">
                    @error('renewal.expiry_date') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-field">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" class="form-input" wire:model.defer="renewal.amount">
                </div>
            </div>
            <button type="button" class="btn btn-secondary" wire:click="renewDocument" wire:loading.attr="disabled">Record Renewal</button>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Maintenance History</span>
            <select class="form-input" wire:model.live="vehicleFilter">
                <option value="">All vehicles</option>
                @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }}</option>
                @endforeach
            </select>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Vehicle</th>
                    <th>Type</th>
                    <th>Provider</th>
                    <th>Mileage</th>
                    <th>Cost</th>
                    <th>Next</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td>{{ $record->service_date?->format('d M Y') }}</td>
                        <td>{{ $record->vehicle?->number_plate }}</td>
                        <td>{{ str($record->maintenance_type)->replace('_', ' ')->title() }}</td>
                        <td>{{ $record->performed_by }}</td>
                        <td>{{ $record->mileage_at_service ? number_format($record->mileage_at_service).' km' : 'N/A' }}</td>
                        <td>{{ number_format($record->cost, 2) }}</td>
                        <td>{{ $record->next_service_date?->format('d M Y') ?? ($record->next_service_mileage ? number_format($record->next_service_mileage).' km' : 'N/A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-state">No maintenance records found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px 14px">{{ $records->links() }}</div>
    </div>
</div>
