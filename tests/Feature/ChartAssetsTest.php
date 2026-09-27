<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StaffRolePermissionSeeder;
use Database\Seeders\TransportRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chart.js is bundled by Vite (version-locked in package-lock.json) and loaded only by the
 * components that draw charts, never from a CDN.
 */
class ChartAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            StaffRolePermissionSeeder::class,
            TransportRolePermissionSeeder::class,
        ]);
    }

    public function test_chart_pages_load_the_bundled_chart_library_instead_of_a_cdn(): void
    {
        $hr = $this->userWithRole('HR001', 'hr_headoffice');

        $pages = [
            [$hr, route('leave.home')],
            [$hr, route('staff.reports')],
            [$this->managerWithEmployee(), route('leave.team-dashboard')],
            [$this->userWithRole('TRM001', 'transport_manager'), route('transport.reports')],
        ];

        foreach ($pages as [$user, $url]) {
            $this->visit($user, $url)
                ->assertOk()
                ->assertSee($this->chartsBundle(), false)
                ->assertDontSee('cdn.jsdelivr.net', false)
                ->assertDontSee('new ApexCharts', false);
        }
    }

    public function test_pages_without_charts_do_not_load_the_chart_library(): void
    {
        $this->visit($this->userWithRole('HR001', 'hr_headoffice'), route('staff.index'))
            ->assertOk()
            ->assertDontSee($this->chartsBundle(), false)
            ->assertDontSee('cdn.jsdelivr.net', false);
    }

    protected function visit(User $user, string $url): TestResponse
    {
        // Livewire keeps @assets in static state between requests of one test process; a real
        // request always starts clean, so reset it to test each page on its own.
        Livewire::flushState();

        return $this->actingAs($user)->get($url);
    }

    protected function chartsBundle(): string
    {
        return Vite::asset('resources/js/charts.js');
    }

    protected function userWithRole(string $staffId, string $role): User
    {
        $user = User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    protected function managerWithEmployee(): User
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create(['district_name' => 'Accra Central District', 'region_id' => $region->id]);

        $employee = Employee::query()->create([
            'staff_id' => 'MGR001',
            'full_name' => 'District Manager',
            'gender' => 'Male',
            'category' => 'Management',
            'email' => 'district.manager@example.com',
            'job_title_id' => JobTitle::query()->create(['job_title_name' => 'District Manager'])->id,
            'department_id' => Department::query()->create(['department_name' => 'Operations'])->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'date_of_birth' => '1980-01-01',
        ]);

        // EmployeeObserver creates the linked login account.
        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
        $user->update(['must_change_password' => false]);
        $user->roles()->attach(Role::query()->where('name', 'district_manager')->firstOrFail());

        return $user;
    }
}
