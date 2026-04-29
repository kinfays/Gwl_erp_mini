<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Services\Staff\EmployeeDirectory;
use App\Support\ErpNavigation;
use Livewire\Component;
use Livewire\WithPagination;

class AllEmployees extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $search = '';
    public int|string $department_id = '';
    public string $category = '';
    public string $location_type = '';
    public string $status = '';
    public int $perPage = 20;

    public function mount(): void
    {
        $this->enforceLivewireModule('staff');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'department_id', 'category', 'location_type', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(EmployeeDirectory $directory, ErpNavigation $navigation)
    {
        $user = auth()->user();

        $employees = $directory
            ->applyFilters($directory->queryFor($user), $this->filters())
            ->orderBy('full_name')
            ->paginate($this->perPage);

        return view('livewire.staff.all-employees', [
            'employees' => $employees,
            'departments' => Department::query()->orderBy('department_name')->get(),
            'categories' => [
                'Senior Staff',
                'Junior Staff',
                'Management',
                'Senior Management',
                'Charwoman',
            ],
            'canManage' => $navigation->canManageStaff($user),
            'exportUrl' => route('staff.export', $this->filters()),
        ]);
    }

    protected function filters(): array
    {
        return [
            'search' => $this->search,
            'department_id' => $this->department_id,
            'category' => $this->category,
            'location_type' => $this->location_type,
            'status' => $this->status,
        ];
    }
}
