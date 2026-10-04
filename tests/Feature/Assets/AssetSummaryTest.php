<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\Dashboard;
use App\Livewire\Assets\Summary;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetMaintenance;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
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

class AssetSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected District $accra;

    protected District $kumasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 2)->startOfDay());
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);

        $ga = Region::query()->create(['region_name' => 'Greater Accra']);
        $ash = Region::query()->create(['region_name' => 'Ashanti']);
        $this->accra = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $ga->id]);
        $this->kumasi = District::query()->create(['district_name' => 'Kumasi', 'region_id' => $ash->id]);

        $user = User::query()->create([
            'staff_id' => 'SA001',
            'full_name' => 'Super Admin',
            'email' => 'sa001@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());
        $this->actingAs($user);
    }

    // ---- Manufacturers & Models ---------------------------------------------------------------------------------

    public function test_manufacturers_tab_groups_devices_by_manufacturer_and_model_and_reports_coverage(): void
    {
        $dell = IctAssetManufacturer::query()->create(['name' => 'Dell']);
        $hp = IctAssetManufacturer::query()->create(['name' => 'HP']);
        $latitude = $this->model('Latitude', $dell);
        $optiplex = $this->model('OptiPlex', $dell);
        $prodesk = $this->model('ProDesk', $hp);
        $phoneModel = $this->model('Tough Phone', $hp);

        $this->asset(['ict_asset_model_id' => $latitude->id]);
        $this->asset(['ict_asset_model_id' => $latitude->id]);
        $this->asset(['ict_asset_model_id' => $optiplex->id]);
        $this->asset(['ict_asset_model_id' => $prodesk->id]);
        $this->asset([]); // no model
        $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'ict_asset_model_id' => $phoneModel->id]);
        $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph']);
        $this->asset(['device_category' => IctAsset::DEVICE_CATEGORY_NETWORK, 'asset_type' => 'RT']);

        $this->summary('manufacturers')->assertViewHas('manufacturerGroups', function ($groups) {
            $assets = $groups['asset'];
            $phones = $groups['phone'];
            $dell = $assets['result']['rows']->keyBy('manufacturer')['Dell'];

            return array_keys($groups) === ['asset', 'phone', 'network']
                // Computers/printers and phones are charted separately, never mixed.
                && $assets['result']['with_model'] === 4
                && $assets['result']['rows']->pluck('manufacturer')->all() === ['Dell', 'HP']
                && $dell['total'] === 3
                && $dell['percentage'] === 75.0
                && $dell['models'][0] === ['model' => 'Latitude', 'total' => 2, 'percentage' => 50.0]
                && $assets['result']['coverage']['asset'] === ['total' => 5, 'with_model' => 4]
                && $assets['slices']['labels'] === ['Dell', 'HP']
                && $assets['slices']['data'] === [3, 1]
                && $assets['slices']['colors'] === ['series-1', 'series-2']
                && $phones['result']['with_model'] === 1
                && $phones['slices']['labels'] === ['HP']
                && $phones['result']['coverage']['phone'] === ['total' => 2, 'with_model' => 1]
                && $groups['network']['result']['with_model'] === 0;
        })->assertSee('4 of 5 devices have a model recorded')->assertSee('1 of 2 devices have a model recorded');
    }

    public function test_small_manufacturers_fold_into_other_after_seven_slices(): void
    {
        foreach (range(1, 9) as $i) {
            $maker = IctAssetManufacturer::query()->create(['name' => 'Maker '.$i]);
            $model = $this->model('Model '.$i, $maker);
            foreach (range(1, 10 - $i) as $n) {
                $this->asset(['ict_asset_model_id' => $model->id]);
            }
        }

        $this->summary('manufacturers')->assertViewHas('manufacturerGroups', function ($groups) {
            $slices = $groups['asset']['slices'];

            return count($slices['labels']) === 8
                && $slices['labels'][0] === 'Maker 1'
                && $slices['labels'][7] === 'Other'
                && $slices['data'][7] === 2 + 1 // Maker 8 (2 devices) + Maker 9 (1 device)
                && $slices['colors'][7] === 'muted'
                && array_sum($slices['data']) === 45;
        });
    }

    // ---- Maintenance --------------------------------------------------------------------------------------------

    public function test_maintenance_tab_counts_status_top_assets_turnaround_and_monthly_volume(): void
    {
        $a = $this->asset(['asset_name' => 'Repeat offender', 'district_id' => $this->accra->id, 'region_id' => $this->accra->region_id]);
        $b = $this->asset(['asset_name' => 'Once only', 'district_id' => $this->accra->id, 'region_id' => $this->accra->region_id]);

        $this->ticket($a, 'Completed', '2026-08-01', '2026-08-11'); // 10 days
        $this->ticket($b, 'Completed', '2026-08-10', '2026-08-14'); // 4 days
        $this->ticket($a, 'Completed', '2026-08-12', null);         // no completion date: not in the average
        $this->ticket($a, 'Open', '2026-10-01', null);

        $this->summary('maintenance')->assertViewHas('maintenance', function ($m) use ($a, $b) {
            return $m['total'] === 4
                && $m['by_status']->all() === ['Completed' => 3, 'Open' => 1]
                && $m['avg_turnaround_days'] === 7.0
                && $m['completed_counted'] === 2
                && $m['top_assets'][0]['asset_id'] === $a->id
                && $m['top_assets'][0]['total'] === 3
                && $m['top_assets'][1]['asset_id'] === $b->id
                && $m['monthly'] === ['labels' => ['Aug 2026', 'Sep 2026', 'Oct 2026'], 'data' => [3, 0, 1]];
        });
    }

    public function test_turnaround_is_null_when_nothing_completed(): void
    {
        $this->ticket($this->asset(), 'Open', '2026-10-01', null);

        $this->summary('maintenance')->assertViewHas('maintenance', fn ($m) => $m['avg_turnaround_days'] === null)->assertSee('—');
    }

    // ---- Reported issues ----------------------------------------------------------------------------------------

    public function test_issues_tab_counts_by_type_and_status_with_monthly_volume(): void
    {
        $this->issue('Network', 'Open', $this->accra, '2026-09-05');
        $this->issue('Network', 'Resolved', $this->accra, '2026-09-20');
        $this->issue('BitLocker', 'Open', $this->kumasi, '2026-10-01');
        $this->issue('Password Reset', 'Closed', $this->accra, '2026-07-15');

        $this->summary('issues')->assertViewHas('issues', function ($i) {
            return $i['total'] === 4
                && $i['by_type']['Network'] === 2
                && $i['by_type']['BitLocker'] === 1
                && $i['by_type']['Password Reset'] === 1
                && $i['by_type']['Hardware Fault'] === 0
                && array_keys($i['by_type']->all()) === IctAssetIssueReport::ISSUE_TYPES
                && $i['by_status']['Open'] === 2
                && $i['by_status']['Resolved'] === 1
                && $i['by_status']['Closed'] === 1
                && $i['monthly']['labels'] === ['Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026']
                && $i['monthly']['data'] === [1, 0, 2, 1];
        });
    }

    // ---- Filters ------------------------------------------------------------------------------------------------

    public function test_the_district_filter_narrows_every_tab(): void
    {
        $dell = IctAssetManufacturer::query()->create(['name' => 'Dell']);
        $model = $this->model('Latitude', $dell);

        $inAccra = $this->asset(['district_id' => $this->accra->id, 'region_id' => $this->accra->region_id, 'ict_asset_model_id' => $model->id, 'purchased_at' => '2019-01-01']);
        $inKumasi = $this->asset(['district_id' => $this->kumasi->id, 'region_id' => $this->kumasi->region_id, 'ict_asset_model_id' => $model->id]);
        $this->ticket($inAccra, 'Open', '2026-09-01', null);
        $this->ticket($inKumasi, 'Open', '2026-09-02', null);
        $this->ticket($inKumasi, 'Open', '2026-09-03', null);
        $this->issue('Network', 'Open', $this->accra, '2026-09-01');
        $this->issue('Network', 'Open', $this->kumasi, '2026-09-01');
        $this->issue('Software', 'Open', $this->kumasi, '2026-09-01');

        $this->summary('lifecycle', ['district' => (string) $this->accra->id])
            ->assertViewHas('ageBuckets', fn ($b) => collect($b)->sum('total') === 1);
        $this->summary('lifecycle')
            ->assertViewHas('ageBuckets', fn ($b) => collect($b)->sum('total') === 2);

        $this->summary('manufacturers', ['district' => (string) $this->kumasi->id])
            ->assertViewHas('manufacturerGroups', fn ($g) => $g['asset']['result']['with_model'] === 1 && $g['asset']['result']['rows']->first()['total'] === 1);
        $this->summary('maintenance', ['district' => (string) $this->kumasi->id])
            ->assertViewHas('maintenance', fn ($m) => $m['total'] === 2);
        $this->summary('issues', ['district' => (string) $this->kumasi->id])
            ->assertViewHas('issues', fn ($i) => $i['total'] === 2 && $i['by_type']['Software'] === 1);
        $this->summary('issues', ['district' => (string) $this->accra->id])
            ->assertViewHas('issues', fn ($i) => $i['total'] === 1);
        $this->summary('assignment', ['district' => (string) $this->accra->id])
            ->assertViewHas('unassigned', fn ($u) => $u['total'] === 1);
    }

    public function test_the_date_range_narrows_the_maintenance_and_issue_tabs(): void
    {
        $asset = $this->asset();
        $this->ticket($asset, 'Open', '2026-07-10', null);
        $this->ticket($asset, 'Open', '2026-08-10', null);
        $this->ticket($asset, 'Open', '2026-09-10', null);
        $this->issue('Network', 'Open', $this->accra, '2026-07-10');
        $this->issue('Network', 'Open', $this->accra, '2026-08-10');
        $this->issue('Network', 'Open', $this->accra, '2026-09-10');

        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];

        $this->summary('maintenance', $range)->assertViewHas('maintenance', fn ($m) => $m['total'] === 1 && $m['monthly'] === ['labels' => ['Aug 2026'], 'data' => [1]]);
        $this->summary('issues', $range)->assertViewHas('issues', fn ($i) => $i['total'] === 1);
        // Both ends are inclusive.
        $this->summary('issues', ['from' => '2026-07-10', 'to' => '2026-09-10'])->assertViewHas('issues', fn ($i) => $i['total'] === 3);
        // A garbage date is ignored rather than erroring.
        $this->summary('issues', ['from' => 'not-a-date'])->assertViewHas('issues', fn ($i) => $i['total'] === 3);
    }

    // ---- Needs attention ----------------------------------------------------------------------------------------

    public function test_needs_attention_lists_exactly_the_assets_matching_any_condition(): void
    {
        $poor = $this->asset(['asset_name' => 'Poor one', 'condition' => IctAsset::CONDITION_POOR]);
        $damaged = $this->asset(['asset_name' => 'Damaged one', 'status' => IctAsset::STATUS_DAMAGED, 'status_reason' => 'Cracked']);
        $issueOpen = $this->asset(['asset_name' => 'Open issue']);
        $issueProgress = $this->asset(['asset_name' => 'In progress issue']);
        $everything = $this->asset(['asset_name' => 'All three', 'condition' => IctAsset::CONDITION_POOR, 'status' => IctAsset::STATUS_DAMAGED, 'status_reason' => 'Dropped']);
        $this->asset(['asset_name' => 'Resolved issue only']);
        $this->asset(['asset_name' => 'Fair condition', 'condition' => IctAsset::CONDITION_FAIR]);
        $this->asset(['asset_name' => 'Healthy']);

        $this->linkedIssue($issueOpen, 'Open');
        $this->linkedIssue($issueProgress, 'In Progress');
        $this->linkedIssue($everything, 'Open');
        $this->linkedIssue($everything, 'In Progress');
        $this->linkedIssue(IctAsset::query()->where('asset_name', 'Resolved issue only')->first(), 'Resolved');
        $this->linkedIssue(IctAsset::query()->where('asset_name', 'Fair condition')->first(), 'Closed');

        $this->summary('lifecycle')->assertViewHas('needsAttention', function ($rows) use ($poor, $damaged, $issueOpen, $issueProgress, $everything) {
            $by = $rows->keyBy(fn ($r) => $r['asset']->id);

            return $rows->count() === 5
                && $by->keys()->sort()->values()->all() === collect([$poor, $damaged, $issueOpen, $issueProgress, $everything])->pluck('id')->sort()->values()->all()
                && $by[$poor->id]['reasons'] === ['Poor condition']
                && $by[$damaged->id]['reasons'] === ['Damaged']
                && $by[$issueOpen->id]['reasons'] === ['1 open issue']
                && $by[$everything->id]['reasons'] === ['Damaged', 'Poor condition', '2 open issues'];
        })->assertSee('All three')->assertDontSee('Healthy')->assertDontSee('Resolved issue only');
    }

    public function test_needs_attention_is_capped_at_ten_and_respects_the_district_filter(): void
    {
        foreach (range(1, 12) as $i) {
            $this->asset(['condition' => IctAsset::CONDITION_POOR, 'district_id' => $i <= 3 ? $this->kumasi->id : $this->accra->id, 'region_id' => $i <= 3 ? $this->kumasi->region_id : $this->accra->region_id]);
        }

        $this->summary('lifecycle')->assertViewHas('needsAttention', fn ($rows) => $rows->count() === 10);
        $this->summary('lifecycle', ['district' => (string) $this->kumasi->id])->assertViewHas('needsAttention', fn ($rows) => $rows->count() === 3);
    }

    public function test_needs_attention_links_to_the_asset_detail_page(): void
    {
        $this->asset(['asset_name' => 'Cracked phone', 'serial_number' => 'PH-77', 'device_category' => IctAsset::DEVICE_CATEGORY_PHONE, 'asset_type' => 'Ph', 'status' => IctAsset::STATUS_DAMAGED, 'status_reason' => 'Screen']);

        $phone = IctAsset::query()->where('serial_number', 'PH-77')->firstOrFail();

        $this->get(route('assets.summary'))->assertOk()->assertSee(route('assets.show', $phone), false);
        $this->get(route('assets.show', $phone))->assertOk()->assertSee('Cracked phone');
    }

    // ---- Tabs and the dashboard ---------------------------------------------------------------------------------

    public function test_the_active_tab_is_bookmarkable_and_unknown_tabs_fall_back(): void
    {
        $this->asset();

        $this->get(route('assets.summary'))->assertOk()->assertSee('Asset Age');
        $this->get(route('assets.summary', ['tab' => 'maintenance']))->assertOk()->assertSee('Average turnaround')->assertDontSee('Asset Age');
        $this->get(route('assets.summary', ['tab' => 'issues']))->assertOk()->assertSee('Reported issues');
        $this->get(route('assets.summary', ['tab' => 'manufacturers']))->assertOk()->assertSee('Share of devices by manufacturer');
        $this->get(route('assets.summary', ['tab' => 'assignment']))->assertOk()->assertSee('Employees With the Most Assets');
        $this->get(route('assets.summary', ['tab' => 'bogus']))->assertOk()->assertSee('Asset Age');

        Livewire::withQueryParams(['tab' => 'maintenance', 'district' => (string) $this->accra->id])
            ->test(Summary::class)
            ->assertSet('tab', 'maintenance')
            ->assertSet('district', (string) $this->accra->id)
            ->set('tab', 'issues')
            ->assertSet('tab', 'issues');
    }

    public function test_the_relocated_cards_are_gone_from_the_dashboard(): void
    {
        $this->asset(['purchased_at' => '2019-01-01', 'warranty_expires_at' => '2020-01-01']);

        $this->get(route('assets.home'))
            ->assertOk()
            ->assertSee('District Breakdown')
            ->assertSee('Device Allocation')
            ->assertSee('Unassigned Assets')
            ->assertDontSee('Asset Age')
            ->assertDontSee('Warranty Status')
            ->assertDontSee('Replacement Forecast')
            ->assertDontSee('Employees With the Most Assets');

        Livewire::test(Dashboard::class)
            ->assertViewMissing('ageBuckets')
            ->assertViewMissing('warrantyBuckets')
            ->assertViewMissing('replacementBuckets')
            ->assertViewMissing('topAssignees')
            ->assertViewHas('unassigned');
    }

    public function test_the_summary_sits_in_the_sidebar_right_after_the_dashboard(): void
    {
        $sidebar = app(\App\Support\ErpNavigation::class)->build(auth()->user(), 'assets');
        $labels = collect($sidebar['sidebar'])->where('type', '!=', 'section')->pluck('label')->values();

        $this->assertSame('Dashboard', $labels[0]);
        $this->assertSame('Summary', $labels[1]);
    }

    // ---- access -------------------------------------------------------------------------------------------------

    public function test_a_regional_ict_user_only_sees_their_regions_numbers(): void
    {
        $this->asset(['district_id' => $this->accra->id, 'region_id' => $this->accra->region_id]);
        $other = $this->asset(['district_id' => $this->kumasi->id, 'region_id' => $this->kumasi->region_id, 'condition' => IctAsset::CONDITION_POOR]);
        $this->ticket($other, 'Open', '2026-09-01', null);
        $this->issue('Network', 'Open', $this->kumasi, '2026-09-01');

        $ict = User::query()->create([
            'staff_id' => 'ICT1', 'full_name' => 'Accra ICT', 'email' => 'ict1@example.com',
            'password' => Hash::make('password'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $ict->roles()->attach(Role::query()->where('name', 'ict_team')->firstOrFail());
        \App\Models\Employee::query()->create([
            'staff_id' => 'ICT1', 'full_name' => 'Accra ICT', 'gender' => 'Male', 'category' => 'Senior Staff', 'email' => 'ict1@example.com',
            'job_title_id' => \App\Models\JobTitle::query()->create(['job_title_name' => 'Officer'])->id,
            'department_id' => \App\Models\Department::query()->create(['department_name' => 'ICT'])->id,
            'region_id' => $this->accra->region_id, 'district_id' => $this->accra->id, 'location_type' => 'District',
            'date_of_birth' => '1990-01-01', 'date_joined' => '2026-01-01', 'present_appointment' => '2026-01-01', 'is_active' => true,
        ]);
        $this->actingAs($ict);

        $this->summary('lifecycle')->assertViewHas('ageBuckets', fn ($b) => collect($b)->sum('total') === 1)
            ->assertViewHas('needsAttention', fn ($rows) => $rows->isEmpty());
        $this->summary('maintenance')->assertViewHas('maintenance', fn ($m) => $m['total'] === 0);
        $this->summary('issues')->assertViewHas('issues', fn ($i) => $i['total'] === 0);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    protected function summary(string $tab, array $filters = [])
    {
        return Livewire::withQueryParams(['tab' => $tab, ...$filters])->test(Summary::class);
    }

    protected function model(string $name, IctAssetManufacturer $manufacturer): IctAssetModel
    {
        return IctAssetModel::query()->create(['name' => $name, 'category' => 'PC', 'ict_asset_manufacturer_id' => $manufacturer->id, 'is_active' => true]);
    }

    protected function ticket(IctAsset $asset, string $status, string $createdAt, ?string $completed): IctAssetMaintenance
    {
        $ticket = new IctAssetMaintenance([
            'ict_asset_id' => $asset->id,
            'maintenance_type' => 'Repair',
            'status' => $status,
            'completion_date' => $completed,
        ]);
        $ticket->created_at = $createdAt;
        $ticket->save();

        return $ticket;
    }

    protected function issue(string $type, string $status, District $district, string $createdAt): IctAssetIssueReport
    {
        $issue = new IctAssetIssueReport([
            'title' => $type.' problem',
            'issue_type' => $type,
            'status' => $status,
            'reporting_district_id' => $district->id,
            'reporting_region_id' => $district->region_id,
        ]);
        $issue->created_at = $createdAt;
        $issue->save();

        return $issue;
    }

    protected function linkedIssue(IctAsset $asset, string $status): void
    {
        IctAssetIssueReport::query()->create([
            'title' => 'Problem with '.$asset->asset_name,
            'issue_type' => 'Hardware Fault',
            'status' => $status,
            'linked_asset_id' => $asset->id,
        ]);
    }

    protected function asset(array $overrides = []): IctAsset
    {
        static $n = 0;
        $n++;

        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset '.$n,
            'serial_number' => 'SM-'.$n,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }
}
