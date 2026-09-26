<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Assets</h2>
            <p>Computers, laptops, printers, photocopiers, all-in-ones and servers.</p>
        </div>
        <div class="ph-right">
            @if (auth()->user()->hasRoles('super_admin') || auth()->user()->hasPermission('assets.create'))
                <button type="button" wire:click="openCreate" class="btn btn-primary">Add Asset</button>
            @endif
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Filters</span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="text" wire:model.live="search" class="form-input" placeholder="Search name, serial, assignee">
                <select wire:model.live="status" class="form-input">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
                <select wire:model.live="assetType" class="form-input">
                    <option value="">All types</option>
                    @foreach ($assetTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="districtId" class="form-input">
                    <option value="">All districts</option>
                    @foreach ($districts as $district)
                        <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="perPage" class="form-input">
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="50">50</option>
                </select>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Asset</th>
                    <th>Type / Model</th>
                    <th>Location</th>
                    <th>Assigned To</th>
                    <th>Status</th>
                    <th style="width: 90px;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($assets as $asset)
                    <tr>
                        <td>
                            <div>{{ $asset->asset_name }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $asset->serial_number ?: 'No serial' }}</div>
                        </td>
                        <td>
                            <div>{{ $assetTypes[$asset->asset_type] ?? $asset->asset_type }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">
                                {{ $asset->assetModel?->name ?: 'No model' }}
                            </div>
                        </td>
                        <td>
                            <div>{{ $asset->district?->district_name ?: 'No district' }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $asset->region?->region_name ?: 'No region' }}</div>
                        </td>
                        <td>{{ $asset->assignedTo?->full_name ?: 'Unassigned' }}</td>
                        <td>
                            <span class="pill {{ $asset->status === 'Active' ? 'p-g' : ($asset->status === 'In Repair' ? 'p-a' : 'p-d') }}">
                                {{ $asset->status }}
                            </span>
                        </td>
                        <td>
                            @if (auth()->user()->hasRoles('super_admin') || auth()->user()->hasPermission('assets.edit'))
                                <button type="button" wire:click="openEdit({{ $asset->id }})" class="actn">Edit</button>
                            @else
                                <span style="font-size:11px;color:var(--color-text-secondary)">Read only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center;color:var(--color-text-secondary);padding:20px">
                            No assets found for your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary);gap:12px;flex-wrap:wrap">
            <div style="font-size:11px;color:var(--color-text-secondary)">
                Showing {{ $assets->firstItem() ?? 0 }} - {{ $assets->lastItem() ?? 0 }} of {{ $assets->total() }} assets
            </div>
            <div>{{ $assets->links() }}</div>
        </div>
    </div>

    @if ($showForm)
        <div class="letter-panel-backdrop">
            <div class="visitor-signature-modal" style="max-width: 780px;">
                <div class="pg-head">
                    <span class="pg-title">{{ $editingAssetId ? 'Edit Asset' : 'Add Asset' }}</span>
                    <button type="button" wire:click="closeForm" class="actn">Close</button>
                </div>

                <div style="padding:14px">
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Asset Type</label>
                            <select wire:model.defer="form.asset_type" class="form-input">
                                <option value="">Select type</option>
                                @foreach ($assetTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('form.asset_type') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Asset Name</label>
                            <input type="text" wire:model.defer="form.asset_name" class="form-input">
                            @error('form.asset_name') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Serial Number</label>
                            <input type="text" wire:model.defer="form.serial_number" class="form-input">
                            @error('form.serial_number') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <x-assets.model-select :models="$models" />
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Assigned To</label>
                            <select wire:model.defer="form.assigned_to_employee_id" class="form-input">
                                <option value="">Select employee</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
                                @endforeach
                            </select>
                            @error('form.assigned_to_employee_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Previous Assigned</label>
                            <input type="text" class="form-input" value="{{ $previousAssignedLabel ?: 'None' }}" disabled>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Status</label>
                            <select wire:model.defer="form.status" class="form-input">
                                @foreach ($statusOptions as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('form.status') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Location</label>
                            <select wire:model.defer="form.district_id" class="form-input">
                                <option value="">Select location</option>
                                @foreach ($formDistricts as $district)
                                    <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                                @endforeach
                            </select>
                            @error('form.district_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Department</label>
                            <select wire:model.defer="form.department_id" class="form-input">
                                <option value="">Select department</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                                @endforeach
                            </select>
                            @error('form.department_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Date</label>
                            <input type="date" wire:model.defer="form.purchased_at" class="form-input">
                            @error('form.purchased_at') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <x-assets.actor-region :region="$actorRegion" />
                        <div class="form-field"></div>
                    </div>

                    <div style="margin-top:10px">
                        <label class="form-label">Notes</label>
                        <textarea rows="3" wire:model.defer="form.notes" class="form-input"></textarea>
                        @error('form.notes') <div class="txt-err">{{ $message }}</div> @enderror
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
                        <button type="button" wire:click="closeForm" class="btn">Cancel</button>
                        <button type="button" wire:click="save" class="btn btn-primary">
                            {{ $editingAssetId ? 'Update Asset' : 'Create Asset' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
