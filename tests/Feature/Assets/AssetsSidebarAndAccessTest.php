<?php

namespace Tests\Feature\Assets;

use App\Models\Role;
use App\Models\User;
use App\Support\ErpNavigation;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssetsSidebarAndAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_the_full_assets_sidebar_including_settings(): void
    {
        $this->seedCoreAssetsAccess();
        $user = $this->superAdmin();

        $sidebar = app(ErpNavigation::class)->build($user, 'assets');
        $labels = collect($sidebar['sidebar'])->pluck('label')->all();

        $this->assertContains('Dashboard', $labels);
        $this->assertContains('Assets', $labels);
        $this->assertContains('Phones', $labels);
        $this->assertContains('Network', $labels);
        $this->assertContains('Maintenance', $labels);
        $this->assertContains('Reporting', $labels);
        $this->assertContains('Models', $labels);
        $this->assertContains('IP Ranges', $labels);
        $this->assertContains('Agent Reports', $labels);
    }

    public function test_ict_team_sees_operational_items_but_not_ip_ranges_settings(): void
    {
        $this->seedCoreAssetsAccess();
        $user = $this->user('ICT001');
        $user->roles()->attach(Role::query()->where('name', 'ict_team')->firstOrFail());

        $sidebar = app(ErpNavigation::class)->build($user, 'assets');
        $labels = collect($sidebar['sidebar'])->pluck('label')->all();

        $this->assertContains('Assets', $labels);
        $this->assertContains('Phones', $labels);
        $this->assertContains('Network', $labels);
        $this->assertContains('Maintenance', $labels);
        $this->assertNotContains('IP Ranges', $labels, 'ict_team is not granted assets.manage_ip_ranges by default.');
        $this->assertNotContains('Agent Reports', $labels, 'Agent Reports stays super_admin-only.');
    }

    public function test_assets_module_routes_require_module_access(): void
    {
        $this->seedCoreAssetsAccess();
        $outsider = $this->user('OUT001');
        $outsider->roles()->attach(Role::query()->where('name', 'employee')->firstOrFail());

        $this->actingAs($outsider)
            ->get(route('assets.assets'))
            ->assertRedirect('/dashboard');
    }

    public function test_ict_team_can_reach_the_assets_list_route(): void
    {
        $this->seedCoreAssetsAccess();
        $user = $this->user('ICT002');
        $user->roles()->attach(Role::query()->where('name', 'ict_team')->firstOrFail());

        $this->actingAs($user)
            ->get(route('assets.assets'))
            ->assertOk();
    }

    protected function seedCoreAssetsAccess(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            AssetsRolePermissionSeeder::class,
        ]);
    }

    protected function superAdmin(): User
    {
        $user = $this->user('SA001');
        $user->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());

        return $user;
    }

    protected function user(string $staffId, array $overrides = []): User
    {
        return User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            ...$overrides,
        ]);
    }
}
