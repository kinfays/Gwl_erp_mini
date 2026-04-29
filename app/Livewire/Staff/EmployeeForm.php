<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use Illuminate\Support\Arr;
use Livewire\Component;

class EmployeeForm extends Component
{
    use EnforcesModuleAccess;

    public ?Employee $employee = null;

    public string $staff_id = '';
    public string $full_name = '';
    public string $gender = 'Male';
    public string $date_of_birth = '';
    public string $date_joined = '';
    public string $category = 'Senior Staff';
    public int|string $job_title_id = '';
    public int|string $department_id = '';
    public string $unit = '';
    public int|string $district_id = '';
    public ?int $region_id = null;
    public string $present_appointment = '';
    public string $email = '';
    public string $jobTitleSearch = '';
    public string $districtSearch = '';

    public function mount(?Employee $employee = null): void
    {
        $this->enforceLivewireModule('staff');

        $this->employee = $employee?->loadMissing([
            'district.region',
            'leaveBalances' => fn ($query) => $query->where('current_year', now()->year),
        ]);

        if (! $this->employee) {
            return;
        }

        $this->staff_id = $this->employee->staff_id;
        $this->full_name = $this->employee->full_name;
        $this->gender = $this->employee->gender;
        $this->date_of_birth = optional($this->employee->date_of_birth)->toDateString() ?? '';
        $this->date_joined = optional($this->employee->date_joined)->toDateString() ?? '';
        $this->category = $this->employee->category;
        $this->job_title_id = $this->employee->job_title_id;
        $this->department_id = $this->employee->department_id;
        $this->unit = $this->employee->unit ?? '';
        $this->district_id = $this->employee->district_id;
        $this->region_id = $this->employee->region_id;
        $this->present_appointment = $this->employee->present_appointment ?? '';
        $this->email = $this->employee->email;
        $this->jobTitleSearch = $this->employee->jobTitle?->job_title_name ?? '';
        $this->districtSearch = $this->employee->district?->district_name ?? '';
    }

    public function updatedDistrictId($value): void
    {
        if (! $value) {
            $this->region_id = null;

            return;
        }

        $this->region_id = District::query()->whereKey($value)->value('region_id');
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $validated['region_id'] = District::query()->whereKey($validated['district_id'])->value('region_id');
        $validated['date_joined'] = $validated['date_joined'] ?: null;
        $validated['present_appointment'] = $validated['present_appointment'] ?: null;
        $validated['unit'] = $validated['unit'] ?: null;

        $oldValues = $this->employee
            ? Arr::only($this->employee->toArray(), array_keys($validated))
            : null;

        if ($this->employee) {
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
        return view('livewire.staff.employee-form', [
            'departments' => Department::query()->orderBy('department_name')->get(),
            'jobTitles' => JobTitle::query()
                ->search($this->jobTitleSearch)
                ->orderBy('job_title_name')
                ->limit(40)
                ->get(),
            'districts' => District::query()
                ->with('region')
                ->search($this->districtSearch)
                ->orderBy('district_name')
                ->limit(40)
                ->get(),
            'selectedRegionName' => $this->region_id
                ? optional(District::query()->with('region')->find($this->district_id)?->region)->region_name
                : null,
            'age' => $this->date_of_birth ? \Carbon\Carbon::parse($this->date_of_birth)->age : null,
            'leaveBalances' => $this->employee?->leaveBalances ?? collect(),
        ]);
    }

    protected function rules(): array
    {
        $employeeId = $this->employee?->id;

        return [
            'staff_id' => ['required', 'string', 'max:50', 'unique:employees,staff_id,' . $employeeId],
            'full_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:Male,Female'],
            'date_of_birth' => ['required', 'date'],
            'date_joined' => ['nullable', 'date'],
            'category' => ['required', 'in:Senior Staff,Junior Staff,Management,Senior Management,Charwoman'],
            'job_title_id' => ['required', 'exists:job_titles,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'unit' => ['nullable', 'string', 'max:255'],
            'district_id' => ['required', 'exists:districts,id'],
            'present_appointment' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:employees,email,' . $employeeId],
        ];
    }
}
