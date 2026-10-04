<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\Dashboard;
use App\Livewire\Assets\Summary;
use App\Livewire\Assets\EmployeeAssets;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
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

class AssetAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);
    }

    // ---- Age ------------------------------------------------------------------------------------------------

    public function test_age_buckets_count_every_asset_once_and_unknown_is_its_own_bucket(): void
    {
        $this->createAsset(['asset_name' => 'Fresh', 'purchased_at' => '2026-06-01']);          // under 1y
        $this->createAsset(['asset_name' => 'Just one', 'purchased_at' => '2025-10-02']);       // exactly 1y -> 1-2
        $this->createAsset(['asset_name' => 'Almost one', 'purchased_at' => '2025-10-03']);     // 364 days -> 0-1
        $this->createAsset(['asset_name' => 'Two and a bit', 'purchased_at' => '2024-01-15']);  // 2-3
        $this->createAsset(['asset_name' => 'Old', 'purchased_at' => '2019-01-01']);            // 5+
        $this->createAsset(['asset_name' => 'Ancient phone', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'purchased_at' => '2018-01-01']);
        $this->createAsset(['asset_name' => 'No date']);                                        // unknown

        $this->actingAs($this->superAdmin());

        Livewire::test(Summary::class)->assertViewHas('ageBuckets', function ($buckets) {
            $by = collect($buckets)->keyBy('key');

            return $by['0-1']['total'] === 2
                && $by['1-2']['total'] === 1
                && $by['2-3']['total'] === 1
                && $by['3-4']['total'] === 0
                && $by['4-5']['total'] === 0
                && $by['5+']['total'] === 2
                && $by['5+']['by_category'] === ['asset' => 1, 'phone' => 1, 'network' => 0]
                && $by['unknown']['total'] === 1
                && collect($buckets)->sum('total') === 7;
        });
    }

    public function test_age_range_link_filters_each_list(): void
    {
        $this->createAsset(['asset_name' => 'Two-year PC', 'purchased_at' => '2024-03-01']);
        $this->createAsset(['asset_name' => 'Fresh PC', 'purchased_at' => '2026-05-01']);
        $this->createAsset(['asset_name' => 'Undated PC']);
        $this->createAsset(['asset_name' => 'Two-year phone', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'purchased_at' => '2024-03-01']);
        $this->createAsset(['asset_name' => 'Two-year router', 'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK, 'asset_type' => 'RT', 'purchased_at' => '2024-03-01']);

        $this->actingAs($this->superAdmin());

        $this->assertListNames(AssetsList::class, ['age_min' => 2, 'age_max' => 3], ['Two-year PC']);
        $this->assertListNames(PhonesList::class, ['age_min' => 2, 'age_max' => 3], ['Two-year phone']);
        $this->assertListNames(NetworkList::class, ['age_min' => 2, 'age_max' => 3], ['Two-year router']);
        $this->assertListNames(AssetsList::class, ['age_min' => 5], []);
        $this->assertListNames(AssetsList::class, ['age_min' => 0, 'age_max' => 1], ['Fresh PC']);
        $this->assertListNames(AssetsList::class, ['age' => 'unknown'], ['Undated PC']);
        // Junk in a hand-edited URL is ignored, not an error.
        $this->assertListNames(AssetsList::class, ['age_min' => 'abc'], ['Fresh PC', 'Two-year PC', 'Undated PC']);
    }

    // ---- Warranty -------------------------------------------------------------------------------------------

    public function test_warranty_buckets_are_exclusive_and_cover_every_asset(): void
    {
        $this->createAsset(['asset_name' => 'Expired', 'warranty_expires_at' => '2026-10-01']);
        $this->createAsset(['asset_name' => 'Today', 'warranty_expires_at' => '2026-10-02']);   // still valid -> 30
        $this->createAsset(['asset_name' => 'In 30', 'warranty_expires_at' => '2026-11-01']);   // +30 days -> 30
        $this->createAsset(['asset_name' => 'In 31', 'warranty_expires_at' => '2026-11-02']);   // +31 days -> 90
        $this->createAsset(['asset_name' => 'In 90', 'warranty_expires_at' => '2026-12-31']);   // +90 days -> 90
        $this->createAsset(['asset_name' => 'In 91', 'warranty_expires_at' => '2027-01-01']);   // active
        $this->createAsset(['asset_name' => 'Unknown']);

        $this->actingAs($this->superAdmin());

        Livewire::test(Summary::class)->assertViewHas('warrantyBuckets', function ($buckets) {
            $by = collect($buckets)->keyBy('key');

            return $by['active']['total'] === 1
                && $by['expiring_30']['total'] === 2
                && $by['expiring_90']['total'] === 2
                && $by['expired']['total'] === 1
                && $by['unknown']['total'] === 1
                && collect($buckets)->sum('total') === 7;
        });
    }

    public function test_warranty_link_filters_each_list(): void
    {
        $this->createAsset(['asset_name' => 'Lapsed PC', 'warranty_expires_at' => '2026-01-01']);
        $this->createAsset(['asset_name' => 'Covered PC', 'warranty_expires_at' => '2028-01-01']);
        $this->createAsset(['asset_name' => 'Lapsed phone', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'warranty_expires_at' => '2026-01-01']);
        $this->createAsset(['asset_name' => 'Soon router', 'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK, 'asset_type' => 'RT', 'warranty_expires_at' => '2026-10-20']);

        $this->actingAs($this->superAdmin());

        $this->assertListNames(AssetsList::class, ['warranty' => 'expired'], ['Lapsed PC']);
        $this->assertListNames(AssetsList::class, ['warranty' => 'active'], ['Covered PC']);
        $this->assertListNames(PhonesList::class, ['warranty' => 'expired'], ['Lapsed phone']);
        $this->assertListNames(NetworkList::class, ['warranty' => 'expiring_30'], ['Soon router']);
        $this->assertListNames(AssetsList::class, ['warranty' => 'nonsense'], ['Covered PC', 'Lapsed PC']);
    }

    // ---- Assignment -----------------------------------------------------------------------------------------

    public function test_unassigned_tile_counts_by_category_and_link_filters_the_lists(): void
    {
        $employee = $this->employee('E1', 'Ama Mensah');

        $this->createAsset(['asset_name' => 'Held PC', 'assigned_to_employee_id' => $employee->id]);
        $this->createAsset(['asset_name' => 'Spare PC']);
        $this->createAsset(['asset_name' => 'Spare phone', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph']);
        $this->createAsset(['asset_name' => 'Spare router', 'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK, 'asset_type' => 'RT']);

        $this->actingAs($this->superAdmin());

        Livewire::test(Dashboard::class)
            ->assertViewHas('unassigned', fn ($u) => $u['total'] === 3 && $u['by_category'] === ['asset' => 1, 'phone' => 1, 'network' => 1])
            ->assertSee(route('assets.assets', ['assigned' => 'none']), false)
            ->assertSee(route('assets.phones', ['assigned' => 'none']), false)
            ->assertSee(route('assets.network', ['assigned' => 'none']), false);

        $this->assertListNames(AssetsList::class, ['assigned' => 'none'], ['Spare PC']);
        $this->assertListNames(PhonesList::class, ['assigned' => 'none'], ['Spare phone']);
        $this->assertListNames(NetworkList::class, ['assigned' => 'none'], ['Spare router']);
    }

    public function test_top_assignees_ranks_by_count_across_categories_and_caps_at_ten(): void
    {
        $busy = $this->employee('E1', 'Busy Bee');
        $light = $this->employee('E2', 'Light User');
        $this->createAsset(['assigned_to_employee_id' => $busy->id]);
        $this->createAsset(['assigned_to_employee_id' => $busy->id, 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph']);
        $this->createAsset(['assigned_to_employee_id' => $busy->id, 'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK, 'asset_type' => 'RT']);
        $this->createAsset(['assigned_to_employee_id' => $light->id]);
        $this->createAsset(); // unassigned: never listed

        for ($i = 3; $i <= 14; $i++) {
            $this->createAsset(['assigned_to_employee_id' => $this->employee('E'.$i, 'Person '.$i)->id]);
        }

        $this->actingAs($this->superAdmin());

        Livewire::withQueryParams(['tab' => 'assignment'])->test(Summary::class)
            ->assertViewHas('topAssignees', function ($rows) use ($busy, $light) {
                return $rows->count() === 10
                    && $rows[0]['employee_id'] === $busy->id
                    && $rows[0]['total'] === 3
                    && $rows[0]['name'] === 'Busy Bee'
                    && $rows[1]['employee_id'] === $light->id; // ties break on employee id
            })
            ->assertSee(route('assets.employee', $busy->id), false);
    }

    public function test_top_assignees_only_counts_assets_the_actor_can_see(): void
    {
        $tema = $this->district('Tema', 'Greater Accra');
        $kumasi = $this->district('Kumasi', 'Ashanti');
        $ict = $this->regionalIct($tema);
        $holder = $this->employee('E1', 'Holder', $tema);
        $faraway = $this->employee('E2', 'Faraway', $kumasi);

        $this->createAsset(['assigned_to_employee_id' => $holder->id, 'region_id' => $tema->region_id]);
        $this->createAsset(['assigned_to_employee_id' => $faraway->id, 'region_id' => $kumasi->region_id]);

        $this->actingAs($ict);

        Livewire::withQueryParams(['tab' => 'assignment'])->test(Summary::class)
            ->assertViewHas('topAssignees', fn ($rows) => $rows->pluck('employee_id')->all() === [$holder->id]);
    }

    // ---- Per-employee rollup --------------------------------------------------------------------------------

    public function test_employee_rollup_lists_assets_phones_and_network_together_read_only(): void
    {
        $employee = $this->employee('E1', 'Ama Mensah');
        $other = $this->employee('E2', 'Kofi Boateng');
        $this->createAsset(['asset_name' => 'Ama laptop', 'asset_type' => 'Laptop', 'assigned_to_employee_id' => $employee->id, 'condition' => IctAsset::CONDITION_GOOD]);
        $this->createAsset(['asset_name' => 'Ama phone', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'assigned_to_employee_id' => $employee->id]);
        $this->createAsset(['asset_name' => 'Ama mifi', 'device_category' => IctAsset::DEVICE_CATEGORY_NETWORK, 'asset_type' => 'MiFi', 'assigned_to_employee_id' => $employee->id]);
        $this->createAsset(['asset_name' => 'Kofi laptop', 'asset_type' => 'Laptop', 'assigned_to_employee_id' => $other->id]);

        $this->actingAs($this->superAdmin());

        $this->get(route('assets.employee', $employee->id))
            ->assertOk()
            ->assertSee('Ama Mensah')
            ->assertSee('Ama laptop')
            ->assertSee('Ama phone')
            ->assertSee('Ama mifi')
            ->assertSee('Good')
            ->assertDontSee('Kofi laptop');

        Livewire::test(EmployeeAssets::class, ['employee' => $employee])
            ->assertViewHas('total', 3);
    }

    public function test_employee_rollup_hides_out_of_region_employees_from_regional_ict(): void
    {
        $tema = $this->district('Tema', 'Greater Accra');
        $kumasi = $this->district('Kumasi', 'Ashanti');
        $ict = $this->regionalIct($tema);
        $local = $this->employee('E1', 'Local', $tema);
        $faraway = $this->employee('E2', 'Faraway', $kumasi);
        $this->createAsset(['asset_name' => 'Far laptop', 'assigned_to_employee_id' => $faraway->id, 'region_id' => $kumasi->region_id]);

        $this->actingAs($ict);

        $this->get(route('assets.employee', $local->id))->assertOk();
        $this->get(route('assets.employee', $faraway->id))->assertNotFound();
    }

    // ---- Condition ------------------------------------------------------------------------------------------

    public function test_condition_is_optional_and_saved_from_the_assets_form(): void
    {
        $district = $this->district('Accra Central', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $employee = $this->employee('E1', 'Ama Mensah', $district);
        $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
        $model = IctAssetModel::query()->create(['name' => 'ThinkPad', 'category' => 'Laptop', 'is_active' => true]);

        $fill = fn ($component, string $serial, ?string $condition) => $component
            ->call('openCreate')
            ->set('form.asset_name', 'Laptop '.$serial)
            ->set('form.serial_number', $serial)
            ->set('form.asset_type', 'Laptop')
            ->set('form.ict_asset_model_id', $model->id)
            ->set('form.assigned_to_employee_id', $employee->id)
            ->set('form.department_id', $department->id)
            ->set('form.district_id', $district->id)
            ->set('form.condition', $condition)
            ->call('save');

        $fill(Livewire::test(AssetsList::class), 'S-1', null)->assertHasNoErrors();
        $fill(Livewire::test(AssetsList::class), 'S-2', '')->assertHasNoErrors();
        $fill(Livewire::test(AssetsList::class), 'S-3', IctAsset::CONDITION_POOR)->assertHasNoErrors();
        $fill(Livewire::test(AssetsList::class), 'S-4', 'Shiny')->assertHasErrors(['form.condition']);

        $this->assertNull(IctAsset::query()->where('serial_number', 'S-1')->value('condition'));
        $this->assertNull(IctAsset::query()->where('serial_number', 'S-2')->value('condition'));
        $this->assertSame('Poor', IctAsset::query()->where('serial_number', 'S-3')->value('condition'));
        $this->assertFalse(IctAsset::query()->where('serial_number', 'S-4')->exists());
    }

    public function test_condition_is_saved_from_the_phones_and_network_forms_and_loaded_on_edit(): void
    {
        $district = $this->district('Accra Central', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        Livewire::test(PhonesList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Phone A')
            ->set('form.serial_number', 'PH-A')
            ->set('form.asset_type', 'Ph')
            ->set('form.condition', IctAsset::CONDITION_NEW)
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(NetworkList::class)
            ->call('openCreate')
            ->set('form.asset_name', 'Router A')
            ->set('form.serial_number', 'RT-A')
            ->set('form.asset_type', 'RT')
            ->set('form.condition', IctAsset::CONDITION_DAMAGED)
            ->call('save')
            ->assertHasNoErrors();

        $phone = IctAsset::query()->where('asset_name', 'Phone A')->firstOrFail();
        $this->assertSame('New', $phone->condition);
        $this->assertSame('Damaged', IctAsset::query()->where('asset_name', 'Router A')->value('condition'));

        Livewire::test(PhonesList::class)
            ->call('openEdit', $phone->id)
            ->assertSet('form.condition', 'New');
    }

    // ---- Dashboard rendering + bookmarkable links -----------------------------------------------------------

    public function test_dashboard_renders_the_new_cards_with_drill_down_links(): void
    {
        $this->createAsset(['asset_name' => 'Old PC', 'purchased_at' => '2019-01-01', 'warranty_expires_at' => '2020-01-01']);

        $this->actingAs($this->superAdmin());

        $this->get(route('assets.summary'))
            ->assertOk()
            ->assertSee('Asset Age')
            ->assertSee('Warranty Status')
            ->assertSee(route('assets.assets', ['age_min' => 5]), false)
            ->assertSee(route('assets.assets', ['warranty' => 'expired']), false);

        $this->get(route('assets.summary', ['tab' => 'assignment']))
            ->assertOk()
            ->assertSee('Employees With the Most Assets');

        // The dashboard keeps only the Unassigned tile.
        $this->get(route('assets.home'))->assertOk()->assertSee('Unassigned Assets');
    }

    public function test_drill_down_urls_work_as_plain_get_requests(): void
    {
        $this->createAsset(['asset_name' => 'Old PC', 'purchased_at' => '2019-01-01']);
        $this->createAsset(['asset_name' => 'New PC', 'purchased_at' => '2026-09-01']);

        $this->actingAs($this->superAdmin());

        $this->get(route('assets.assets', ['age_min' => 5]))
            ->assertOk()
            ->assertSee('Old PC')
            ->assertDontSee('New PC')
            ->assertSee('Age: 5+ years');
    }

    // ---- helpers --------------------------------------------------------------------------------------------

    /** @param class-string $component */
    protected function assertListNames(string $component, array $query, array $expected): void
    {
        Livewire::withQueryParams($query)
            ->test($component)
            ->assertViewHas('assets', function ($assets) use ($expected) {
                $names = $assets->getCollection()->pluck('asset_name')->sort()->values()->all();
                sort($expected);

                return $names === array_values($expected);
            });
    }

    protected function superAdmin(?District $homeDistrict = null): User
    {
        $user = $this->user('SA001');
        $user->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());

        if ($homeDistrict) {
            $this->employee('SA001', 'Super Admin', $homeDistrict);
        }

        return $user;
    }

    protected function regionalIct(District $district): User
    {
        $user = $this->user('ICT001');
        $user->roles()->attach(Role::query()->where('name', 'ict_team')->firstOrFail());
        $this->employee('ICT001', 'Regional ICT', $district);

        return $user;
    }

    protected function user(string $staffId): User
    {
        return User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    protected function district(string $name, string $region): District
    {
        $regionModel = Region::query()->firstOrCreate(['region_name' => $region]);

        return District::query()->firstOrCreate(['district_name' => $name], ['region_id' => $regionModel->id]);
    }

    protected function employee(string $staffId, string $name, ?District $district = null): Employee
    {
        $district ??= $this->district('Accra Central', 'Greater Accra');
        $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $name,
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

    protected function createAsset(array $overrides = []): IctAsset
    {
        static $serial = 0;
        $serial++;

        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset '.$serial,
            'serial_number' => 'AN-'.$serial,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }
}
