<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Departments</h2>
            <p>Manage department names used across staff records and leave workflows.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="erp-card" style="margin-bottom:14px;background:#eaf7ef;border-color:#b8e0c5;color:#21633c;">
            {{ session('success') }}
        </div>
    @endif

    @error('department_name')
        <div class="erp-card" style="margin-bottom:14px;background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ $message }}
        </div>
    @enderror

    <div class="two">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Department Directory</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Employees</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($departments as $department)
                        <tr>
                            <td>{{ $department->department_name }}</td>
                            <td>{{ $department->employees_count }}</td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <button wire:click="edit({{ $department->id }})" class="actn">Edit</button>
                                    <button wire:click="delete({{ $department->id }})" class="actn actn-r">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="text-align:center;color:var(--color-text-secondary)">No departments found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">{{ $editingId ? 'Edit Department' : 'Add Department' }}</span>
            </div>

            <div style="padding:14px">
                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Department Name</label>
                    <input
                        type="text"
                        wire:model.defer="{{ $editingId ? 'editingName' : 'department_name' }}"
                        class="form-input"
                        placeholder="Enter department name"
                    >
                </div>

                <div style="display:flex;gap:8px;justify-content:flex-end">
                    @if ($editingId)
                        <button wire:click="update" class="btn btn-primary">Save</button>
                        <button wire:click="cancelEdit" class="btn">Cancel</button>
                    @else
                        <button wire:click="save" class="btn btn-primary">Add Department</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
