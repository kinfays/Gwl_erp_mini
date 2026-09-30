<div>
    <x-ui.page-header title="Regions" description="Manage region records used by locations, employees, and HR workflows." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    @error('region_name')
        <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert>
    @enderror

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Region Directory" :description="$regions->count().' regions'" :padded="false">
            <x-ui.table label="Regions">
                <x-slot:head>
                    <tr>
                        <th>Region</th>
                        <th>Letter prefix</th>
                        <th class="num">Locations</th>
                        <th class="num">Employees</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($regions as $region)
                    <tr wire:key="region-{{ $region->id }}" @class(['is-selected' => $editingId === $region->id])>
                        <td>{{ $region->region_name }}</td>
                        <td @class(['mono', 'cell-muted' => ! $region->letter_prefix])>{{ $region->letter_prefix ?: 'Set on first letter' }}</td>
                        <td class="num">{{ $region->districts_count }}</td>
                        <td class="num">{{ $region->employees_count }}</td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $region->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $region->region_name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm btn-icon is-danger"
                                    title="Delete"
                                    aria-label="Delete {{ $region->region_name }}"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Delete region?',
                                        message: @js('This will delete ' . $region->region_name . ' if no locations or employees are assigned.'),
                                        confirmLabel: 'Delete',
                                        variant: 'danger',
                                        action: () => $wire.delete({{ $region->id }})
                                    })"
                                >
                                    <x-ui.icon name="trash-2" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="map" title="No regions found." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit Region' : 'Add Region'" class="manager-form">
            <div class="ui-stack">
                @if ($editingId)
                    <x-ui.input label="Region Name" wire:model="editingName" placeholder="Enter region name" wire:key="region-edit-{{ $editingId }}" x-init="$nextTick(() => $el.focus())" />
                    <x-ui.input label="Letter prefix" wire:model="editingPrefix" class="mono" placeholder="e.g. GA" hint="Starts this region's letter serial numbers (GA-2026-001). Changing it only affects future letters; numbers already issued are never rewritten." wire:key="region-edit-prefix-{{ $editingId }}" />
                @else
                    <x-ui.input label="Region Name" wire:model="region_name" placeholder="Enter region name" wire:key="region-new" />
                    <x-ui.input label="Letter prefix" wire:model="letter_prefix" class="mono" maxlength="6" placeholder="Leave blank to derive from the name" hint="2-6 capital letters or digits. Starts this region's letter serial numbers (GA-2026-001). Changing it later only affects future letters." wire:key="region-new-prefix" />
                @endif

                <div class="ui-form-actions">
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button type="button" wire:click="update" class="btn btn-primary">Save</button>
                    @else
                        <button type="button" wire:click="save" class="btn btn-primary">
                            <x-ui.icon name="plus" />
                            Add Region
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
