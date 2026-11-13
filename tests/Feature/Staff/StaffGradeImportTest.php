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
 * The "Staff grades" upload: staff_id + grade only, existing staff only, any spelling of a grade is read as the real one,
 * and the grade fixes the category.
 */
class StaffGradeImportTest extends TestCase
{
    use RefreshDatabase;

    protected Region $region;

    protected District $district;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->region = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->district = District::query()->create(['district_name' => 'Accra West Regional Office', 'region_id' => $this->region->id]);
        Department::query()->create(['department_name' => 'Administration']);
        JobTitle::query()->create(['job_title_name' => 'HR Officer']);
    }

    protected function employee(string $staffId, ?Region $region = null, ?District $district = null, string $category = 'Senior Staff', ?string $grade = null): Employee
    {
        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Staff '.$staffId,
            'gender' => 'Female',
            'category' => $category,
            'grade' => $grade,
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->value('id'),
            'department_id' => Department::query()->value('id'),
            'district_id' => ($district ?? $this->district)->id,
            'region_id' => ($region ?? $this->region)->id,
            'date_of_birth' => '1990-01-01',
            'is_active' => true,
        ]);
    }

    protected function preview(array $rows, string $headings = 'staff_id,grade', ?User $actor = null): array
    {
        $file = UploadedFile::fake()->createWithContent('grades.csv', $headings."\n".implode("\n", $rows)."\n");

        return app(DataImportService::class)->preview($file, 'staff_grades', $actor);
    }

    public function test_the_type_is_offered_with_its_two_columns(): void
    {
        $type = collect(app(DataImportService::class)->availableTypes(false))->firstWhere('type', 'staff_grades');

        $this->assertNotNull($type);
        $this->assertSame('Staff grades', $type['label']);
        $this->assertSame(['staff_id', 'grade'], app(DataImportService::class)->templateExport('staff_grades')->headings());
    }

    public function test_grades_are_set_on_existing_staff_and_fix_the_category(): void
    {
        $this->employee('G001', category: 'Senior Staff');
        $this->employee('G002', category: 'Junior Staff', grade: 'Junior Gd. Level 1');
        $this->employee('G003', category: 'Senior Staff');

        $preview = $this->preview(['G001,snr gd level 2', 'G002,Mgt. Gd. Level 3', 'G003,charwoman']);

        $this->assertSame(0, $preview['error_count'], json_encode($preview['errors']));
        $this->assertSame(3, $preview['valid_count']);

        $result = app(DataImportService::class)->run('staff_grades', $preview['valid_rows']);

        $this->assertSame(['created' => 0, 'updated' => 3, 'processed' => 3], $result);
        $this->assertDatabaseHas('employees', ['staff_id' => 'G001', 'grade' => 'Snr. Gd. Level 2', 'category' => 'Senior Staff']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'G002', 'grade' => 'Mgt. Gd. Level 3', 'category' => 'Management']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'G003', 'grade' => 'Charwoman', 'category' => 'Contract']);
    }

    public function test_a_numeric_staff_id_cell_still_matches(): void
    {
        $this->employee('12345');

        $preview = $this->preview(['12345,Junior Gd. Level 4']);

        $this->assertSame(0, $preview['error_count'], json_encode($preview['errors']));
    }

    public function test_an_unknown_staff_id_and_an_unknown_grade_are_row_errors_and_create_nobody(): void
    {
        $this->employee('K001');

        $preview = $this->preview(['NOPE1,Junior Gd. Level 1', 'K001,Director', 'K001,', 'K001,Snr. Gd. Level 1']);

        $this->assertSame(3, $preview['error_count']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame([2, 3, 4], array_column($preview['errors'], 'row'));
        $this->assertStringContainsString('No staff member with this staff ID', $preview['errors'][0]['message']);
        $this->assertStringContainsString('The grade "Director" is not recognised', $preview['errors'][1]['message']);

        app(DataImportService::class)->run('staff_grades', $preview['valid_rows']);

        $this->assertDatabaseMissing('employees', ['staff_id' => 'NOPE1']);
        $this->assertDatabaseHas('employees', ['staff_id' => 'K001', 'grade' => 'Snr. Gd. Level 1']);
    }

    public function test_both_columns_are_required(): void
    {
        $preview = $this->preview(['K001'], 'staff_id');

        $this->assertStringContainsString('Missing required columns: grade', $preview['errors'][0]['message']);
    }

    public function test_region_hr_cannot_grade_staff_outside_their_region(): void
    {
        $otherRegion = Region::query()->create(['region_name' => 'Ashanti']);
        $otherDistrict = District::query()->create(['district_name' => 'Kumasi', 'region_id' => $otherRegion->id]);

        $own = $this->employee('H001');
        $this->employee('H002', $otherRegion, $otherDistrict);

        $role = Role::query()->create(['name' => 'hr_region', 'display_name' => 'HR Region', 'is_system' => true]);
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'staff', 'can_access' => true]);
        $actor = User::query()->create([
            'full_name' => 'Region HR',
            'email' => 'region.hr@example.com',
            'staff_id' => 'H001',
            'employee_id' => $own->id,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $actor->roles()->attach($role);

        $preview = $this->preview(['H001,Snr. Gd. Level 1', 'H002,Snr. Gd. Level 1'], actor: $actor);

        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame(1, $preview['error_count']);
        $this->assertSame(3, $preview['errors'][0]['row']);
    }
}
