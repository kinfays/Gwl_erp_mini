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
            <x-ui.multi-select label="Asset type" all-label="All types" :options="$assetTypes" :selected="$selectedTypes" toggle="toggleAssetType" clear="clearAssetTypes" />
            <select wire:model.live="districtId" class="form-input" aria-label="District">
                <option value="">All districts</option>
                @foreach ($districts as $district)
                    <option value="{{ $district->id }}">{{ $district->district_name.($seesAllRegions && $district->region ? ' ('.$district->region->region_name.')' : '') }}</option>
                @endforeach
            </select>
            <select wire:model.live="ups" class="form-input" aria-label="UPS">
                <option value="">All UPS states</option>
                @foreach ($upsFilters as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="perPage" class="form-input" aria-label="Rows per page">
                <option value="15">15 per page</option>
                <option value="30">30 per page</option>
                <option value="50">50 per page</option>
            </select>
            @if ($canExport)
                <a href="{{ route('assets.assets.export.excel', $exportParams) }}" class="btn btn-secondary btn-icon" title="Export Excel" aria-label="Export Excel">
                    <x-ui.file-icon type="excel" />
                </a>
                <a href="{{ route('assets.assets.export.pdf', $exportParams) }}" class="btn btn-secondary btn-icon" title="Export PDF" aria-label="Export PDF">
                    <x-ui.file-icon type="pdf" />
                </a>
            @endif
            <p class="pager-total" role="status">
                <b>{{ number_format($assets->total()) }}</b> {{ $assets->total() === 1 ? 'asset' : 'assets' }}
                {{ $filtersActive ? 'match your filters' : 'in total' }}
            </p>
        </div>

        <x-assets.filter-chips :chips="$analyticsChips" />

        <div class="ui-loading-host">
            <x-ui.table label="Assets" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Asset</th>
                        @if (in_array('type_model', $visibleColumns))
                            <th>Type / Model</th>
                        @endif
                        @if (in_array('processor', $visibleColumns))
                            <th>Processor</th>
                        @endif
                        @if (in_array('location', $visibleColumns))
                            <th>Location</th>
                        @endif
                        @if (in_array('assigned_to', $visibleColumns))
                            <th>Assigned To</th>
                        @endif
                        @if (in_array('status', $visibleColumns))
                            <th>Status</th>
                        @endif
                        @if (in_array('ups', $visibleColumns))
                            <th>UPS</th>
                        @endif
                        <th class="actions">
                            <div class="ui-th-actions">
                                <span>Action</span>
                                <x-ui.column-picker :columns="$columnManifest" :visible="$visibleColumns" :mode="$columnMode" />
                            </div>
                        </th>
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
                        @if (in_array('type_model', $visibleColumns))
                            <td>
                            <span class="ui-cell-stack">
                                <span>{{ $assetTypes[$asset->asset_type] ?? $asset->asset_type }}</span>
                                <span class="ui-person-sub">{{ $asset->assetModel?->name ?: 'No model' }}</span>
                            </span>
                        </td>
                        @endif
                        @if (in_array('processor', $visibleColumns))
                            <td>
                            @if (App\Models\IctAsset::supportsProcessor($asset->device_category, $asset->asset_type))
                                <span class="mono">{{ $asset->cpu_name ?: '—' }}</span>
                            @endif
                        </td>
                        @endif
                        @if (in_array('location', $visibleColumns))
                            <td>
                            <span class="ui-cell-stack">
                                <span>{{ $asset->district?->district_name ?: 'No district' }}</span>
                                <span class="ui-person-sub">{{ $asset->actual_location ?: ($asset->region?->region_name ?: 'No region') }}</span>
                            </span>
                        </td>
                        @endif
                        @if (in_array('assigned_to', $visibleColumns))
                            <td @class(['nowrap', 'cell-muted' => ! $asset->assignedTo && ! $asset->is_general_use])><x-assets.assignee :asset="$asset" /></td>
                        @endif
                        @if (in_array('status', $visibleColumns))
                            <td><x-ui.status-pill domain="asset" :status="$asset->status" /></td>
                        @endif
                        @if (in_array('ups', $visibleColumns))
                            <td>
                            @if (App\Models\IctAsset::supportsUps($asset->device_category, $asset->asset_type))
                                @if ($asset->has_ups)
                                    <x-ui.status-pill :tone="$asset->ups_status === 'Spoiled' ? 'danger' : 'success'" :label="'UPS: '.($asset->ups_status ?: 'Unknown')" />
                                @else
                                    <span class="cell-muted">No UPS</span>
                                @endif
                            @endif
                        </td>
                        @endif
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
                    <x-ui.empty-row :colspan="count($visibleColumns)" icon="laptop" title="No assets found for your filters." description="Try a different search or clear the filters." />
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
                <x-loading.drop-fill class="wl-veil-drop" label="Loading" />
            </div>
        </div>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$editingAssetId ? 'Edit Asset' : 'Add Asset'" close="closeForm()" size="lg" icon="laptop">
            <div class="ui-form-grid">
                <x-ui.select label="Asset Type" wire:model.live="form.asset_type">
                    <option value="">Select type</option>
                    @foreach ($assetTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Asset Name" wire:model.defer="form.asset_name" />

                <x-ui.input label="Serial Number" wire:model.defer="form.serial_number" class="mono" />
                <div>
                    <x-assets.model-select :models="$models" />
                    <x-assets.add-model-button :can="$canAddModel" :open="$showNewModel" />
                </div>
                <x-assets.add-model-panel :can="$canAddModel" :open="$showNewModel" :asset-type="$form['asset_type']" :types="$assetTypes" :manufacturers="$newModelManufacturers" />

                <div class="field-check">
                    <x-ui.checkbox label="General use (not assigned to a person)" description="Shared by several people, for example a printer or a shared PC" wire:model.live="form.is_general_use" id="f-form-is-general-use" />
                </div>
                @unless ($form['is_general_use'])
                    <x-form.searchable-select
                        label="Assigned To"
                        model="form.assigned_to_employee_id"
                        search="searchEmployees"
                        :initial-label="$assignedToLabel"
                        placeholder="Search by name or staff ID"
                        required
                        :live="(bool) $editingAssetId"
                    />
                @endunless
                <x-ui.input label="Previous Assigned" id="f-previous-assigned" :value="$previousAssignedLabel ?: 'None'" disabled :error="false" />
                <x-assets.assignment-change :show="$askAssignmentChange" />

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
                <x-ui.input label="Actual Location" wire:model.defer="form.actual_location" placeholder="e.g. Server Room, 2nd Floor" />

                <x-ui.select label="Department" wire:model.defer="form.department_id">
                    <option value="">Select department</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Asset Tag" wire:model.defer="form.tag_no" class="mono" placeholder="GWCL/FOE/YEAR/NUMBER" maxlength="100" />

                <x-ui.input label="Date" type="date" wire:model.defer="form.purchased_at" />

                <x-assets.actor-region :region="$actorRegion" />

                @if ($formSupportsProcessor)
                    <div class="span-2">
                        <x-ui.input label="Processor" wire:model.defer="form.cpu_name" placeholder="e.g. Intel core i7-1255U CPU@2.40GHz, Ultra 7-115U" />
                    </div>
                @endif

                @if ($formSupportsUps)
                    <div class="span-2">
                        <x-ui.checkbox label="On a UPS" description="Tick if this device is connected to a UPS." wire:model.live="form.has_ups" id="f-has-ups" />
                    </div>
                    @if ($form['has_ups'])
                        <x-ui.select label="UPS condition" wire:model.defer="form.ups_status" required>
                            <option value="">Select condition</option>
                            @foreach ($upsStatuses as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.input label="UPS notes" wire:model.defer="form.ups_notes" placeholder="Optional" />
                    @endif
                @endif

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
