<div>
    <x-ui.page-header title="Departments" description="Manage department names used across staff records and leave workflows." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    @error('department_name')
        <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert>
    @enderror

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Department Directory" :description="$departments->count().' departments'" :padded="false">
            <x-ui.table label="Departments">
                <x-slot:head>
                    <tr>
                        <th>Department</th>
                        <th class="num">Employees</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($departments as $department)
                    <tr wire:key="department-{{ $department->id }}" @class(['is-selected' => $editingId === $department->id])>
                        <td>{{ $department->department_name }}</td>
                        <td class="num">{{ $department->employees_count }}</td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $department->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $department->department_name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm btn-icon is-danger"
                                    title="Delete"
                                    aria-label="Delete {{ $department->department_name }}"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Delete department?',
                                        message: @js('This will delete ' . $department->department_name . ' if no employees are assigned.'),
                                        confirmLabel: 'Delete',
                                        variant: 'danger',
                                        action: () => $wire.delete({{ $department->id }})
                                    })"
                                >
                                    <x-ui.icon name="trash-2" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="3" icon="building-2" title="No departments found." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit Department' : 'Add Department'" class="manager-form">
            <div class="ui-stack">
                @if ($editingId)
                    <x-ui.input label="Department Name" wire:model="editingName" placeholder="Enter department name" wire:key="department-edit-{{ $editingId }}" x-init="$nextTick(() => $el.focus())" />
                @else
                    <x-ui.input label="Department Name" wire:model="department_name" placeholder="Enter department name" wire:key="department-new" />
                @endif

                <div class="ui-form-actions">
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button type="button" wire:click="update" class="btn btn-primary">Save</button>
                    @else
                        <button type="button" wire:click="save" class="btn btn-primary">
                            <x-ui.icon name="plus" />
                            Add Department
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
