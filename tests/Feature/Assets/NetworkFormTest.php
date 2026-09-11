<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\NetworkList;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctIpRange;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class NetworkFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_network_device_persists_it_under_the_network_category(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(NetworkList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Core Router 01')
            ->set('form.asset_type', 'RT')
            ->set('form.serial_number', 'RT-0001')
            ->set('form.device_ip', '10.0.0.1')
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('serial_number', 'RT-0001')->firstOrFail();
        $this->assertSame(IctAsset::DEVICE_CATEGORY_NETWORK, $asset->device_category);
    }

    public function test_login_and_ssid_passwords_are_encrypted_at_rest(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(NetworkList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Office AP')
            ->set('form.asset_type', 'AP')
            ->set('form.serial_number', 'AP-0001')
            ->set('form.login_password', 'super-secret-login')
            ->set('form.ssid_password', 'super-secret-wifi')
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('serial_number', 'AP-0001')->firstOrFail();

        $this->assertSame('super-secret-login', $asset->login_password);
        $this->assertSame('super-secret-wifi', $asset->ssid_password);

        $rawRow = DB::table('ict_assets')->where('id', $asset->id)->first();
        $this->assertStringNotContainsString('super-secret-login', $rawRow->login_password);
        $this->assertStringNotContainsString('super-secret-wifi', $rawRow->ssid_password);
    }

    public function test_leaving_password_fields_blank_on_edit_keeps_the_existing_secret(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        $asset = IctAsset::query()->create([
            'asset_name' => 'Office AP',
            'asset_type' => 'AP',
            'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK,
            'status' => IctAsset::STATUS_ACTIVE,
            'login_password' => 'original-login',
            'ssid_password' => 'original-wifi',
        ]);

        Livewire::test(NetworkList::class)
            ->call('openEdit', $asset->id)
            ->set('form.asset_name', 'Office AP Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $asset->refresh();
        $this->assertSame('Office AP Renamed', $asset->asset_name);
        $this->assertSame('original-login', $asset->login_password);
        $this->assertSame('original-wifi', $asset->ssid_password);
    }

    public function test_network_secrets_are_masked_unless_the_viewer_has_permission(): void
    {
        $this->seedCoreAssetsAccess();

        IctAsset::query()->create([
            'asset_name' => 'Office AP',
            'asset_type' => 'AP',
            'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK,
            'status' => IctAsset::STATUS_ACTIVE,
            'login_password' => 'original-login',
            'ssid_password' => 'original-wifi',
        ]);

        // super_admin has assets.view_network_secrets by default (see the
        // Assets migration's permission seeding).
        $this->actingAs($this->superAdmin());
        Livewire::test(NetworkList::class)
            ->assertViewHas('canViewSecrets', true);

        // A plain employee has no assets permissions at all.
        $this->actingAs($this->user('EMP001'));
        $this->withoutExceptionHandling();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        Livewire::test(NetworkList::class)->call('toggleReveal', 1);
    }

    public function test_device_ip_outside_configured_range_is_rejected(): void
    {
        $this->seedCoreAssetsAccess();
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create(['district_name' => 'Accra Central District', 'region_id' => $region->id]);

        IctIpRange::query()->create([
            'label' => 'HQ LAN',
            'district_id' => $district->id,
            'start_ip' => '10.0.0.1',
            'end_ip' => '10.0.0.254',
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin());

        Livewire::test(NetworkList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Out Of Range AP')
            ->set('form.asset_type', 'AP')
            ->set('form.district_id', $district->id)
            ->set('form.device_ip', '192.168.1.50')
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors(['form.device_ip']);
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
