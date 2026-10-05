<?php

namespace App\Livewire\Staff;

use App\Enums\StaffGrade;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\Staff\EmployeeDirectory;
use App\Support\ErpNavigation;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class EmployeeForm extends Component
{
    use EnforcesModuleAccess;

    public ?Employee $employee = null;

    public string $staff_id = '';

    public string $full_name = '';

    /** The honorific (Mr., Ing., Dr., ...), printed before the name on leave approval letters. */
    public string $title = '';

    public string $gender = 'Male';

    public string $date_of_birth = '';

    public string $date_joined = '';

    public string $category = 'Senior Staff';

    /** One of StaffGrade; it fixes the category. Blank only for a record that predates grades. */
    public string $grade = '';

    public ?int $job_title_id = null;

    public ?int $department_id = null;

    public string $unit = '';

    public ?int $district_id = null;

    public ?int $region_id = null;

    public string $present_appointment = '';

    public string $email = '';

    public function mount(?Employee $employee = null): void
    {
        $this->enforceLivewireModule('staff');

        $user = $this->actor();

        abort_unless(app(ErpNavigation::class)->canManageStaff($user), 403);
        abort_if($employee && ! app(EmployeeDirectory::class)->canAccess($user, $employee), 403);
        abort_if(! $employee && $this->assignableRegionIds() === [], 403, 'Your profile needs a region before you can add employees.');

        $this->employee = $employee?->loadMissing([
            'district.region',
            'leaveBalances' => fn ($query) => $query->where('current_year', now()->year),
        ]);

        if (! $this->employee) {
            return;
        }

        $this->staff_id = $this->employee->staff_id;
        $this->full_name = $this->employee->full_name;
        $this->title = (string) ($this->employee->title ?? '');
        $this->gender = $this->employee->gender;
        $this->date_of_birth = optional($this->employee->date_of_birth)->toDateString() ?? '';
        $this->date_joined = optional($this->employee->date_joined)->toDateString() ?? '';
        $this->category = $this->employee->category;
        $this->grade = (string) ($this->employee->grade ?? '');
        $this->job_title_id = $this->employee->job_title_id;
        $this->department_id = $this->employee->department_id;
        $this->unit = $this->employee->unit ?? '';
        $this->district_id = $this->employee->district_id;
        $this->region_id = $this->employee->region_id;
        $this->present_appointment = $this->employee->present_appointment ?? '';
        $this->email = $this->employee->email;
    }

    /** The category follows the grade, so it is shown (not chosen) once a grade is picked. */
    public function updatedGrade($value): void
    {
        if ($grade = StaffGrade::tryFrom((string) $value)) {
            $this->category = $grade->category();
        }
    }

    public function updatedDistrictId($value): void
    {
        $this->district_id = $value ? (int) $value : null;

        if (! $this->district_id) {
            $this->region_id = null;

            return;
        }

        $this->region_id = District::query()->whereKey($this->district_id)->value('region_id');
    }

    public function save()
    {
        abort_if($this->employee && ! app(EmployeeDirectory::class)->canAccess($this->actor(), $this->employee), 403);

        $validated = $this->validate($this->rules(), $this->validationMessages());
        $validated['region_id'] = District::query()->whereKey($validated['district_id'])->value('region_id');
        $validated['date_joined'] = $validated['date_joined'] ?: null;
        $validated['present_appointment'] = $validated['present_appointment'] ?: null;
        $validated['unit'] = $validated['unit'] ?: null;
        $validated['title'] = $validated['title'] ?: null;
        $validated['grade'] = $validated['grade'] ?: null;

        // The grade fixes the category: it is never stored as something else.
        if ($grade = StaffGrade::tryFrom((string) $validated['grade'])) {
            $validated['category'] = $grade->category();
        }

        $oldValues = $this->employee
            ? Arr::only($this->employee->toArray(), array_keys($validated))
            : null;

        if ($this->employee) {
            // One transaction: a transfer out of Head Office also removes the person's Global Admin role
            // (EmployeeObserver), and the move and that removal must stand or fall together.
            $employee = DB::transaction(function () use ($validated, $oldValues) {
                $this->employee->update($validated);
                $employee = $this->employee->fresh(['leaveBalances']);

                AuditLog::record(
                    'update_employee',
                    'staff',
                    'employees',
                    $employee->id,
                    $oldValues,
                    Arr::only($employee->toArray(), array_keys($validated))
                );

                return $employee;
            });

            session()->flash('success', 'Employee updated successfully.');
        } else {
            $employee = Employee::create($validated);

            AuditLog::record(
                'create_employee',
                'staff',
                'employees',
                $employee->id,
                null,
                Arr::only($employee->toArray(), array_keys($validated))
            );

            session()->flash('success', 'Employee created successfully.');
        }

        return redirect()->route('staff.index');
    }

    public function render()
    {
        $regionIds = $this->assignableRegionIds();

        return view('livewire.staff.employee-form', [
            'departmentOptions' => Department::query()
                ->orderBy('department_name')
                ->get(['id', 'department_name'])
                ->map(fn (Department $department) => [
                    'value' => $department->id,
                    'label' => $department->department_name,
                ])
                ->all(),
            'jobTitleOptions' => JobTitle::query()
                ->orderBy('job_title_name')
                ->get(['id', 'job_title_name'])
                ->map(fn (JobTitle $jobTitle) => [
                    'value' => $jobTitle->id,
                    'label' => $jobTitle->job_title_name,
                ])
                ->all(),
            'districtOptions' => District::query()
                ->with('region')
                ->when($regionIds !== null, fn ($query) => $query->whereIn('region_id', $regionIds))
                ->orderBy('district_name')
                ->get(['id', 'district_name', 'region_id'])
                ->map(fn (District $district) => [
                    'value' => $district->id,
                    'label' => $district->district_name,
                    'description' => $district->region?->region_name,
                ])
                ->all(),
            'selectedRegionName' => $this->district_id
                ? optional(District::query()->with('region')->find($this->district_id)?->region)->region_name
                : null,
            'age' => $this->date_of_birth ? Carbon::parse($this->date_of_birth)->age : null,
            'retirementAge' => Employee::retirementAge(),
            'titles' => Employee::TITLES,
            'gradeGroups' => collect(StaffGrade::cases())
                ->groupBy(fn (StaffGrade $grade) => $grade->category())
                ->map(fn ($grades) => $grades->map(fn (StaffGrade $grade) => $grade->value)->all())
                ->all(),
            'gradeCategory' => StaffGrade::tryFrom($this->grade)?->category(),
            'gradeRequired' => $this->gradeIsRequired(),
            'retirementDate' => optional(Employee::retirementDateFromBirthDate($this->date_of_birth))->toDateString(),
            'leaveBalances' => $this->employee?->leaveBalances ?? collect(),
        ]);
    }

    protected function rules(): array
    {
        $employeeId = $this->employee?->id;
        $regionIds = $this->assignableRegionIds();
        $districtRule = Rule::exists('districts', 'id');

        if ($regionIds !== null) {
            $districtRule->whereIn('region_id', $regionIds);
        }

        return [
            'staff_id' => ['required', 'string', 'max:50', 'unique:employees,staff_id,'.$employeeId],
            'title' => ['nullable', Rule::in(Employee::TITLES)],
            'full_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:Male,Female'],
            'date_of_birth' => ['required', 'date'],
            'date_joined' => ['nullable', 'date'],
            // New staff must be graded; an existing record without a grade can still be edited until HR grades it.
            'grade' => [$this->gradeIsRequired() ? 'required' : 'nullable', Rule::in(StaffGrade::values())],
            // The category is derived from the grade; it is only entered for a record that has no grade.
            'category' => [blank($this->grade) ? 'required' : 'nullable', Rule::in(StaffGrade::allCategories())],
            'job_title_id' => ['required', 'exists:job_titles,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'unit' => ['nullable', 'string', 'max:255'],
            'district_id' => ['required', $districtRule],
            'present_appointment' => ['nullable', 'date'],
            'email' => ['required', 'email', 'max:255', 'unique:employees,email,'.$employeeId],
        ];
    }

    protected function gradeIsRequired(): bool
    {
        return ! $this->employee || filled($this->employee->grade);
    }

    protected function validationMessages(): array
    {
        $messages = ['grade.in' => 'Choose a grade from the list.'];

        return $this->assignableRegionIds() === null
            ? $messages
            : $messages + ['district_id.exists' => 'Choose a district in your own region.'];
    }

    protected function assignableRegionIds(): ?array
    {
        return app(EmployeeDirectory::class)->assignableRegionIds($this->actor());
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_unless($user, 403);

        return $user->loadMissing(['roles', 'employee', 'employeeByStaffId']);
    }
}
