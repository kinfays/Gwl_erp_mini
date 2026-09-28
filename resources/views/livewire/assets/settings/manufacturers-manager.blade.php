<div>
    @php
        // One set of inputs serves both modes; each mode binds its own properties.
        $mode = $editingId ? 'edit-'.$editingId : 'new';
    @endphp

    <x-ui.page-header title="Manufacturers" description="Manage the manufacturers offered when adding or editing an asset model. Shared across all regions." />

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Manufacturer Directory" :description="$manufacturers->count().' manufacturers'" :padded="false">
            <x-ui.table label="Manufacturers">
                <x-slot:head>
                    <tr>
                        <th>Manufacturer</th>
                        <th class="num">Models</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($manufacturers as $manufacturer)
                    <tr wire:key="manufacturer-{{ $manufacturer->id }}" @class(['is-selected' => $editingId === $manufacturer->id])>
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name">{{ $manufacturer->name }}</span>
                                @if ($manufacturer->notes)
                                    <span class="ui-person-sub">{{ $manufacturer->notes }}</span>
                                @endif
                            </span>
                        </td>
                        <td class="num">{{ $manufacturer->models_count }}</td>
                        <td><x-ui.status-pill domain="account" :status="$manufacturer->is_active ? 'Active' : 'Inactive'" /></td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $manufacturer->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $manufacturer->name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button type="button" wire:click="toggleActive({{ $manufacturer->id }})" class="btn btn-sm">
                                    {{ $manufacturer->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm btn-icon is-danger"
                                    title="Delete"
                                    aria-label="Delete {{ $manufacturer->name }}"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Delete manufacturer?',
                                        message: @js('This will delete ' . $manufacturer->name . ' if no models use it.'),
                                        confirmLabel: 'Delete',
                                        variant: 'danger',
                                        action: () => $wire.delete({{ $manufacturer->id }})
                                    })"
                                >
                                    <x-ui.icon name="trash-2" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" icon="factory" title="No manufacturers found." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit Manufacturer' : 'Add Manufacturer'" class="manager-form">
            <div class="ui-stack">
                <x-ui.input
                    label="Manufacturer Name"
                    wire:model="{{ $editingId ? 'editingName' : 'name' }}"
                    wire:key="manufacturer-name-{{ $mode }}"
                    placeholder="e.g. HP"
                    x-init="{{ $editingId ? '$nextTick(() => $el.focus())' : '' }}"
                />

                <x-ui.textarea label="Notes" wire:model="{{ $editingId ? 'editingNotes' : 'notes' }}" wire:key="manufacturer-notes-{{ $mode }}" rows="2" />

                @if ($editingId)
                    <x-ui.checkbox label="Active" wire:model="editingIsActive" id="f-manufacturer-active" />
                @endif

                <div class="ui-form-actions">
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button type="button" wire:click="update" class="btn btn-primary">Save</button>
                    @else
                        <button type="button" wire:click="save" class="btn btn-primary">
                            <x-ui.icon name="plus" />
                            Add Manufacturer
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
