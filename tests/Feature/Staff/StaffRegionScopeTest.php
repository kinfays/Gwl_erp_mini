<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\AllEmployees;
use App\Livewire\Staff\EmployeeForm;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\ModuleAccess;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Import\DataImportService;
use App\Services\Staff\EmployeeDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffRegionScopeTest extends TestCase
{
    use RefreshDatabase;

    protected int $employeeSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_hr_region_cannot_open_or_deactivate_an_employee_in_another_region(): void
    {
        [$ownDistrict, $otherDistrict] = $this->createTwoRegions();
        $this->actingAs($this->createHrRegionUser($ownDistrict));
        $outsider = $this->createEmployee($otherDistrict);

        $this->get(route('staff.edit', $outsider))->assertForbidden();
        $this->patch(route('staff.toggle-status', $outsider), [
            'deactivation_reason' => 'left',
        ])->assertForbidden();

        $this->assertTrue($outsider->fresh()->is_active);
        $this->assertNull($outsider->fresh()->deactivation_reason);
    }

    public function test_hr_region_can_edit_and_deactivate_an_employee_in_their_own_region(): void
    {
        [$ownDistrict] = $this->createTwoRegions();
        $this->actingAs($this->createHrRegionUser($ownDistrict));
        $colleague = $this->createEmployee($ownDistrict);

        $this->get(route('staff.edit', $colleague))->assertOk();
        $this->patch(route('staff.toggle-status', $colleague), [
            'deactivation_reason' => 'left',
        ])->assertRedirect();

        $this->assertFalse($colleague->fresh()->is_active);
        $this->assertSame('left', $colleague->fresh()->deactivation_reason);
    }

    public function test_employee_form_refuses_an_employee_outside_the_hr_region_scope(): void
    {
        [$ownDistrict, $otherDistrict] = $this->createTwoRegions();
        $this->actingAs($this->createHrRegionUser($ownDistrict));
        $outsider = $this->createEmployee($otherDistrict);

        $this->assertForbiddenLivewire(fn () => Livewire::test(EmployeeForm::class, ['employee' => $outsider]));
    }

    public function test_employee_form_only_offers_and_accepts_districts_in_the_hr_region(): void
    {
        [$ownDistrict, $otherDistrict] = $this->createTwoRegions();
        $this->actingAs($this->createHrRegionUser($ownDistrict));

        $component = Livewire::test(EmployeeForm::class);

        $this->assertSame(['Own District'], collect($component->viewData('districtOptions'))->pluck('label')->all());

        $this->fillNewEmployee($component, $otherDistrict)
            ->call('save')
            ->assertHasErrors('district_id');

        $this->assertSame('Choose a district in your own region.', $component->errors()->first('district_id'));
        $this->assertDatabaseMissing('employees', ['staff_id' => 'NEW001']);

        $this->fillNewEmployee($component, $ownDistrict)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', [
            'staff_id' => 'NEW001',
            'region_id' => $ownDistrict->region_id,
            'district_id' => $ownDistrict->id,
        ]);
    }

    public function test_hr_region_cannot_move_an_employee_to_another_region(): void
    {
        [$ownDistrict, $otherDistrict] = $this->createTwoRegions();
        $this->actingAs($this->createHrRegionUser($ownDistrict));
        $colleague = $this->createEmployee($ownDistrict);

        Livewire::test(EmployeeForm::class, ['employee' => $colleague])
            ->set('district_id', $otherDistrict->id)
            ->call('save')
            ->assertHasErrors('district_id');

        $this->assertSame($ownDistrict->id, $colleague->fresh()->district_id);
    }

    public function test_employee_form_save_rechecks_scope_for_the_edited_employee(): void
    {
        [$ownDistrict, $otherDistrict] = $this->createTwoRegions();
        $hr = $this->createHrRegionUser($ownDistrict);
        $this->actingAs($hr);
        $colleague = $this->createEmployee($ownDistrict);

        $component = Livewire::test(EmployeeForm::class, ['employee' => $colleague]);

        // The HR user is posted to another region after the form was opened.
        $hr->employee->update(['district_id' => $otherDistrict->id, 'region_id' => $otherDistrict->region_id]);

        $component->set('full_name', 'Renamed')
            ->call('save')
            ->assertForbidden();

        $this->assertNotSame('Renamed', $colleague->fresh()->full_name);
    }

    public function test_hr_region_user_without_a_linked_employee_sees_no_employees(): void
    {
        [$ownDistrict, $otherDistrict] = $this->createTwoRegions();
        $this->createEmployee($ownDistrict, ['full_name' => 'Ama Owusu']);
        $this->createEmployee($otherDistrict, ['full_name' => 'Kofi Boateng']);
        $unlinked = $this->createUnlinkedUser('hr_region', 'HRX001');
        $this->actingAs($unlinked);

        Livewire::test(AllEmployees::class)
            ->assertDontSee('Ama Owusu')
            ->assertDontSee('Kofi Boateng');

        $this->assertSame(0, app(EmployeeDirectory::class)->queryFor($unlinked)->count());
        $this->assertSame([], app(EmployeeDirectory::class)->assignableRegionIds($unlinked));
        $this->assertForbiddenLivewire(fn () => Livewire::test(EmployeeForm::class));
    }

    public function test_manager_without_a_linked_employee_sees_no_employees(): void
    {
        [$ownDistrict] = $this->createTwoRegions();
        $this->createEmployee($ownDistrict);

        $manager = $this->createUnlinkedUser('chief_manager', 'CM001');

        $this->assertSame(0, app(EmployeeDirectory::class)->queryFor($manager)->count());
    }

    public function test_head_office_hr_keeps_access_to_every_region(): void
    {
        [, $otherDistrict] = $this->createTwoRegions();
        $this->actingAs($this->createUnlinkedUser('hr_headoffice', 'HQ001'));
        $outsider = $this->createEmployee($otherDistrict);

        $this->get(route('staff.edit', $outsider))->assertOk();

        $labels = collect(Livewire::test(EmployeeForm::class)->viewData('districtOptions'))->pluck('label')->all();

        $this->assertEqualsCanonicalizing(['Own District', 'Other District'], $labels);
    }

    public function test_staff_import_only_accepts_rows_inside_the_hr_region_scope(): void
    {
        // Let the run go ahead despite the rejected rows, to prove only in-scope rows are written.
        config(['gwl.max_import_failure_percent' => 100]);

        [$ownDistrict, $otherDistrict] = $this->createTwoRegions();
        $this->actingAs($this->createHrRegionUser($ownDistrict));
        $outsider = $this->createEmployee($otherDistrict, ['staff_id' => 'OUT001', 'full_name' => 'Kofi Boateng']);

        $csv = implode("\n", [
            'staff_id,full_name,gender,category,email,job_title_name,department_name,district_name,region_name,date_of_birth,date_joined,unit,present_appointment',
            'NEW001,Own Region Hire,Female,Senior Staff,own.hire@example.com,HR Officer,Administration,Own District,Own Region,1991-02-03,2024-01-01,,',
            'NEW002,Other Region Hire,Male,Senior Staff,other.hire@example.com,HR Officer,Administration,Other District,Other Region,1991-02-03,2024-01-01,,',
            'OUT001,Moved In,Male,Senior Staff,moved.in@example.com,HR Officer,Administration,Own District,Own Region,1991-02-03,2024-01-01,,',
        ]);

        $this->post(route('staff.import.preview'), [
            'type' => 'employees',
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ])->assertRedirect();

        $preview = session('import_preview.staff');

        $this->assertSame(['NEW001'], collect($preview['valid_rows'])->pluck('staff_id')->all());
        $this->assertEqualsCanonicalizing([
            ['row' => 3, 'message' => 'Employees must be placed in your own region.'],
            ['row' => 4, 'message' => 'You can only import employees in your own region.'],
        ], $preview['errors']);

        $this->post(route('staff.import.run'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('employees', ['staff_id' => 'NEW001', 'region_id' => $ownDistrict->region_id]);
        $this->assertDatabaseMissing('employees', ['staff_id' => 'NEW002']);
        $this->assertSame('Kofi Boateng', $outsider->fresh()->full_name);
        $this->assertSame($otherDistrict->id, $outsider->fresh()->district_id);
    }

    public function test_staff_import_run_rechecks_region_scope(): void
    {
        [$ownDistrict, $otherDistrict] = $this->createTwoRegions();
        $hr = $this->createHrRegionUser($ownDistrict);
        $this->createEmployee($otherDistrict);

        $service = app(DataImportService::class);
        $validateRow = new ReflectionMethod($service, 'validateRow');
        [$row, $errors] = $validateRow->invoke($service, 'employees', [
            'staff_id' => 'NEW002',
            'full_name' => 'Other Region Hire',
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => 'other.hire@example.com',
            'job_title_name' => 'HR Officer',
            'department_name' => 'Administration',
            'district_name' => 'Other District',
            'region_name' => 'Other Region',
            'date_of_birth' => '1991-02-03',
            'date_joined' => '2024-01-01',
            'unit' => '',
            'present_appointment' => '',
        ], 2);

        $this->assertSame([], $errors);

        try {
            $service->run('employees', [$row], $hr);
            $this->fail('Expected the import to be refused for a row outside the region.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'You can only import employees in your own region. Please preview the file again.',
                $exception->errors()['import'][0]
            );
        }

        $this->assertDatabaseMissing('employees', ['staff_id' => 'NEW002']);
    }

    protected function fillNewEmployee($component, District $district)
    {
        return $component
            ->set('staff_id', 'NEW001')
            ->set('full_name', 'Esi Asante')
            ->set('gender', 'Female')
            ->set('date_of_birth', '1992-05-05')
            ->set('category', 'Senior Staff')
            ->set('job_title_id', JobTitle::query()->value('id'))
            ->set('department_id', Department::query()->value('id'))
            ->set('district_id', $district->id)
            ->set('email', 'esi.asante@example.com');
    }

    protected function assertForbiddenLivewire(callable $callback): void
    {
        $this->withoutExceptionHandling();

        try {
            $callback();
            $this->fail('Expected a 403 response.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    protected function createTwoRegions(): array
    {
        $ownRegion = Region::query()->create(['region_name' => 'Own Region']);
        $otherRegion = Region::query()->create(['region_name' => 'Other Region']);

        Department::query()->firstOrCreate(['department_name' => 'Administration']);
        JobTitle::query()->firstOrCreate(['job_title_name' => 'HR Officer']);

        return [
            District::query()->create(['district_name' => 'Own District', 'region_id' => $ownRegion->id]),
            District::query()->create(['district_name' => 'Other District', 'region_id' => $otherRegion->id]),
        ];
    }

    protected function createEmployee(District $district, array $overrides = []): Employee
    {
        $this->employeeSequence++;

        return Employee::query()->create(array_merge([
            'staff_id' => sprintf('EMP%03d', $this->employeeSequence),
            'full_name' => 'Employee '.$this->employeeSequence,
            'gender' => 'Female',
            'category' => 'Senior Staff',
            'email' => 'employee'.$this->employeeSequence.'@example.com',
            'job_title_id' => JobTitle::query()->value('id'),
            'department_id' => Department::query()->value('id'),
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'date_of_birth' => '1990-01-01',
            'is_active' => true,
        ], $overrides));
    }

    protected function createHrRegionUser(District $district): User
    {
        $employee = $this->createEmployee($district, [
            'staff_id' => 'HRR001',
            'full_name' => 'Regional HR Officer',
            'email' => 'regional.hr@example.com',
        ]);

        // EmployeeObserver creates the linked login account.
        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
        $user->update(['must_change_password' => false]);
        $user->roles()->attach($this->role('hr_region'));

        return $user;
    }

    protected function createUnlinkedUser(string $roleName, string $staffId): User
    {
        $user = User::query()->create([
            'full_name' => Str::headline($roleName).' User',
            'email' => strtolower($staffId).'@example.com',
            'staff_id' => $staffId,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->roles()->attach($this->role($roleName));

        return $user;
    }

    protected function role(string $name): Role
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $name],
            ['display_name' => Str::headline($name), 'is_system' => true]
        );

        ModuleAccess::query()->firstOrCreate(
            ['role_id' => $role->id, 'module' => 'staff'],
            ['can_access' => true]
        );

        return $role;
    }
}
