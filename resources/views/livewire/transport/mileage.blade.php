<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Mileage Tracking</h2>
            <p>Driver mileage logs and maintenance progress.</p>
        </div>
        <div class="ph-right">
            <a href="{{ route('transport.issues') }}" class="btn btn-secondary">Report Issue</a>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Mileage Entry</span>
        </div>
        <div style="padding:14px">
            @if ($availableVehicles->isNotEmpty())
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Vehicle</label>
                        <select class="form-input" wire:model.live="vehicleId">
                            <option value="">Select vehicle</option>
                            @foreach ($availableVehicles as $vehicle)
                                <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                            @endforeach
                        </select>
                        @error('vehicleId') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Trip Date</label>
                        <input type="date" class="form-input" wire:model.defer="form.trip_date">
                        @error('form.trip_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if ($selectedVehicle)
                    @php
                        $used = max(0, $selectedVehicle->maintenance_interval_km - $selectedVehicle->maintenance_remaining_km);
                        $progress = $selectedVehicle->maintenance_interval_km > 0 ? min(100, round(($used / $selectedVehicle->maintenance_interval_km) * 100)) : 0;
                    @endphp
                    <div style="margin-bottom:12px">
                        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--color-text-secondary);margin-bottom:5px">
                            <span>{{ number_format($selectedVehicle->current_mileage) }} km current</span>
                            <span>{{ number_format($selectedVehicle->maintenance_remaining_km) }} km to service</span>
                        </div>
                        <div style="height:8px;border-radius:999px;background:var(--color-background-secondary);overflow:hidden">
                            <div style="height:8px;width:{{ $progress }}%;background:{{ $selectedVehicle->maintenance_remaining_km <= 500 ? '#a32d2d' : '#185fa5' }}"></div>
                        </div>
                    </div>

                    @if ($selectedVehicle->maintenance_remaining_km <= 500)
                        <div class="alert alert-warning" style="margin-bottom:12px">Maintenance warning: this vehicle is within 500 km of its next service point.</div>
                    @endif
                @endif

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Previous Mileage</label>
                        <input type="number" class="form-input" wire:model="form.mileage_before" readonly>
                    </div>
                    <div class="form-field">
                        <label class="form-label">New Mileage</label>
                        <input type="number" class="form-input" wire:model.defer="form.mileage_after">
                        @error('form.mileage_after') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Trip Purpose</label>
                        <input class="form-input" wire:model.defer="form.trip_purpose">
                        @error('form.trip_purpose') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save Mileage</button>
                    </div>
                </div>
            @else
                <div class="empty-state">No active vehicle is assigned to your account.</div>
            @endif
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">{{ $canManage ? 'Mileage Logs' : 'My Mileage Logs' }}</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Before</th>
                    <th>After</th>
                    <th>Distance</th>
                    <th>Purpose</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td>{{ $log->trip_date?->format('d M Y') }}</td>
                        <td>{{ $log->vehicle?->number_plate }}</td>
                        <td>{{ $log->driver?->full_name ?? $log->driver?->email }}</td>
                        <td>{{ number_format($log->mileage_before) }}</td>
                        <td>{{ number_format($log->mileage_after) }}</td>
                        <td>{{ number_format($log->distance_driven) }} km</td>
                        <td>{{ $log->trip_purpose }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-state">No mileage logs found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px 14px">{{ $logs->links() }}</div>
    </div>
</div>
