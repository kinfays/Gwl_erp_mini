<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\NetworkList;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctIpRange;
use App\Models\JobTitle;
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
        $district = $this->district('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

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
        $this->assertSame($district->region_id, $asset->region_id);
    }

    public function test_network_region_is_forced_to_the_actor_region_and_never_accepted_from_form_input(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $otherDistrict = $this->district('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->superAdmin($homeDistrict));

        Livewire::test(NetworkList::class)
            ->assertSet('form.region_id', $homeDistrict->region_id)
            ->call('openCreate')
            ->set('form.asset_name', 'Branch Switch 01')
            ->set('form.asset_type', 'SW')
            ->set('form.region_id', $otherDistrict->region_id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('asset_name', 'Branch Switch 01')->firstOrFail();
        $this->assertSame($homeDistrict->region_id, $asset->region_id);
    }

    public function test_network_district_outside_the_actor_region_is_rejected(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $otherDistrict = $this->district('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->superAdmin($homeDistrict));

        $component = Livewire::test(NetworkList::class);
        $this->assertSame([$homeDistrict->id], $component->viewData('formDistricts')->pluck('id')->all());

        $component
            ->call('openCreate')
            ->set('form.asset_name', 'Branch Switch 02')
            ->set('form.asset_type', 'SW')
            ->set('form.district_id', $otherDistrict->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors(['form.district_id' => 'exists']);

        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_actor_without_a_region_on_file_cannot_save_a_network_device(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(NetworkList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Branch Switch 03')
            ->set('form.asset_type', 'SW')
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors(['form.region_id']);

        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_login_and_ssid_passwords_are_encrypted_at_rest(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin($this->district('Accra Central District', 'Greater Accra')));

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
        $this->actingAs($this->superAdmin($this->district('Accra Central District', 'Greater Accra')));

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
        $district = $this->district('Accra Central District', 'Greater Accra');

        IctIpRange::query()->create([
            'label' => 'HQ LAN',
            'district_id' => $district->id,
            'start_ip' => '10.0.0.1',
            'end_ip' => '10.0.0.254',
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin($district));

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

    /**
     * Region is derived from the actor's employee record, so pass a district
     * to give the super admin one; omit it for an account with no region.
     */
    protected function superAdmin(?District $homeDistrict = null): User
    {
        $user = $this->user('SA001');
        $user->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());

        if ($homeDistrict) {
            $this->createEmployee('SA001', 'Super Admin', $homeDistrict);
        }

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

    protected function district(string $districtName, string $regionName): District
    {
        $region = Region::query()->firstOrCreate(['region_name' => $regionName]);

        return District::query()->firstOrCreate(
            ['district_name' => $districtName],
            ['region_id' => $region->id]
        );
    }

    protected function createEmployee(string $staffId, string $fullName, District $district): Employee
    {
        $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $fullName,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-01-01',
            'present_appointment' => '2026-01-01',
            'is_active' => true,
        ]);
    }
}
