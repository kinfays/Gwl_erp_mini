<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Manufacturers</h2>
            <p>Manage the manufacturers offered when adding or editing an asset model. Shared across all regions.</p>
        </div>
    </div>

    <div class="two">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Manufacturer Directory</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Manufacturer</th>
                        <th>Models</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($manufacturers as $manufacturer)
                        <tr wire:key="manufacturer-{{ $manufacturer->id }}">
                            <td>
                                <div>{{ $manufacturer->name }}</div>
                                @if ($manufacturer->notes)
                                    <div style="font-size:10px;color:var(--color-text-secondary)">{{ $manufacturer->notes }}</div>
                                @endif
                            </td>
                            <td>{{ $manufacturer->models_count }}</td>
                            <td>
                                <span class="pill {{ $manufacturer->is_active ? 'p-g' : 'p-d' }}">{{ $manufacturer->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <button wire:click="edit({{ $manufacturer->id }})" class="actn">Edit</button>
                                    <button wire:click="toggleActive({{ $manufacturer->id }})" class="actn">
                                        {{ $manufacturer->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="actn actn-r"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Delete manufacturer?',
                                            message: @js('This will delete ' . $manufacturer->name . ' if no models use it.'),
                                            confirmLabel: 'Delete',
                                            variant: 'danger',
                                            action: () => $wire.delete({{ $manufacturer->id }})
                                        })"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align:center;color:var(--color-text-secondary)">No manufacturers found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">{{ $editingId ? 'Edit Manufacturer' : 'Add Manufacturer' }}</span>
            </div>

            <div style="padding:14px">
                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Manufacturer Name</label>
                    <input type="text" wire:model="{{ $editingId ? 'editingName' : 'name' }}" class="form-input" placeholder="e.g. HP">
                    @error($editingId ? 'editingName' : 'name')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
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
                        <button wire:click="save" class="btn btn-primary">Add Manufacturer</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
