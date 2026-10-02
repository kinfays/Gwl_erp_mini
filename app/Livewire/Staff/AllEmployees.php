<?php

namespace App\Livewire\Staff;

use App\Enums\StaffGrade;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Staff\EmployeeDirectory;
use App\Support\ErpNavigation;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AllEmployees extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    // In the URL so a card on a dashboard can link straight to a filtered list.
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public int|string $department_id = '';

    #[Url(except: '')]
    public int|string $region_id = '';

    #[Url(except: '')]
    public string $category = '';

    /** A StaffGrade value, or "none" for staff who have no grade yet. */
    #[Url(except: '')]
    public string $grade = '';

    #[Url(except: '')]
    public string $location_type = '';

    #[Url(except: '')]
    public string $status = '';

    public int $perPage = 20;

    public array $perPageOptions = [10, 20, 50, 100];

    public function mount(): void
    {
        $this->enforceLivewireModule('staff');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'department_id', 'region_id', 'category', 'grade', 'location_type', 'status', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function updatedPerPage($value): void
    {
        $this->perPage = in_array((int) $value, $this->perPageOptions, true)
            ? (int) $value
            : 20;
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
            'categories' => StaffGrade::categories(),
            'gradeGroups' => collect(StaffGrade::cases())
                ->groupBy(fn (StaffGrade $grade) => $grade->category())
                ->map(fn ($grades) => $grades->map(fn (StaffGrade $grade) => $grade->value)->all())
                ->all(),
            'canManage' => $navigation->canManageStaff($user),
            'exportUrl' => route('staff.export', $this->filters()),
            'perPageOptions' => $this->perPageOptions,
            'deactivationReasons' => Employee::DEACTIVATION_REASONS,
        ]);
    }

    protected function filters(): array
    {
        return [
            'search' => $this->search,
            'department_id' => $this->department_id,
            'region_id' => $this->region_id,
            'category' => $this->category,
            'grade' => $this->grade,
            'location_type' => $this->location_type,
            'status' => $this->status,
        ];
    }
}
