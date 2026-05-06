<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Locations</h2>
            <p>Manage district/location records and attach each one to a region.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="erp-card" style="margin-bottom:14px;background:#eaf7ef;border-color:#b8e0c5;color:#21633c;">
            {{ session('success') }}
        </div>
    @endif

    @error('district_name')
        <div class="erp-card" style="margin-bottom:14px;background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ $message }}
        </div>
    @enderror

    @if ($regions->isEmpty())
        <div class="erp-card" style="margin-bottom:14px;background:#faeeda;border-color:#fac775;color:#854f0b;">
            Add regions through import before creating locations.
        </div>
    @endif

    <div class="two">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Location Directory</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>Region</th>
                        <th>Employees</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($locations as $location)
                        <tr>
                            <td>{{ $location->district_name }}</td>
                            <td>{{ $location->region?->region_name ?? '-' }}</td>
                            <td>{{ $location->employees_count }}</td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <button wire:click="edit({{ $location->id }})" class="actn">Edit</button>
                                    <button
                                        type="button"
                                        class="actn actn-r"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Delete location?',
                                            message: @js('This will delete ' . $location->district_name . ' if no employees are assigned.'),
                                            confirmLabel: 'Delete',
                                            variant: 'danger',
                                            action: () => $wire.delete({{ $location->id }})
                                        })"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align:center;color:var(--color-text-secondary)">No locations found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">{{ $editingId ? 'Edit Location' : 'Add Location' }}</span>
            </div>

            <div style="padding:14px">
                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Region</label>
                    @if ($editingId)
                        <select wire:model="editingRegionId" class="form-input">
                            <option value="">Select region</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                            @endforeach
                        </select>
                    @else
                        <select wire:model="region_id" class="form-input">
                            <option value="">Select region</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                            @endforeach
                        </select>
                    @endif
                    @error($editingId ? 'editingRegionId' : 'region_id')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Location Name</label>
                    @if ($editingId)
                        <input
                            type="text"
                            wire:model="editingName"
                            class="form-input"
                            placeholder="Enter location name"
                        >
                    @else
                        <input
                            type="text"
                            wire:model="district_name"
                            class="form-input"
                            placeholder="Enter location name"
                        >
                    @endif
                    @error($editingId ? 'editingName' : 'district_name')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div style="display:flex;gap:8px;justify-content:flex-end">
                    @if ($editingId)
                        <button wire:click="update" class="btn btn-primary">Save</button>
                        <button wire:click="cancelEdit" class="btn">Cancel</button>
                    @else
                        <button wire:click="save" class="btn btn-primary" @disabled($regions->isEmpty())>Add Location</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
