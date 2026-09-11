<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\Dashboard;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetMaintenance;
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

class AssetDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_kpi_cards_bucket_assets_by_category(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->createDistrict();

        $this->createAsset(['asset_type' => 'PC', 'district_id' => $district->id]);
        $this->createAsset(['asset_type' => 'PC', 'district_id' => $district->id]);
        $this->createAsset(['asset_type' => 'AIO', 'district_id' => $district->id]);
        $this->createAsset(['asset_type' => 'Laptop', 'district_id' => $district->id]);
        $this->createAsset(['asset_type' => 'Ph', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'district_id' => $district->id]);
        $this->createAsset(['asset_type' => 'RT', 'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK, 'district_id' => $district->id]);

        $this->actingAs($this->superAdmin());

        Livewire::test(Dashboard::class)
            ->assertViewHas('cards', function ($cards) {
                return $cards['computers']['total'] === 3
                    && $cards['computers']['badges']['PC'] === 2
                    && $cards['computers']['badges']['AIO'] === 1
                    && $cards['laptops']['total'] === 1
                    && $cards['phones']['total'] === 1
                    && $cards['network']['total'] === 1;
            });
    }

    public function test_district_marked_attention_when_it_has_an_open_maintenance_ticket(): void
    {
        $this->seedCoreAssetsAccess();
        $optimalDistrict = $this->createDistrict('Optimal District');
        $attentionDistrict = $this->createDistrict('Attention District');

        $this->createAsset(['district_id' => $optimalDistrict->id]);
        $flagged = $this->createAsset(['district_id' => $attentionDistrict->id]);

        IctAssetMaintenance::query()->create([
            'ict_asset_id' => $flagged->id,
            'maintenance_type' => 'Repair',
            'status' => 'Open',
        ]);

        $this->actingAs($this->superAdmin());

        Livewire::test(Dashboard::class)
            ->assertViewHas('districtBreakdown', function ($rows) use ($optimalDistrict, $attentionDistrict) {
                $byName = collect($rows)->keyBy('district_name');

                return $byName[$optimalDistrict->district_name]['status'] === 'OPTIMAL'
                    && $byName[$attentionDistrict->district_name]['status'] === 'ATTENTION';
            });
    }

    public function test_district_marked_attention_when_it_has_an_open_issue_report(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->createDistrict();
        $this->createAsset(['district_id' => $district->id]);

        IctAssetIssueReport::query()->create([
            'title' => 'No network',
            'issue_type' => 'Network',
            'status' => 'Open',
            'reporting_district_id' => $district->id,
        ]);

        $this->actingAs($this->superAdmin());

        Livewire::test(Dashboard::class)
            ->assertViewHas('districtBreakdown', function ($rows) use ($district) {
                return collect($rows)->keyBy('district_name')[$district->district_name]['status'] === 'ATTENTION';
            });
    }

    public function test_device_allocation_percentages_cover_computers_phones_network_and_printers(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->createDistrict();

        $this->createAsset(['asset_type' => 'PC', 'district_id' => $district->id]);
        $this->createAsset(['asset_type' => 'PC', 'district_id' => $district->id]);
        $this->createAsset(['asset_type' => 'PRT', 'district_id' => $district->id]);
        $this->createAsset(['asset_type' => 'Ph', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'district_id' => $district->id]);

        $this->actingAs($this->superAdmin());

        Livewire::test(Dashboard::class)
            ->assertViewHas('allocation', function ($allocation) {
                $byKey = collect($allocation)->keyBy('key');

                return $byKey['computers']['percentage'] === 50.0
                    && $byKey['printers']['percentage'] === 25.0
                    && $byKey['phones']['percentage'] === 25.0
                    && $byKey['network']['percentage'] === 0.0;
            });
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

    protected function createDistrict(string $name = 'Accra Central District'): District
    {
        $region = Region::query()->firstOrCreate(['region_name' => 'Greater Accra']);

        return District::query()->create([
            'district_name' => $name,
            'region_id' => $region->id,
        ]);
    }

    protected function createAsset(array $overrides = []): IctAsset
    {
        static $serial = 0;
        $serial++;

        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset '.$serial,
            'serial_number' => 'SN-'.$serial,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }
}
