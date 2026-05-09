<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\AllEmployees;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AllEmployeesPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_list_has_pagination_and_per_page_control(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $this->createEmployees(25);

        Livewire::test(AllEmployees::class)
            ->assertSee('20 per page')
            ->assertSee('Showing 1 - 20 of 25 employees')
            ->assertSee('Employee 001')
            ->assertDontSee('Employee 021')
            ->set('perPage', 10)
            ->assertSet('perPage', 10)
            ->assertSee('Showing 1 - 10 of 25 employees')
            ->assertDontSee('Employee 011');
    }

    protected function createSuperAdmin(): User
    {
        $role = Role::query()->create([
            'name' => 'super_admin',
            'display_name' => 'Super Admin',
            'is_system' => true,
        ]);

        $user = User::query()->create([
            'full_name' => 'Super Admin',
            'email' => 'super.admin@example.com',
            'staff_id' => 'SA001',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->roles()->attach($role);

        return $user;
    }

    protected function createEmployees(int $count): void
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create([
            'district_name' => 'Accra West District',
            'region_id' => $region->id,
        ]);
        $department = Department::query()->create(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->create(['job_title_name' => 'HR Officer']);

        Employee::withoutEvents(function () use ($count, $region, $district, $department, $jobTitle): void {
            foreach (range(1, $count) as $index) {
                Employee::query()->create([
                    'staff_id' => sprintf('EMP%03d', $index),
                    'full_name' => sprintf('Employee %03d', $index),
                    'gender' => 'Male',
                    'category' => 'Senior Staff',
                    'email' => sprintf('employee%03d@example.com', $index),
                    'job_title_id' => $jobTitle->id,
                    'department_id' => $department->id,
                    'region_id' => $region->id,
                    'district_id' => $district->id,
                    'location_type' => 'District',
                    'date_of_birth' => '1990-01-01',
                    'date_joined' => '2026-05-04',
                ]);
            }
        });
    }
}
