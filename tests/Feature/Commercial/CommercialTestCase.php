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

    // ---------------------------------------------------------------- reading data

    /**
     * An imported reading batch built straight in the database, for the analytics tests.
     *
     * @param  array<string, array<string, array{0: int, 1: int}>>  $readers  staff id => month (Y-m-d) => [read, skipped]
     * @param  array{strength?: array<string, int>|int, status?: array<string, string>, names?: array<string, string>, districts?: array<string, int>}  $options
     */
    protected function seedReading(array $readers, ?Region $region = null, array $options = []): CommercialImportBatch
    {
        $region ??= $this->accraWest;
        $months = collect($readers)->flatMap(fn ($byMonth) => array_keys($byMonth))->unique()->sort()->values();

        $batch = CommercialImportBatch::query()->create([
            'report_type' => CommercialImportBatch::TYPE_READING_SUMMARY,
            'region_id' => $region->id,
            'period_from' => $months->first(),
            'period_to' => $months->last(),
            'granularity' => CommercialImportBatch::GRANULARITY_MONTHLY,
            'source_filename' => 'reading-'.uniqid().'.xlsx',
            'file_hash' => hash('sha256', uniqid('reading', true)),
            'status' => CommercialImportBatch::STATUS_IMPORTED,
            'imported_at' => now(),
        ]);

        foreach ($months as $month) {
            $strength = is_array($options['strength'] ?? null) ? ($options['strength'][$month] ?? null) : ($options['strength'] ?? 10000);

            if ($strength !== null) {
                $batch->strengths()->create(['month' => $month, 'verified_strength' => $strength]);
            }
        }

        foreach ($readers as $staffId => $byMonth) {
            foreach ($byMonth as $month => [$read, $skipped]) {
                $batch->stats()->create([
                    'month' => $month,
                    'reader_staff_id' => (string) $staffId,
                    'reader_name_raw' => $options['names'][$staffId] ?? 'Reader '.$staffId,
                    'district_id' => $options['districts'][$staffId] ?? null,
                    'read_count' => $read,
                    'skipped_count' => $skipped,
                    'visited_count' => $read + $skipped,
                    'match_status' => $options['status'][$staffId] ?? 'matched',
                ]);
            }
        }

        return $batch;
    }

    // ---------------------------------------------------------------- billing data

    /**
     * An imported billing batch built straight in the database, for the analytics tests.
     *
     * @param  list<array<string, mixed>>  $routes  each: district (label as printed), code, plus any route column
     * @param  array{segment?: string, region?: Region, period_to?: string, bands?: list<array{0: string, 1: int, 2: float, 3: float}>|false, districts?: array<string, int>, status?: string}  $options
     */
    protected function seedBilling(array $routes, string $month = '2026-09-01', array $options = []): CommercialImportBatch
    {
        $region = $options['region'] ?? $this->accraWest;

        $batch = CommercialImportBatch::query()->create([
            'report_type' => CommercialImportBatch::TYPE_BILLING_SUMMARY,
            'region_id' => $region->id,
            'period_from' => $month,
            'period_to' => $options['period_to'] ?? date('Y-m-t', strtotime($month)),
            'granularity' => isset($options['period_to']) ? CommercialImportBatch::GRANULARITY_MULTI_MONTH : CommercialImportBatch::GRANULARITY_MONTHLY,
            'customer_segment' => $options['segment'] ?? 'new_service',
            'source_filename' => 'billing-'.uniqid().'.xlsx',
            'file_hash' => hash('sha256', uniqid('billing', true)),
            'status' => $options['status'] ?? CommercialImportBatch::STATUS_IMPORTED,
            'imported_at' => now(),
        ]);

        foreach ($routes as $route) {
            $label = $route['district'];
            unset($route['district']);

            $batch->routes()->create([
                'district_label_raw' => $label,
                'district_id' => $options['districts'][$label] ?? null,
                'route_code' => $route['code'],
                ...collect($route)->except('code')->all(),
            ]);
        }

        foreach (($options['bands'] ?? []) ?: [] as [$band, $customers, $volume, $amount]) {
            $batch->bands()->create(['category_code' => '611', 'band' => $band, 'customers' => $customers, 'volume' => $volume, 'amount' => $amount]);
        }

        return $batch;
    }

    /** The small hand-computed fixture most billing tests use (see BillingAnalyticsTest for the arithmetic). @return list<array<string, mixed>> */
    protected function billingFixture(): array
    {
        return [
            ['district' => 'SOWUTUOM', 'code' => 'SOWUTUOM 1', 'customers_count' => 7777,
                'volume_actual' => 60, 'volume_average' => 40, 'volume_total' => 100,
                'opening_balance' => 0, 'billing_for_period' => 1000, 'revenue_adjustment' => 0, 'total_receivable' => 1000,
                'payment_for_month' => 600, 'prev_month_payment' => 200, 'offset_payments' => 0, 'total_payments' => 800, 'closing_balance' => 200,
                'billed_average_metered' => 4, 'billed_average_unmetered' => 1, 'billed_actual_reading' => 5, 'billed_total' => 10,
                'unbilled_total' => 0],
            ['district' => 'SOWUTUOM', 'code' => 'SOWUTUOM 2', 'customers_count' => 7777,
                'volume_actual' => 0, 'volume_average' => 10, 'volume_total' => 10,
                'opening_balance' => 0, 'billing_for_period' => 100, 'revenue_adjustment' => 0, 'total_receivable' => 100,
                'payment_for_month' => 0, 'prev_month_payment' => 0, 'offset_payments' => 0, 'total_payments' => 0, 'closing_balance' => 100,
                'billed_average_metered' => 2, 'billed_average_unmetered' => 0, 'billed_actual_reading' => 0, 'billed_total' => 2,
                'unbilled_suspense_metered' => 8, 'unbilled_total' => 8],
            ['district' => 'ODORKOR', 'code' => 'ODORKOR 1', 'customers_count' => 7777,
                'volume_actual' => 50, 'volume_average' => 0, 'volume_total' => 50,
                'opening_balance' => -400, 'billing_for_period' => 500, 'revenue_adjustment' => 0, 'total_receivable' => 100,
                'payment_for_month' => 100, 'prev_month_payment' => 300, 'offset_payments' => 0, 'total_payments' => 400, 'closing_balance' => -300,
                'billed_average_metered' => 0, 'billed_average_unmetered' => 0, 'billed_actual_reading' => 5, 'billed_total' => 5,
                'unbilled_total' => 0],
            ['district' => 'ODORKOR', 'code' => 'ODORKOR 2', 'customers_count' => 7777,
                'volume_actual' => 0, 'volume_average' => 0, 'volume_total' => 0,
                'opening_balance' => -50, 'billing_for_period' => 0, 'revenue_adjustment' => 0, 'total_receivable' => -50,
                'payment_for_month' => 0, 'prev_month_payment' => 0, 'offset_payments' => 0, 'total_payments' => 0, 'closing_balance' => -50,
                'billed_total' => 0, 'unbilled_total' => 0],
        ];
    }

    /** Same counts in every one of the given months. @return array<string, array{0: int, 1: int}> */
    protected function everyMonth(array $months, int $read, int $skipped): array
    {
        return array_fill_keys($months, [$read, $skipped]);
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
