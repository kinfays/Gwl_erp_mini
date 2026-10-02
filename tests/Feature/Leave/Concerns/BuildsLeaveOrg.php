<?php

namespace Tests\Feature\Leave\Concerns;

use App\Enums\StaffGrade;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveWorkflowService;
use Illuminate\Support\Facades\Hash;

/**
 * A small organisation for the leave approval tests: two regions, Head Office (a district, as in the app) and a
 * regional office in Greater Accra, a district in Greater Accra, a regional office in Ashanti, and two
 * departments. Call buildOrg() from setUp(), then create people with staff().
 *
 * Head Office sits in the Greater Accra region on purpose — that is how the real data looks (its staff carry a
 * region_id), and it is what makes "same department, same region" ambiguous between Head Office and the
 * regional office.
 */
trait BuildsLeaveOrg
{
    protected Region $accra;

    protected Region $ashanti;

    protected District $headOffice;

    protected District $accraOffice;

    protected District $temaDistrict;

    protected District $kumasiOffice;

    protected District $kumasiDistrict;

    protected Department $finance;

    protected Department $operations;

    protected function buildOrg(): void
    {
        $this->accra = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->ashanti = Region::query()->create(['region_name' => 'Ashanti']);

        $this->headOffice = District::query()->create(['district_name' => 'Head Office', 'region_id' => $this->accra->id]);
        $this->accraOffice = District::query()->create(['district_name' => 'Accra Regional Office', 'region_id' => $this->accra->id]);
        $this->temaDistrict = District::query()->create(['district_name' => 'Tema District', 'region_id' => $this->accra->id]);
        $this->kumasiOffice = District::query()->create(['district_name' => 'Kumasi Regional Office', 'region_id' => $this->ashanti->id]);
        $this->kumasiDistrict = District::query()->create(['district_name' => 'Obuasi District', 'region_id' => $this->ashanti->id]);

        $this->finance = Department::query()->create(['department_name' => 'Finance']);
        $this->operations = Department::query()->create(['department_name' => 'Operations']);
    }

    /**
     * An employee with a linked user holding $roles. location_type follows the district name, as
     * Employee::boot() does (the model events are off so no invite email is sent).
     *
     * @param  list<string>  $roles
     */
    protected function staff(
        string $staffId,
        District $district,
        Department $department,
        array $roles = [],
        ?string $unit = null,
        bool $employeeActive = true,
        bool $userActive = true,
        ?string $grade = null,
        ?string $joined = null,
    ): Employee {
        $name = strtolower($district->district_name);
        $locationType = str_contains($name, 'head office') ? 'HeadOffice' : (str_contains($name, 'regional office') ? 'Region' : 'District');

        $employee = Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Employee '.$staffId,
            'gender' => 'Female',
            'grade' => $grade,
            'category' => $grade ? StaffGrade::from($grade)->category() : 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => $department->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => $locationType,
            'unit' => $unit,
            'date_of_birth' => '1990-01-01',
            'date_joined' => $joined ?? '2020-01-06',
            'is_active' => $employeeActive,
        ]));

        $user = User::query()->create([
            'staff_id' => $employee->staff_id,
            'employee_id' => $employee->id,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'password' => Hash::make('abc12'),
            'is_active' => $userActive,
            'must_change_password' => false,
        ]);

        foreach ($roles as $role) {
            $user->roles()->attach(Role::query()->firstOrCreate(
                ['name' => $role],
                ['display_name' => str($role)->replace('_', ' ')->title()->toString(), 'is_system' => true]
            ));
        }

        return $employee;
    }

    protected function userOf(Employee $employee): User
    {
        return User::query()->where('employee_id', $employee->id)->firstOrFail();
    }

    protected function workflow(): LeaveWorkflowService
    {
        return app(LeaveWorkflowService::class);
    }

    protected function leaveData(array $overrides = []): array
    {
        return [
            'leave_type' => 'Annual',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-07',
            'leave_details' => 'Family visit',
            ...$overrides,
        ];
    }

    protected function submitLeave(Employee $applicant, array $overrides = []): LeaveRequest
    {
        return $this->workflow()->submit($applicant, $this->leaveData($overrides));
    }
}
