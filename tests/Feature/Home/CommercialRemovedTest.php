<?php

namespace Tests\Feature\Home;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * The Commercial module was removed (migration 2026_11_26_000001_remove_commercial_module). Only its name stays, as an inert
 * card on the Home screen: shown to everyone, not a link, and backed by no route, table, permission, role or flag.
 */
class CommercialRemovedTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->buildUacOrg();
    }

    public function test_the_home_screen_shows_the_commercial_name_to_everyone_as_a_card_that_opens_nothing(): void
    {
        foreach ([[], ['employee'], ['super_admin'], ['district_manager']] as $i => $roles) {
            $user = $this->person('CM00'.$i, $this->temaDistrict, $roles);
            $this->actingAs($user);

            $card = collect(dashboardModules())->firstWhere('slug', 'commercial');
            $this->assertNotNull($card, json_encode($roles));
            $this->assertSame('Commercial', $card['title']);
            $this->assertNull($card['route']);
            $this->assertTrue($card['placeholder']);

            $this->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Commercial')
                ->assertSee('Coming soon')
                ->assertSee('dashboard-card is-placeholder', false)
                ->assertDontSee('/commercial', false);
        }
    }

    public function test_no_commercial_route_permission_role_module_or_table_is_left(): void
    {
        $this->assertSame([], collect(Route::getRoutes()->getRoutesByName())->keys()->filter(
            fn (string $name) => str_starts_with($name, 'commercial.') || $name === 'webhooks.sms-arkesel'
        )->values()->all());
        $this->get('/commercial')->assertNotFound();

        $this->assertNotContains(Permission::MODULE_COMMERCIAL, Permission::MODULES);
        $this->assertSame(0, DB::table('permissions')->where('module', 'commercial')->orWhere('name', 'like', 'commercial.%')->count());
        $this->assertSame(0, DB::table('module_access')->where('module', 'commercial')->count());
        $this->assertSame(0, Role::query()->whereIn('name', ['commercial_officer', 'commercial_manager', 'district_commercial_viewer'])->count());

        $tables = collect(Schema::getTableListing(schemaQualified: false))->filter(fn (string $t) => str_starts_with($t, 'commercial_'));
        $this->assertSame([], $tables->values()->all());

        $this->assertNull(config('gwl.commercial_module_enabled'));
        $this->assertNull(config('gwl.arkesel'));
    }

    public function test_the_removal_migration_clears_commercial_rows_and_files_and_can_run_twice(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('commercial/imports/left-over.xlsx', 'x');
        Storage::disk('local')->put('commercial-export-abc.xlsx', 'x');
        Storage::disk('local')->put('gwater/waybills/keep.pdf', 'x');

        // Rows the old module could have left behind.
        $role = Role::query()->create(['name' => 'commercial_officer', 'display_name' => 'Commercial Officer', 'description' => 'x', 'is_system' => true]);
        $user = $this->person('CM100', $this->temaDistrict, ['employee']);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $role->id]);
        $permissionId = DB::table('permissions')->insertGetId(['name' => 'commercial.view_billing', 'display_name' => 'View Billing', 'module' => 'commercial', 'description' => 'x']);
        DB::table('role_permissions')->insert(['role_id' => Role::query()->where('name', 'district_manager')->value('id'), 'permission_id' => $permissionId]);
        DB::table('module_access')->insert(['role_id' => Role::query()->where('name', 'district_manager')->value('id'), 'module' => 'commercial', 'can_access' => true]);
        DB::table('notifications')->insert([
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $user->id, 'data' => json_encode(['title' => 'Overdue', 'module' => 'commercial']), 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $user->id, 'data' => json_encode(['title' => 'Kept', 'module' => 'leave']), 'created_at' => now(), 'updated_at' => now()],
        ]);
        Schema::create('commercial_left_over', fn ($table) => $table->id());

        $migration = require database_path('migrations/2026_11_26_000001_remove_commercial_module.php');
        $migration->up();
        $migration->up();

        $this->assertFalse(Schema::hasTable('commercial_left_over'));
        $this->assertSame(0, Role::query()->where('name', 'commercial_officer')->count());
        $this->assertSame(0, DB::table('user_roles')->where('role_id', $role->id)->count());
        $this->assertTrue($user->fresh()->hasRoles('employee'));
        $this->assertSame(0, DB::table('permissions')->where('id', $permissionId)->count());
        $this->assertSame(0, DB::table('role_permissions')->where('permission_id', $permissionId)->count());
        $this->assertSame(0, DB::table('module_access')->where('module', 'commercial')->count());
        $this->assertSame(['Kept'], DB::table('notifications')->pluck('data')->map(fn ($d) => json_decode($d, true)['title'])->all());

        Storage::disk('local')->assertMissing('commercial/imports/left-over.xlsx');
        Storage::disk('local')->assertMissing('commercial-export-abc.xlsx');
        Storage::disk('local')->assertExists('gwater/waybills/keep.pdf');
    }
}
