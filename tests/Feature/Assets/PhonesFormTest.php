<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\PhonesList;
use App\Models\IctAsset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PhonesFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_phone_device_requires_asset_name_and_type(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(PhonesList::class)
            ->call('openCreate')
            ->call('save')
            ->assertHasErrors(['form.asset_name', 'form.asset_type']);
    }

    public function test_creating_a_phone_device_persists_imei_and_numbers_under_the_phone_category(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(PhonesList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Field POS 01')
            ->set('form.asset_type', 'POS')
            ->set('form.serial_number', 'POS-0001')
            ->set('form.imei', '123456789012345')
            ->set('form.user_phone_number', '0200000001')
            ->set('form.device_phone_number', '0200000002')
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('serial_number', 'POS-0001')->firstOrFail();
        $this->assertSame(IctAsset::DEVICE_CATEGORY_PHONE, $asset->device_category);
        $this->assertSame('123456789012345', $asset->imei);
        $this->assertSame('0200000002', $asset->device_phone_number);
    }

    public function test_phone_type_dropdown_is_scoped_to_the_phone_category_vocabulary(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        $assetTypes = Livewire::test(PhonesList::class)->viewData('assetTypes');

        $this->assertArrayHasKey('POS', $assetTypes);
        $this->assertArrayHasKey('SIM', $assetTypes);
        $this->assertArrayNotHasKey('PC', $assetTypes);
    }

    public function test_editing_a_network_device_via_the_phones_screen_is_rejected(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        $networkAsset = IctAsset::query()->create([
            'asset_name' => 'Router 01',
            'asset_type' => 'RT',
            'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK,
            'status' => IctAsset::STATUS_ACTIVE,
        ]);

        $this->withoutExceptionHandling();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(PhonesList::class)->call('openEdit', $networkAsset->id);
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
