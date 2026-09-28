<div>
    <x-ui.page-header title="Mileage Tracking" description="Driver mileage logs and maintenance progress.">
        <x-slot:actions>
            <a href="{{ route('transport.issues') }}" class="btn btn-secondary">
                <x-ui.icon name="triangle-alert" />
                Report Issue
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Mileage Entry" class="dash-row">
        @if ($availableVehicles->isNotEmpty())
            <div class="ui-stack">
                <div class="ui-form-grid">
                    <x-ui.select label="Vehicle" wire:model.live="vehicleId" id="f-mileage-vehicle" error="vehicleId">
                        <option value="">Select vehicle</option>
                        @foreach ($availableVehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Trip Date" type="date" wire:model.defer="form.trip_date" />
                </div>

                @if ($selectedVehicle)
                    @php
                        $used = max(0, $selectedVehicle->maintenance_interval_km - $selectedVehicle->maintenance_remaining_km);
                        $progress = $selectedVehicle->maintenance_interval_km > 0 ? min(100, round(($used / $selectedVehicle->maintenance_interval_km) * 100)) : 0;
                        $dueSoon = $selectedVehicle->maintenance_remaining_km <= 500;
                    @endphp
                    <div @class(['ui-meter', 'service-meter', 'is-due' => $dueSoon])>
                        <div class="ui-meter-head">
                            <span class="ui-meter-label">Service interval used</span>
                            <span class="ui-meter-value">
                                <b>{{ number_format($selectedVehicle->current_mileage) }} km</b>
                                <small>current · {{ number_format($selectedVehicle->maintenance_remaining_km) }} km to service</small>
                            </span>
                        </div>
                        <div class="ui-meter-track" role="progressbar" aria-label="Service interval used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}" aria-valuetext="{{ $progress }}% used, {{ number_format($selectedVehicle->maintenance_remaining_km) }} km to service">
                            <span style="width: {{ $progress }}%"></span>
                        </div>
                    </div>

                    @if ($dueSoon)
                        <x-ui.alert tone="warning">Maintenance warning: this vehicle is within 500 km of its next service point.</x-ui.alert>
                    @endif
                @endif

                <div class="ui-form-grid">
                    <x-ui.input label="Previous Mileage" type="number" wire:model="form.mileage_before" readonly :error="false" />
                    <x-ui.input label="New Mileage" type="number" wire:model.defer="form.mileage_after" inputmode="numeric" />

                    <div class="span-2">
                        <x-ui.input label="Trip Purpose" wire:model.defer="form.trip_purpose" />
                    </div>
                </div>

                <div class="ui-form-actions">
                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                        <x-ui.icon name="check" />
                        Save Mileage
                    </button>
                </div>
            </div>
        @else
            <x-ui.empty-state icon="car" title="No active vehicle is assigned to your account." />
        @endif
    </x-ui.card>

    <x-ui.card :title="$canManage ? 'Mileage Logs' : 'My Mileage Logs'" :padded="false">
        <x-ui.table label="Mileage logs" pin-first>
            <x-slot:head>
                <tr>
                    <th>Date</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th class="num">Before</th>
                    <th class="num">After</th>
                    <th class="num">Distance</th>
                    <th>Purpose</th>
                </tr>
            </x-slot:head>

            @forelse ($logs as $log)
                <tr wire:key="mileage-log-{{ $log->id }}">
                    <td class="nowrap">{{ $log->trip_date?->format('d M Y') }}</td>
                    <td class="mono nowrap">{{ $log->vehicle?->number_plate }}</td>
                    <td class="nowrap">{{ $log->driver?->full_name ?? $log->driver?->email }}</td>
                    <td class="num">{{ number_format($log->mileage_before) }}</td>
                    <td class="num">{{ number_format($log->mileage_after) }}</td>
                    <td class="num nowrap"><strong>{{ number_format($log->distance_driven) }} km</strong></td>
                    <td class="cell-wrap">{{ $log->trip_purpose }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="gauge" title="No mileage logs found." />
            @endforelse

            @if ($logs->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $logs->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
