<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffGrade;
use App\Exports\Staff\StaffReportExport;
use App\Livewire\Staff\StaffReports;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Staff\StaffReportService;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StaffRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class StaffReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_leave_report_page_is_available_to_hr_and_not_managers(): void
    {
        $this->seedStaffAccess();

        $hr = $this->user('HR001');
        $hr->roles()->attach(Role::query()->where('name', 'hr_headoffice')->firstOrFail());

        $manager = $this->user('MGR001');
        $manager->roles()->attach(Role::query()->where('name', 'manager')->firstOrFail());

        $this->actingAs($hr)
            ->get(route('staff.reports'))
            ->assertOk()
            ->assertSee('Staff Reports')
            ->assertSee('Male Vs Female Total');

        $this->actingAs($manager)
            ->get(route('staff.reports'))
            ->assertForbidden();
    }

    public function test_hr_region_report_counts_only_the_actor_region(): void
    {
        $this->seedStaffAccess();

        $regionA = Region::query()->create(['region_name' => 'Greater Accra']);
        $regionB = Region::query()->create(['region_name' => 'Ashanti']);
        $districtA = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $regionA->id]);
        $districtB = District::query()->create(['district_name' => 'Kumasi Metro', 'region_id' => $regionB->id]);

        $actorEmployee = $this->employee('HRR001', $regionA, $districtA, 'Female');
        $actor = $this->user('HRR001', [
            'employee_id' => $actorEmployee->id,
            'full_name' => $actorEmployee->full_name,
            'email' => $actorEmployee->email,
        ]);
        $actor->roles()->attach(Role::query()->where('name', 'hr_region')->firstOrFail());

        $this->employee('EMP001', $regionA, $districtA, 'Male');
        $onLeave = $this->employee('EMP002', $regionA, $districtA, 'Female');
        $this->employee('EMP003', $regionB, $districtB, 'Male');

        $this->leaveRequest($onLeave, 'Approved', today()->subDay()->toDateString(), today()->addDays(2)->toDateString());

        $this->actingAs($actor);

        Livewire::test(StaffReports::class)
            ->assertSet('payload.statCards.0.value', '3')
            ->assertSet('payload.statCards.1.value', '2')
            ->assertSet('payload.statCards.2.value', '1')
            ->assertSet('payload.statCards.3.value', '1')
            ->assertSee('Greater Accra')
            ->assertDontSee('Kumasi Metro');
    }

    public function test_hr_region_report_requires_an_employee_region_profile(): void
    {
        $this->seedStaffAccess();

        $hr = $this->user('HRNOREG');
        $hr->roles()->attach(Role::query()->where('name', 'hr_region')->firstOrFail());

        $this->actingAs($hr)
            ->get(route('staff.reports'))
            ->assertForbidden();
    }

    public function test_report_still_renders_when_its_payload_comes_back_from_the_cache(): void
    {
        // Production caches the payload in the database store, which serialises it and, because
        // cache.serializable_classes is false, reads any object inside it back as
        // __PHP_Incomplete_Class. The array store the suite normally uses does neither.
        config(['cache.default' => 'database']);

        $this->seedStaffAccess();
        $this->actingAs($this->reportFixtures());

        // First render: cache miss, so the payload is built and stored.
        Livewire::test(StaffReports::class)->assertSee('Accra Central');

        $this->assertTrue(DB::table('cache')->where('key', 'like', '%staff_reports:v2:payload:%')->exists());

        // Second render: cache hit, so the payload is unserialised from the database.
        Livewire::test(StaffReports::class)
            ->assertSet('payload.districtRows.0.district', 'Accra Central')
            ->assertSet('payload.districtRows.0.total', 2)
            ->assertSee('Accra Central');
    }

    public function test_report_payload_holds_only_arrays_and_scalars_so_it_survives_the_cache(): void
    {
        $this->seedStaffAccess();
        $hr = $this->reportFixtures();

        $payload = app(StaffReportService::class)->reportPayload($hr, today()->startOfMonth(), today()->endOfMonth());

        $this->assertNotEmpty($payload['districtRows']);
        $this->assertNotEmpty($payload['currentlyOnLeave']);
        $this->assertCacheSafe($payload, 'payload');
    }

    public function test_the_page_the_navigation_and_the_permission_are_called_staff_reports(): void
    {
        $this->seedStaffAccess();
        $hr = $this->user('HR001');
        $hr->roles()->attach(Role::query()->where('name', 'hr_headoffice')->firstOrFail());

        $this->actingAs($hr)
            ->get(route('staff.reports'))
            ->assertOk()
            ->assertSee('Staff Reports')
            ->assertDontSee('Staff Leave Reports');

        $this->assertSame('View Staff Reports', Permission::query()->where('name', 'staff.view_reports')->value('display_name'));
        // The route and permission keys are unchanged.
        $this->assertSame('/staff/reports', route('staff.reports', absolute: false));
    }

    public function test_the_breakdown_counts_categories_every_level_and_the_staff_with_no_grade(): void
    {
        $this->seedStaffAccess();
        $hr = $this->reportFixtures();
        $region = Region::query()->firstOrFail();
        $district = District::query()->firstOrFail();

        foreach ([['S1', 'Snr. Gd. Level 1'], ['S2', 'Snr. Gd. Level 1'], ['S3', 'Snr. Gd. Level 3'],
            ['J1', 'Junior Gd. Level 2'], ['J2', 'Junior Gd. Level 2'], ['J3', 'Junior Gd. Level 2'], ['J4', 'Junior Gd. Level 6'],
            ['M1', 'Mgt. Gd. Level 4'], ['C1', 'Charwoman']] as [$id, $grade]) {
            $this->employee('B'.$id, $region, $district, 'Female', $grade);
        }
        // Before grades: no grade, with the old categories. "Senior Management" is reported as Management.
        $this->employee('BL1', $region, $district, 'Male', null, 'Senior Management');
        $this->employee('BL2', $region, $district, 'Male', null, 'Junior Staff');
        // Someone who left is not headcount.
        $this->employee('BX1', $region, $district, 'Male', 'Snr. Gd. Level 2', null, false);

        $this->actingAs($hr);
        $breakdown = Livewire::test(StaffReports::class)->get('payload.gradeBreakdown');

        $categories = collect($breakdown['categories'])->pluck('count', 'label')->all();
        // The two fixture employees (EMP101/102) are ungraded "Senior Staff".
        $this->assertSame(['Junior Staff' => 5, 'Senior Staff' => 5, 'Management' => 2, 'Contract' => 1], $categories);
        $this->assertSame(
            ['Snr. Gd. Level 1' => 2, 'Snr. Gd. Level 2' => 0, 'Snr. Gd. Level 3' => 1, 'Snr. Gd. Level 4' => 0],
            collect($breakdown['senior'])->pluck('count', 'grade')->all()
        );
        $this->assertSame(
            ['Junior Gd. Level 1' => 0, 'Junior Gd. Level 2' => 3, 'Junior Gd. Level 3' => 0, 'Junior Gd. Level 4' => 0, 'Junior Gd. Level 5' => 0, 'Junior Gd. Level 6' => 1],
            collect($breakdown['junior'])->pluck('count', 'grade')->all()
        );
        $this->assertSame(
            ['Mgt. Gd. Level 1' => 0, 'Mgt. Gd. Level 2' => 0, 'Mgt. Gd. Level 3' => 0, 'Mgt. Gd. Level 4' => 1],
            collect($breakdown['management'])->pluck('count', 'grade')->all()
        );
        $this->assertSame(4, $breakdown['no_grade']['count']); // EMP101, EMP102, BL1, BL2
        $this->assertSame(13, $breakdown['total']);
        $this->assertCacheSafe($breakdown, 'gradeBreakdown');
    }

    public function test_the_breakdown_respects_the_viewers_scope_and_hides_super_admin(): void
    {
        $this->seedStaffAccess();
        $accra = Region::query()->create(['region_name' => 'Greater Accra']);
        $ashanti = Region::query()->create(['region_name' => 'Ashanti']);
        $accraDistrict = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $accra->id]);
        $ashantiDistrict = District::query()->create(['district_name' => 'Kumasi Metro', 'region_id' => $ashanti->id]);

        $actorEmployee = $this->employee('HRR001', $accra, $accraDistrict, 'Female', 'Snr. Gd. Level 2');
        $regionalHr = $this->user('HRR001', ['employee_id' => $actorEmployee->id, 'full_name' => $actorEmployee->full_name, 'email' => $actorEmployee->email]);
        $regionalHr->roles()->attach(Role::query()->where('name', 'hr_region')->firstOrFail());

        $this->employee('A1', $accra, $accraDistrict, 'Male', 'Snr. Gd. Level 2');
        $this->employee('K1', $ashanti, $ashantiDistrict, 'Male', 'Snr. Gd. Level 2');
        $this->employee('K2', $ashanti, $ashantiDistrict, 'Male', 'Junior Gd. Level 1');

        $hidden = $this->employee('SA1', $accra, $accraDistrict, 'Male', 'Mgt. Gd. Level 1');
        $this->user('SA1', ['employee_id' => $hidden->id, 'full_name' => $hidden->full_name, 'email' => $hidden->email])
            ->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());

        $headOfficeHr = $this->user('HRH001');
        $headOfficeHr->roles()->attach(Role::query()->where('name', 'hr_headoffice')->firstOrFail());

        $count = fn (array $breakdown, string $label) => collect($breakdown['categories'])->firstWhere('label', $label)['count'];

        // Regional HR: Greater Accra only (themselves + A1), and the hidden super_admin account is not counted.
        $this->actingAs($regionalHr);
        $regional = Livewire::test(StaffReports::class)->get('payload.gradeBreakdown');
        $this->assertSame(2, $count($regional, 'Senior Staff'));
        $this->assertSame(0, $count($regional, 'Management'));
        $this->assertSame(0, $count($regional, 'Junior Staff'));
        $this->assertSame(2, $regional['total']);

        // Head Office HR: every region, still without the super_admin account.
        $this->actingAs($headOfficeHr);
        $all = Livewire::test(StaffReports::class)->get('payload.gradeBreakdown');
        $this->assertSame(3, $count($all, 'Senior Staff'));
        $this->assertSame(1, $count($all, 'Junior Staff'));
        $this->assertSame(0, $count($all, 'Management'));
        $this->assertSame(4, $all['total']);
    }

    public function test_the_report_page_shows_the_breakdown_and_links_to_the_filtered_staff_list(): void
    {
        $this->seedStaffAccess();
        $hr = $this->reportFixtures();
        $this->employee('S1', Region::query()->firstOrFail(), District::query()->firstOrFail(), 'Male', 'Snr. Gd. Level 4');

        $this->actingAs($hr);

        Livewire::test(StaffReports::class)
            ->assertSee('Staff by category')
            ->assertSee('Senior Staff by grade')
            ->assertSee('Junior Staff by grade')
            ->assertSee('Management by grade')
            ->assertSee('No grade set')
            ->assertSee('Snr. Gd. Level 4')
            ->assertSeeHtml('grade=none');
    }

    public function test_the_report_exports_to_excel_with_the_grade_breakdown_and_grade_columns(): void
    {
        $this->seedStaffAccess();
        $hr = $this->reportFixtures();
        $this->employee('S1', Region::query()->firstOrFail(), District::query()->firstOrFail(), 'Male', 'Snr. Gd. Level 4');

        Excel::fake();

        $this->actingAs($hr)->get(route('staff.reports.export'))->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/^staff_reports_\d{4}_\d{2}_\d{2}_\d{6}\.xlsx$/', function (StaffReportExport $export) {
            $sheets = collect($export->sheets())->keyBy(fn ($sheet) => $sheet->title());

            $this->assertTrue($sheets->has('Summary') && $sheets->has('By Grade') && $sheets->has('By District') && $sheets->has('Staff'));
            $this->assertContains(['Senior Staff', 'Snr. Gd. Level 4', 1], $sheets['By Grade']->array());
            $this->assertContains('Grade', $sheets['Staff']->headings());
            $this->assertContains('No grade', $sheets['By District']->headings());
            $this->assertContains('Snr. Gd. Level 4', collect($sheets['Staff']->array())->pluck(6)->all());

            return true;
        });

        $this->assertTrue(\App\Models\AuditLog::query()->where('action', 'export_staff_reports')->exists());
    }

    public function test_the_report_export_is_refused_to_people_without_the_permission(): void
    {
        $this->seedStaffAccess();
        $manager = $this->user('MGR001');
        $manager->roles()->attach(Role::query()->where('name', 'manager')->firstOrFail());

        $this->actingAs($manager)->get(route('staff.reports.export'))->assertForbidden();
    }

    /**
     * Cached values are unserialised without classes (config/cache.php serializable_classes), so
     * anything other than arrays and scalars would come back as __PHP_Incomplete_Class.
     */
    protected function assertCacheSafe(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertCacheSafe($item, $path.'.'.$key);
            }

            return;
        }

        $this->assertTrue(
            $value === null || is_scalar($value),
            $path.' is '.get_debug_type($value).'; cached report payloads may only hold arrays and scalars.'
        );
    }

    protected function reportFixtures(): User
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $region->id]);

        $this->employee('EMP101', $region, $district, 'Male');
        $onLeave = $this->employee('EMP102', $region, $district, 'Female');
        $this->leaveRequest($onLeave, 'Approved', today()->subDay()->toDateString(), today()->addDays(2)->toDateString());

        $hr = $this->user('HR101');
        $hr->roles()->attach(Role::query()->where('name', 'hr_headoffice')->firstOrFail());

        return $hr;
    }

    protected function seedStaffAccess(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            StaffRolePermissionSeeder::class,
        ]);

        $this->assertTrue(Permission::query()->where('name', 'staff.view_reports')->exists());
        $this->assertTrue(ModuleAccess::query()->where('module', Permission::MODULE_STAFF)->where('can_access', true)->exists());
    }

    protected function user(string $staffId, array $overrides = []): User
    {
        return User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            ...$overrides,
        ]);
    }

    protected function employee(string $staffId, Region $region, District $district, string $gender, ?string $grade = null, ?string $category = null, bool $active = true): Employee
    {
        $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

        return Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Employee '.$staffId,
            'gender' => $gender,
            'grade' => $grade,
            'category' => $category ?? ($grade ? StaffGrade::from($grade)->category() : 'Senior Staff'),
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
            'is_active' => $active,
        ]));
    }

    protected function leaveRequest(Employee $employee, string $status, string $startDate, string $endDate): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'requester_id' => $employee->id,
            'leave_type' => 'Annual',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days_applied' => 3,
            'leave_details' => null,
            'manager_id' => $employee->id,
            'manager_comments' => null,
            'manager_recommendation' => 'Recommended',
            'leave_status' => $status,
            'approved_by_id' => $status === 'Approved' ? $employee->id : null,
            'chiefManager_comments' => null,
            'request_year' => (int) substr($startDate, 0, 4),
            'department_id' => $employee->department_id,
            'region_id' => $employee->region_id,
            'file_attachment' => null,
        ]);
    }
}
