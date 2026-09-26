<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\Settings\ManufacturersManager;
use App\Models\AuditLog;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ManufacturersManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_a_manufacturer(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(ManufacturersManager::class)
            ->set('name', 'Lenovo')
            ->set('notes', 'Laptops and desktops')
            ->call('save')
            ->assertHasNoErrors();

        $manufacturer = IctAssetManufacturer::query()->where('name', 'Lenovo')->firstOrFail();

        $this->assertTrue($manufacturer->is_active);
        $this->assertSame('Laptops and desktops', $manufacturer->notes);
        $this->assertTrue(AuditLog::query()->where('action', 'create_asset_manufacturer')->where('target_id', $manufacturer->id)->exists());
    }

    public function test_manufacturer_names_must_be_unique(): void
    {
        $this->seedCoreAssetsAccess();
        IctAssetManufacturer::query()->create(['name' => 'Lenovo', 'is_active' => true]);
        $this->actingAs($this->superAdmin());

        Livewire::test(ManufacturersManager::class)
            ->set('name', 'Lenovo')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);

        $this->assertSame(1, IctAssetManufacturer::query()->count());
    }

    public function test_super_admin_can_rename_and_deactivate_a_manufacturer(): void
    {
        $this->seedCoreAssetsAccess();
        $manufacturer = IctAssetManufacturer::query()->create(['name' => 'HP', 'is_active' => true]);
        $this->actingAs($this->superAdmin());

        Livewire::test(ManufacturersManager::class)
            ->call('edit', $manufacturer->id)
            ->set('editingName', 'Hewlett Packard')
            ->set('editingIsActive', false)
            ->call('update')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $manufacturer->refresh();

        $this->assertSame('Hewlett Packard', $manufacturer->name);
        $this->assertFalse($manufacturer->is_active);
        $this->assertTrue(AuditLog::query()->where('action', 'update_asset_manufacturer')->where('target_id', $manufacturer->id)->exists());
    }

    public function test_a_manufacturer_that_still_has_models_cannot_be_deleted(): void
    {
        $this->seedCoreAssetsAccess();
        $manufacturer = IctAssetManufacturer::query()->create(['name' => 'Dell', 'is_active' => true]);
        IctAssetModel::query()->create([
            'name' => 'Dell OptiPlex 7090',
            'category' => 'PC',
            'ict_asset_manufacturer_id' => $manufacturer->id,
            'is_active' => true,
        ]);
        $this->actingAs($this->superAdmin());

        Livewire::test(ManufacturersManager::class)
            ->call('delete', $manufacturer->id);

        $this->assertDatabaseHas('ict_asset_manufacturers', ['id' => $manufacturer->id]);
    }

    public function test_an_unused_manufacturer_can_be_deleted(): void
    {
        $this->seedCoreAssetsAccess();
        $manufacturer = IctAssetManufacturer::query()->create(['name' => 'Compaq', 'is_active' => true]);
        $this->actingAs($this->superAdmin());

        Livewire::test(ManufacturersManager::class)
            ->call('delete', $manufacturer->id);

        $this->assertDatabaseMissing('ict_asset_manufacturers', ['id' => $manufacturer->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'delete_asset_manufacturer')->where('target_id', $manufacturer->id)->exists());
    }

    public function test_super_admin_can_open_the_manufacturers_settings_page(): void
    {
        $this->seedCoreAssetsAccess();

        $this->actingAs($this->superAdmin())
            ->get(route('assets.settings.manufacturers'))
            ->assertOk()
            ->assertSee('Manufacturer Directory');
    }

    public function test_ict_team_member_without_manage_manufacturers_permission_cannot_open_the_screen(): void
    {
        $this->seedCoreAssetsAccess();
        $user = $this->user('ICT001');
        $user->roles()->attach(Role::query()->where('name', 'ict_team')->firstOrFail());
        $this->actingAs($user);

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);

        Livewire::test(ManufacturersManager::class);
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
