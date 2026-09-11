<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Phones</h2>
            <p>POS terminals, SIM cards and mobile phones.</p>
        </div>
        <div class="ph-right">
            @if (auth()->user()->hasRoles('super_admin') || auth()->user()->hasPermission('assets.create'))
                <button type="button" wire:click="openCreate" class="btn btn-primary">Add Phone Device</button>
            @endif
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Filters</span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="text" wire:model.live="search" class="form-input" placeholder="Search name, serial, IMEI, number">
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
                    <th>Device</th>
                    <th>Type / Model</th>
                    <th>Numbers</th>
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
                            <div style="font-size:10px;color:var(--color-text-secondary)">
                                {{ $asset->serial_number ?: 'No serial' }}
                                @if ($asset->imei)
                                    | IMEI {{ $asset->imei }}
                                @endif
                            </div>
                        </td>
                        <td>
                            <div>{{ $assetTypes[$asset->asset_type] ?? $asset->asset_type }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $asset->assetModel?->name ?: 'No model' }}</div>
                        </td>
                        <td>
                            <div>{{ $asset->device_phone_number ?: 'No device number' }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">User: {{ $asset->user_phone_number ?: 'n/a' }}</div>
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
                            No phone devices found for your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary);gap:12px;flex-wrap:wrap">
            <div style="font-size:11px;color:var(--color-text-secondary)">
                Showing {{ $assets->firstItem() ?? 0 }} - {{ $assets->lastItem() ?? 0 }} of {{ $assets->total() }} devices
            </div>
            <div>{{ $assets->links() }}</div>
        </div>
    </div>

    @if ($showForm)
        <div class="letter-panel-backdrop">
            <div class="visitor-signature-modal" style="max-width: 780px;">
                <div class="pg-head">
                    <span class="pg-title">{{ $editingAssetId ? 'Edit Phone Device' : 'Add Phone Device' }}</span>
                    <button type="button" wire:click="closeForm" class="actn">Close</button>
                </div>

                <div style="padding:14px">
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Asset Name</label>
                            <input type="text" wire:model.defer="form.asset_name" class="form-input">
                            @error('form.asset_name') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Type</label>
                            <select wire:model.defer="form.asset_type" class="form-input">
                                <option value="">Select type</option>
                                @foreach ($assetTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('form.asset_type') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Serial Number</label>
                            <input type="text" wire:model.defer="form.serial_number" class="form-input">
                            @error('form.serial_number') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Model</label>
                            <select wire:model.defer="form.ict_asset_model_id" class="form-input">
                                <option value="">Select model</option>
                                @foreach ($models as $model)
                                    <option value="{{ $model->id }}">{{ $model->name }} ({{ $model->manufacturer ?: 'n/a' }})</option>
                                @endforeach
                            </select>
                            @error('form.ict_asset_model_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">IMEI</label>
                            <input type="text" wire:model.defer="form.imei" class="form-input">
                            @error('form.imei') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Assigned To</label>
                            <select wire:model.defer="form.assigned_to_employee_id" class="form-input">
                                <option value="">Unassigned</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
                                @endforeach
                            </select>
                            @error('form.assigned_to_employee_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Previous Assigned</label>
                            <input type="text" class="form-input" value="{{ $previousAssignedLabel ?: 'None' }}" disabled>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Status</label>
                            <select wire:model.defer="form.status" class="form-input">
                                @foreach ($statusOptions as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('form.status') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Location (District)</label>
                            <select wire:model.defer="form.district_id" class="form-input">
                                <option value="">Select location</option>
                                @foreach ($districts as $district)
                                    <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                                @endforeach
                            </select>
                            @error('form.district_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">User Phone Number</label>
                            <input type="text" wire:model.defer="form.user_phone_number" class="form-input">
                            @error('form.user_phone_number') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Region</label>
                            <select wire:model.defer="form.region_id" class="form-input" @if ($regionLocked) disabled @endif>
                                <option value="">Select region</option>
                                @foreach ($regions as $region)
                                    <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                                @endforeach
                            </select>
                            @error('form.region_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Device Phone Number</label>
                            <input type="text" wire:model.defer="form.device_phone_number" class="form-input">
                            @error('form.device_phone_number') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
                        <button type="button" wire:click="closeForm" class="btn">Cancel</button>
                        <button type="button" wire:click="save" class="btn btn-primary">
                            {{ $editingAssetId ? 'Update Device' : 'Create Device' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
