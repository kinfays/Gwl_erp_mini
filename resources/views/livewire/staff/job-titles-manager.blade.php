<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Job Titles</h2>
            <p>Manage job titles used on employee profiles and import validation.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="erp-card" style="margin-bottom:14px;background:#eaf7ef;border-color:#b8e0c5;color:#21633c;">
            {{ session('success') }}
        </div>
    @endif

    @error('job_title_name')
        <div class="erp-card" style="margin-bottom:14px;background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ $message }}
        </div>
    @enderror

    <div class="two">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Job Title Directory</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Job Title</th>
                        <th>Employees</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobTitles as $jobTitle)
                        <tr>
                            <td>{{ $jobTitle->job_title_name }}</td>
                            <td>{{ $jobTitle->employees_count }}</td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <button wire:click="edit({{ $jobTitle->id }})" class="actn">Edit</button>
                                    <button
                                        type="button"
                                        class="actn actn-r"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Delete job title?',
                                            message: @js('This will delete ' . $jobTitle->job_title_name . ' if no employees are assigned.'),
                                            confirmLabel: 'Delete',
                                            variant: 'danger',
                                            action: () => $wire.delete({{ $jobTitle->id }})
                                        })"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="text-align:center;color:var(--color-text-secondary)">No job titles found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">{{ $editingId ? 'Edit Job Title' : 'Add Job Title' }}</span>
            </div>

            <div style="padding:14px">
                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Job Title Name</label>
                    @if ($editingId)
                        <input
                            type="text"
                            wire:model="editingName"
                            class="form-input"
                            placeholder="Enter job title"
                        >
                    @else
                        <input
                            type="text"
                            wire:model="job_title_name"
                            class="form-input"
                            placeholder="Enter job title"
                        >
                    @endif
                    @error($editingId ? 'editingName' : 'job_title_name')
                        <span class="form-label" style="color:#a32d2d">{{ $message }}</span>
                    @enderror
                </div>

                <div style="display:flex;gap:8px;justify-content:flex-end">
                    @if ($editingId)
                        <button wire:click="update" class="btn btn-primary">Save</button>
                        <button wire:click="cancelEdit" class="btn">Cancel</button>
                    @else
                        <button wire:click="save" class="btn btn-primary">Add Job Title</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
