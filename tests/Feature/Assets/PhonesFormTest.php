<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\PhonesList;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\JobTitle;
use App\Models\Region;
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
        $this->actingAs($this->superAdmin($this->district('Accra Central District', 'Greater Accra')));

        Livewire::test(PhonesList::class)
            ->call('openCreate')
            ->call('save')
            ->assertHasErrors(['form.asset_name', 'form.asset_type']);
    }

    public function test_creating_a_phone_device_persists_imei_and_numbers_under_the_phone_category(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->district('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

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
        $this->assertSame($district->region_id, $asset->region_id);
    }

    public function test_phone_region_is_forced_to_the_actor_region_and_never_accepted_from_form_input(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $otherDistrict = $this->district('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->superAdmin($homeDistrict));

        Livewire::test(PhonesList::class)
            ->assertSet('form.region_id', $homeDistrict->region_id)
            ->call('openCreate')
            ->set('form.asset_name', 'Field POS 02')
            ->set('form.asset_type', 'POS')
            ->set('form.region_id', $otherDistrict->region_id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('asset_name', 'Field POS 02')->firstOrFail();
        $this->assertSame($homeDistrict->region_id, $asset->region_id);
    }

    public function test_phone_location_outside_the_actor_region_is_rejected(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $otherDistrict = $this->district('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->superAdmin($homeDistrict));

        Livewire::test(PhonesList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Field POS 03')
            ->set('form.asset_type', 'POS')
            ->set('form.district_id', $otherDistrict->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors(['form.district_id' => 'exists']);

        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_phone_location_backfills_from_an_assignee_in_the_actor_region(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $assignee = $this->createEmployee('100001', 'Accra Employee', $homeDistrict);
        $this->actingAs($this->superAdmin($homeDistrict));

        Livewire::test(PhonesList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Field POS 04')
            ->set('form.asset_type', 'POS')
            ->set('form.assigned_to_employee_id', $assignee->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($homeDistrict->id, IctAsset::query()->where('asset_name', 'Field POS 04')->firstOrFail()->district_id);
    }

    public function test_phone_location_does_not_backfill_from_an_assignee_in_another_region(): void
    {
        $this->seedCoreAssetsAccess();
        $homeDistrict = $this->district('Accra Central District', 'Greater Accra');
        $otherDistrict = $this->district('Kumasi Central District', 'Ashanti');
        $assignee = $this->createEmployee('100002', 'Kumasi Employee', $otherDistrict);
        $this->actingAs($this->superAdmin($homeDistrict));

        Livewire::test(PhonesList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Field POS 05')
            ->set('form.asset_type', 'POS')
            ->set('form.assigned_to_employee_id', $assignee->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('asset_name', 'Field POS 05')->firstOrFail();
        $this->assertNull($asset->district_id);
        $this->assertSame($homeDistrict->region_id, $asset->region_id);
    }

    public function test_actor_without_a_region_on_file_cannot_save_a_phone_device(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(PhonesList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Field POS 06')
            ->set('form.asset_type', 'POS')
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors(['form.region_id']);

        $this->assertSame(0, IctAsset::query()->count());
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
