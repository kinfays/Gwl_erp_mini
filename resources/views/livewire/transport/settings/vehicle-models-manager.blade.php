<div>
    @php
        // One set of inputs serves both modes; each mode binds its own properties.
        $mode = $editingId ? 'edit-'.$editingId : 'new';
        $vehicleWord = \Illuminate\Support\Str::plural('vehicle', $editingVehicleCount);
    @endphp

    <x-ui.page-header title="Vehicle Models" description="Manage the models offered under each brand on the vehicle form. Shared across all regions. A model is deactivated, never deleted, because vehicles carry its name." />

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Model Directory" :description="$models->total().' models'" :padded="false">
            <div class="ui-toolbar">
                <x-form.searchable-select
                    label="Brand"
                    model="filterBrandId"
                    :options="$allBrandOptions"
                    placeholder="All brands"
                    empty-text="No brands found"
                    :live="true"
                />
                <input class="form-input ui-input" placeholder="Search models" aria-label="Search models" wire:model.live.debounce.300ms="search">
            </div>

            <x-ui.table label="Vehicle models">
                <x-slot:head>
                    <tr>
                        <th>Model</th>
                        <th>Brand</th>
                        <th class="num">Vehicles</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($models as $model)
                    <tr wire:key="model-{{ $model->id }}" @class(['is-selected' => $editingId === $model->id])>
                        <td><span class="ui-person-name">{{ $model->name }}</span></td>
                        <td>
                            {{ $model->brand?->name ?? '-' }}
                            @if ($model->brand && ! $model->brand->is_active)
                                <span class="ui-hint">(inactive)</span>
                            @endif
                        </td>
                        <td class="num">{{ $vehicleCounts[$model->id] ?? 0 }}</td>
                        <td><x-ui.status-pill domain="account" :status="$model->is_active ? 'Active' : 'Inactive'" /></td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $model->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $model->name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button type="button" wire:click="toggleActive({{ $model->id }})" class="btn btn-sm">
                                    {{ $model->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="car" title="No models found." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>

            <div>{{ $models->links() }}</div>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit Model' : 'Add Model'" class="manager-form">
            <div class="ui-stack">
                <x-ui.select label="Brand" wire:model="{{ $editingId ? 'editingBrandId' : 'brandId' }}" wire:key="model-brand-{{ $mode }}" required>
                    <option value="">Select brand</option>
                    @foreach ($brandChoices as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->name }}{{ $brand->is_active ? '' : ' (inactive)' }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input
                    label="Model Name"
                    wire:model="{{ $editingId ? 'editingName' : 'name' }}"
                    wire:key="model-name-{{ $mode }}"
                    placeholder="e.g. Hilux"
                    x-init="{{ $editingId ? '$nextTick(() => $el.focus())' : '' }}"
                />

                @if ($editingId)
                    <p class="ui-hint">
                        {{ $editingVehicleCount }} {{ $vehicleWord }} currently show this model (deleted vehicles included).
                        Renaming it or moving it to another brand rewrites the brand and model on all of them.
                    </p>
                @endif

                <div class="ui-form-actions">
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button
                            type="button"
                            class="btn btn-primary"
                            x-data
                            x-on:click.prevent="
                                const changed = ($wire.editingName || '').trim() !== @js($editing?->name ?? '') || String($wire.editingBrandId) !== @js((string) ($editing?->vehicle_brand_id ?? ''));
                                if (! changed || {{ (int) $editingVehicleCount }} === 0) { $wire.update(); return; }
                                $dispatch('confirm-action', {
                                    title: 'Update model?',
                                    message: @js('This also changes the brand and model on '.$editingVehicleCount.' '.$vehicleWord.'. Continue?'),
                                    confirmLabel: 'Update',
                                    variant: 'danger',
                                    action: () => $wire.update()
                                })
                            "
                        >Save</button>
                    @else
                        <button type="button" wire:click="save" class="btn btn-primary">
                            <x-ui.icon name="plus" />
                            Add Model
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
