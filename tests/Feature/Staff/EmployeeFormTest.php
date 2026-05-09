<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\EmployeeForm;
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

class EmployeeFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_district_can_be_changed_without_losing_component_property(): void
    {
        $this->actingAs($this->createSuperAdmin());

        [$employee, $newRegion, $newDistrict] = $this->createEmployeeWithAlternateDistrict();

        Livewire::test(EmployeeForm::class, ['employee' => $employee])
            ->set('district_id', $newDistrict->id)
            ->assertSet('district_id', $newDistrict->id)
            ->assertSet('region_id', $newRegion->id)
            ->assertSee($newRegion->region_name);
    }

    public function test_district_combobox_can_temporarily_clear_selection(): void
    {
        $this->actingAs($this->createSuperAdmin());

        [$employee] = $this->createEmployeeWithAlternateDistrict();

        Livewire::test(EmployeeForm::class, ['employee' => $employee])
            ->set('district_id', '')
            ->assertSet('district_id', null)
            ->assertSet('region_id', null)
            ->assertSee('Auto-filled from district');
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

    protected function createEmployeeWithAlternateDistrict(): array
    {
        $currentRegion = Region::query()->create(['region_name' => 'Greater Accra']);
        $currentDistrict = District::query()->create([
            'district_name' => 'Accra Central District',
            'region_id' => $currentRegion->id,
        ]);
        $newRegion = Region::query()->create(['region_name' => 'Ashanti']);
        $newDistrict = District::query()->create([
            'district_name' => 'Kumasi District',
            'region_id' => $newRegion->id,
        ]);
        $department = Department::query()->create(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->create(['job_title_name' => 'HR Officer']);

        $employee = Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => '123456',
            'full_name' => 'Frank Bin Frank',
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => 'fbfra@test.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $currentRegion->id,
            'district_id' => $currentDistrict->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
            'present_appointment' => '2024-01-15',
        ]));

        return [$employee, $newRegion, $newDistrict];
    }
}
