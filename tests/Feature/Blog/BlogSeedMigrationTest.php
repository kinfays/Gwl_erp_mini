<?php

namespace Tests\Feature\Blog;

use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RegionalBlogRolePermissionSeeder;
use Illuminate\Support\Facades\DB;

class BlogSeedMigrationTest extends BlogTestCase
{
    private const MIGRATION = 'database/migrations/2026_10_13_000002_seed_regional_blog_module_access.php';

    public function test_re_running_the_seed_migration_changes_nothing(): void
    {
        $this->runMigration();
        $first = $this->snapshot();

        $this->runMigration();
        $this->runMigration();

        $this->assertSame($first, $this->snapshot());
    }

    public function test_it_creates_the_role_the_permission_and_an_access_row_for_every_role(): void
    {
        $this->runMigration();

        $this->assertSame('Public Relations Officer', Role::query()->where('name', 'pr_officer')->value('display_name'));
        $this->assertSame(['blog.manage_posts'], Permission::query()->where('module', Permission::MODULE_BLOG)->pluck('name')->all());

        $this->assertSame(Role::query()->count(), ModuleAccess::query()->where('module', Permission::MODULE_BLOG)->count(), 'every existing role is listed explicitly');
        $this->assertSame(0, ModuleAccess::query()->where('module', Permission::MODULE_BLOG)->where('can_access', false)->count(), 'and every one of them may enter: everyone reads');
    }

    public function test_only_the_pr_officer_and_super_admin_may_write(): void
    {
        $this->runMigration();

        $this->assertSame(['pr_officer', 'super_admin'], array_keys(array_filter($this->grants())));
    }

    public function test_the_migration_grants_what_the_seeder_grants(): void
    {
        $this->runMigration();
        $fromMigration = $this->grants();

        DB::table('role_permissions')->whereIn('permission_id', Permission::query()->where('module', Permission::MODULE_BLOG)->pluck('id'))->delete();
        $this->seed(RegionalBlogRolePermissionSeeder::class);

        $this->assertSame($fromMigration, $this->grants());
    }

    public function test_the_migration_can_be_rolled_back(): void
    {
        $this->runMigration();

        $migration = require base_path(self::MIGRATION);
        $migration->down();

        $this->assertSame(0, Permission::query()->where('module', Permission::MODULE_BLOG)->count());
        $this->assertSame(0, ModuleAccess::query()->where('module', Permission::MODULE_BLOG)->count());
        $this->assertSame(0, Role::query()->where('name', 'pr_officer')->count());
    }

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    /** @return array<string, list<string>> role name => blog permission slugs (only roles that hold any) */
    private function grants(): array
    {
        $grants = [];

        foreach (Role::query()->orderBy('name')->get() as $role) {
            $grants[$role->name] = $role->permissions()->where('module', Permission::MODULE_BLOG)->pluck('name')->sort()->values()->all();
        }

        return $grants;
    }

    private function snapshot(): array
    {
        return [
            'roles' => DB::table('roles')->orderBy('id')->pluck('name')->all(),
            'permissions' => DB::table('permissions')->where('module', Permission::MODULE_BLOG)->orderBy('name')->pluck('name')->all(),
            'module_access' => DB::table('module_access')->where('module', Permission::MODULE_BLOG)->orderBy('role_id')->get(['role_id', 'can_access'])->map(fn ($row) => [$row->role_id, (bool) $row->can_access])->all(),
            'grants' => DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])->map(fn ($row) => [$row->role_id, $row->permission_id])->all(),
        ];
    }
}
