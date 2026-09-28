<div>
    @php
        // One set of inputs serves both modes; each mode binds its own properties.
        $mode = $editingId ? 'edit-'.$editingId : 'new';
        $imageField = $editingId ? 'editingImage' : 'image';
        $pendingImage = $editingId ? $editingImage : $image;
    @endphp

    <x-ui.page-header title="Asset Models" description="Manage the model catalogue used by the Assets, Phones and Network add/edit forms. Models are shared across all regions." />

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Model Directory" :description="$models->count().' models'" :padded="false">
            <x-ui.table label="Asset models">
                <x-slot:head>
                    <tr>
                        <th class="thumb-col">Image</th>
                        <th>Model</th>
                        <th>Category</th>
                        <th>Manufacturer</th>
                        <th class="num">In Use</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($models as $model)
                    <tr wire:key="model-{{ $model->id }}" @class(['is-selected' => $editingId === $model->id])>
                        <td class="thumb-col">
                            @if ($model->image_path)
                                <img src="{{ $model->imageUrl() }}" alt="{{ $model->name }}" class="model-thumb" loading="lazy">
                            @else
                                <span class="model-thumb is-empty" title="No image" aria-label="No image" role="img"></span>
                            @endif
                        </td>
                        <td class="nowrap"><span class="ui-person-name">{{ $model->name }}</span></td>
                        <td>{{ $model->category }}</td>
                        <td @class(['cell-muted' => ! $model->manufacturer])>{{ $model->manufacturer?->name ?: '-' }}</td>
                        <td class="num">{{ $model->assets_count }}</td>
                        <td><x-ui.status-pill domain="account" :status="$model->is_active ? 'Active' : 'Inactive'" /></td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $model->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $model->name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button type="button" wire:click="toggleActive({{ $model->id }})" class="btn btn-sm">
                                    {{ $model->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm btn-icon is-danger"
                                    title="Delete"
                                    aria-label="Delete {{ $model->name }}"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Delete model?',
                                        message: @js('This will delete ' . $model->name . ' and its image if no assets use it.'),
                                        confirmLabel: 'Delete',
                                        variant: 'danger',
                                        action: () => $wire.delete({{ $model->id }})
                                    })"
                                >
                                    <x-ui.icon name="trash-2" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="7" icon="boxes" title="No models found." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit Model' : 'Add Model'" class="manager-form">
            <div class="ui-stack">
                <x-ui.input
                    label="Model Name"
                    wire:model="{{ $editingId ? 'editingName' : 'name' }}"
                    wire:key="model-name-{{ $mode }}"
                    placeholder="e.g. HP EliteBook 840 G9"
                    x-init="{{ $editingId ? '$nextTick(() => $el.focus())' : '' }}"
                />

                <x-ui.select label="Category" wire:model="{{ $editingId ? 'editingCategory' : 'category' }}" wire:key="model-category-{{ $mode }}">
                    <option value="">Select category</option>
                    @foreach ($assetTypeGroups as $group => $types)
                        <optgroup label="{{ ucfirst($group) }}">
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-ui.select>

                <div>
                    <x-ui.select label="Manufacturer" wire:model="{{ $editingId ? 'editingManufacturerId' : 'manufacturer_id' }}" wire:key="model-manufacturer-{{ $mode }}">
                        <option value="">Select manufacturer</option>
                        @foreach ($manufacturers as $manufacturer)
                            <option value="{{ $manufacturer->id }}">{{ $manufacturer->name }}{{ $manufacturer->is_active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($manufacturers->isEmpty())
                        <p class="ui-hint field-note">
                            No manufacturers yet.
                            @if ($canManageManufacturers)
                                <a href="{{ route('assets.settings.manufacturers') }}" class="text-link">Add one under Settings &rsaquo; Manufacturers.</a>
                            @endif
                        </p>
                    @endif
                </div>

                <x-ui.field label="Image" for="f-model-image" hint="JPG, PNG or WebP, up to 2MB." :error="$imageField">
                    <div class="model-image-row">
                        @if ($pendingImage && ! $errors->has($imageField) && $pendingImage->isPreviewable())
                            <img src="{{ $pendingImage->temporaryUrl() }}" alt="New image preview" class="model-thumb model-thumb-lg">
                        @elseif ($editingModel?->image_path)
                            <img src="{{ $editingModel->imageUrl() }}" alt="{{ $editingModel->name }}" class="model-thumb model-thumb-lg">
                        @endif
                        <input
                            id="f-model-image"
                            type="file"
                            wire:model="{{ $imageField }}"
                            wire:key="model-image-input-{{ $editingId ?? 'new' }}"
                            class="form-input"
                            accept=".jpg,.jpeg,.png,.webp"
                            aria-describedby="f-model-image-hint"
                        >
                    </div>
                    <span wire:loading wire:target="{{ $imageField }}" class="ui-hint">Uploading…</span>
                    @if ($editingModel?->image_path)
                        <div>
                            <button type="button" wire:click="removeImage({{ $editingModel->id }})" class="btn btn-sm btn-danger">
                                <x-ui.icon name="trash-2" class="icon-sm" />
                                Remove image
                            </button>
                        </div>
                    @endif
                </x-ui.field>

                <x-ui.textarea label="Notes" wire:model="{{ $editingId ? 'editingNotes' : 'notes' }}" wire:key="model-notes-{{ $mode }}" rows="2" />

                @if ($editingId)
                    <x-ui.checkbox label="Active" wire:model="editingIsActive" id="f-model-active" />
                @endif

                <div class="ui-form-actions">
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button type="button" wire:click="update" wire:loading.attr="disabled" wire:target="editingImage" class="btn btn-primary">Save</button>
                    @else
                        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="image" class="btn btn-primary">
                            <x-ui.icon name="plus" />
                            Add Model
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
