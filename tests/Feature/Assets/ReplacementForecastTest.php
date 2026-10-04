<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\Summary;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Livewire\Assets\Settings\ReplacementPolicyManager;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetReplacementPolicy;
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

class ReplacementForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);
    }

    public function test_the_default_four_year_policy_is_seeded_by_the_migration(): void
    {
        $default = IctAssetReplacementPolicy::query()->whereNull('asset_type')->get();

        $this->assertCount(1, $default);
        $this->assertSame(4, $default->first()->years);
    }

    public function test_years_for_prefers_an_override_then_the_default_then_the_safety_net(): void
    {
        IctAssetReplacementPolicy::query()->create(['asset_type' => 'Ph', 'years' => 2]);

        $this->assertSame(2, IctAssetReplacementPolicy::yearsFor('Ph'));
        $this->assertSame(4, IctAssetReplacementPolicy::yearsFor('Laptop'));

        IctAssetReplacementPolicy::query()->whereNull('asset_type')->update(['years' => 5]);
        $this->assertSame(5, IctAssetReplacementPolicy::yearsFor('Laptop'));
        $this->assertSame(2, IctAssetReplacementPolicy::yearsFor('Ph'));

        IctAssetReplacementPolicy::query()->delete();
        $this->assertSame(IctAssetReplacementPolicy::FALLBACK_YEARS, IctAssetReplacementPolicy::yearsFor('Laptop'));
    }

    public function test_dashboard_buckets_by_due_date_including_unknown(): void
    {
        $this->asset(['purchased_at' => '2021-09-01']);  // due 2025-09-01 -> overdue
        $this->asset(['purchased_at' => '2022-10-01']);  // due 2026-10-01 -> overdue (yesterday)
        $this->asset(['purchased_at' => '2022-10-02']);  // due today -> due within 90
        $this->asset(['purchased_at' => '2022-12-31']);  // due 2026-12-31 (90 days) -> due within 90
        $this->asset(['purchased_at' => '2023-01-01']);  // due 2027-01-01 (91 days) -> within 1 year
        $this->asset(['purchased_at' => '2023-10-02']);  // due 2027-10-02 (exactly 1y) -> within 1 year
        $this->asset(['purchased_at' => '2023-10-03']);  // beyond 1y -> not due
        $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'purchased_at' => '2025-01-01']); // not due
        $this->asset([]);                                 // unknown

        $this->actingAs($this->user('SA001', 'super_admin'));

        Livewire::test(Summary::class)->assertViewHas('replacementBuckets', function ($buckets) {
            $by = collect($buckets)->keyBy('key');

            return $by['overdue']['total'] === 2
                && $by['due_90']['total'] === 2
                && $by['due_1y']['total'] === 2
                && $by['not_due']['total'] === 2
                && $by['not_due']['by_category'] === ['asset' => 1, 'phone' => 1, 'network' => 0]
                && $by['unknown']['total'] === 1
                && collect($buckets)->sum('total') === 9;
        });
    }

    public function test_changing_the_default_changes_the_forecast_for_types_without_an_override(): void
    {
        $this->asset(['asset_name' => 'Old PC', 'purchased_at' => '2021-09-01']); // 4y: overdue. 6y: due 2027-09-01
        $this->actingAs($this->user('SA001', 'super_admin'));

        $this->assertForecast(['overdue' => 1, 'due_1y' => 0]);

        IctAssetReplacementPolicy::query()->whereNull('asset_type')->update(['years' => 6]);

        $this->assertForecast(['overdue' => 0, 'due_1y' => 1]);
    }

    public function test_an_override_applies_only_to_its_type_and_the_filter_agrees(): void
    {
        IctAssetReplacementPolicy::query()->create(['asset_type' => 'Ph', 'years' => 2]);
        $this->asset(['asset_name' => 'Phone', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'purchased_at' => '2024-06-01']); // 2y: overdue
        $this->asset(['asset_name' => 'POS', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'POS', 'purchased_at' => '2024-06-01']);  // 4y default: not due
        $this->asset(['asset_name' => 'PC', 'purchased_at' => '2021-09-01']);
        $this->asset(['asset_name' => 'Undated PC']);

        $this->actingAs($this->user('SA001', 'super_admin'));

        $this->assertListNames(PhonesList::class, ['replacement_bucket' => 'overdue'], ['Phone']);
        $this->assertListNames(PhonesList::class, ['replacement_bucket' => 'not_due'], ['POS']);
        $this->assertListNames(AssetsList::class, ['replacement_bucket' => 'overdue'], ['PC']);
        $this->assertListNames(AssetsList::class, ['replacement_bucket' => 'unknown'], ['Undated PC']);
        $this->assertListNames(NetworkList::class, ['replacement_bucket' => 'overdue'], []);
        // Junk is ignored rather than erroring.
        $this->assertListNames(AssetsList::class, ['replacement_bucket' => 'nonsense'], ['PC', 'Undated PC']);
    }

    public function test_dashboard_card_links_to_the_filtered_lists(): void
    {
        $this->asset(['purchased_at' => '2021-09-01']);
        $this->actingAs($this->user('SA001', 'super_admin'));

        $this->get(route('assets.summary'))
            ->assertOk()
            ->assertSee('Replacement Forecast')
            ->assertSee(route('assets.assets', ['replacement_bucket' => 'overdue']), false);

        $this->get(route('assets.assets', ['replacement_bucket' => 'overdue']))
            ->assertOk()
            ->assertSee('Replacement: Overdue');
    }

    // ---- settings screen --------------------------------------------------------------------------------------

    public function test_settings_screen_needs_the_manage_replacement_policy_permission(): void
    {
        $this->actingAs($this->user('ICT001', 'ict_team'));
        $this->get(route('assets.settings.replacement-policy'))->assertForbidden();
        Livewire::test(ReplacementPolicyManager::class)->assertForbidden();

        $this->actingAs($this->user('SA001', 'super_admin'));
        $this->get(route('assets.settings.replacement-policy'))->assertOk()->assertSee('Replacement Policy');
    }

    public function test_editing_the_policy_updates_it_and_writes_audit_rows(): void
    {
        $this->actingAs($this->user('SA001', 'super_admin'));

        Livewire::test(ReplacementPolicyManager::class)
            ->assertSet('defaultYears', 4)
            ->set('defaultYears', 5)
            ->call('saveDefault')
            ->assertHasNoErrors()
            ->set('overrideType', 'Ph')
            ->set('overrideYears', 2)
            ->call('addOverride')
            ->assertHasNoErrors();

        $this->assertSame(1, IctAssetReplacementPolicy::query()->whereNull('asset_type')->count());
        $this->assertSame(5, IctAssetReplacementPolicy::yearsFor('Laptop'));
        $this->assertSame(2, IctAssetReplacementPolicy::yearsFor('Ph'));

        $update = AuditLog::query()->where('action', 'update_replacement_policy')->firstOrFail();
        $this->assertSame('assets', strtolower($update->module));
        $this->assertSame(4, $update->old_values['years']);
        $this->assertSame(5, $update->new_values['years']);
        $this->assertTrue(AuditLog::query()->where('action', 'create_replacement_policy')->exists());

        $override = IctAssetReplacementPolicy::query()->where('asset_type', 'Ph')->firstOrFail();

        Livewire::test(ReplacementPolicyManager::class)->call('updateOverride', $override->id, 3);
        $this->assertSame(3, $override->fresh()->years);

        Livewire::test(ReplacementPolicyManager::class)->call('deleteOverride', $override->id);
        $this->assertSame(5, IctAssetReplacementPolicy::yearsFor('Ph'));
        $this->assertSame(2, AuditLog::query()->where('action', 'update_replacement_policy')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'delete_replacement_policy')->exists());
    }

    public function test_policy_inputs_are_validated(): void
    {
        $this->actingAs($this->user('SA001', 'super_admin'));

        Livewire::test(ReplacementPolicyManager::class)
            ->set('defaultYears', 0)->call('saveDefault')->assertHasErrors(['defaultYears'])
            ->set('overrideType', 'Spaceship')->set('overrideYears', 2)->call('addOverride')->assertHasErrors(['overrideType'])
            ->set('overrideType', 'Ph')->set('overrideYears', 2)->call('addOverride')->assertHasNoErrors()
            ->set('overrideType', 'Ph')->set('overrideYears', 3)->call('addOverride')->assertHasErrors(['overrideType']);

        $this->assertSame(4, IctAssetReplacementPolicy::yearsFor('Laptop'));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    protected function assertForecast(array $expected): void
    {
        Livewire::test(Summary::class)->assertViewHas('replacementBuckets', function ($buckets) use ($expected) {
            $by = collect($buckets)->keyBy('key');

            foreach ($expected as $key => $total) {
                if ($by[$key]['total'] !== $total) {
                    return false;
                }
            }

            return true;
        });
    }

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

    protected function user(string $staffId, string $role): User
    {
        $user = User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    protected function asset(array $overrides = []): IctAsset
    {
        static $n = 0;
        $n++;

        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset '.$n,
            'serial_number' => 'RF-'.$n,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }
}
