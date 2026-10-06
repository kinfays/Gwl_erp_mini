<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Batches;
use App\Models\CommercialImportBatch;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CommercialRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;
use Tests\TestCase;

abstract class CommercialTestCase extends TestCase
{
    use RefreshDatabase;

    protected Region $accraWest;

    protected Region $ashanti;

    protected District $sowutuom;

    protected District $odorkor;

    protected District $headOffice;

    /** @var list<string> workbook files written during the test */
    protected array $workbooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Creating an Employee triggers EmployeeObserver's user sync + invite mail.
        Notification::fake();
        Storage::fake('local');

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            CommercialRolePermissionSeeder::class,
        ]);

        $this->accraWest = Region::query()->create(['region_name' => 'Accra West']);
        $this->ashanti = Region::query()->create(['region_name' => 'Ashanti']);
        $this->sowutuom = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Sowutuom']);
        $this->odorkor = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Odorkor']);
        $this->headOffice = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Head Office']);
    }

    protected function tearDown(): void
    {
        foreach ($this->workbooks as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- people

    protected function employee(string $staffId, string $name, ?Region $region = null, ?District $district = null): Employee
    {
        $region ??= $this->accraWest;
        $district ??= $this->sowutuom;

        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $name,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Meter Reader'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Commercial'])->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2020-01-01',
            'present_appointment' => '2020-01-01',
            'is_active' => true,
        ]);
    }

    /** A user (with their employee record, in the given place) holding the given roles. */
    protected function userWithRoles(string $staffId, array $roles, ?Region $region = null, ?District $district = null): User
    {
        // Idempotent: asking for the same staff ID twice returns the same user.
        $user = User::query()->where('staff_id', $staffId)->first();

        if (! $user) {
            $employee = $this->employee($staffId, 'Staff '.$staffId, $region, $district);
            $user = User::query()->where('staff_id', $employee->staff_id)->firstOrFail();
        }

        $user->forceFill(['must_change_password' => false])->save();

        foreach ($roles as $role) {
            $user->roles()->syncWithoutDetaching(Role::query()->where('name', $role)->firstOrFail());
        }

        return $user->fresh();
    }

    /** A login with no employee record, so no region and no location. */
    protected function userWithoutEmployee(string $staffId, array $roles): User
    {
        $user = User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => $staffId.'@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        foreach ($roles as $role) {
            $user->roles()->syncWithoutDetaching(Role::query()->where('name', $role)->firstOrFail());
        }

        return $user->fresh();
    }

    protected function officer(string $staffId = '900001', ?Region $region = null, ?District $district = null): User
    {
        return $this->userWithRoles($staffId, ['commercial_officer'], $region, $district);
    }

    protected function superAdmin(string $staffId = '900000'): User
    {
        return $this->userWithRoles($staffId, ['super_admin']);
    }

    // ---------------------------------------------------------------- files

    protected function readingFile(array $spec = [], string $name = 'rptReadingSummDate.xlsx'): UploadedFile
    {
        return $this->asUpload(ReportWorkbooks::reading($spec), $name);
    }

    protected function billingFile(array $spec = [], string $name = 'rptBillingSumm_ExP.xlsx'): UploadedFile
    {
        return $this->asUpload(ReportWorkbooks::billing($spec), $name);
    }

    protected function asUpload(string $path, string $name): UploadedFile
    {
        $this->workbooks[] = $path;

        return ReportWorkbooks::upload($path, $name);
    }

    // ---------------------------------------------------------------- flows

    /** The full upload -> preview -> import flow through the Livewire screen, as the signed-in user. */
    protected function importFile(UploadedFile $file): CommercialImportBatch
    {
        Livewire::test(Batches::class)
            ->call('openUpload')
            ->set('file', $file)
            ->call('previewFile')
            ->call('runImport')
            ->assertHasNoErrors();

        return CommercialImportBatch::query()->latest('id')->firstOrFail();
    }

    protected function previewOf(UploadedFile $file): array
    {
        return Livewire::test(Batches::class)
            ->call('openUpload')
            ->set('file', $file)
            ->call('previewFile')
            ->get('preview');
    }
}
