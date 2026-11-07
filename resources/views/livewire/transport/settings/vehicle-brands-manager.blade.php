<div>
    @php
        // One set of inputs serves both modes; each mode binds its own properties.
        $mode = $editingId ? 'edit-'.$editingId : 'new';
        $vehicleWord = \Illuminate\Support\Str::plural('vehicle', $editingVehicleCount);
    @endphp

    <x-ui.page-header title="Vehicle Brands" description="Manage the brands offered when adding or editing a vehicle. Shared across all regions. A brand is deactivated, never deleted, because vehicles carry its name." />

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Brand Directory" :description="$brands->count().' brands'" :padded="false">
            <div class="ui-toolbar">
                <input class="form-input ui-input" placeholder="Search brands" aria-label="Search brands" wire:model.live.debounce.300ms="search">
            </div>

            <x-ui.table label="Vehicle brands">
                <x-slot:head>
                    <tr>
                        <th>Brand</th>
                        <th class="num">Models</th>
                        <th class="num">Vehicles</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($brands as $brand)
                    <tr wire:key="brand-{{ $brand->id }}" @class(['is-selected' => $editingId === $brand->id])>
                        <td><span class="ui-person-name">{{ $brand->name }}</span></td>
                        <td class="num">{{ $brand->models_count }}</td>
                        <td class="num">{{ $vehicleCounts[$brand->id] ?? 0 }}</td>
                        <td><x-ui.status-pill domain="account" :status="$brand->is_active ? 'Active' : 'Inactive'" /></td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $brand->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $brand->name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button type="button" wire:click="toggleActive({{ $brand->id }})" class="btn btn-sm">
                                    {{ $brand->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="car" title="No brands found." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit Brand' : 'Add Brand'" class="manager-form">
            <div class="ui-stack">
                <x-ui.input
                    label="Brand Name"
                    wire:model="{{ $editingId ? 'editingName' : 'name' }}"
                    wire:key="brand-name-{{ $mode }}"
                    placeholder="e.g. Toyota"
                    x-init="{{ $editingId ? '$nextTick(() => $el.focus())' : '' }}"
                />

                @if ($editingId)
                    <p class="ui-hint">
                        {{ $editingVehicleCount }} {{ $vehicleWord }} currently show this brand (deleted vehicles included).
                        Renaming rewrites the brand on all of them.
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
                                const renamed = ($wire.editingName || '').trim() !== @js($editing?->name ?? '');
                                if (! renamed || {{ (int) $editingVehicleCount }} === 0) { $wire.update(); return; }
                                $dispatch('confirm-action', {
                                    title: 'Rename brand?',
                                    message: @js('This also changes the brand on '.$editingVehicleCount.' '.$vehicleWord.'. Continue?'),
                                    confirmLabel: 'Rename',
                                    variant: 'danger',
                                    action: () => $wire.update()
                                })
                            "
                        >Save</button>
                    @else
                        <button type="button" wire:click="save" class="btn btn-primary">
                            <x-ui.icon name="plus" />
                            Add Brand
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
