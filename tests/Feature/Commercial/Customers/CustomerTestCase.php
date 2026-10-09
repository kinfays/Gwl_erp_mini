<?php

namespace Tests\Feature\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Models\District;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\Customers\CustomerImportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commercial\CommercialTestCase;
use Tests\Support\Commercial\ReportWorkbooks;

/** Shared builders for the customer-list tests. Synthetic data only. */
abstract class CustomerTestCase extends CommercialTestCase
{
    protected District $kumasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kumasi = District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi']);
    }

    /** @return list<array<string, mixed>> */
    protected function customers(int $from, int $to, array $over = []): array
    {
        return array_map(fn (int $n) => ReportWorkbooks::customer($n, $over), range($from, $to));
    }

    /** A one-route file of customers $from..$to. */
    protected function simpleSpec(int $from = 1, int $to = 5, array $extra = [], string $route = '1001'): array
    {
        return $extra + ['routes' => [['name' => $route, 'customers' => $this->customers($from, $to)]]];
    }

    /** Builds the workbook, registers it and runs the whole pipeline in the foreground. */
    protected function loadCustomers(array $spec, string $asOf = '2026-10-05', string $period = 'monthly', ?User $actor = null): CommercialCustomerBatch
    {
        $path = ReportWorkbooks::customerList($spec);
        $this->workbooks[] = $path;

        $imports = app(CustomerImportService::class);
        ['batch' => $batch] = $imports->register($path, 'customers-'.uniqid().'.xlsx', $period, Carbon::parse($asOf), $actor);

        return $imports->process($batch);
    }

    /** Accra West (Sowutuom) customers 1..5 and Ashanti (Kumasi) customers 101..103, both imported. */
    protected function loadTwoRegions(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        $this->loadCustomers($this->simpleSpec(101, 103, ['region' => 'ASHANTI', 'district' => 'KUMASI'], '2001'));
    }

    protected function customerCount(?int $districtId = null): int
    {
        return (int) DB::table('commercial_customers')->when($districtId, fn ($q) => $q->where('district_id', $districtId))->count();
    }

    protected function customerRow(int $n): ?object
    {
        return DB::table('commercial_customers')->where('account_no', str_pad((string) (100000000000 + $n), 12, '0', STR_PAD_LEFT))->first();
    }

    protected function customerId(int $n): int
    {
        return (int) $this->customerRow($n)->id;
    }

    /** A user holding exactly the given commercial permissions (and nothing else), in the given region. */
    protected function userWith(string $staffId, array $permissions, ?Region $region = null, ?District $district = null): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'custom_'.$staffId], ['display_name' => 'Custom '.$staffId, 'is_system' => false]);
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        ModuleAccess::query()->updateOrCreate(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL], ['can_access' => true]);

        $user = $this->userWithRoles($staffId, [], $region, $district);
        $user->roles()->syncWithoutDetaching($role);

        return $user->fresh();
    }

    /** Everything the customer list shows, without names: what a commercial officer may do. */
    protected function analyst(string $staffId = '900020', ?Region $region = null, ?District $district = null): User
    {
        return $this->userWith($staffId, ['commercial.view_customer_analytics', 'commercial.export_reports'], $region, $district);
    }

    /** As the analyst, plus names and addresses (phones and e-mails masked). */
    protected function detailer(string $staffId = '900021', ?Region $region = null, ?District $district = null): User
    {
        return $this->userWith($staffId, ['commercial.view_customer_analytics', 'commercial.view_customer_details', 'commercial.export_reports'], $region, $district);
    }
}
