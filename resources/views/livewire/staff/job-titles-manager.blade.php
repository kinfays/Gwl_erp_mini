<div>
    <x-ui.page-header title="Job Titles" description="Manage job titles used on employee profiles and import validation." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    @error('job_title_name')
        <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert>
    @enderror

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Job Title Directory" :description="$jobTitles->count().' job titles'" :padded="false">
            <x-ui.table label="Job titles">
                <x-slot:head>
                    <tr>
                        <th>Job Title</th>
                        <th class="num">Employees</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($jobTitles as $jobTitle)
                    <tr wire:key="job-title-{{ $jobTitle->id }}" @class(['is-selected' => $editingId === $jobTitle->id])>
                        <td>{{ $jobTitle->job_title_name }}</td>
                        <td class="num">{{ $jobTitle->employees_count }}</td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $jobTitle->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $jobTitle->job_title_name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm btn-icon is-danger"
                                    title="Delete"
                                    aria-label="Delete {{ $jobTitle->job_title_name }}"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Delete job title?',
                                        message: @js('This will delete ' . $jobTitle->job_title_name . ' if no employees are assigned.'),
                                        confirmLabel: 'Delete',
                                        variant: 'danger',
                                        action: () => $wire.delete({{ $jobTitle->id }})
                                    })"
                                >
                                    <x-ui.icon name="trash-2" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="3" icon="briefcase" title="No job titles found." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit Job Title' : 'Add Job Title'" class="manager-form">
            <div class="ui-stack">
                @if ($editingId)
                    <x-ui.input label="Job Title Name" wire:model="editingName" placeholder="Enter job title" wire:key="job-title-edit-{{ $editingId }}" x-init="$nextTick(() => $el.focus())" />
                @else
                    <x-ui.input label="Job Title Name" wire:model="job_title_name" placeholder="Enter job title" wire:key="job-title-new" />
                @endif

                <div class="ui-form-actions">
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button type="button" wire:click="update" class="btn btn-primary">Save</button>
                    @else
                        <button type="button" wire:click="save" class="btn btn-primary">
                            <x-ui.icon name="plus" />
                            Add Job Title
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
