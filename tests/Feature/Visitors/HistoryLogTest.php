<?php

namespace Tests\Feature\Visitors;

use App\Exports\Visitors\VisitorsExport;
use App\Livewire\Visitors\HistoryLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class HistoryLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_log_filters_visitors_by_selected_date_range(): void
    {
        $this->actingAs($this->createReceptionistUser());
        $staff = $this->createEmployee();

        $this->createVisitor($staff, [
            'visitor_name' => 'Before Range Visitor',
            'check_in_at' => '2026-05-01 09:00:00',
        ]);
        $this->createVisitor($staff, [
            'visitor_name' => 'Inside Range Visitor',
            'check_in_at' => '2026-05-03 09:00:00',
        ]);
        $this->createVisitor($staff, [
            'visitor_name' => 'Range End Visitor',
            'check_in_at' => '2026-05-04 17:30:00',
        ]);
        $this->createVisitor($staff, [
            'visitor_name' => 'After Range Visitor',
            'check_in_at' => '2026-05-05 09:00:00',
        ]);

        Livewire::test(HistoryLog::class)
            ->set('startDate', '2026-05-02')
            ->set('endDate', '2026-05-04')
            ->assertSee('Inside Range Visitor')
            ->assertSee('Range End Visitor')
            ->assertDontSee('Before Range Visitor')
            ->assertDontSee('After Range Visitor');
    }

    public function test_excel_export_uses_selected_date_range(): void
    {
        Excel::fake();

        $this->actingAs($this->createReceptionistUser());
        $staff = $this->createEmployee();

        $this->createVisitor($staff, [
            'visitor_name' => 'Exported Range Visitor',
            'check_in_at' => '2026-05-03 10:00:00',
        ]);
        $this->createVisitor($staff, [
            'visitor_name' => 'Skipped Export Visitor',
            'check_in_at' => '2026-05-05 10:00:00',
        ]);

        $this->get(route('visitors.export.excel', [
            'start_date' => '2026-05-02',
            'end_date' => '2026-05-04',
        ]))->assertOk();

        Excel::assertDownloaded('visitors_2026_05_02_to_2026_05_04.xlsx', function (VisitorsExport $export) {
            $names = $export->collection()->pluck('visitor_name');

            return $names->contains('Exported Range Visitor')
                && ! $names->contains('Skipped Export Visitor');
        });
    }

    protected function createReceptionistUser(): User
    {
        $role = Role::query()->create([
            'name' => 'receptionist',
            'display_name' => 'Receptionist',
            'is_system' => true,
        ]);

        ModuleAccess::query()->create([
            'role_id' => $role->id,
            'module' => 'visitors',
            'can_access' => true,
        ]);

        $permission = Permission::query()->create([
            'name' => 'visitors.export',
            'display_name' => 'Export Visitors',
            'module' => 'visitors',
        ]);

        $role->permissions()->attach($permission);

        $user = User::query()->create([
            'full_name' => 'Reception Desk',
            'email' => 'reception@example.com',
            'staff_id' => 'REC001',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->roles()->attach($role);

        return $user;
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
            'location_type' => 'Region',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
        ]));
    }

    protected function createVisitor(Employee $staff, array $overrides = []): Visitor
    {
        return Visitor::query()->create(array_merge([
            'visitor_name' => 'Test Visitor',
            'phone' => '0200000000',
            'staff_id' => $staff->id,
            'purpose' => 'Meeting',
            'signature' => 'data:image/png;base64,test',
            'checkout_code' => '123',
            'check_in_at' => '2026-05-03 09:00:00',
        ], $overrides));
    }
}
