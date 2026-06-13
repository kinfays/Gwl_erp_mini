<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Asset Maintenance</h2>
            <p>Track repair cycles, technicians, and completion status.</p>
        </div>
        <div class="ph-right">
            <button type="button" wire:click="openCreate" class="btn btn-primary">Add Maintenance</button>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Maintenance Log</span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="text" wire:model.live="search" class="form-input" placeholder="Search type, asset, technician">
                <select wire:model.live="status" class="form-input">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
                <select wire:model.live="type" class="form-input">
                    <option value="">All types</option>
                    @foreach ($typeOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Asset</th>
                    <th>Type</th>
                    <th>Technician</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Completed</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($maintenance as $row)
                    <tr>
                        <td>
                            <div>{{ $row->asset?->asset_name ?: 'Unknown asset' }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $row->asset?->serial_number ?: 'No serial' }}</div>
                        </td>
                        <td>{{ $row->maintenance_type }}</td>
                        <td>{{ $row->technician ?: '-' }}</td>
                        <td>{{ $row->location ?: '-' }}</td>
                        <td>
                            <span class="pill {{ $row->status === 'Completed' ? 'p-g' : ($row->status === 'In Progress' ? 'p-w' : 'p-d') }}">
                                {{ $row->status }}
                            </span>
                        </td>
                        <td>{{ $row->completion_date?->format('d M Y') ?: '-' }}</td>
                        <td><button type="button" wire:click="openEdit({{ $row->id }})" class="actn">Edit</button></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center;color:var(--color-text-secondary);padding:20px">
                            No maintenance records found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="display:flex;justify-content:flex-end;padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary)">
            {{ $maintenance->links() }}
        </div>
    </div>

    @if ($showForm)
        <div class="letter-panel-backdrop">
            <div class="visitor-signature-modal" style="max-width: 860px;">
                <div class="pg-head">
                    <span class="pg-title">{{ $editingMaintenanceId ? 'Edit Maintenance' : 'New Maintenance' }}</span>
                    <button type="button" wire:click="closeForm" class="actn">Close</button>
                </div>

                <div style="padding:14px">
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
                        <div>
                            <label class="form-label">Asset</label>
                            <select wire:model.defer="form.ict_asset_id" class="form-input">
                                <option value="">Select asset</option>
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}">{{ $asset->asset_name }} ({{ $asset->serial_number ?: 'No serial' }})</option>
                                @endforeach
                            </select>
                            @error('form.ict_asset_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Maintenance Type</label>
                            <input type="text" wire:model.defer="form.maintenance_type" class="form-input" placeholder="Repair, Upgrade, Preventive">
                            @error('form.maintenance_type') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Status</label>
                            <select wire:model.defer="form.status" class="form-input">
                                <option value="Open">Open</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                            @error('form.status') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Completion Date</label>
                            <input type="date" wire:model.defer="form.completion_date" class="form-input">
                            @error('form.completion_date') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Technician</label>
                            <input type="text" wire:model.defer="form.technician" class="form-input">
                            @error('form.technician') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Location</label>
                            <input type="text" wire:model.defer="form.location" class="form-input">
                            @error('form.location') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div style="margin-top:10px">
                        <label class="form-label">Notes</label>
                        <textarea rows="3" wire:model.defer="form.notes" class="form-input"></textarea>
                        @error('form.notes') <div class="txt-err">{{ $message }}</div> @enderror
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
                        <button type="button" wire:click="closeForm" class="btn">Cancel</button>
                        <button type="button" wire:click="save" class="btn btn-primary">
                            {{ $editingMaintenanceId ? 'Update' : 'Save' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

