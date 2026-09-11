<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>IP Ranges</h2>
            <p>Assigned IP ranges per district/region, used to validate Device IP / Management IP on save.</p>
        </div>
    </div>

    <div class="two">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Range Directory</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Location</th>
                        <th>Range</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ranges as $range)
                        <tr>
                            <td>{{ $range->label }}</td>
                            <td>
                                {{ $range->district?->district_name ?: ($range->region?->region_name ?: 'All locations') }}
                            </td>
                            <td>
                                <div>{{ $range->start_ip }} - {{ $range->end_ip }}</div>
                                @if ($range->cidr)
                                    <div style="font-size:10px;color:var(--color-text-secondary)">{{ $range->cidr }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="pill {{ $range->is_active ? 'p-g' : 'p-d' }}">{{ $range->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <button wire:click="edit({{ $range->id }})" class="actn">Edit</button>
                                    <button
                                        type="button"
                                        class="actn actn-r"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Delete IP range?',
                                            message: @js('This will delete ' . $range->label . '.'),
                                            confirmLabel: 'Delete',
                                            variant: 'danger',
                                            action: () => $wire.delete({{ $range->id }})
                                        })"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align:center;color:var(--color-text-secondary)">No IP ranges configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">{{ $editingId ? 'Edit IP Range' : 'Add IP Range' }}</span>
            </div>

            <div style="padding:14px">
                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Label</label>
                    <input type="text" wire:model="{{ $editingId ? 'editingLabel' : 'label' }}" class="form-input" placeholder="e.g. Accra West HQ LAN">
                    @error($editingId ? 'editingLabel' : 'label')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Region</label>
                    <select wire:model="{{ $editingId ? 'editingRegionId' : 'region_id' }}" class="form-input">
                        <option value="">Any region</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">District</label>
                    <select wire:model="{{ $editingId ? 'editingDistrictId' : 'district_id' }}" class="form-input">
                        <option value="">Any district</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Start IP</label>
                        <input type="text" wire:model="{{ $editingId ? 'editingStartIp' : 'start_ip' }}" class="form-input" placeholder="192.168.10.1">
                        @error($editingId ? 'editingStartIp' : 'start_ip')
                            <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">End IP</label>
                        <input type="text" wire:model="{{ $editingId ? 'editingEndIp' : 'end_ip' }}" class="form-input" placeholder="192.168.10.254">
                        @error($editingId ? 'editingEndIp' : 'end_ip')
                            <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">CIDR (optional)</label>
                    <input type="text" wire:model="{{ $editingId ? 'editingCidr' : 'cidr' }}" class="form-input" placeholder="192.168.10.0/24">
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Notes</label>
                    <textarea rows="2" wire:model="{{ $editingId ? 'editingNotes' : 'notes' }}" class="form-input"></textarea>
                </div>

                @if ($editingId)
                    <label class="form-label" style="display:flex;align-items:center;gap:6px;margin-bottom:12px">
                        <input type="checkbox" wire:model="editingIsActive"> Active
                    </label>
                @endif

                <div style="display:flex;gap:8px;justify-content:flex-end">
                    @if ($editingId)
                        <button wire:click="update" class="btn btn-primary">Save</button>
                        <button wire:click="cancelEdit" class="btn">Cancel</button>
                    @else
                        <button wire:click="save" class="btn btn-primary">Add Range</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
