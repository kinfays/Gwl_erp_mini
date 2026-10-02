<?php

namespace Tests\Feature\Staff;

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
use Tests\TestCase;

/**
 * The employee import and Grade: the column is optional, any spelling of a grade is read as the real one, an unknown grade
 * rejects its row, and staff imported without one are listed as "grade missing" (and still imported).
 */
class EmployeeImportGradeTest extends TestCase
{
    use RefreshDatabase;

    protected const HEADINGS = 'staff_id,full_name,gender,category,grade,email,job_title_name,department_name,district_name,region_name,date_of_birth,date_joined,unit,present_appointment';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        District::query()->create(['district_name' => 'Accra West Regional Office', 'region_id' => $region->id]);
        Department::query()->create(['department_name' => 'Administration']);
        JobTitle::query()->create(['job_title_name' => 'HR Officer']);
    }

    /** One CSV row; $category and $grade are the two cells under test. */
    protected function row(string $staffId, string $category, ?string $grade): string
    {
        return implode(',', [$staffId, 'Staff '.$staffId, 'Female', $category, $grade ?? '', strtolower($staffId).'@example.com', 'HR Officer', 'Administration', 'Accra West Regional Office', 'Greater Accra', '1990-01-01', '2020-01-06', '', '']);
    }

    protected function upload(array $rows, string $headings = self::HEADINGS): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('employees.csv', $headings."\n".implode("\n", $rows)."\n");
    }

    protected function preview(array $rows, string $headings = self::HEADINGS): array
    {
        return app(DataImportService::class)->preview($this->upload($rows, $headings), 'employees');
    }

    public function test_a_valid_grade_is_accepted_and_fixes_the_category(): void
    {
        // The category cell may be blank when there is a grade.
        $preview = $this->preview([$this->row('G001', '', 'Snr. Gd. Level 2'), $this->row('G002', 'Junior Staff', 'Junior Gd. Level 5')]);

        $this->assertSame(0, $preview['error_count'], json_encode($preview['errors']));
        $this->assertSame(2, $preview['valid_count']);
        $this->assertSame([], $preview['warnings']);

        app(DataImportService::class)->run('employees', $preview['valid_rows']);

        $this->assertDatabaseHas('employees', ['staff_id' => 'G001', 'grade' => 'Snr. Gd. Level 2', 'category' => 'Senior Staff']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'G002', 'grade' => 'Junior Gd. Level 5', 'category' => 'Junior Staff']);
    }

    public function test_spelling_and_case_variants_are_read_as_the_real_grade(): void
    {
        $preview = $this->preview([
            $this->row('V001', '', 'snr gd level 2'),
            $this->row('V002', '', 'JUNIOR GD. LEVEL 3'),
            $this->row('V003', '', 'Mgt Grade Lvl 4'),
            $this->row('V004', '', ' charwoman '),
        ]);

        $this->assertSame(0, $preview['error_count'], json_encode($preview['errors']));
        $this->assertSame(
            ['Snr. Gd. Level 2', 'Junior Gd. Level 3', 'Mgt. Gd. Level 4', 'Charwoman'],
            array_column($preview['valid_rows'], 'grade')
        );

        app(DataImportService::class)->run('employees', $preview['valid_rows']);

        $this->assertSame('Contract', Employee::query()->where('staff_id', 'V004')->value('category'));
    }

    public function test_an_unknown_grade_is_a_row_error_that_names_it(): void
    {
        $preview = $this->preview([$this->row('U001', 'Senior Staff', 'Snr. Gd. Level 5'), $this->row('U002', 'Senior Staff', 'Director'), $this->row('U003', 'Senior Staff', 'Snr. Gd. Level 3')]);

        $this->assertSame(2, $preview['error_count']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame([2, 3], array_column($preview['errors'], 'row'));
        $this->assertStringContainsString('The grade "Snr. Gd. Level 5" is not recognised', $preview['errors'][0]['message']);
        $this->assertStringContainsString('The grade "Director" is not recognised', $preview['errors'][1]['message']);

        app(DataImportService::class)->run('employees', $preview['valid_rows']);
        $this->assertDatabaseMissing('employees', ['staff_id' => 'U001']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'U003']);
    }

    public function test_rows_without_a_grade_still_import_and_are_listed_as_grade_missing(): void
    {
        $preview = $this->preview([$this->row('M001', 'Senior Staff', null), $this->row('M002', 'Junior Staff', 'Junior Gd. Level 1'), $this->row('M003', 'Management', null)]);

        $this->assertSame(0, $preview['error_count']);
        $this->assertSame(3, $preview['valid_count']);
        $this->assertSame(2, $preview['grade_missing_count']);
        $this->assertSame([2, 4], array_column($preview['warnings'], 'row'));
        $this->assertStringContainsString('Grade missing for staff ID M001', $preview['warnings'][0]['message']);

        $result = app(DataImportService::class)->run('employees', $preview['valid_rows']);

        $this->assertSame(3, $result['created']);
        $this->assertSame(2, $result['grade_missing']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'M001', 'grade' => null, 'category' => 'Senior Staff']);
    }

    public function test_a_row_needs_a_category_when_it_has_no_grade(): void
    {
        $preview = $this->preview([$this->row('N001', '', null)]);

        $this->assertSame(1, $preview['error_count']);
        $this->assertStringContainsString('category', strtolower($preview['errors'][0]['message']));
    }

    public function test_a_category_that_contradicts_the_grade_is_rejected(): void
    {
        $preview = $this->preview([$this->row('C001', 'Junior Staff', 'Snr. Gd. Level 2')]);

        $this->assertSame(1, $preview['error_count']);
        $this->assertStringContainsString('does not match the grade "Snr. Gd. Level 2" (Senior Staff)', $preview['errors'][0]['message']);
    }

    public function test_a_file_without_a_grade_column_still_imports(): void
    {
        $headings = str_replace('category,grade,', 'category,', self::HEADINGS);
        $row = fn (string $id) => implode(',', [$id, 'Staff '.$id, 'Male', 'Senior Staff', $id.'@example.com', 'HR Officer', 'Administration', 'Accra West Regional Office', 'Greater Accra', '1990-01-01', '2020-01-06', '', '']);

        $preview = $this->preview([$row('O001'), $row('O002')], $headings);

        $this->assertSame(0, $preview['error_count'], json_encode($preview['errors']));
        $this->assertSame(2, $preview['valid_count']);
        $this->assertSame(2, $preview['grade_missing_count']);

        // Other columns are still required.
        $broken = $this->preview([$row('O003')], str_replace('full_name,', '', $headings));
        $this->assertSame('Header', $broken['errors'][0]['row']);
        $this->assertStringContainsString('full_name', $broken['errors'][0]['message']);
    }

    public function test_importing_again_without_a_grade_does_not_clear_the_one_the_employee_has(): void
    {
        $first = $this->preview([$this->row('R001', '', 'Snr. Gd. Level 2')]);
        app(DataImportService::class)->run('employees', $first['valid_rows']);

        $again = $this->preview([$this->row('R001', 'Senior Staff', null)]);
        $result = app(DataImportService::class)->run('employees', $again['valid_rows']);

        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'R001', 'grade' => 'Snr. Gd. Level 2']);
    }

    public function test_the_legacy_charwoman_category_is_stored_as_contract(): void
    {
        $preview = $this->preview([$this->row('L001', 'charwoman', null)]);

        $this->assertSame(0, $preview['error_count'], json_encode($preview['errors']));
        app(DataImportService::class)->run('employees', $preview['valid_rows']);

        $this->assertDatabaseHas('employees', ['staff_id' => 'L001', 'category' => 'Contract', 'grade' => null]);
    }

    public function test_the_template_carries_the_grade_column_and_a_sample_grade(): void
    {
        $template = app(DataImportService::class)->templateExport('employees');

        $this->assertContains('grade', $template->headings());
        $index = array_search('grade', $template->headings(), true);
        $this->assertSame('Mgt. Gd. Level 2', $template->array()[0][$index]);
    }

    public function test_the_grade_missing_list_shows_on_the_import_page_and_in_the_completion_message(): void
    {
        $user = $this->hrUser();

        $this->actingAs($user)
            ->post(route('staff.import.preview'), [
                'type' => 'employees',
                'file' => $this->upload([$this->row('P001', 'Senior Staff', null), $this->row('P002', '', 'Snr. Gd. Level 1')]),
            ])
            ->assertRedirect();

        $this->get(route('staff.import'))
            ->assertOk()
            ->assertSee('1 grade missing')
            ->assertSee('Grade missing for staff ID P001');

        $this->post(route('staff.import.run'))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '1 imported with the grade missing.'));

        $this->assertDatabaseHas('employees', ['staff_id' => 'P002', 'grade' => 'Snr. Gd. Level 1']);
    }

    protected function hrUser(): User
    {
        $role = Role::query()->create(['name' => 'hr_headoffice', 'display_name' => 'HR Head Office', 'is_system' => true]);
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'staff', 'can_access' => true]);

        $user = User::query()->create([
            'full_name' => 'HR Importer', 'email' => 'hr.importer@example.com', 'staff_id' => 'HR001',
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        $user->roles()->attach($role);

        return $user;
    }
}
