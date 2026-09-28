<div>
    <x-ui.page-header title="Vehicles" description="Fleet records, assignments, documents, and import.">
        @if ($canManage)
            <x-slot:actions>
                <button type="button" class="btn btn-secondary" wire:click="$toggle('showImport')" aria-pressed="{{ $showImport ? 'true' : 'false' }}">
                    <x-ui.icon name="upload" />
                    Import
                </button>
                <button type="button" class="btn btn-primary" wire:click="openCreate">
                    <x-ui.icon name="plus" />
                    Add Vehicle
                </button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($showImport && $canManage)
        <x-ui.card title="Vehicle Import" description="Columns: number_plate, type, brand, model, assigned_user_email, assigned_driver_email" class="dash-row">
            <div class="ui-stack">
                <div class="import-type-row">
                    <x-ui.field label="Excel or CSV file" for="f-vehicle-import" error="importFile" class="toolbar-grow">
                        <input id="f-vehicle-import" type="file" class="form-input" wire:model="importFile">
                    </x-ui.field>
                    <button type="button" class="btn btn-primary" wire:click="importVehicles" wire:loading.attr="disabled">
                        <x-ui.icon name="upload" />
                        Run Import
                    </button>
                </div>

                @if ($importSummary)
                    <div class="import-result" role="status">
                        <x-ui.status-pill tone="success" :label="$importSummary['created'].' created'" />
                        <x-ui.status-pill tone="info" :label="$importSummary['updated'].' updated'" />
                        <x-ui.status-pill :tone="$importSummary['failed'] > 0 ? 'danger' : 'muted'" :label="$importSummary['failed'].' failed'" />
                    </div>
                @endif

                @if ($importErrors)
                    <ul class="import-issue-list">
                        @foreach ($importErrors as $error)
                            <li>
                                <x-ui.icon name="circle-alert" />
                                <span><strong>Row {{ $error['row'] }}:</strong> {{ $error['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-ui.card>
    @endif

    <x-ui.card title="Fleet Register" :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter vehicles">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input class="form-input ui-input has-icon" placeholder="Search" aria-label="Search vehicles" wire:model.live.debounce.300ms="search">
            </div>
            <select class="form-input" wire:model.live="type" aria-label="Vehicle type">
                <option value="">All types</option>
                @foreach ($types as $option)
                    <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="status" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Fleet register" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Vehicle</th>
                        <th>Assigned</th>
                        <th class="num">Mileage</th>
                        <th>Documents</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($vehicles as $vehicle)
                    <tr wire:key="vehicle-{{ $vehicle->id }}" @class(['is-selected' => $selectedVehicle?->id === $vehicle->id])>
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name mono">{{ $vehicle->number_plate }}</span>
                                <span class="ui-person-sub">{{ $vehicle->brand }} {{ $vehicle->model }} · {{ str($vehicle->type)->replace('_', ' ')->title() }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $vehicle->assignedUser?->full_name ?? $vehicle->assignedUser?->email ?? ($vehicle->is_pool_car ? 'Pool car' : 'Unassigned') }}</span>
                                <span class="ui-person-sub">{{ $vehicle->assignedDriver?->full_name ?? $vehicle->assignedDriver?->email ?? 'Self drive' }}</span>
                            </span>
                        </td>
                        <td class="num nowrap">{{ number_format($vehicle->current_mileage) }} km</td>
                        <td>
                            <span class="ui-cell-stack doc-lines">
                                <span>Insurance: {{ $vehicle->insurance_expiry_date?->format('d M Y') ?? 'Not set' }}</span>
                                <span>Road: {{ $vehicle->road_worthiness_expiry_date?->format('d M Y') ?? 'Not set' }}</span>
                            </span>
                        </td>
                        <td><x-ui.status-pill domain="vehicle" :status="$vehicle->status" :label="str($vehicle->status)->replace('_', ' ')->title()" /></td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" class="btn btn-sm btn-ghost" wire:click="viewVehicle({{ $vehicle->id }})">
                                    <x-ui.icon name="eye" class="icon-sm" />
                                    Details
                                </button>
                                @if ($canManage)
                                    <button type="button" class="btn btn-ghost btn-sm btn-icon" wire:click="openEdit({{ $vehicle->id }})" title="Edit" aria-label="Edit {{ $vehicle->number_plate }}">
                                        <x-ui.icon name="pencil" />
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="6" icon="car" title="No vehicles found." description="Try a different search or clear the filters." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">
                        Showing {{ $vehicles->firstItem() ?? 0 }} - {{ $vehicles->lastItem() ?? 0 }} of {{ $vehicles->total() }} vehicles
                    </p>
                    <div>{{ $vehicles->links() }}</div>
                </x-slot:footer>
            </x-ui.table>

            <div wire:loading.delay wire:target="search,type,status,gotoPage,nextPage,previousPage,setPage" class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>

    @if ($showForm && $canManage)
        <x-ui.modal :title="$editingVehicleId ? 'Edit Vehicle' : 'Add Vehicle'" close="closeForm()" size="lg" icon="car">
            <div class="ui-form-grid">
                <x-ui.select label="Type" wire:model="form.type">
                    @foreach ($types as $option)
                        <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Number Plate" wire:model.defer="form.number_plate" class="mono" />

                <x-ui.input label="Brand" wire:model.defer="form.brand" />
                <x-ui.input label="Model" wire:model.defer="form.model" />

                <x-ui.input label="Color" wire:model.defer="form.color" />
                <x-ui.input label="Year Purchased" type="number" wire:model.defer="form.year_purchased" inputmode="numeric" />

                <div class="field-check">
                    <x-ui.checkbox label="Pool car" description="Shared vehicle with no assigned employee" wire:model.live="form.is_pool_car" id="f-form-is-pool-car" />
                </div>
                <x-ui.select label="Department" wire:model="form.department_id">
                    <option value="">Unassigned</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                    @endforeach
                </x-ui.select>

                @unless ($form['is_pool_car'])
                    <x-ui.select label="Assigned Employee" wire:model="form.assigned_user_id">
                        <option value="">Select employee</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->full_name ?? $user->email }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Driver Type" wire:model.live="form.driver_type">
                        @foreach ($driverTypes as $option)
                            <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </x-ui.select>
                @endunless

                @if ($form['driver_type'] === 'assigned_driver')
                    <x-ui.select label="Assigned Driver" wire:model="form.assigned_driver_id">
                        <option value="">Select driver</option>
                        @foreach ($drivers as $driver)
                            <option value="{{ $driver->id }}">{{ $driver->full_name ?? $driver->email }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.field label="Photo" for="f-vehicle-photo" error="photo">
                        <input id="f-vehicle-photo" type="file" class="form-input" wire:model="photo">
                    </x-ui.field>
                @endif

                <x-ui.input label="Current Mileage" type="number" wire:model.defer="form.current_mileage" inputmode="numeric" />
                <x-ui.input label="Maintenance Interval KM" type="number" wire:model.defer="form.maintenance_interval_km" inputmode="numeric" />

                <x-ui.input label="Insurance Expiry" type="date" wire:model.defer="form.insurance_expiry_date" />
                <x-ui.input label="Road Worthiness Expiry" type="date" wire:model.defer="form.road_worthiness_expiry_date" />

                <x-ui.select label="Status" wire:model="form.status">
                    @foreach ($statuses as $option)
                        <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-slot:footer>
                <button type="button" class="btn btn-secondary" wire:click="closeForm">Cancel</button>
                <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save Vehicle</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($selectedVehicle)
        <x-ui.drawer
            :title="$selectedVehicle->number_plate.' Details'"
            :description="trim($selectedVehicle->brand.' '.$selectedVehicle->model)"
            show="true"
            close="$wire.$set('selectedVehicleId', null)"
            width="42rem"
            wire:key="vehicle-details-{{ $selectedVehicle->id }}"
        >
            <div class="ui-stack">
                <dl class="ui-dl">
                    <div>
                        <dt>Age</dt>
                        <dd>{{ $selectedVehicle->age ?? 'N/A' }} <span class="ui-person-sub">years</span></dd>
                    </div>
                    <div>
                        <dt>Next Maintenance</dt>
                        <dd>{{ number_format($selectedVehicle->next_maintenance_mileage) }} km</dd>
                    </div>
                    <div>
                        <dt>Open Issues</dt>
                        <dd>{{ $selectedVehicle->issues->where('status', '!=', 'resolved')->count() }}</dd>
                    </div>
                    <div>
                        <dt>Department</dt>
                        <dd>{{ $selectedVehicle->department?->department_name ?? 'Unassigned' }}</dd>
                    </div>
                </dl>

                @if ($selectedVehicle->maintenance_remaining_km <= 500)
                    <x-ui.alert tone="warning">Maintenance is due within {{ number_format($selectedVehicle->maintenance_remaining_km) }} km.</x-ui.alert>
                @endif

                <section aria-labelledby="vehicle-history-title">
                    <h3 id="vehicle-history-title" class="ui-panel-title">Assignment history</h3>
                    <x-ui.table label="Assignment history" :sticky="false" dense>
                        <x-slot:head>
                            <tr>
                                <th>Assigned To</th>
                                <th>Driver</th>
                                <th>Assigned At</th>
                                <th>Unassigned At</th>
                                <th>Notes</th>
                            </tr>
                        </x-slot:head>

                        @forelse ($selectedVehicle->assignmentHistories->sortByDesc('assigned_at') as $history)
                            <tr>
                                <td class="nowrap">{{ $history->user?->full_name ?? $history->user?->email ?? 'Unassigned' }}</td>
                                <td class="nowrap">{{ $history->driver?->full_name ?? $history->driver?->email ?? 'Self drive' }}</td>
                                <td class="nowrap">{{ $history->assigned_at?->format('d M Y H:i') }}</td>
                                <td class="nowrap">
                                    @if ($history->unassigned_at)
                                        {{ $history->unassigned_at->format('d M Y H:i') }}
                                    @else
                                        <x-ui.status-pill tone="success" label="Active" />
                                    @endif
                                </td>
                                <td>{{ $history->notes }}</td>
                            </tr>
                        @empty
                            <x-ui.empty-row :colspan="5" icon="history" title="No assignment history yet." />
                        @endforelse
                    </x-ui.table>
                </section>
            </div>
        </x-ui.drawer>
    @endif
</div>
