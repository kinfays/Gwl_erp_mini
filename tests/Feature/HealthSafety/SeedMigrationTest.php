<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\HealthSafetyRolePermissionSeeder;
use Illuminate\Support\Facades\DB;

class SeedMigrationTest extends HealthSafetyTestCase
{
    private const MIGRATION = 'database/migrations/2026_10_07_000002_seed_health_safety_module_access.php';

    public function test_re_running_the_seed_migration_changes_nothing(): void
    {
        $this->runMigration();
        $first = $this->snapshot();

        $this->runMigration();
        $this->runMigration();

        $this->assertSame($first, $this->snapshot());
    }

    public function test_it_creates_the_two_roles_the_fourteen_permissions_and_an_access_row_for_every_role(): void
    {
        $this->runMigration();

        $this->assertSame(['hs_manager', 'hs_officer'], Role::query()->whereIn('name', ['hs_officer', 'hs_manager'])->orderBy('name')->pluck('name')->all());
        $this->assertSame('Health & Safety Officer', Role::query()->where('name', 'hs_officer')->value('display_name'));
        $this->assertSame('Health & Safety Manager', Role::query()->where('name', 'hs_manager')->value('display_name'));

        $this->assertCount(14, Permission::query()->where('module', Permission::MODULE_HEALTH_SAFETY)->get());
        $this->assertContains('health_safety.report_incident', Permission::query()->where('module', Permission::MODULE_HEALTH_SAFETY)->pluck('name')->all());

        $this->assertSame(Role::query()->count(), ModuleAccess::query()->where('module', Permission::MODULE_HEALTH_SAFETY)->count(), 'every existing role is listed explicitly');
        $this->assertSame(0, ModuleAccess::query()->where('module', Permission::MODULE_HEALTH_SAFETY)->where('can_access', false)->count(), 'and every one of them may enter: everyone reports');
    }

    public function test_every_role_may_report_an_incident(): void
    {
        $this->runMigration();

        $reportId = Permission::query()->where('name', 'health_safety.report_incident')->value('id');

        foreach (Role::query()->get() as $role) {
            $this->assertTrue($role->permissions()->whereKey($reportId)->exists(), "{$role->name} should be able to report an incident");
        }
    }

    public function test_the_migration_grants_what_the_seeder_grants(): void
    {
        $this->runMigration();

        $fromMigration = $this->grants();

        DB::table('role_permissions')->whereIn('permission_id', Permission::query()->where('module', Permission::MODULE_HEALTH_SAFETY)->pluck('id'))->delete();
        $this->seed(HealthSafetyRolePermissionSeeder::class);

        $this->assertSame($fromMigration, $this->grants());
    }

    public function test_the_grants_follow_the_role_table_in_the_design(): void
    {
        $this->runMigration();

        $grants = $this->grants();

        $this->assertEqualsCanonicalizing(
            Permission::query()->where('module', Permission::MODULE_HEALTH_SAFETY)->pluck('name')->all(),
            $grants['super_admin']
        );

        // The officer works the incidents but neither signs off the closure of High / Critical ones nor changes settings.
        $this->assertContains('health_safety.manage_incidents', $grants['hs_officer']);
        $this->assertContains('health_safety.view_injury_details', $grants['hs_officer']);
        $this->assertNotContains('health_safety.approve_closure', $grants['hs_officer']);
        $this->assertNotContains('health_safety.manage_settings', $grants['hs_officer']);

        $this->assertContains('health_safety.approve_closure', $grants['hs_manager']);
        $this->assertContains('health_safety.manage_settings', $grants['hs_manager']);

        $this->assertEqualsCanonicalizing(
            ['health_safety.report_incident', 'health_safety.view_incidents', 'health_safety.approve_closure', 'health_safety.view_dashboard', 'health_safety.view_equipment', 'health_safety.export_reports'],
            $grants['regional_chief_manager']
        );
        $this->assertEqualsCanonicalizing(
            ['health_safety.report_incident', 'health_safety.view_incidents', 'health_safety.view_dashboard', 'health_safety.view_equipment', 'health_safety.record_checks', 'health_safety.record_on_behalf'],
            $grants['district_manager']
        );

        // HR gets no safety rights yet (design section 10, question 6 is open).
        foreach (['hr_headoffice', 'hr_region', 'employee', 'admin', 'ict_team', 'chief_manager', 'departmental_manager', 'manager'] as $role) {
            $this->assertSame(['health_safety.report_incident'], $grants[$role], "{$role} may only report");
        }
    }

    public function test_the_migration_can_be_rolled_back(): void
    {
        $this->runMigration();

        $migration = require base_path(self::MIGRATION);
        $migration->down();

        $this->assertSame(0, Permission::query()->where('module', Permission::MODULE_HEALTH_SAFETY)->count());
        $this->assertSame(0, ModuleAccess::query()->where('module', Permission::MODULE_HEALTH_SAFETY)->count());
        $this->assertSame(0, Role::query()->whereIn('name', ['hs_officer', 'hs_manager'])->count());
    }

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    /** @return array<string, list<string>> role name => sorted health_safety permission slugs */
    private function grants(): array
    {
        $grants = [];

        foreach (Role::query()->orderBy('name')->get() as $role) {
            $grants[$role->name] = $role->permissions()
                ->where('module', Permission::MODULE_HEALTH_SAFETY)
                ->pluck('name')
                ->sort()
                ->values()
                ->all();
        }

        return $grants;
    }

    private function snapshot(): array
    {
        return [
            'roles' => DB::table('roles')->orderBy('id')->pluck('name')->all(),
            'permissions' => DB::table('permissions')->where('module', Permission::MODULE_HEALTH_SAFETY)->orderBy('name')->pluck('name')->all(),
            'module_access' => DB::table('module_access')->where('module', Permission::MODULE_HEALTH_SAFETY)->orderBy('role_id')->get(['role_id', 'can_access'])->map(fn ($row) => [$row->role_id, (bool) $row->can_access])->all(),
            'grants' => DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])->map(fn ($row) => [$row->role_id, $row->permission_id])->all(),
        ];
    }
}
