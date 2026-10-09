<?php

namespace Tests\Feature\Commercial;

use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class SeedMigrationTest extends CommercialTestCase
{
    private const MIGRATION = 'database/migrations/2026_10_05_000004_seed_commercial_module_access.php';

    public function test_re_running_the_seed_migration_changes_nothing(): void
    {
        $this->runMigration();
        $first = $this->snapshot();

        $this->runMigration();
        $this->runMigration();

        $this->assertSame($first, $this->snapshot());
    }

    public function test_it_creates_the_roles_the_eleven_permissions_and_an_access_row_for_every_role(): void
    {
        $this->runMigration();

        $this->assertSame(['commercial_manager', 'commercial_officer'], Role::query()->whereIn('name', ['commercial_officer', 'commercial_manager'])->orderBy('name')->pluck('name')->all());

        $this->assertEqualsCanonicalizing([
            'commercial.view_dashboard', 'commercial.view_billing', 'commercial.view_reading', 'commercial.view_reader_performance',
            'commercial.upload_reports', 'commercial.resolve_matches', 'commercial.void_batches', 'commercial.export_reports', 'commercial.manage_settings',
            'commercial.view_customer_analytics', 'commercial.view_customer_details',
        ], Permission::query()->where('module', Permission::MODULE_COMMERCIAL)->pluck('name')->all());

        $this->assertSame(Role::query()->count(), ModuleAccess::query()->where('module', Permission::MODULE_COMMERCIAL)->count(), 'every existing role is listed explicitly');

        $can = fn (string $role) => (bool) ModuleAccess::query()
            ->where('module', Permission::MODULE_COMMERCIAL)
            ->where('role_id', Role::query()->where('name', $role)->value('id'))
            ->value('can_access');

        foreach (['super_admin', 'commercial_officer', 'commercial_manager', 'chief_manager', 'regional_chief_manager', 'district_manager'] as $role) {
            $this->assertTrue($can($role), "{$role} should have access");
        }

        foreach (['employee', 'admin', 'ict_team', 'hr_headoffice', 'hr_region', 'manager', 'departmental_manager', 'secretary', 'receptionist', 'driver', 'transport_manager', 'managing_director', 'credit_union_officer'] as $role) {
            $this->assertFalse($can($role), "{$role} should not have access");
        }
    }

    public function test_the_migration_grants_what_the_seeder_grants(): void
    {
        $this->runMigration();

        $fromMigration = $this->grants();

        DB::table('role_permissions')->whereIn('permission_id', Permission::query()->where('module', Permission::MODULE_COMMERCIAL)->pluck('id'))->delete();
        $this->seed(\Database\Seeders\CommercialRolePermissionSeeder::class);

        $this->assertSame($fromMigration, $this->grants());
        $this->assertContains('commercial.upload_reports', $fromMigration['commercial_officer']);
        $this->assertNotContains('commercial.upload_reports', $fromMigration['commercial_manager']);
        $this->assertContains('commercial.view_reader_performance', $fromMigration['commercial_manager']);
        $this->assertNotContains('commercial.view_reader_performance', $fromMigration['regional_chief_manager']);
        $this->assertCount(11, $fromMigration['super_admin']);
    }

    public function test_the_hr_roles_do_not_pick_up_the_new_module_from_the_seeder(): void
    {
        foreach (['hr_headoffice', 'hr_region'] as $role) {
            $this->assertFalse((bool) ModuleAccess::query()
                ->where('module', Permission::MODULE_COMMERCIAL)
                ->where('role_id', Role::query()->where('name', $role)->value('id'))
                ->value('can_access'));
        }
    }

    private function runMigration(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        return [
            'roles' => DB::table('roles')->count(),
            'permissions' => DB::table('permissions')->count(),
            'module_access' => DB::table('module_access')->where('module', Permission::MODULE_COMMERCIAL)->count(),
            'role_permissions' => DB::table('role_permissions')->count(),
            'enabled' => DB::table('module_access')->where('module', Permission::MODULE_COMMERCIAL)->where('can_access', true)->count(),
        ];
    }

    /** @return array<string, list<string>> */
    private function grants(): array
    {
        return Role::query()->with('permissions')->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->where('module', Permission::MODULE_COMMERCIAL)->pluck('name')->sort()->values()->all()])
            ->filter()
            ->all();
    }
}
