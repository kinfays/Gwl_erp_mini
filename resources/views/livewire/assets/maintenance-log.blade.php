<div>
    <x-ui.page-header title="Asset Maintenance" description="Track repair cycles, technicians, and completion status.">
        <x-slot:actions>
            <button type="button" wire:click="openCreate" class="btn btn-primary">
                <x-ui.icon name="plus" />
                Add Maintenance
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($dueCount > 0)
        <x-ui.alert tone="warning">
            {{ $dueCount }} {{ \Illuminate\Support\Str::plural('device', $dueCount) }} due for maintenance (open tickets).
            <x-slot:actions>
                <button type="button" wire:click="$set('dueOnly', true)" class="btn btn-secondary btn-sm">View due</button>
            </x-slot:actions>
        </x-ui.alert>
    @endif

    <x-ui.card title="Maintenance Log" :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter maintenance records">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live="search" class="form-input ui-input has-icon" placeholder="Search type, asset, technician" aria-label="Search maintenance records">
            </div>
            <select wire:model.live="status" class="form-input" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <select wire:model.live="type" class="form-input" aria-label="Maintenance type">
                <option value="">All types</option>
                @foreach ($typeOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <label class="toolbar-check">
                <input type="checkbox" wire:model.live="dueOnly">
                <span>Due only</span>
            </label>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Maintenance log" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Asset</th>
                        <th>Type</th>
                        <th>Technician</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Completed</th>
                        <th class="actions"><span class="sr-only-text">Action</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($maintenance as $row)
                    <tr wire:key="maintenance-{{ $row->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name">{{ $row->asset?->asset_name ?: 'Unknown asset' }}</span>
                                <span class="ui-person-sub mono">{{ $row->asset?->serial_number ?: 'No serial' }}</span>
                            </span>
                        </td>
                        <td>{{ $row->maintenance_type }}</td>
                        <td @class(['cell-muted' => ! $row->technician])>{{ $row->technician ?: '-' }}</td>
                        <td @class(['cell-muted' => ! $row->location])>{{ $row->location ?: '-' }}</td>
                        <td><x-ui.status-pill domain="maintenance" :status="$row->status" /></td>
                        <td @class(['nowrap', 'cell-muted' => ! $row->completion_date])>{{ $row->completion_date?->format('d M Y') ?: '-' }}</td>
                        <td class="actions">
                            @if ((! $regionLimited || (int) $row->asset?->region_id === (int) $ownRegionId))
                                <button type="button" wire:click="openEdit({{ $row->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit maintenance for {{ $row->asset?->asset_name ?: 'unknown asset' }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                            @else
                                <span class="ui-hint">Read only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="7" icon="wrench" title="No maintenance records found." description="Log a repair or upgrade with Add Maintenance." />
                @endforelse

                @if ($maintenance->hasPages())
                    <x-slot:footer>
                        <div class="pager-end">{{ $maintenance->links() }}</div>
                    </x-slot:footer>
                @endif
            </x-ui.table>

            <div wire:loading.delay class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$editingMaintenanceId ? 'Edit Maintenance' : 'New Maintenance'" close="closeForm()" size="lg" icon="wrench">
            <div class="ui-form-grid">
                <div class="span-2">
                    <x-ui.select label="Asset" wire:model.defer="form.ict_asset_id">
                        <option value="">Select asset</option>
                        @foreach ($assets as $asset)
                            <option value="{{ $asset->id }}">{{ $asset->asset_name }} ({{ $asset->serial_number ?: 'No serial' }})</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input label="Maintenance Type" wire:model.defer="form.maintenance_type" placeholder="Repair, Upgrade, Preventive" />
                <x-ui.select label="Status" wire:model.defer="form.status">
                    <option value="Open">Open</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Completed">Completed</option>
                    <option value="Cancelled">Cancelled</option>
                </x-ui.select>

                <x-ui.input label="Completion Date" type="date" wire:model.defer="form.completion_date" />
                <x-ui.input label="Technician" wire:model.defer="form.technician" />

                <x-ui.input label="Location" wire:model.defer="form.location" />

                <div class="span-2">
                    <x-ui.textarea label="Notes" wire:model.defer="form.notes" rows="3" />
                </div>
            </div>

            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingMaintenanceId ? 'Update' : 'Save' }}
                </button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
