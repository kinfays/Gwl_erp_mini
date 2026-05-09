<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DefaultPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_default_password_is_redirected_to_profile(): void
    {
        $user = $this->createDefaultPasswordUser();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('status', 'Please change your default password before continuing.');
    }

    public function test_changing_password_clears_default_password_gate(): void
    {
        $user = $this->createDefaultPasswordUser();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->put(route('password.update'), [
                'current_password' => User::DEFAULT_PASSWORD,
                'password' => 'abc12',
                'password_confirmation' => 'abc12',
            ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('abc12', $user->fresh()->password));
    }

    public function test_employee_account_cannot_delete_itself(): void
    {
        $user = $this->createDefaultPasswordUser([
            'password' => Hash::make('abc12'),
            'must_change_password' => false,
        ]);
        $employeeRole = Role::query()->create([
            'name' => 'employee',
            'display_name' => 'Employee',
            'is_system' => true,
        ]);
        $user->roles()->attach($employeeRole);

        $response = $this->actingAs($user)->delete(route('profile.destroy'), [
            'password' => 'abc12',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }

    public function test_profile_shows_employee_details_without_name_or_delete_controls(): void
    {
        $employee = Employee::withoutEvents(fn () => $this->createEmployee());
        $user = $this->createDefaultPasswordUser([
            'staff_id' => $employee->staff_id,
            'email' => $employee->email,
            'employee_id' => $employee->id,
            'password' => Hash::make('abc12'),
            'must_change_password' => false,
        ]);
        $employeeRole = Role::query()->create([
            'name' => 'employee',
            'display_name' => 'Employee',
            'is_system' => true,
        ]);
        $user->roles()->attach($employeeRole);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response
            ->assertOk()
            ->assertSee('Employee Details')
            ->assertSee($employee->full_name)
            ->assertSee('Save Email')
            ->assertSee('Save Password')
            ->assertSee('Employee accounts are managed by UAC')
            ->assertDontSee('name="name"', false)
            ->assertDontSee('>Delete Account</button>', false);
    }

    protected function createDefaultPasswordUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'staff_id' => 'STF001',
            'email' => 'staff@example.com',
            'password' => Hash::make(User::DEFAULT_PASSWORD),
            'is_active' => true,
            'must_change_password' => true,
        ], $overrides));
    }

    protected function createEmployee(): Employee
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create([
            'district_name' => 'Accra West Regional Office',
            'region_id' => $region->id,
        ]);
        $department = Department::query()->create(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->create(['job_title_name' => 'HR Officer']);

        return Employee::query()->create([
            'staff_id' => 'STF002',
            'full_name' => 'Frank Bin Frank',
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => 'employee@example.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'location_type' => 'Region',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
        ]);
    }
}
