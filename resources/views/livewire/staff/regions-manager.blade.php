<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Regions</h2>
            <p>Manage region records used by locations, employees, and HR workflows.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="erp-card" style="margin-bottom:14px;background:#eaf7ef;border-color:#b8e0c5;color:#21633c;">
            {{ session('success') }}
        </div>
    @endif

    @error('region_name')
        <div class="erp-card" style="margin-bottom:14px;background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ $message }}
        </div>
    @enderror

    <div class="two">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Region Directory</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Region</th>
                        <th>HR Email</th>
                        <th>Locations</th>
                        <th>Employees</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($regions as $region)
                        <tr>
                            <td>{{ $region->region_name }}</td>
                            <td>{{ $region->hr_email ?: '-' }}</td>
                            <td>{{ $region->districts_count }}</td>
                            <td>{{ $region->employees_count }}</td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <button wire:click="edit({{ $region->id }})" class="actn">Edit</button>
                                    <button
                                        type="button"
                                        class="actn actn-r"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Delete region?',
                                            message: @js('This will delete ' . $region->region_name . ' if no locations or employees are assigned.'),
                                            confirmLabel: 'Delete',
                                            variant: 'danger',
                                            action: () => $wire.delete({{ $region->id }})
                                        })"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align:center;color:var(--color-text-secondary)">No regions found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">{{ $editingId ? 'Edit Region' : 'Add Region' }}</span>
            </div>

            <div style="padding:14px">
                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Region Name</label>
                    @if ($editingId)
                        <input
                            type="text"
                            wire:model="editingName"
                            class="form-input"
                            placeholder="Enter region name"
                        >
                    @else
                        <input
                            type="text"
                            wire:model="region_name"
                            class="form-input"
                            placeholder="Enter region name"
                        >
                    @endif
                    @error($editingId ? 'editingName' : 'region_name')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">HR Email</label>
                    @if ($editingId)
                        <input
                            type="email"
                            wire:model="editingHrEmail"
                            class="form-input"
                            placeholder="Optional"
                        >
                    @else
                        <input
                            type="email"
                            wire:model="hr_email"
                            class="form-input"
                            placeholder="Optional"
                        >
                    @endif
                    @error($editingId ? 'editingHrEmail' : 'hr_email')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div style="display:flex;gap:8px;justify-content:flex-end">
                    @if ($editingId)
                        <button wire:click="update" class="btn btn-primary">Save</button>
                        <button wire:click="cancelEdit" class="btn">Cancel</button>
                    @else
                        <button wire:click="save" class="btn btn-primary">Add Region</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
