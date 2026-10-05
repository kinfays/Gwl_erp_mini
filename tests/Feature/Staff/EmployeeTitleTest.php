<?php

namespace Tests\Feature\Staff;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** The honorific (Mr., Ing., Dr., ...) on the staff form, the import, the profile and the profile drawer. */
class EmployeeTitleTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        District::query()->create(['district_name' => 'Accra West Regional Office', 'region_id' => $region->id]);
        Department::query()->create(['department_name' => 'Administration']);
        JobTitle::query()->create(['job_title_name' => 'HR Officer']);

        $role = Role::query()->create(['name' => 'hr_headoffice', 'display_name' => 'HR Head Office', 'is_system' => true]);
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'staff', 'can_access' => true]);
        $this->hr = User::query()->create(['full_name' => 'HR User', 'email' => 'hr@example.com', 'staff_id' => 'HR001', 'password' => Hash::make('password'), 'is_active' => true]);
        $this->hr->roles()->attach($role);
        $this->actingAs($this->hr);
    }

    protected function employee(?string $title = null): Employee
    {
        return Employee::query()->create([
            'staff_id' => 'E001', 'full_name' => 'Ama Mensah', 'title' => $title, 'gender' => 'Female', 'grade' => 'Snr. Gd. Level 1', 'category' => 'Senior Staff',
            'email' => 'ama@example.com', 'job_title_id' => JobTitle::query()->value('id'), 'department_id' => Department::query()->value('id'),
            'region_id' => Region::query()->value('id'), 'district_id' => District::query()->value('id'),
            'date_of_birth' => '1990-01-01', 'date_joined' => '2020-01-06', 'is_active' => true,
        ]);
    }

    public function test_the_form_offers_the_titles_and_saves_the_one_chosen(): void
    {
        $component = Livewire::test(EmployeeForm::class);
        $this->assertContains('Ing.', $component->viewData('titles'));

        $component
            ->set('staff_id', 'NEW001')->set('full_name', 'Kojo Asare')->set('title', 'Ing.')->set('gender', 'Male')
            ->set('date_of_birth', '1985-03-03')->set('grade', 'Mgt. Gd. Level 1')
            ->set('job_title_id', JobTitle::query()->value('id'))->set('department_id', Department::query()->value('id'))
            ->set('district_id', District::query()->value('id'))->set('email', 'kojo@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', ['staff_id' => 'NEW001', 'title' => 'Ing.']);
    }

    public function test_the_title_is_optional_can_be_changed_and_cleared_and_must_be_a_known_one(): void
    {
        $employee = $this->employee('Ms.');

        Livewire::test(EmployeeForm::class, ['employee' => $employee])->assertSet('title', 'Ms.')->set('title', 'Dr.')->call('save')->assertHasNoErrors();
        $this->assertSame('Dr.', $employee->fresh()->title);

        Livewire::test(EmployeeForm::class, ['employee' => $employee])->set('title', '')->call('save')->assertHasNoErrors();
        $this->assertNull($employee->fresh()->title);

        Livewire::test(EmployeeForm::class, ['employee' => $employee])->set('title', 'Emperor')->call('save')->assertHasErrors('title');
    }

    public function test_the_import_reads_titles_in_any_case_rejects_unknown_ones_and_never_clears_an_existing_title(): void
    {
        $headings = 'staff_id,full_name,title,gender,category,email,job_title_name,department_name,district_name,region_name,date_of_birth,date_joined,unit,present_appointment';
        $row = fn (string $id, string $title) => implode(',', [$id, 'Staff '.$id, $title, 'Female', 'Senior Staff', $id.'@example.com', 'HR Officer', 'Administration', 'Accra West Regional Office', 'Greater Accra', '1990-01-01', '2020-01-06', '', '']);
        $upload = fn (array $rows, string $head = null) => UploadedFile::fake()->createWithContent('employees.csv', ($head ?? $headings)."\n".implode("\n", $rows)."\n");

        $preview = app(DataImportService::class)->preview($upload([$row('T1', 'ING.'), $row('T2', 'dr'), $row('T3', ''), $row('T4', 'Emperor')]), 'employees');

        $this->assertSame(1, $preview['error_count']);
        $this->assertStringContainsString('title', strtolower($preview['errors'][0]['message']));
        $this->assertSame(['Ing.', 'Dr.', null], array_column($preview['valid_rows'], 'title'));

        app(DataImportService::class)->run('employees', $preview['valid_rows']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'T1', 'title' => 'Ing.']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'T3', 'title' => null]);

        // Importing T1 again without a title keeps "Ing.".
        $again = app(DataImportService::class)->preview($upload([$row('T1', '')]), 'employees');
        app(DataImportService::class)->run('employees', $again['valid_rows']);
        $this->assertSame('Ing.', Employee::query()->where('staff_id', 'T1')->value('title'));

        // A file with no title column at all still imports; the template has the column.
        $noColumn = app(DataImportService::class)->preview($upload(['T9,Staff T9,Male,Senior Staff,t9@example.com,HR Officer,Administration,Accra West Regional Office,Greater Accra,1990-01-01,2020-01-06,,'], 'staff_id,full_name,gender,category,email,job_title_name,department_name,district_name,region_name,date_of_birth,date_joined,unit,present_appointment'), 'employees');
        $this->assertSame(0, $noColumn['error_count'], json_encode($noColumn['errors']));
        $this->assertContains('title', app(DataImportService::class)->templateExport('employees')->headings());
    }

    public function test_the_title_is_on_the_profile_and_the_drawer_payload(): void
    {
        $employee = $this->employee('Prof.');
        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();

        $this->getJson(route('staff.users.show', $user))->assertOk()->assertJsonPath('employee.title', 'Prof.');
    }
}
