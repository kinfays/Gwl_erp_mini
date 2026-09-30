<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\JobTitlesManager;
use App\Livewire\Staff\RegionsManager;
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

class StaffDataManagersTest extends TestCase
{
    use RefreshDatabase;

    public function test_regions_can_be_managed_manually(): void
    {
        $this->actingAs($this->createSuperAdmin());

        Livewire::test(RegionsManager::class)
            ->set('region_name', 'Greater Accra')
            ->call('save')
            ->assertHasNoErrors();

        $region = Region::query()->where('region_name', 'Greater Accra')->firstOrFail();

        Livewire::test(RegionsManager::class)
            ->call('edit', $region->id)
            ->set('editingName', 'Greater Accra Region')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('regions', [
            'id' => $region->id,
            'region_name' => 'Greater Accra Region',
        ]);
    }

    public function test_the_regions_screen_no_longer_has_an_hr_email(): void
    {
        $this->actingAs($this->createSuperAdmin());
        Region::query()->create(['region_name' => 'Ashanti']);

        // HR emails for leave live on the Leave module's HR Contacts screen now.
        Livewire::test(RegionsManager::class)
            ->assertDontSee('HR Email')
            ->assertSee('Ashanti')
            ->call('edit', Region::query()->value('id'))
            ->assertDontSee('HR Email');
    }

    public function test_the_region_import_template_only_asks_for_the_region_name_and_ignores_an_old_hr_email_column(): void
    {
        $service = app(\App\Services\Import\DataImportService::class);

        $template = $service->templateExport('regions');
        $this->assertSame(['region_name'], $template->headings());
        $this->assertSame([['Greater Accra']], $template->array());

        // A file exported from before still carries hr_email: it is neither validated nor stored.
        $validate = new \ReflectionMethod($service, 'validateRow');
        $validate->setAccessible(true);
        [$row, $errors] = $validate->invoke($service, 'regions', ['region_name' => 'Volta', 'hr_email' => 'not-an-email'], 2);
        $this->assertSame([], $errors);

        $this->assertSame(['created' => 1, 'updated' => 0, 'processed' => 1], $service->run('regions', [$row]));
        $this->assertDatabaseHas('regions', ['region_name' => 'Volta']);
        $this->assertSame(['created' => 0, 'updated' => 1, 'processed' => 1], $service->run('regions', [$row]));
    }

    public function test_region_delete_is_blocked_when_locations_are_assigned(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $region = Region::query()->create(['region_name' => 'Ashanti']);

        District::query()->create([
            'district_name' => 'Kumasi District',
            'region_id' => $region->id,
        ]);

        Livewire::test(RegionsManager::class)
            ->call('delete', $region->id)
            ->assertHasErrors(['region_name']);

        $this->assertDatabaseHas('regions', ['id' => $region->id]);
    }

    public function test_job_titles_can_be_managed_manually(): void
    {
        $this->actingAs($this->createSuperAdmin());

        Livewire::test(JobTitlesManager::class)
            ->set('job_title_name', 'HR Officer')
            ->call('save')
            ->assertHasNoErrors();

        $jobTitle = JobTitle::query()->where('job_title_name', 'HR Officer')->firstOrFail();

        Livewire::test(JobTitlesManager::class)
            ->call('edit', $jobTitle->id)
            ->set('editingName', 'Senior HR Officer')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('job_titles', [
            'id' => $jobTitle->id,
            'job_title_name' => 'Senior HR Officer',
        ]);
    }

    public function test_job_title_delete_is_blocked_when_employees_are_assigned(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->createEmployee();

        Livewire::test(JobTitlesManager::class)
            ->call('delete', $employee->job_title_id)
            ->assertHasErrors(['job_title_name']);

        $this->assertDatabaseHas('job_titles', ['id' => $employee->job_title_id]);
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

    protected function createEmployee(): Employee
    {
        $region = Region::query()->create(['region_name' => 'Central']);
        $district = District::query()->create([
            'district_name' => 'Cape Coast District',
            'region_id' => $region->id,
        ]);
        $department = Department::query()->create(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->create(['job_title_name' => 'Administrator']);

        return Employee::withoutEvents(fn () => Employee::query()->create([
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
        ]));
    }
}
