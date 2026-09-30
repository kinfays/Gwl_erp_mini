<?php

namespace Tests\Feature\Uac\Concerns;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\LeaveApprovalRolePermissionSeeder;
use Database\Seeders\LettersRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StaffRolePermissionSeeder;
use Database\Seeders\UacRolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * An organisation for the UAC role tests, with the real roles, permissions and classification seeded: Greater
 * Accra holds Head Office (a district, as in the app), the Accra regional office and Tema district; Ashanti holds
 * the Kumasi regional office and Obuasi district. Head Office staff carry Greater Accra's region_id on purpose — it
 * is what makes "same region" ambiguous between Head Office and the regional office.
 *
 * Call buildUacOrg() from setUp(), then create people with person().
 */
trait BuildsUacOrg
{
    protected Region $accra;

    protected Region $ashanti;

    protected District $headOffice;

    protected District $accraOffice;

    protected District $temaDistrict;

    protected District $kumasiOffice;

    protected District $obuasiDistrict;

    protected Department $finance;

    protected function buildUacOrg(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            StaffRolePermissionSeeder::class,
            LettersRolePermissionSeeder::class,
            UacRolePermissionSeeder::class,
            AssetsRolePermissionSeeder::class,
            LeaveApprovalRolePermissionSeeder::class,
        ]);

        $this->accra = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->ashanti = Region::query()->create(['region_name' => 'Ashanti']);

        $this->headOffice = District::query()->create(['district_name' => 'Head Office', 'region_id' => $this->accra->id]);
        $this->accraOffice = District::query()->create(['district_name' => 'Accra Regional Office', 'region_id' => $this->accra->id]);
        $this->temaDistrict = District::query()->create(['district_name' => 'Tema District', 'region_id' => $this->accra->id]);
        $this->kumasiOffice = District::query()->create(['district_name' => 'Kumasi Regional Office', 'region_id' => $this->ashanti->id]);
        $this->obuasiDistrict = District::query()->create(['district_name' => 'Obuasi District', 'region_id' => $this->ashanti->id]);

        $this->finance = Department::query()->create(['department_name' => 'Finance']);
    }

    /**
     * A user with an employee record in $district (location_type follows the district name, as Employee::boot() does;
     * the model events are off so no invite email is sent) holding $roles.
     *
     * @param  list<string>  $roles
     */
    protected function person(string $staffId, District $district, array $roles = [], bool $withEmployee = true): User
    {
        $employee = $withEmployee ? $this->makeEmployee($staffId, $district) : null;

        $user = User::query()->create([
            'staff_id' => $staffId,
            'employee_id' => $employee?->id,
            'full_name' => 'Employee '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('abc12'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        foreach ($roles as $role) {
            $user->roles()->attach($this->role($role));
        }

        return $user->fresh();
    }

    /** An employee record with no account yet (the model events are off, so no user or invite is created). */
    protected function makeEmployee(string $staffId, District $district): Employee
    {
        $name = strtolower($district->district_name);
        $locationType = str_contains($name, 'head office') ? 'HeadOffice' : (str_contains($name, 'regional office') ? 'Region' : 'District');

        return Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Employee '.$staffId,
            'gender' => 'Female',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => $this->finance->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => $locationType,
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2020-01-06',
            'is_active' => true,
        ]));
    }

    protected function role(string $name): Role
    {
        return Role::query()->where('name', $name)->firstOrFail();
    }

    /** A staff-form style move of an employee to another district (events on, so the observer runs). */
    protected function moveTo(User $user, District $district): void
    {
        // A fresh instance each time, as in a real request: Employee::saving reads the loaded district relation.
        Employee::query()->findOrFail($user->employee_id)->update([
            'district_id' => $district->id,
            'region_id' => $district->region_id,
        ]);
    }

    /** @param  list<string>  $roleNames */
    protected function roleIds(array $roleNames): array
    {
        return array_values(array_map(fn (string $name) => $this->role($name)->id, $roleNames));
    }

    protected function patchRoles(User $actor, User $target, array $roleNames)
    {
        return $this->actingAs($actor)
            ->from(route('uac.users'))
            ->patch(route('uac.users.update', $target), ['roles' => $this->roleIds($roleNames)]);
    }
}
