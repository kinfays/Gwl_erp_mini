<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\AllEmployees;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\ModuleAccess;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeDeactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_deactivation_requires_and_stores_reason(): void
    {
        Notification::fake();
        $this->actingAs($this->createStaffUser());
        $employee = $this->createEmployee();

        $this->patch(route('staff.toggle-status', $employee))
            ->assertSessionHasErrors('deactivation_reason');

        $this->patch(route('staff.toggle-status', $employee), [
            'deactivation_reason' => 'retired',
        ])->assertRedirect();

        $employee->refresh();

        $this->assertFalse($employee->is_active);
        $this->assertSame('retired', $employee->deactivation_reason);
        $this->assertSame(today()->toDateString(), $employee->deactivated_at->toDateString());
        $this->assertFalse($employee->user()->first()->is_active);

        Livewire::test(AllEmployees::class)
            ->set('status', 'inactive')
            ->assertSee('Deactivated')
            ->assertSee('Reason: Retirement');
    }

    public function test_reactivating_employee_clears_reason_and_restores_login_access(): void
    {
        Notification::fake();
        $this->actingAs($this->createStaffUser());
        $employee = $this->createEmployee([
            'is_active' => false,
            'deactivation_reason' => 'left',
        ]);

        $this->patch(route('staff.toggle-status', $employee))
            ->assertRedirect();

        $employee->refresh();

        $this->assertTrue($employee->is_active);
        $this->assertNull($employee->deactivation_reason);
        $this->assertNull($employee->deactivated_at);
        $this->assertTrue($employee->user()->first()->is_active);
    }

    public function test_deactivated_employee_cannot_login(): void
    {
        Notification::fake();
        $this->actingAs($this->createStaffUser());
        $employee = $this->createEmployee();

        $employee->user()->first()->update(['password' => Hash::make('password')]);

        $this->patch(route('staff.toggle-status', $employee), [
            'deactivation_reason' => 'dead',
        ])->assertRedirect();

        auth()->logout();

        $this->post(route('login'), [
            'staff_id' => $employee->staff_id,
            'password' => 'password',
        ])->assertSessionHasErrors([
            'staff_id' => "You don't have access, contact Administrator.",
        ]);
    }

    public function test_staff_module_user_can_view_user_details_from_employee_page(): void
    {
        Notification::fake();
        $viewer = $this->createStaffUser();
        $employee = $this->createEmployee();

        $this->actingAs($viewer)
            ->getJson(route('staff.users.show', $employee->user()->first()))
            ->assertOk()
            ->assertJsonPath('employee.full_name', $employee->full_name)
            ->assertJsonPath('employee.retirement_date', '2050-01-01')
            ->assertJsonPath('user.staff_id', $employee->staff_id);
    }

    protected function createStaffUser(): User
    {
        $role = Role::query()->create([
            'name' => 'hr_headoffice',
            'display_name' => 'HR Head Office',
            'is_system' => true,
        ]);

        ModuleAccess::query()->create([
            'role_id' => $role->id,
            'module' => 'staff',
            'can_access' => true,
        ]);

        $user = User::query()->create([
            'full_name' => 'HR User',
            'email' => 'hr.user@example.com',
            'staff_id' => 'HR001',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->roles()->attach($role);

        return $user;
    }

    protected function createEmployee(array $overrides = []): Employee
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create([
            'district_name' => 'Accra Central District',
            'region_id' => $region->id,
        ]);
        $department = Department::query()->create(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->create(['job_title_name' => 'HR Officer']);

        return Employee::query()->create(array_merge([
            'staff_id' => '123456',
            'full_name' => 'Frank Bin Frank',
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => 'fbfra@test.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
            'present_appointment' => '2024-01-15',
            'is_active' => true,
        ], $overrides));
    }
}
