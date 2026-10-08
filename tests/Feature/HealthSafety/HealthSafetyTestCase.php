<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\HsIncident;
use App\Models\HsPpeType;
use App\Models\HsSite;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\HealthSafety\IncidentWorkflowService;
use Database\Seeders\HealthSafetyRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class HealthSafetyTestCase extends TestCase
{
    use RefreshDatabase;

    protected Region $accraWest;

    protected Region $ashanti;

    protected District $sowutuom;

    protected District $odorkor;

    protected District $headOffice;

    protected District $kumasi;

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
            HealthSafetyRolePermissionSeeder::class,
        ]);

        $this->accraWest = Region::query()->create(['region_name' => 'Accra West']);
        $this->ashanti = Region::query()->create(['region_name' => 'Ashanti']);
        $this->sowutuom = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Sowutuom']);
        $this->odorkor = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Odorkor']);
        $this->headOffice = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Head Office']);
        $this->kumasi = District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi']);
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
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'General Staff'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Distribution'])->id,
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
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        foreach ($roles as $role) {
            $user->roles()->syncWithoutDetaching(Role::query()->where('name', $role)->firstOrFail());
        }

        return $user->fresh();
    }

    protected function reporter(string $staffId = '100001', ?Region $region = null, ?District $district = null): User
    {
        return $this->userWithRoles($staffId, ['employee'], $region, $district);
    }

    protected function officer(string $staffId = '200001', ?Region $region = null, ?District $district = null): User
    {
        return $this->userWithRoles($staffId, ['employee', 'hs_officer'], $region, $district);
    }

    protected function hsManager(string $staffId = '200002', ?Region $region = null, ?District $district = null): User
    {
        return $this->userWithRoles($staffId, ['employee', 'hs_manager'], $region, $district);
    }

    protected function districtManager(string $staffId = '200003', ?District $district = null): User
    {
        return $this->userWithRoles($staffId, ['employee', 'district_manager'], $district?->region, $district);
    }

    protected function chiefManager(string $staffId = '200004', ?Region $region = null): User
    {
        return $this->userWithRoles($staffId, ['employee', 'regional_chief_manager'], $region);
    }

    protected function superAdmin(string $staffId = '900000'): User
    {
        return $this->userWithRoles($staffId, ['super_admin']);
    }

    // ---------------------------------------------------------------- incidents

    /** What the report form would send, for the service. @return array<string, mixed> */
    protected function reportData(array $overrides = []): array
    {
        return [
            'context' => HsIncident::CONTEXT_DISTRICT_OFFICE,
            'district_id' => $this->sowutuom->id,
            'incident_type' => HsIncident::TYPE_NEAR_MISS,
            'occurred_on' => today()->toDateString(),
            'description' => 'A ladder slipped on the wet floor.',
            'first_aid' => HsIncident::FIRST_AID_NOT_NEEDED,
            ...$overrides,
        ];
    }

    protected function incident(User $reporter, array $overrides = []): HsIncident
    {
        return app(IncidentWorkflowService::class)->submit($reporter, $this->reportData($overrides));
    }

    /** An incident that has been acknowledged and triaged, ready to be worked on. */
    protected function triaged(User $reporter, User $officer, string $severity = 'low', array $overrides = []): HsIncident
    {
        $incident = $this->incident($reporter, $overrides);

        return app(IncidentWorkflowService::class)->triage($incident, $officer, ['severity' => $severity]);
    }

    protected function workflow(): IncidentWorkflowService
    {
        return app(IncidentWorkflowService::class);
    }

    // ---------------------------------------------------------------- equipment

    protected function site(string $name = 'Sowutuom District Office', ?District $district = null, string $kind = 'district_office'): HsSite
    {
        $district ??= $this->sowutuom;

        return HsSite::query()->create([
            'name' => $name,
            'kind' => $kind,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
        ]);
    }

    /** An extinguisher written straight to the database (bypassing the service), for the state tests. */
    protected function unit(array $attributes = [], ?HsSite $site = null): HsFireExtinguisher
    {
        $site ??= $this->site('Site '.uniqid());

        return HsFireExtinguisher::query()->create([
            'asset_code' => 'T-'.uniqid(),
            'extinguisher_type' => 'water',
            'site_id' => $site->id,
            'region_id' => $site->region_id,
            'district_id' => $site->district_id,
            'status' => 'in_service',
            'last_checked_on' => today()->toDateString(),
            'last_check_result' => 'pass',
            ...$attributes,
        ]);
    }

    /** A kit written straight to the database, with items given as [name, required, current, expiry|null]. */
    protected function kit(array $attributes = [], array $items = [], ?HsSite $site = null): HsFirstAidKit
    {
        $site ??= $this->site('Site '.uniqid());

        $kit = HsFirstAidKit::query()->create([
            'asset_code' => 'K-'.uniqid(),
            'kit_type' => 'medium',
            'site_id' => $site->id,
            'region_id' => $site->region_id,
            'district_id' => $site->district_id,
            'status' => 'in_service',
            'last_checked_on' => today()->toDateString(),
            'last_check_result' => 'pass',
            ...$attributes,
        ]);

        foreach ($items as $index => [$name, $required, $current, $expiry]) {
            $kit->items()->create(['item_name' => $name, 'required_qty' => $required, 'current_qty' => $current, 'expiry_date' => $expiry, 'has_expiry' => $expiry !== null, 'sort_order' => $index]);
        }

        return $kit;
    }

    protected function extinguisherData(array $overrides = []): array
    {
        return [
            'extinguisher_type' => 'dry_powder',
            'site_id' => (HsSite::query()->where('name', 'Main Office')->first() ?? $this->site('Main Office'))->id,
            'expiry_date' => today()->addYear()->toDateString(),
            ...$overrides,
        ];
    }

    // ---------------------------------------------------------------- PPE

    /** A PPE store (a site flagged as one). */
    protected function ppeStore(string $name = 'Central Store', ?\App\Models\District $district = null): HsSite
    {
        $site = $this->site($name, $district ?? $this->headOffice, 'depot');
        $site->update(['is_ppe_store' => true]);

        return $site->fresh();
    }

    protected function ppeType(array $overrides = []): HsPpeType
    {
        return HsPpeType::query()->create([
            'name' => 'Type '.uniqid(),
            'category' => 'head',
            'has_sizes' => false,
            'sizes' => null,
            'replacement_months' => null,
            'has_expiry' => false,
            'unit' => 'each',
            'is_active' => true,
            ...$overrides,
        ]);
    }

    protected function bootsType(array $overrides = []): HsPpeType
    {
        return $this->ppeType(['name' => 'Safety boots', 'category' => 'foot', 'has_sizes' => true, 'sizes' => ['40', '41', '42'], 'replacement_months' => 12, ...$overrides]);
    }

    /** Stock received by an officer through the service. */
    protected function stocked(\App\Models\HsSite $store, HsPpeType $type, int $quantity, ?string $size = null): void
    {
        app(\App\Services\HealthSafety\PpeStockService::class)->receive($this->officer('200099', $this->accraWest, $this->headOffice), $store, $type, $size, $quantity);
    }

    // ---------------------------------------------------------------- spreadsheets

    /** @var list<string> */
    private array $workbookFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->workbookFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * A small synthetic workbook as a fake upload: the heading row, then rows keyed by heading. DateTime values become real
     * Excel dates; strings stay text; numbers stay numbers. Never a real register.
     *
     * @param  list<string>  $headings
     * @param  list<array<string, mixed>>  $rows
     */
    protected function xlsx(array $headings, array $rows): \Illuminate\Http\Testing\File
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headings as $column => $heading) {
            $sheet->setCellValueExplicit([$column + 1, 1], $heading, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($headings as $column => $heading) {
                $value = $row[$heading] ?? null;
                $coordinate = [$column + 1, $rowIndex + 2];

                if ($value === null) {
                    continue;
                }

                if ($value instanceof \DateTimeInterface) {
                    $sheet->setCellValue($coordinate, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($value));
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'hs_xlsx_').'.xlsx';
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $this->workbookFiles[] = $path;

        return \Illuminate\Http\UploadedFile::fake()->createWithContent('register.xlsx', file_get_contents($path));
    }
}
