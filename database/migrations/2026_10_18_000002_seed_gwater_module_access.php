<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-Water (docs/17-module-g-water.md): the seven gwater.* permissions, the `g_water` role (display name "G-Water", ICT may
 * assign it, not protected, not a system role), module_access for that role and super_admin, and the seed data: the two
 * products and the placeholder price list (no location). Idempotent. Database\Seeders\GwaterRolePermissionSeeder repeats the
 * grants for fresh installs (this migration runs before any role exists there) and for tests that seed roles themselves.
 *
 * Prices are pesewas. Bottle pack: sells 25.00, costs 20.00; sachet pack: sells 10.00, costs 8.00, all from 2026-01-01.
 * The bottle selling price rose to 26.00 from 12 May 2026 (cost still 20.00): one dated row, so a sale on 11 May is priced at
 * 25.00 and one on 12 May at 26.00. These are the system defaults; a location's own rows override them from their own date.
 */
return new class extends Migration
{
    public const ROLE = 'g_water';

    public const PERMISSIONS = [
        'gwater.view' => 'View',
        'gwater.record' => 'Record',
        'gwater.manage_prices' => 'Manage Prices',
        'gwater.view_reports' => 'View Reports',
        'gwater.void' => 'Void',
        'gwater.reimburse' => 'Reimburse',
        'gwater.export' => 'Export',
    ];

    public function up(): void
    {
        $now = now();

        $role = [
            'display_name' => 'G-Water',
            'description' => 'G-Water bottled and sachet water recording',
            'is_system' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach (['ict_assignable' => true, 'is_protected' => false] as $column => $value) {
            if (Schema::hasColumn('roles', $column)) {
                $role[$column] = $value;
            }
        }

        DB::table('roles')->updateOrInsert(['name' => self::ROLE], $role);

        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'display_name' => $label,
                    'module' => Permission::MODULE_GWATER,
                    'description' => $label.' permission for GWATER module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        foreach (DB::table('roles')->whereIn('name', [self::ROLE, 'super_admin'])->pluck('id') as $roleId) {
            DB::table('module_access')->updateOrInsert(
                ['role_id' => $roleId, 'module' => Permission::MODULE_GWATER],
                ['can_access' => true, 'created_at' => $now, 'updated_at' => $now]
            );

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $this->seedProductsAndPrices($now);
    }

    public function down(): void
    {
        DB::table('module_access')->where('module', Permission::MODULE_GWATER)->delete();
        DB::table('permissions')->where('module', Permission::MODULE_GWATER)->delete();
        DB::table('roles')->where('name', self::ROLE)->delete();

        if (Schema::hasTable('gw_prices')) {
            DB::table('gw_prices')->whereNull('region_id')->whereNull('district_id')->whereNull('created_by')->delete();
        }
    }

    private function seedProductsAndPrices($now): void
    {
        if (! Schema::hasTable('gw_products') || ! Schema::hasTable('gw_prices')) {
            return;
        }

        foreach ([['bottle', 'Bottled water (pack of 15)', 15], ['sachet', 'Sachet water (pack of 30)', 30]] as [$code, $name, $units]) {
            if (! DB::table('gw_products')->where('code', $code)->exists()) {
                DB::table('gw_products')->insert([
                    'code' => $code,
                    'name' => $name,
                    'units_per_pack' => $units,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $rows = [
            ['bottle', '2026-01-01', 2500, 2000, 'Placeholder price list'],
            ['sachet', '2026-01-01', 1000, 800, 'Placeholder price list'],
            ['bottle', '2026-05-12', 2600, 2000, 'Selling price increase from 12 May 2026'],
        ];

        foreach ($rows as [$code, $from, $selling, $cost, $note]) {
            $productId = DB::table('gw_products')->where('code', $code)->value('id');

            $exists = DB::table('gw_prices')
                ->where('product_id', $productId)
                ->whereNull('region_id')
                ->whereNull('district_id')
                ->where('effective_from', $from)
                ->exists();

            if (! $exists) {
                DB::table('gw_prices')->insert([
                    'region_id' => null,
                    'district_id' => null,
                    'product_id' => $productId,
                    'effective_from' => $from,
                    'selling_price' => $selling,
                    'cost_price' => $cost,
                    'note' => $note,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
