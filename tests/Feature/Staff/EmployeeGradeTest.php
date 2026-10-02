<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffGrade;
use App\Exports\Staff\EmployeesExport;
use App\Livewire\Staff\AllEmployees;
use App\Livewire\Staff\EmployeeForm;
use App\Models\AuditLog;
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
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Grade on the staff screens: the form, the list and its filters, the export, the profile and the audit trail. A grade
 * fixes the category (it is never stored as something else); staff recorded before grades keep working without one.
 */
class EmployeeGradeTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected Region $region;

    protected District $district;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->region = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->district = District::query()->create(['district_name' => 'Accra Central District', 'region_id' => $this->region->id]);
        Department::query()->create(['department_name' => 'Administration']);
        JobTitle::query()->create(['job_title_name' => 'HR Officer']);

        $role = Role::query()->create(['name' => 'hr_headoffice', 'display_name' => 'HR Head Office', 'is_system' => true]);
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'staff', 'can_access' => true]);
        $this->hr = User::query()->create([
            'full_name' => 'HR User', 'email' => 'hr.user@example.com', 'staff_id' => 'HR001',
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        $this->hr->roles()->attach($role);
        $this->actingAs($this->hr);
    }

    protected function employee(string $staffId, ?string $grade = null, string $category = 'Senior Staff', array $extra = []): Employee
    {
        return Employee::query()->create($extra + [
            'staff_id' => $staffId, 'full_name' => 'Staff '.$staffId, 'gender' => 'Female',
            'grade' => $grade, 'category' => $category, 'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->value('id'), 'department_id' => Department::query()->value('id'),
            'region_id' => $this->region->id, 'district_id' => $this->district->id,
            'date_of_birth' => '1990-01-01', 'date_joined' => '2020-01-06', 'is_active' => true,
        ]);
    }

    protected function fillNew(string $staffId, ?string $grade)
    {
        return Livewire::test(EmployeeForm::class)
            ->set('staff_id', $staffId)
            ->set('full_name', 'New '.$staffId)
            ->set('gender', 'Female')
            ->set('date_of_birth', '1992-05-05')
            ->set('grade', $grade ?? '')
            ->set('job_title_id', JobTitle::query()->value('id'))
            ->set('department_id', Department::query()->value('id'))
            ->set('district_id', $this->district->id)
            ->set('email', strtolower($staffId).'@example.com');
    }

    // ------------------------------------------------------------------ the form

    public function test_the_form_offers_every_grade_grouped_by_category(): void
    {
        $groups = Livewire::test(EmployeeForm::class)->viewData('gradeGroups');

        $this->assertSame(['Junior Staff', 'Contract', 'Senior Staff', 'Management'], array_keys($groups));
        $this->assertCount(6, $groups['Junior Staff']);
        $this->assertSame(['Charwoman'], $groups['Contract']);
        $this->assertCount(4, $groups['Senior Staff']);
        $this->assertCount(4, $groups['Management']);
        $offered = array_merge(...array_values($groups));
        $expected = StaffGrade::values();
        sort($offered);
        sort($expected);
        $this->assertSame($expected, $offered);
    }

    public function test_a_new_employee_must_be_given_a_grade_and_it_must_be_a_real_one(): void
    {
        $this->fillNew('NEW001', null)->call('save')->assertHasErrors('grade');
        $this->fillNew('NEW002', 'Snr. Gd. Level 9')->call('save')->assertHasErrors('grade');

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_the_grade_is_saved_and_fixes_the_category_whatever_the_form_held(): void
    {
        $this->fillNew('NEW003', 'Mgt. Gd. Level 2')
            ->set('category', 'Junior Staff')   // stale, as if the grade was changed after the category was set
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', ['staff_id' => 'NEW003', 'grade' => 'Mgt. Gd. Level 2', 'category' => 'Management']);
    }

    public function test_choosing_a_grade_shows_the_category_it_gives(): void
    {
        Livewire::test(EmployeeForm::class)
            ->set('grade', 'Junior Gd. Level 4')
            ->assertSet('category', 'Junior Staff')
            ->assertSee('Category: Junior Staff');
    }

    public function test_changing_the_grade_is_audited_with_the_old_and_new_values(): void
    {
        $employee = $this->employee('E001', 'Junior Gd. Level 1', 'Junior Staff');
        AuditLog::query()->delete();

        Livewire::test(EmployeeForm::class, ['employee' => $employee])
            ->set('grade', 'Snr. Gd. Level 3')
            ->call('save')
            ->assertHasNoErrors();

        $employee->refresh();
        $this->assertSame('Snr. Gd. Level 3', $employee->grade);
        $this->assertSame('Senior Staff', $employee->category);

        $audit = AuditLog::query()->where('action', 'employee_grade_changed')->sole();
        $this->assertSame(['grade' => 'Junior Gd. Level 1', 'category' => 'Junior Staff'], $audit->old_values);
        $this->assertSame(['grade' => 'Snr. Gd. Level 3', 'category' => 'Senior Staff'], $audit->new_values);
        $this->assertSame($employee->id, $audit->target_id);

        // The edit itself is audited as before, with the grade in its old and new values.
        $update = AuditLog::query()->where('action', 'update_employee')->sole();
        $this->assertSame('Junior Gd. Level 1', $update->old_values['grade']);
        $this->assertSame('Snr. Gd. Level 3', $update->new_values['grade']);
    }

    public function test_a_new_employees_first_grade_is_audited_too(): void
    {
        $this->fillNew('NEW004', 'Snr. Gd. Level 1')->call('save');

        $audit = AuditLog::query()->where('action', 'employee_grade_changed')->sole();
        $this->assertNull($audit->old_values['grade']);
        $this->assertSame('Snr. Gd. Level 1', $audit->new_values['grade']);
    }

    public function test_an_unchanged_grade_is_not_audited_again(): void
    {
        $employee = $this->employee('E002', 'Snr. Gd. Level 2');
        AuditLog::query()->delete();

        Livewire::test(EmployeeForm::class, ['employee' => $employee])->set('full_name', 'Renamed')->call('save');

        $this->assertSame(0, AuditLog::query()->where('action', 'employee_grade_changed')->count());
    }

    public function test_staff_recorded_before_grades_can_still_be_edited_and_keep_their_category(): void
    {
        $legacy = $this->employee('OLD001', null, 'Senior Management');

        Livewire::test(EmployeeForm::class, ['employee' => $legacy])
            ->assertSet('grade', '')
            ->assertSet('category', 'Senior Management')
            ->set('full_name', 'Renamed Legacy')
            ->call('save')
            ->assertHasNoErrors();

        $legacy->refresh();
        $this->assertNull($legacy->grade);
        $this->assertSame('Senior Management', $legacy->category);
        $this->assertSame('Renamed Legacy', $legacy->full_name);
    }

    public function test_an_employee_who_has_a_grade_cannot_have_it_cleared(): void
    {
        $employee = $this->employee('E003', 'Snr. Gd. Level 2');

        Livewire::test(EmployeeForm::class, ['employee' => $employee])->set('grade', '')->call('save')->assertHasErrors('grade');

        $this->assertSame('Snr. Gd. Level 2', $employee->fresh()->grade);
    }

    // ------------------------------------------------------------------ list, filters, export, profile

    public function test_the_list_shows_each_grade_and_flags_staff_with_none(): void
    {
        $this->employee('E001', 'Snr. Gd. Level 3');
        $this->employee('E002');

        Livewire::test(AllEmployees::class)
            ->assertSee('Snr. Gd. Level 3')
            ->assertSee('No grade set');
    }

    public function test_the_list_filters_by_grade_by_no_grade_and_by_category(): void
    {
        $this->employee('S1', 'Snr. Gd. Level 1');
        $this->employee('S2', 'Snr. Gd. Level 2');
        $this->employee('J1', 'Junior Gd. Level 1', 'Junior Staff');
        $this->employee('N1');
        $this->employee('LM', null, 'Senior Management');
        $this->employee('M1', 'Mgt. Gd. Level 1');

        $staffIds = fn ($component) => $component->viewData('employees')->pluck('staff_id')->sort()->values()->all();

        $this->assertSame(['S1'], $staffIds(Livewire::test(AllEmployees::class)->set('grade', 'Snr. Gd. Level 1')));
        $this->assertSame(['LM', 'N1'], $staffIds(Livewire::test(AllEmployees::class)->set('grade', 'none')));
        $this->assertSame(['J1'], $staffIds(Livewire::test(AllEmployees::class)->set('category', 'Junior Staff')));
        // Management includes the old "Senior Management" category.
        $this->assertSame(['LM', 'M1'], $staffIds(Livewire::test(AllEmployees::class)->set('category', 'Management')));
    }

    public function test_the_filters_can_arrive_in_the_url_so_cards_can_link_to_them(): void
    {
        $this->employee('S1', 'Snr. Gd. Level 1');
        $this->employee('N1');

        $component = Livewire::withQueryParams(['grade' => 'none', 'status' => 'active'])->test(AllEmployees::class);

        $this->assertSame(['N1'], $component->viewData('employees')->pluck('staff_id')->all());
        $this->assertSame('none', $component->get('grade'));
    }

    public function test_the_export_carries_the_grade_and_the_filter(): void
    {
        $this->employee('S1', 'Snr. Gd. Level 1');
        $this->employee('N1');

        Excel::fake();
        Excel::matchByRegex();

        $this->get(route('staff.export', ['grade' => 'none']))->assertOk();

        Excel::assertDownloaded('/^employees_.*\.xlsx$/', function (EmployeesExport $export) {
            $this->assertContains('Grade', $export->headings());
            $this->assertSame(['N1'], $export->collection()->pluck('staff_id')->all());
            $this->assertSame('Not set', $export->map($export->collection()->first())[5]);

            return true;
        });
    }

    public function test_the_profile_drawer_payload_includes_the_grade(): void
    {
        $employee = $this->employee('E001', 'Mgt. Gd. Level 3');
        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();

        $this->getJson(route('staff.users.show', $user))
            ->assertOk()
            ->assertJsonPath('employee.grade', 'Mgt. Gd. Level 3')
            ->assertJsonPath('employee.category', 'Management');
    }

    // ------------------------------------------------------------------ leaving

    public function test_deactivating_with_each_exit_reason_stamps_the_date_and_reactivating_clears_it(): void
    {
        foreach (['retired' => 'Retirement', 'resigned' => 'Resignation', 'contract_ended' => 'Contract ended', 'transfer' => 'Transfer', 'other' => 'Other'] as $key => $label) {
            $this->assertSame($label, Employee::DEACTIVATION_REASONS[$key]);

            $employee = $this->employee('X'.$key);
            $this->patch(route('staff.toggle-status', $employee), ['deactivation_reason' => $key])->assertRedirect();

            $employee->refresh();
            $this->assertFalse($employee->is_active);
            $this->assertSame($key, $employee->deactivation_reason);
            $this->assertSame(today()->toDateString(), $employee->deactivated_at->toDateString());

            $this->patch(route('staff.toggle-status', $employee))->assertRedirect();
            $this->assertNull($employee->fresh()->deactivated_at);
        }

        $this->patch(route('staff.toggle-status', $this->employee('XBAD')), ['deactivation_reason' => 'bogus'])->assertSessionHasErrors('deactivation_reason');
    }
}
