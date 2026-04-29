<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\Department;
use App\Support\ErpNavigation;
use Livewire\Component;

class DepartmentsManager extends Component
{
    use EnforcesModuleAccess;

    public string $department_name = '';
    public ?int $editingId = null;
    public string $editingName = '';

    public function mount(ErpNavigation $navigation): void
    {
        $this->enforceLivewireModule('staff');

        if (! $navigation->canManageStaff(auth()->user())) {
            abort(403);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'department_name' => ['required', 'string', 'max:255', 'unique:departments,department_name'],
        ]);

        $department = Department::create($validated);

        AuditLog::record('create_department', 'staff', 'departments', $department->id, null, $department->toArray());

        $this->reset('department_name');
        session()->flash('success', 'Department created successfully.');
    }

    public function edit(int $departmentId): void
    {
        $department = Department::findOrFail($departmentId);

        $this->editingId = $department->id;
        $this->editingName = $department->department_name;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editingName');
    }

    public function update(): void
    {
        $department = Department::findOrFail($this->editingId);

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255', 'unique:departments,department_name,' . $department->id],
        ]);

        $old = $department->toArray();
        $department->update([
            'department_name' => $validated['editingName'],
        ]);

        AuditLog::record('update_department', 'staff', 'departments', $department->id, $old, $department->fresh()->toArray());

        $this->reset('editingId', 'editingName');
        session()->flash('success', 'Department updated successfully.');
    }

    public function delete(int $departmentId): void
    {
        $department = Department::withCount('employees')->findOrFail($departmentId);

        if ($department->employees_count > 0) {
            $this->addError('department_name', 'You cannot delete a department that still has employees assigned.');

            return;
        }

        $old = $department->toArray();
        $department->delete();

        AuditLog::record('delete_department', 'staff', 'departments', $departmentId, $old, null);
        session()->flash('success', 'Department deleted successfully.');
    }

    public function render()
    {
        return view('livewire.staff.departments-manager', [
            'departments' => Department::query()
                ->withCount('employees')
                ->orderBy('department_name')
                ->get(),
        ]);
    }
}
