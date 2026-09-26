<div>
    @php
        $thumbStyle = 'display:block;width:36px;height:36px;object-fit:cover;border-radius:6px;border:0.5px solid var(--color-border-tertiary)';
        $imageField = $editingId ? 'editingImage' : 'image';
        $pendingImage = $editingId ? $editingImage : $image;
    @endphp

    <div class="page-head">
        <div class="ph-left">
            <h2>Asset Models</h2>
            <p>Manage the model catalogue used by the Assets, Phones and Network add/edit forms. Models are shared across all regions.</p>
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
                        <th style="width:52px">Image</th>
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
                        <tr wire:key="model-{{ $model->id }}">
                            <td>
                                @if ($model->image_path)
                                    <img src="{{ $model->imageUrl() }}" alt="{{ $model->name }}" style="{{ $thumbStyle }}" loading="lazy">
                                @else
                                    <div style="{{ $thumbStyle }};border-style:dashed" title="No image"></div>
                                @endif
                            </td>
                            <td>{{ $model->name }}</td>
                            <td>{{ $model->category }}</td>
                            <td>{{ $model->manufacturer?->name ?: '-' }}</td>
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
                                            message: @js('This will delete ' . $model->name . ' and its image if no assets use it.'),
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
                            <td colspan="7" style="text-align:center;color:var(--color-text-secondary)">No models found.</td>
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
                    <select wire:model="{{ $editingId ? 'editingManufacturerId' : 'manufacturer_id' }}" class="form-input">
                        <option value="">Select manufacturer</option>
                        @foreach ($manufacturers as $manufacturer)
                            <option value="{{ $manufacturer->id }}">{{ $manufacturer->name }}{{ $manufacturer->is_active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </select>
                    @if ($manufacturers->isEmpty())
                        <span class="form-label" style="color:var(--color-text-secondary)">
                            No manufacturers yet.
                            @if ($canManageManufacturers)
                                <a href="{{ route('assets.settings.manufacturers') }}">Add one under Settings &rsaquo; Manufacturers.</a>
                            @endif
                        </span>
                    @endif
                    @error($editingId ? 'editingManufacturerId' : 'manufacturer_id')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Image</label>
                    <div style="display:flex;gap:10px;align-items:center">
                        @if ($pendingImage && ! $errors->has($imageField) && $pendingImage->isPreviewable())
                            <img src="{{ $pendingImage->temporaryUrl() }}" alt="New image preview" style="{{ $thumbStyle }};width:56px;height:56px">
                        @elseif ($editingModel?->image_path)
                            <img src="{{ $editingModel->imageUrl() }}" alt="{{ $editingModel->name }}" style="{{ $thumbStyle }};width:56px;height:56px">
                        @endif
                        <input
                            type="file"
                            wire:model="{{ $imageField }}"
                            wire:key="model-image-input-{{ $editingId ?? 'new' }}"
                            class="form-input"
                            accept=".jpg,.jpeg,.png,.webp"
                        >
                    </div>
                    <span wire:loading wire:target="{{ $imageField }}" class="form-label">Uploading…</span>
                    <span class="form-label" style="color:var(--color-text-secondary)">JPG, PNG or WebP, up to 2MB.</span>
                    @error($imageField)
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                    @if ($editingModel?->image_path)
                        <div style="margin-top:6px">
                            <button type="button" wire:click="removeImage({{ $editingModel->id }})" class="actn actn-r">Remove image</button>
                        </div>
                    @endif
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
                        <button wire:click="update" wire:loading.attr="disabled" wire:target="editingImage" class="btn btn-primary">Save</button>
                        <button wire:click="cancelEdit" class="btn">Cancel</button>
                    @else
                        <button wire:click="save" wire:loading.attr="disabled" wire:target="image" class="btn btn-primary">Add Model</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
