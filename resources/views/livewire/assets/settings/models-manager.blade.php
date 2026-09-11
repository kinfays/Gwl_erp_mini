<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Asset Models</h2>
            <p>Manage the model catalogue used by the Assets, Phones and Network add/edit forms.</p>
        </div>
    </div>

    <div class="two">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Model Directory</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Model</th>
                        <th>Category</th>
                        <th>Manufacturer</th>
                        <th>In Use</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($models as $model)
                        <tr>
                            <td>{{ $model->name }}</td>
                            <td>{{ $model->category }}</td>
                            <td>{{ $model->manufacturer ?: '-' }}</td>
                            <td>{{ $model->assets_count }}</td>
                            <td>
                                <span class="pill {{ $model->is_active ? 'p-g' : 'p-d' }}">{{ $model->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <button wire:click="edit({{ $model->id }})" class="actn">Edit</button>
                                    <button wire:click="toggleActive({{ $model->id }})" class="actn">
                                        {{ $model->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="actn actn-r"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Delete model?',
                                            message: @js('This will delete ' . $model->name . ' if no assets use it.'),
                                            confirmLabel: 'Delete',
                                            variant: 'danger',
                                            action: () => $wire.delete({{ $model->id }})
                                        })"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align:center;color:var(--color-text-secondary)">No models found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">{{ $editingId ? 'Edit Model' : 'Add Model' }}</span>
            </div>

            <div style="padding:14px">
                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Model Name</label>
                    <input type="text" wire:model="{{ $editingId ? 'editingName' : 'name' }}" class="form-input" placeholder="e.g. HP EliteBook 840 G9">
                    @error($editingId ? 'editingName' : 'name')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Category</label>
                    <select wire:model="{{ $editingId ? 'editingCategory' : 'category' }}" class="form-input">
                        <option value="">Select category</option>
                        @foreach ($assetTypeGroups as $group => $types)
                            <optgroup label="{{ ucfirst($group) }}">
                                @foreach ($types as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error($editingId ? 'editingCategory' : 'category')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Manufacturer</label>
                    <input type="text" wire:model="{{ $editingId ? 'editingManufacturer' : 'manufacturer' }}" class="form-input">
                    @error($editingId ? 'editingManufacturer' : 'manufacturer')
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
                        <button wire:click="save" class="btn btn-primary">Add Model</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
