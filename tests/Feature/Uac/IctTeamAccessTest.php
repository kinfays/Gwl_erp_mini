<?php

namespace Tests\Feature\Uac;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IctTeamAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_role_only_contributes_leave_and_uac_modules(): void
    {
        $admin = $this->createRole(User::ROLE_ADMIN, [Permission::MODULE_UAC, Permission::MODULE_STAFF]);
        $user = $this->createUser('ADM001');
        $user->roles()->attach($admin);

        $this->assertEqualsCanonicalizing(
            [Permission::MODULE_LEAVE, Permission::MODULE_UAC],
            $user->getAccessibleModules()
        );
    }

    public function test_ict_user_list_is_scoped_to_actor_region(): void
    {
        $ict = $this->createRole(User::ROLE_ICT_TEAM, [Permission::MODULE_UAC]);
        $regionA = Region::query()->create(['region_name' => 'Greater Accra']);
        $regionB = Region::query()->create(['region_name' => 'Ashanti']);

        $actor = $this->createUserForEmployee($this->createEmployee($regionA, 'ICT001', 'ict@example.com'));
        $actor->roles()->attach($ict);
        $regionalUser = $this->createUserForEmployee($this->createEmployee($regionA, 'REG001', 'regional@example.com'));
        $otherUser = $this->createUserForEmployee($this->createEmployee($regionB, 'OTH001', 'other@example.com'));

        $response = $this->actingAs($actor)->get(route('uac.users'));

        $response
            ->assertOk()
            ->assertSee($regionalUser->email)
            ->assertDontSee($otherUser->email);
    }

    public function test_ict_user_cannot_assign_roles_to_self(): void
    {
        $ict = $this->createRole(User::ROLE_ICT_TEAM, [Permission::MODULE_UAC]);
        $manager = $this->createRole('manager', [Permission::MODULE_LEAVE]);
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $actor = $this->createUserForEmployee($this->createEmployee($region, 'ICT001', 'ict@example.com'));
        $actor->roles()->attach($ict);

        $response = $this
            ->actingAs($actor)
            ->patch(route('uac.users.update', $actor), [
                'roles' => [$manager->id],
            ]);

        $response->assertForbidden();
        $this->assertFalse($actor->fresh()->hasRoles('manager'));
    }

    public function test_only_admin_or_super_admin_can_assign_admin_role(): void
    {
        $ict = $this->createRole(User::ROLE_ICT_TEAM, [Permission::MODULE_UAC]);
        $admin = $this->createRole(User::ROLE_ADMIN, [Permission::MODULE_UAC]);
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $actor = $this->createUserForEmployee($this->createEmployee($region, 'ICT001', 'ict@example.com'));
        $actor->roles()->attach($ict);
        $target = $this->createUserForEmployee($this->createEmployee($region, 'REG001', 'regional@example.com'));

        $this
            ->actingAs($actor)
            ->from(route('uac.users'))
            ->patch(route('uac.users.update', $target), [
                'roles' => [$admin->id],
            ])
            ->assertRedirect(route('uac.users'))
            ->assertSessionHasErrors('roles.0');

        $adminActor = $this->createUserForEmployee($this->createEmployee($region, 'ADM001', 'admin@example.com'));
        $adminActor->roles()->attach($admin);

        $this
            ->actingAs($adminActor)
            ->from(route('uac.users'))
            ->patch(route('uac.users.update', $target), [
                'roles' => [$admin->id],
            ])
            ->assertRedirect(route('uac.users'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRoles(User::ROLE_ADMIN));
    }

    public function test_admin_can_unassign_all_roles_from_a_user(): void
    {
        $admin = $this->createRole(User::ROLE_ADMIN, [Permission::MODULE_UAC]);
        $manager = $this->createRole('manager', [Permission::MODULE_LEAVE]);
        $region = Region::query()->create(['region_name' => 'Greater Accra']);

        $adminActor = $this->createUserForEmployee($this->createEmployee($region, 'ADM001', 'admin@example.com'));
        $adminActor->roles()->attach($admin);

        $target = $this->createUserForEmployee($this->createEmployee($region, 'MGR001', 'manager@example.com'));
        $target->roles()->attach($manager);

        $this
            ->actingAs($adminActor)
            ->from(route('uac.users'))
            ->patch(route('uac.users.update', $target), [])
            ->assertRedirect(route('uac.users'))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $target->fresh()->roles()->count());
    }

    public function test_employee_role_is_not_returned_as_a_visible_role_tag(): void
    {
        $admin = $this->createRole(User::ROLE_ADMIN, [Permission::MODULE_UAC]);
        $employee = $this->createRole(User::ROLE_EMPLOYEE, [Permission::MODULE_LEAVE]);
        $manager = $this->createRole('manager', [Permission::MODULE_LEAVE]);
        $actor = $this->createUser('ADM001');
        $actor->roles()->attach($admin);
        $target = $this->createUser('USR001');
        $target->roles()->attach([$employee->id, $manager->id]);

        $response = $this->actingAs($actor)->getJson(route('uac.users.show', $target));

        $response
            ->assertOk()
            ->assertJsonMissing(['name' => User::ROLE_EMPLOYEE])
            ->assertJsonFragment(['name' => 'manager']);
    }

    protected function createRole(string $name, array $modules = []): Role
    {
        $role = Role::query()->create([
            'name' => $name,
            'display_name' => str($name)->replace('_', ' ')->title()->toString(),
            'is_system' => true,
        ]);

        foreach (Permission::MODULES as $module) {
            ModuleAccess::query()->create([
                'role_id' => $role->id,
                'module' => $module,
                'can_access' => in_array($module, $modules, true),
            ]);
        }

        return $role;
    }

    protected function createUser(string $staffId): User
    {
        return User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('abc12'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    protected function createUserForEmployee(Employee $employee): User
    {
        return User::query()->create([
            'staff_id' => $employee->staff_id,
            'employee_id' => $employee->id,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'password' => Hash::make('abc12'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    protected function createEmployee(Region $region, string $staffId, string $email): Employee
    {
        return Employee::withoutEvents(function () use ($region, $staffId, $email) {
            $district = District::query()->create([
                'district_name' => $staffId.' District',
                'region_id' => $region->id,
            ]);
            $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
            $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

            return Employee::query()->create([
                'staff_id' => $staffId,
                'full_name' => 'Employee '.$staffId,
                'gender' => 'Male',
                'category' => 'Senior Staff',
                'email' => $email,
                'job_title_id' => $jobTitle->id,
                'department_id' => $department->id,
                'region_id' => $region->id,
                'district_id' => $district->id,
                'location_type' => 'District',
                'date_of_birth' => '1990-01-01',
                'date_joined' => '2026-05-04',
            ]);
        });
    }
}
