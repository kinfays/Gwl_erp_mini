<div>
    @php
        $viewer = auth()->user();
        $canCreate = $viewer->hasRoles('super_admin') || $viewer->hasPermission('assets.create');
        $canEdit = $viewer->hasRoles('super_admin') || $viewer->hasPermission('assets.edit');
    @endphp

    <x-ui.page-header title="Assets" description="Computers, laptops, printers, photocopiers, all-in-ones and servers.">
        @if ($canCreate)
            <x-slot:actions>
                <button type="button" wire:click="openCreate" class="btn btn-primary">
                    <x-ui.icon name="plus" />
                    Add Asset
                </button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter assets">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live="search" class="form-input ui-input has-icon" placeholder="Search name, serial, assignee" aria-label="Search assets">
            </div>
            <select wire:model.live="status" class="form-input" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <select wire:model.live="assetType" class="form-input" aria-label="Asset type">
                <option value="">All types</option>
                @foreach ($assetTypes as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="districtId" class="form-input" aria-label="District">
                <option value="">All districts</option>
                @foreach ($districts as $district)
                    <option value="{{ $district->id }}">{{ $district->district_name.($seesAllRegions && $district->region ? ' ('.$district->region->region_name.')' : '') }}</option>
                @endforeach
            </select>
            <select wire:model.live="perPage" class="form-input" aria-label="Rows per page">
                <option value="15">15 per page</option>
                <option value="30">30 per page</option>
                <option value="50">50 per page</option>
            </select>
        </div>

        <x-assets.filter-chips :chips="$analyticsChips" />

        <div class="ui-loading-host">
            <x-ui.table label="Assets" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Asset</th>
                        <th>Type / Model</th>
                        <th>Location</th>
                        <th>Assigned To</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Action</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($assets as $asset)
                    <tr wire:key="asset-{{ $asset->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <a href="{{ route('assets.show', $asset) }}" class="ui-person-name">{{ $asset->asset_name }}</a>
                                <span class="ui-person-sub mono">{{ $asset->serial_number ?: 'No serial' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $assetTypes[$asset->asset_type] ?? $asset->asset_type }}</span>
                                <span class="ui-person-sub">{{ $asset->assetModel?->name ?: 'No model' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $asset->district?->district_name ?: 'No district' }}</span>
                                <span class="ui-person-sub">{{ $asset->region?->region_name ?: 'No region' }}</span>
                            </span>
                        </td>
                        <td @class(['nowrap', 'cell-muted' => ! $asset->assignedTo])>{{ $asset->assignedTo?->full_name ?: 'Unassigned' }}</td>
                        <td><x-ui.status-pill domain="asset" :status="$asset->status" /></td>
                        <td class="actions">
                            @if ($canEdit && (! $regionLimited || (int) $asset->region_id === (int) $ownRegionId))
                                <button type="button" wire:click="openEdit({{ $asset->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $asset->asset_name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                            @else
                                <span class="ui-hint">Read only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="6" icon="laptop" title="No assets found for your filters." description="Try a different search or clear the filters." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">
                        Showing {{ $assets->firstItem() ?? 0 }} - {{ $assets->lastItem() ?? 0 }} of {{ $assets->total() }} assets
                    </p>
                    <div>{{ $assets->links() }}</div>
                </x-slot:footer>
            </x-ui.table>

            <div wire:loading.delay class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$editingAssetId ? 'Edit Asset' : 'Add Asset'" close="closeForm()" size="lg" icon="laptop">
            <div class="ui-form-grid">
                <x-ui.select label="Asset Type" wire:model.defer="form.asset_type">
                    <option value="">Select type</option>
                    @foreach ($assetTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Asset Name" wire:model.defer="form.asset_name" />

                <x-ui.input label="Serial Number" wire:model.defer="form.serial_number" class="mono" />
                <x-assets.model-select :models="$models" />

                <x-ui.select label="Assigned To" wire:model.defer="form.assigned_to_employee_id">
                    <option value="">Select employee</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Previous Assigned" id="f-previous-assigned" :value="$previousAssignedLabel ?: 'None'" disabled :error="false" />

                <x-ui.select label="Status" wire:model.defer="form.status">
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Location" wire:model.defer="form.district_id">
                    <option value="">Select location</option>
                    @foreach ($formDistricts as $district)
                        <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select label="Department" wire:model.defer="form.department_id">
                    <option value="">Select department</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Date" type="date" wire:model.defer="form.purchased_at" />

                <x-assets.actor-region :region="$actorRegion" />
                <x-ui.select label="Condition" wire:model.defer="form.condition">
                    <option value="">Not recorded</option>
                    @foreach ($conditionOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>

                <div class="span-2">
                    <x-ui.textarea label="Notes" wire:model.defer="form.notes" rows="3" />
                </div>
                <div class="span-2">
                    <x-ui.textarea label="Status reason" wire:model.defer="form.status_reason" rows="2" hint="Required when the status is Damaged (e.g. faulty power button)." />
                </div>

                <x-assets.history :transfers="$history" :editing="(bool) $editingAssetId" />
            </div>

            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingAssetId ? 'Update Asset' : 'Create Asset' }}
                </button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
