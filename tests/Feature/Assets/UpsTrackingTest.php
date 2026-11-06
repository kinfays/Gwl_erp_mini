<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Livewire\Assets\Summary;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\AssetRecordService;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UpsTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected District $district;

    protected Employee $holder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);

        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->district = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $region->id]);

        $admin = User::query()->create([
            'staff_id' => 'SA001', 'full_name' => 'Super Admin', 'email' => 'sa001@example.com',
            'password' => Hash::make('password'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $admin->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());
        $this->employee('SA001', 'Super Admin');
        $this->holder = $this->employee('E1', 'Kwame Mensah');

        $this->actingAs($admin);
    }

    // ---- form -------------------------------------------------------------------------------------------------

    public function test_the_ups_condition_is_required_when_the_device_is_on_a_ups(): void
    {
        foreach (['PC', 'AIO', 'Server', 'PRT', 'PTC'] as $i => $type) {
            $this->fillForm($type, 'REQ-'.$i)
                ->set('form.has_ups', true)
                ->set('form.ups_status', '')
                ->call('save')
                ->assertHasErrors(['form.ups_status' => 'required']);
        }

        $this->fillForm('PC', 'REQ-X')->set('form.has_ups', true)->set('form.ups_status', 'Broken')->call('save')->assertHasErrors(['form.ups_status']);

        $this->assertSame(0, IctAsset::query()->count());

        $this->fillForm('PC', 'REQ-OK')->set('form.has_ups', true)->set('form.ups_status', 'Working')->call('save')->assertHasNoErrors();

        $asset = IctAsset::query()->where('serial_number', 'REQ-OK')->firstOrFail();
        $this->assertTrue($asset->has_ups);
        $this->assertSame('Working', $asset->ups_status);
        $this->assertNull($asset->ups_notes, 'notes are optional');
    }

    public function test_spoiled_does_not_need_notes(): void
    {
        $this->fillForm('Server', 'SPO-1')->set('form.has_ups', true)->set('form.ups_status', 'Spoiled')->call('save')->assertHasNoErrors();

        $this->assertSame('Spoiled', IctAsset::query()->firstOrFail()->ups_status);
    }

    public function test_saving_without_a_ups_clears_status_and_notes(): void
    {
        $model = IctAssetModel::query()->create(['name' => 'Edit model', 'category' => 'PC', 'is_active' => true]);
        $asset = $this->asset(['asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Spoiled', 'ups_notes' => 'Battery swollen', 'ict_asset_model_id' => $model->id, 'assigned_to_employee_id' => $this->holder->id, 'department_id' => Department::query()->value('id')]);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->assertSet('form.has_ups', true)
            ->assertSet('form.ups_status', 'Spoiled')
            ->assertSet('form.ups_notes', 'Battery swollen')
            ->set('form.has_ups', false)
            ->call('save')->assertHasNoErrors();

        $asset = $asset->fresh();
        $this->assertFalse($asset->has_ups);
        $this->assertNull($asset->ups_status);
        $this->assertNull($asset->ups_notes);

        // An empty selection is stored as NULL, never as an empty string.
        $this->fillForm('PC', 'EMPTY-1')->set('form.has_ups', true)->set('form.ups_status', 'Working')->set('form.ups_notes', '')->call('save');
        $this->assertNull(IctAsset::query()->where('serial_number', 'EMPTY-1')->value('ups_notes'));
    }

    public function test_the_ups_section_only_shows_for_the_five_types(): void
    {
        foreach (['PC', 'AIO', 'Server', 'PRT', 'PTC'] as $type) {
            Livewire::test(AssetsList::class)->call('openCreate')->set('form.asset_type', $type)
                ->assertViewHas('formSupportsUps', true)->assertSee('connected to a UPS');
        }

        Livewire::test(AssetsList::class)->call('openCreate')->set('form.asset_type', 'Laptop')
            ->assertViewHas('formSupportsUps', false)->assertDontSee('connected to a UPS');

        Livewire::test(AssetsList::class)->call('openCreate')->assertDontSee('connected to a UPS');

        $this->assertSame(['PC', 'AIO', 'Server', 'PRT', 'PTC'], IctAsset::UPS_ASSET_TYPES);
    }

    public function test_a_laptop_never_keeps_ups_values_even_when_they_are_submitted(): void
    {
        // Switching a type in the form drops what was entered...
        Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_type', 'PC')->set('form.has_ups', true)->set('form.ups_status', 'Working')->set('form.ups_notes', 'Rack 2')
            ->set('form.asset_type', 'Laptop')
            ->assertSet('form.has_ups', false)->assertSet('form.ups_status', '')->assertSet('form.ups_notes', '');

        // ...and the service refuses to store them for a laptop whatever the form says.
        $this->fillForm('Laptop', 'LAP-1')->set('form.has_ups', true)->set('form.ups_status', 'Spoiled')->set('form.ups_notes', 'x')->call('save');
        $laptop = IctAsset::query()->where('serial_number', 'LAP-1')->firstOrFail();
        $this->assertFalse($laptop->has_ups);
        $this->assertNull($laptop->ups_status);
        $this->assertNull($laptop->ups_notes);
    }

    public function test_changing_a_pc_with_a_ups_to_a_laptop_clears_it(): void
    {
        $asset = $this->asset(['asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Spoiled', 'ups_notes' => 'Old']);

        app(AssetRecordService::class)->save(['asset_type' => 'Laptop'], 'asset', $asset);

        $asset = $asset->fresh();
        $this->assertFalse($asset->has_ups);
        $this->assertNull($asset->ups_status);
        $this->assertNull($asset->ups_notes);
    }

    public function test_phone_and_network_assets_never_persist_ups_fields(): void
    {
        $service = app(AssetRecordService::class);
        $base = ['asset_name' => 'X', 'status' => 'Active', 'has_ups' => true, 'ups_status' => 'Spoiled', 'ups_notes' => 'x'];

        $phone = $service->save([...$base, 'asset_type' => 'Ph', 'serial_number' => 'PH-U'], 'phone');
        // Asset names are unique across every category, so the router needs a name of its own.
        $router = $service->save([...$base, 'asset_name' => 'Y', 'asset_type' => 'RT', 'serial_number' => 'RT-U'], 'network');

        foreach ([$phone, $router] as $device) {
            $device = $device->fresh();
            $this->assertFalse($device->has_ups);
            $this->assertNull($device->ups_status);
            $this->assertNull($device->ups_notes);
        }

        // The Phones and Network forms do not carry the fields at all.
        foreach ([PhonesList::class, NetworkList::class] as $list) {
            $form = Livewire::test($list)->call('openCreate')->get('form');
            $this->assertArrayNotHasKey('has_ups', $form);
            $this->assertArrayNotHasKey('ups_status', $form);
            Livewire::test($list)->call('openCreate')->assertDontSee('connected to a UPS');
        }
    }

    public function test_a_save_that_does_not_mention_the_ups_leaves_it_alone(): void
    {
        $asset = $this->asset(['asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Spoiled']);

        // e.g. the issue-report popup or an audit correction.
        app(AssetRecordService::class)->save(['status' => 'Retired'], 'asset', $asset);

        $this->assertSame('Spoiled', $asset->fresh()->ups_status);
        $this->assertTrue($asset->fresh()->has_ups);
    }

    // ---- list filter + badge ----------------------------------------------------------------------------------

    public function test_the_ups_filter_returns_the_right_rows(): void
    {
        $this->asset(['asset_name' => 'Working PC', 'asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Working']);
        $this->asset(['asset_name' => 'Spoiled AIO', 'asset_type' => 'AIO', 'has_ups' => true, 'ups_status' => 'Spoiled']);
        $this->asset(['asset_name' => 'Bare server', 'asset_type' => 'Server']);
        $this->asset(['asset_name' => 'Null flag printer', 'asset_type' => 'PRT', 'has_ups' => null]);
        $this->asset(['asset_name' => 'A laptop', 'asset_type' => 'Laptop']);
        $this->asset(['asset_name' => 'A phone', 'asset_type' => 'Ph', 'device_category' => 'phone']);

        $this->assertNames([], ['Working PC', 'Spoiled AIO', 'Bare server', 'Null flag printer', 'A laptop']);
        $this->assertNames(['ups' => 'all'], ['Working PC', 'Spoiled AIO', 'Bare server', 'Null flag printer', 'A laptop']);
        $this->assertNames(['ups' => 'has_ups'], ['Working PC', 'Spoiled AIO']);
        $this->assertNames(['ups' => 'spoiled'], ['Spoiled AIO']);
        // "No UPS" is only about the five tracked types: a laptop does not count as lacking one.
        $this->assertNames(['ups' => 'no_ups'], ['Bare server', 'Null flag printer']);
    }

    public function test_the_list_shows_a_ups_badge_only_where_it_applies(): void
    {
        $this->asset(['asset_name' => 'Good one', 'asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Working']);
        $this->asset(['asset_name' => 'Bad one', 'asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Spoiled']);
        $this->asset(['asset_name' => 'Plain one', 'asset_type' => 'Server']);
        $this->asset(['asset_name' => 'Lappy', 'asset_type' => 'Laptop']);

        $html = Livewire::test(AssetsList::class)->html();

        $this->assertStringContainsString('UPS: Working', $html);
        $this->assertStringContainsString('UPS: Spoiled', $html);
        $this->assertStringContainsString('ui-pill-danger">UPS: Spoiled', $html);
        $this->assertStringContainsString('ui-pill-success">UPS: Working', $html);
        $this->assertSame(1, substr_count($html, 'No UPS</span>'), 'only the server is a tracked type without a UPS');

        $this->get(route('assets.assets', ['ups' => 'spoiled']))->assertOk()->assertSee('Bad one')->assertDontSee('Good one');
    }

    // ---- Needs Attention + Lifecycle stat ---------------------------------------------------------------------

    public function test_needs_attention_includes_exactly_the_spoiled_ups_assets(): void
    {
        $spoiled = $this->asset(['asset_name' => 'Spoiled UPS PC', 'asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Spoiled']);
        $this->asset(['asset_name' => 'Working UPS PC', 'asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Working']);
        $this->asset(['asset_name' => 'No UPS PC', 'asset_type' => 'PC']);
        $this->asset(['asset_name' => 'Stale spoiled', 'asset_type' => 'PC', 'has_ups' => false, 'ups_status' => 'Spoiled']);
        $damagedToo = $this->asset(['asset_name' => 'Both', 'asset_type' => 'Server', 'status' => 'Damaged', 'status_reason' => 'Dropped', 'has_ups' => true, 'ups_status' => 'Spoiled']);

        $this->summary('lifecycle')->assertViewHas('needsAttention', function ($rows) use ($spoiled, $damagedToo) {
            $by = $rows->keyBy(fn ($r) => $r['asset']->id);

            return $rows->count() === 2
                && $by[$spoiled->id]['reasons'] === ['UPS spoiled']
                && $by[$damagedToo->id]['reasons'] === ['Damaged', 'UPS spoiled'];
        })->assertSee('Spoiled UPS PC')->assertDontSee('Working UPS PC')->assertDontSee('No UPS PC');
    }

    public function test_the_lifecycle_tab_counts_ups_coverage_and_links_to_the_filtered_list(): void
    {
        $other = District::query()->create(['district_name' => 'Tema', 'region_id' => $this->district->region_id]);

        $this->asset(['asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Working']);
        $this->asset(['asset_type' => 'AIO', 'has_ups' => true, 'ups_status' => 'Working']);
        $this->asset(['asset_type' => 'Server', 'has_ups' => true, 'ups_status' => 'Spoiled']);
        $this->asset(['asset_type' => 'PRT']);
        $this->asset(['asset_type' => 'PTC']);
        $this->asset(['asset_type' => 'PC', 'district_id' => $other->id, 'region_id' => $other->region_id]);
        $this->asset(['asset_type' => 'Laptop']);
        $this->asset(['asset_type' => 'Ph', 'device_category' => 'phone']);
        $this->asset(['asset_type' => 'RT', 'device_category' => 'network']);

        $this->summary('lifecycle')
            ->assertViewHas('upsCoverage', ['on_ups' => 3, 'no_ups' => 3, 'spoiled' => 1, 'tracked' => 6])
            ->assertSee('UPS Coverage')
            ->assertSee(route('assets.assets', ['ups' => 'has_ups']), false)
            ->assertSee(route('assets.assets', ['ups' => 'no_ups']), false)
            ->assertSee(route('assets.assets', ['ups' => 'spoiled']), false);

        // It follows the page's district filter and stays off the other tabs.
        $this->summary('lifecycle', ['district' => (string) $other->id])->assertViewHas('upsCoverage', ['on_ups' => 0, 'no_ups' => 1, 'spoiled' => 0, 'tracked' => 1]);
        $this->summary('assignment')->assertDontSee('UPS Coverage');
    }

    public function test_the_asset_detail_page_shows_the_ups_for_tracked_types_only(): void
    {
        $pc = $this->asset(['asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Spoiled', 'ups_notes' => 'Battery swollen']);
        $laptop = $this->asset(['asset_type' => 'Laptop']);

        $this->get(route('assets.show', $pc))->assertOk()->assertSee('Spoiled')->assertSee('Battery swollen');
        $this->get(route('assets.show', $laptop))->assertOk()->assertDontSee('No UPS')->assertDontSee('Battery swollen');
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    protected function summary(string $tab, array $filters = [])
    {
        return Livewire::withQueryParams(['tab' => $tab, ...$filters])->test(Summary::class);
    }

    protected function assertNames(array $query, array $expected): void
    {
        $names = Livewire::withQueryParams($query)->test(AssetsList::class)->viewData('assets')->getCollection()->pluck('asset_name')->sort()->values()->all();
        sort($expected);

        $this->assertSame(array_values($expected), $names, json_encode($query));
    }

    protected function fillForm(string $type, string $serial)
    {
        $model = IctAssetModel::query()->firstOrCreate(['name' => 'Model '.$type], ['category' => $type, 'is_active' => true]);

        return Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_type', $type)
            ->set('form.asset_name', 'Device '.$serial)
            ->set('form.serial_number', $serial)
            ->set('form.ict_asset_model_id', $model->id)
            ->set('form.assigned_to_employee_id', $this->holder->id)
            ->set('form.department_id', Department::query()->value('id'))
            ->set('form.district_id', $this->district->id);
    }

    protected function employee(string $staffId, string $name): Employee
    {
        return Employee::query()->create([
            'staff_id' => $staffId, 'full_name' => $name, 'gender' => 'Male', 'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Administration'])->id,
            'region_id' => $this->district->region_id, 'district_id' => $this->district->id, 'location_type' => 'District',
            'date_of_birth' => '1990-01-01', 'date_joined' => '2026-01-01', 'present_appointment' => '2026-01-01', 'is_active' => true,
        ]);
    }

    protected function asset(array $overrides = []): IctAsset
    {
        static $n = 0;
        $n++;

        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset '.$n,
            'serial_number' => 'UPS-'.$n,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
            'district_id' => $this->district->id,
            'region_id' => $this->district->region_id,
        ], $overrides));
    }
}
