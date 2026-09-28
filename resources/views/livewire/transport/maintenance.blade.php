<div>
    <x-ui.page-header title="Maintenance" description="Service records, maintenance thresholds, and document renewal." />

    <x-ui.card title="Upcoming Maintenance" description="Vehicles in service, closest to their next service point first" :padded="false" class="dash-row">
        <x-ui.table label="Upcoming maintenance" :sticky="false">
            <x-slot:head>
                <tr>
                    <th>Vehicle</th>
                    <th class="num">Current Mileage</th>
                    <th>Remaining</th>
                    <th class="num">Next Mileage</th>
                </tr>
            </x-slot:head>

            @forelse ($upcoming as $vehicle)
                <tr>
                    <td>
                        <span class="ui-cell-stack">
                            <span class="ui-person-name mono">{{ $vehicle->number_plate }}</span>
                            <span class="ui-person-sub">{{ $vehicle->brand }} {{ $vehicle->model }}</span>
                        </span>
                    </td>
                    <td class="num nowrap">{{ number_format($vehicle->current_mileage) }} km</td>
                    <td><x-ui.status-pill :tone="$vehicle->maintenance_remaining_km <= 500 ? 'danger' : 'success'" :label="number_format($vehicle->maintenance_remaining_km).' km'" /></td>
                    <td class="num nowrap">{{ number_format($vehicle->next_maintenance_mileage) }} km</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="4" icon="car" title="No vehicles found." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card title="Add Maintenance Record">
            <div class="ui-stack">
                <div class="ui-form-grid">
                    <x-ui.select label="Vehicle" wire:model="form.vehicle_id">
                        <option value="">Select vehicle</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Maintenance Type" wire:model.defer="form.maintenance_type" />

                    <x-ui.input label="Performed By" wire:model.defer="form.performed_by" />
                    <x-ui.input label="Service Date" type="date" wire:model.defer="form.service_date" />

                    <x-ui.input label="Mileage At Service" type="number" wire:model.defer="form.mileage_at_service" inputmode="numeric" />
                    <x-ui.input label="Cost" type="number" step="0.01" wire:model.defer="form.cost" inputmode="decimal" />

                    <x-ui.input label="Next Service Date" type="date" wire:model.defer="form.next_service_date" />
                    <x-ui.input label="Next Service Mileage" type="number" wire:model.defer="form.next_service_mileage" inputmode="numeric" />

                    <x-ui.textarea label="Description" wire:model.defer="form.description" rows="3" />
                    <x-ui.field label="Receipt Photo" for="f-maintenance-receipt" error="receipt">
                        <input id="f-maintenance-receipt" type="file" class="form-input" wire:model="receipt">
                    </x-ui.field>
                </div>

                <div class="ui-form-actions">
                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                        <x-ui.icon name="check" />
                        Save Maintenance
                    </button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Renew Documents" description="Record an insurance or road worthiness renewal">
            <div class="ui-stack">
                <x-ui.select label="Vehicle" wire:model="renewal.vehicle_id">
                    <option value="">Select vehicle</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Document" wire:model="renewal.type">
                    <option value="insurance">Insurance</option>
                    <option value="road_worthiness">Road Worthiness</option>
                </x-ui.select>
                <x-ui.input label="New Expiry Date" type="date" wire:model.defer="renewal.expiry_date" />
                <x-ui.input label="Amount" type="number" step="0.01" wire:model.defer="renewal.amount" inputmode="decimal" />

                <div class="ui-form-actions">
                    <button type="button" class="btn btn-secondary" wire:click="renewDocument" wire:loading.attr="disabled">
                        <x-ui.icon name="refresh-cw" />
                        Record Renewal
                    </button>
                </div>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card title="Maintenance History" :padded="false">
        <x-slot:actions>
            <select class="form-input" wire:model.live="vehicleFilter" aria-label="Filter by vehicle">
                <option value="">All vehicles</option>
                @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }}</option>
                @endforeach
            </select>
        </x-slot:actions>

        <x-ui.table label="Maintenance history" pin-first>
            <x-slot:head>
                <tr>
                    <th>Date</th>
                    <th>Vehicle</th>
                    <th>Type</th>
                    <th>Provider</th>
                    <th class="num">Mileage</th>
                    <th class="num">Cost</th>
                    <th>Next</th>
                </tr>
            </x-slot:head>

            @forelse ($records as $record)
                <tr wire:key="maintenance-record-{{ $record->id }}">
                    <td class="nowrap">{{ $record->service_date?->format('d M Y') }}</td>
                    <td class="mono nowrap">{{ $record->vehicle?->number_plate }}</td>
                    <td>{{ str($record->maintenance_type)->replace('_', ' ')->title() }}</td>
                    <td>{{ $record->performed_by }}</td>
                    <td @class(['num', 'nowrap', 'cell-muted' => ! $record->mileage_at_service])>{{ $record->mileage_at_service ? number_format($record->mileage_at_service).' km' : 'N/A' }}</td>
                    <td class="num">{{ number_format($record->cost, 2) }}</td>
                    <td class="nowrap">{{ $record->next_service_date?->format('d M Y') ?? ($record->next_service_mileage ? number_format($record->next_service_mileage).' km' : 'N/A') }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="wrench" title="No maintenance records found." />
            @endforelse

            @if ($records->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $records->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
