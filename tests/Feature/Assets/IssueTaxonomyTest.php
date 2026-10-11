<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\IssueReports;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Livewire\Assets\Settings\IssueCategoriesManager;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\IctAssetTransfer;
use App\Models\IctIssueCategory;
use App\Models\IctIssueSubtype;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\AssetSummaryService;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class IssueTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);

        $this->admin = $this->user('SA001', 'super_admin');
        $this->actingAs($this->admin);
    }

    // ---- the seeded taxonomy ------------------------------------------------------------------------------------

    public function test_the_seeded_categories_and_subtypes_are_exactly_the_decided_list(): void
    {
        $categories = IctIssueCategory::query()->ordered()->with('subtypes')->get();

        $this->assertSame(
            ['Computer', 'Printer/Photocopier', 'Phone', 'Network/Internet', 'Password Reset', 'BitLocker', 'Software', 'Other'],
            $categories->pluck('label')->all()
        );

        $by = $categories->keyBy('label');
        $this->assertSame([true, 'asset'], [$by['Computer']->requires_linked_asset, $by['Computer']->maps_to_device_category]);
        $this->assertSame([true, 'asset'], [$by['Printer/Photocopier']->requires_linked_asset, $by['Printer/Photocopier']->maps_to_device_category]);
        $this->assertSame([true, 'phone'], [$by['Phone']->requires_linked_asset, $by['Phone']->maps_to_device_category]);
        $this->assertSame([false, 'network'], [$by['Network/Internet']->requires_linked_asset, $by['Network/Internet']->maps_to_device_category]);

        foreach (['Password Reset', 'BitLocker', 'Software', 'Other'] as $single) {
            $this->assertSame([false, null, []], [$by[$single]->requires_linked_asset, $by[$single]->maps_to_device_category, $by[$single]->subtypes->all()]);
        }

        $this->assertSame(["Won't boot", 'Blue screen / crash', 'Slow performance', 'Keyboard', 'Display/Screen', 'Battery', 'Overheating', 'Virus/Malware', 'Software install', 'Other'], $by['Computer']->subtypes->pluck('label')->all());
        $this->assertSame(['Paper jam', 'Toner/Ink', 'Connectivity', 'Driver', 'Print quality', 'Other'], $by['Printer/Photocopier']->subtypes->pluck('label')->all());
        $this->assertSame(['Screen', 'Power button', 'Battery', 'Camera', 'Speaker/Mic', 'Charging port', 'SIM', 'Software/App', 'Other'], $by['Phone']->subtypes->pluck('label')->all());
        $this->assertSame(['Internal', 'ISP', 'Hardware (router/switch/AP)', 'Wi-Fi coverage', 'Other'], $by['Network/Internet']->subtypes->pluck('label')->all());
    }

    // ---- the form: categories, subtypes --------------------------------------------------------------------------

    public function test_dropdowns_only_offer_active_categories_and_subtypes(): void
    {
        IctIssueCategory::query()->where('label', 'Software')->update(['is_active' => false]);
        IctIssueSubtype::query()->where('label', 'Overheating')->update(['is_active' => false]);

        $component = Livewire::test(IssueReports::class)->call('openCreate');

        $component->assertViewHas('categoryOptions', fn ($options) => $options === ['Computer', 'Printer/Photocopier', 'Phone', 'Network/Internet', 'Password Reset', 'BitLocker', 'Other']);

        $component->set('form.issue_type', 'Computer')
            ->assertViewHas('subtypeOptions', fn ($o) => ! in_array('Overheating', $o, true) && in_array('Battery', $o, true) && count($o) === 9)
            ->assertSeeHtml('<option value="Battery">Battery</option>')
            ->assertDontSeeHtml('<option value="Overheating">');

        // A switched-off category or subtype is refused even if someone posts it by hand.
        $component->set('form.title', 'x')->set('form.issue_type', 'Software')->call('save')->assertHasErrors(['form.issue_type']);

        $asset = $this->asset(['asset_type' => 'PC']);
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'Hot')->set('form.issue_type', 'Computer')->set('form.issue_subtype', 'Overheating')
            ->set('form.linked_asset_id', $asset->id)->call('save')->assertHasErrors(['form.issue_subtype']);
    }

    public function test_subtype_is_required_exactly_when_the_category_has_active_subtypes(): void
    {
        $asset = $this->asset(['asset_type' => 'PC']);

        // Has subtypes: required.
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'Slow')->set('form.issue_type', 'Computer')->set('form.linked_asset_id', $asset->id)
            ->call('save')->assertHasErrors(['form.issue_subtype' => 'required']);

        // Single-level category: no subtype needed, none offered, and one posted by hand is dropped.
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'Locked out')->set('form.issue_type', 'Password Reset')
            ->assertViewHas('subtypeOptions', [])
            ->set('form.issue_subtype', 'Something')
            ->call('save')->assertHasNoErrors();

        $report = IctAssetIssueReport::query()->where('title', 'Locked out')->firstOrFail();
        $this->assertSame('Password Reset', $report->issue_type);
        $this->assertNull($report->issue_subtype);

        // Switch off every Network subtype and the requirement disappears with them.
        $network = IctIssueCategory::query()->where('label', 'Network/Internet')->firstOrFail();
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'Down')->set('form.issue_type', 'Network/Internet')
            ->call('save')->assertHasErrors(['form.issue_subtype' => 'required']);

        $network->subtypes()->update(['is_active' => false]);
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'Down')->set('form.issue_type', 'Network/Internet')
            ->assertViewHas('subtypeOptions', [])
            ->call('save')->assertHasNoErrors();
    }

    public function test_the_category_label_and_subtype_are_stored_as_plain_text(): void
    {
        $asset = $this->asset(['asset_type' => 'PC']);

        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'Dead battery')->set('form.issue_type', 'Computer')->set('form.issue_subtype', 'Battery')
            ->set('form.linked_asset_id', $asset->id)->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('ict_asset_issue_reports', ['title' => 'Dead battery', 'issue_type' => 'Computer', 'issue_subtype' => 'Battery', 'linked_asset_id' => $asset->id]);
    }

    public function test_changing_the_category_resets_the_subtype(): void
    {
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.issue_type', 'Computer')->set('form.issue_subtype', 'Battery')
            ->set('form.issue_type', 'Phone')
            ->assertSet('form.issue_subtype', '');
    }

    public function test_the_linked_asset_is_required_exactly_when_the_category_requires_it(): void
    {
        foreach (['Computer' => 'Battery', 'Printer/Photocopier' => 'Paper jam', 'Phone' => 'Screen'] as $category => $subtype) {
            Livewire::test(IssueReports::class)->call('openCreate')
                ->set('form.title', 'T')->set('form.issue_type', $category)->set('form.issue_subtype', $subtype)
                ->call('save')->assertHasErrors(['form.linked_asset_id' => 'required']);
        }

        // Not required: Network/Internet, and the general categories.
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'ISP down')->set('form.issue_type', 'Network/Internet')->set('form.issue_subtype', 'ISP')
            ->call('save')->assertHasNoErrors();
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'Key')->set('form.issue_type', 'BitLocker')->call('save')->assertHasNoErrors();

        // The message says what to do.
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'T')->set('form.issue_type', 'Computer')->set('form.issue_subtype', 'Battery')
            ->call('save')->assertSee('must be linked to the affected device');
    }

    public function test_the_asset_picker_narrows_by_category_device_kind_and_type(): void
    {
        $pc = $this->asset(['asset_name' => 'A PC', 'asset_type' => 'PC']);
        $laptop = $this->asset(['asset_name' => 'A Laptop', 'asset_type' => 'Laptop']);
        $server = $this->asset(['asset_name' => 'A Server', 'asset_type' => 'Server']);
        $printer = $this->asset(['asset_name' => 'A Printer', 'asset_type' => 'PRT']);
        $copier = $this->asset(['asset_name' => 'A Copier', 'asset_type' => 'PTC']);
        $phone = $this->asset(['asset_name' => 'A Phone', 'asset_type' => 'Ph', 'device_category' => 'phone']);
        $router = $this->asset(['asset_name' => 'A Router', 'asset_type' => 'RT', 'device_category' => 'network']);

        $ids = fn (string $category) => Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.issue_type', $category)->viewData('assets')->pluck('id')->sort()->values()->all();
        $sorted = fn (array $assets) => collect($assets)->pluck('id')->sort()->values()->all();

        $this->assertSame($sorted([$pc, $laptop, $server]), $ids('Computer'));
        $this->assertSame($sorted([$printer, $copier]), $ids('Printer/Photocopier'));
        $this->assertSame($sorted([$phone]), $ids('Phone'));
        $this->assertSame($sorted([$router]), $ids('Network/Internet'));
        $this->assertSame($sorted([$pc, $laptop, $server, $printer, $copier, $phone, $router]), $ids('Password Reset'));
        $this->assertSame($sorted([$pc, $laptop, $server, $printer, $copier, $phone, $router]), Livewire::test(IssueReports::class)->call('openCreate')->viewData('assets')->pluck('id')->sort()->values()->all());

        // The server side holds the same line: a printer cannot be linked to a Phone issue.
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'T')->set('form.issue_type', 'Phone')->set('form.issue_subtype', 'Screen')->set('form.linked_asset_id', $printer->id)
            ->call('save')->assertHasErrors(['form.linked_asset_id']);

        // And switching category drops a linked asset that no longer fits.
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.issue_type', 'Computer')->set('form.linked_asset_id', $pc->id)
            ->set('form.issue_type', 'Phone')
            ->assertSet('form.linked_asset_id', null)
            ->set('form.issue_type', 'Computer')->set('form.linked_asset_id', $laptop->id)
            ->set('form.issue_type', 'Password Reset')
            ->assertSet('form.linked_asset_id', $laptop->id);
    }

    public function test_an_old_report_with_a_retired_label_can_still_be_edited(): void
    {
        $report = IctAssetIssueReport::query()->create(['title' => 'Old one', 'issue_type' => 'Hardware Fault', 'status' => 'Open']);

        Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->assertSee('older type')
            ->set('form.title', 'Old one, renamed')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Old one, renamed', $report->fresh()->title);
        $this->assertSame('Hardware Fault', $report->fresh()->issue_type);
    }

    public function test_the_status_must_be_one_of_the_known_statuses(): void
    {
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'T')->set('form.issue_type', 'Other')->set('form.status', 'Banana')
            ->call('save')->assertHasErrors(['form.status']);

        $this->assertSame(['Open', 'In Progress', 'Resolved', 'Closed'], IctAssetIssueReport::STATUSES);
    }

    // ---- the "still active?" popup ------------------------------------------------------------------------------

    public function test_closing_a_linked_report_asks_whether_the_device_is_still_active_and_skip_changes_nothing(): void
    {
        [$asset, $report] = $this->linkedReport();

        Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->set('form.status', 'Resolved')->call('save')->assertHasNoErrors()
            ->assertSet('statusPromptAssetId', $asset->id)
            ->assertSet('statusPromptChoice', 'none')
            ->assertSee('still Active')
            ->call('skipStatusPrompt')
            ->assertSet('statusPromptAssetId', null)
            ->assertDontSee('still Active');

        $this->assertSame('Resolved', $report->fresh()->status, 'the report saved regardless');
        $this->assertSame('Active', $asset->fresh()->status);
        $this->assertSame(0, IctAssetTransfer::query()->count());
        $this->assertFalse(AuditLog::query()->where('action', 'like', 'update_%')->where('target_type', 'ict_assets')->exists());
    }

    public function test_answering_no_change_is_the_same_as_skipping(): void
    {
        [$asset, $report] = $this->linkedReport();

        Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->set('form.status', 'Closed')->call('save')
            ->call('applyStatusPrompt')
            ->assertHasNoErrors()->assertSet('statusPromptAssetId', null);

        $this->assertSame('Active', $asset->fresh()->status);
        $this->assertSame(0, IctAssetTransfer::query()->count());
    }

    public function test_a_choice_updates_the_asset_through_the_record_service_and_logs_a_transfer(): void
    {
        [$asset, $report] = $this->linkedReport();

        Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->set('form.status', 'Resolved')->call('save')
            ->set('statusPromptChoice', 'Retired')
            ->call('applyStatusPrompt')
            ->assertHasNoErrors()
            ->assertSet('statusPromptAssetId', null);

        $this->assertSame('Retired', $asset->fresh()->status);

        $transfer = IctAssetTransfer::query()->sole();
        $this->assertSame('status_change', $transfer->transfer_type);
        $this->assertSame(['Active', 'Retired'], [$transfer->from_status, $transfer->to_status]);
        $this->assertSame($this->admin->id, $transfer->performed_by_user_id);
        $this->assertTrue(AuditLog::query()->where('target_type', 'ict_assets')->where('target_id', $asset->id)->where('action', 'update_asset')->exists());
    }

    public function test_marking_a_device_damaged_from_the_popup_needs_a_reason(): void
    {
        [$asset, $report] = $this->linkedReport();

        $component = Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->set('form.status', 'Resolved')->call('save')
            ->set('statusPromptChoice', 'Damaged')
            ->call('applyStatusPrompt')
            ->assertHasErrors(['statusPromptReason'])
            ->assertSet('statusPromptAssetId', $asset->id);

        $this->assertSame('Active', $asset->fresh()->status);

        $component->set('statusPromptReason', 'Faulty power button')->call('applyStatusPrompt')->assertHasNoErrors();

        $asset = $asset->fresh();
        $this->assertSame('Damaged', $asset->status);
        $this->assertSame('Faulty power button', $asset->status_reason);
        $this->assertSame('Faulty power button', IctAssetTransfer::query()->sole()->reason);
    }

    public function test_an_unknown_popup_choice_is_rejected(): void
    {
        [$asset, $report] = $this->linkedReport();

        Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->set('form.status', 'Resolved')->call('save')
            ->set('statusPromptChoice', 'Active')
            ->call('applyStatusPrompt')->assertHasErrors(['statusPromptChoice']);

        $this->assertSame('Active', $asset->fresh()->status);
    }

    public function test_the_popup_only_appears_when_a_linked_report_moves_into_a_closed_state(): void
    {
        [$asset, $report] = $this->linkedReport();

        // Still open: no popup.
        Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->set('form.status', 'In Progress')->call('save')->assertSet('statusPromptAssetId', null);

        // Moving between two closed states: the question was already asked.
        $report->update(['status' => 'Resolved']);
        Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->set('form.status', 'Closed')->call('save')->assertSet('statusPromptAssetId', null);

        // A brand new report, even one created already closed.
        Livewire::test(IssueReports::class)->call('openCreate')
            ->set('form.title', 'New')->set('form.issue_type', 'Computer')->set('form.issue_subtype', 'Battery')
            ->set('form.linked_asset_id', $asset->id)->set('form.status', 'Resolved')
            ->call('save')->assertHasNoErrors()->assertSet('statusPromptAssetId', null);

        // Unlinked report being closed.
        $unlinked = IctAssetIssueReport::query()->create(['title' => 'No device', 'issue_type' => 'Other', 'status' => 'Open']);
        Livewire::test(IssueReports::class)->call('openEdit', $unlinked->id)
            ->set('form.status', 'Resolved')->call('save')->assertSet('statusPromptAssetId', null);

        // Reopened and closed again does ask again.
        $again = IctAssetIssueReport::query()->create(['title' => 'Again', 'issue_type' => 'Computer', 'issue_subtype' => 'Battery', 'status' => 'Open', 'linked_asset_id' => $asset->id]);
        Livewire::test(IssueReports::class)->call('openEdit', $again->id)
            ->set('form.status', 'Closed')->call('save')->assertSet('statusPromptAssetId', $asset->id);
    }

    public function test_the_popup_is_not_offered_to_someone_who_cannot_edit_assets(): void
    {
        [$asset, $report] = $this->linkedReport();

        $role = Role::query()->where('name', 'ict_team')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('name', 'assets.edit')->value('id'));
        $this->actingAs($this->headOfficeIct('ICT1'));

        // In the user's own region, so the only thing standing in the way is the missing assets.edit permission.
        $regionId = Region::query()->value('id');
        $asset->update(['region_id' => $regionId]);
        $report->update(['reporting_region_id' => $regionId]);

        Livewire::test(IssueReports::class)->call('openEdit', $report->id)
            ->set('form.status', 'Resolved')->call('save')->assertHasNoErrors()->assertSet('statusPromptAssetId', null);

        $this->assertSame('Resolved', $report->fresh()->status);
        $this->assertSame('Active', $asset->fresh()->status);
    }

    // ---- Condition is gone --------------------------------------------------------------------------------------

    public function test_condition_is_gone_from_the_schema_the_model_and_the_forms(): void
    {
        $this->assertFalse(Schema::hasColumn('ict_assets', 'condition'));
        $this->assertFalse(defined(IctAsset::class.'::CONDITIONS'));
        $this->assertFalse(defined(IctAsset::class.'::CONDITION_POOR'));
        $this->assertNotContains('condition', (new IctAsset)->getFillable());

        foreach ([AssetsList::class, PhonesList::class, NetworkList::class] as $list) {
            $component = Livewire::test($list)->call('openCreate');

            $this->assertArrayNotHasKey('condition', $component->get('form'));
            $component->assertDontSee('Condition');
        }

        // A hand-posted condition is ignored: there is nothing to write it to.
        $asset = $this->asset(['asset_type' => 'PC']);
        $this->get(route('assets.show', $asset))->assertOk()->assertDontSee('Condition');
        $this->get(route('assets.summary'))->assertOk()->assertDontSee('poor condition');
    }

    // ---- Reported Issues tab: models ----------------------------------------------------------------------------

    public function test_the_reported_issues_tab_ranks_models_and_says_what_mostly_goes_wrong(): void
    {
        $dell = IctAssetManufacturer::query()->create(['name' => 'Dell']);
        $hp = IctAssetManufacturer::query()->create(['name' => 'HP']);
        $latitude = IctAssetModel::query()->create(['name' => 'Latitude', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $dell->id, 'is_active' => true]);
        $probook = IctAssetModel::query()->create(['name' => 'ProBook', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $hp->id, 'is_active' => true]);

        $a1 = $this->asset(['ict_asset_model_id' => $latitude->id]);
        $a2 = $this->asset(['ict_asset_model_id' => $latitude->id]);
        $b1 = $this->asset(['ict_asset_model_id' => $probook->id]);
        $noModel = $this->asset([]);

        $this->issue($a1, 'Battery');
        $this->issue($a1, 'Battery');
        $this->issue($a2, 'Display/Screen');
        $this->issue($b1, 'Keyboard');
        $this->issue($b1, null);
        $this->issue($noModel, 'Battery');
        IctAssetIssueReport::query()->create(['title' => 'Unlinked', 'issue_type' => 'Other', 'status' => 'Open']);

        Livewire::withQueryParams(['tab' => 'issues'])->test(\App\Livewire\Assets\Summary::class)
            ->assertViewHas('issues', function ($issues) {
                $by = $issues['by_model'];

                return $by['rows']->count() === 2
                    && $by['rows'][0] === ['model' => 'Dell Latitude', 'total' => 3, 'top_subtype' => 'Battery', 'top_subtype_count' => 2]
                    && $by['rows'][1] === ['model' => 'HP ProBook', 'total' => 2, 'top_subtype' => 'Keyboard', 'top_subtype_count' => 1]
                    && $by['linked'] === 6
                    && $by['without_model'] === 1
                    && $issues['total'] === 7;
            })
            ->assertSee('Dell Latitude')
            ->assertSee('Models With the Most Issues')
            ->assertSee('Battery')
            ->assertSee('2 of 3');
    }

    public function test_the_models_list_follows_the_district_and_date_filters(): void
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $accra = District::query()->create(['district_name' => 'Accra', 'region_id' => $region->id]);
        $tema = District::query()->create(['district_name' => 'Tema', 'region_id' => $region->id]);

        $maker = IctAssetManufacturer::query()->create(['name' => 'Dell']);
        $model = IctAssetModel::query()->create(['name' => 'Latitude', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $maker->id, 'is_active' => true]);
        $asset = $this->asset(['ict_asset_model_id' => $model->id]);

        $this->issue($asset, 'Battery', $accra, '2026-08-10');
        $this->issue($asset, 'Battery', $tema, '2026-09-10');
        $this->issue($asset, 'Keyboard', $tema, '2026-09-20');

        $service = app(AssetSummaryService::class);
        $reports = fn (?int $district, ?string $from, ?string $to) => $service->issuesQuery(IctAssetIssueReport::query(), $district, $from, $to);

        $this->assertSame(3, $service->issuesByModel($reports(null, null, null))['rows'][0]['total']);
        $this->assertSame(2, $service->issuesByModel($reports($tema->id, null, null))['rows'][0]['total']);
        $this->assertSame(1, $service->issuesByModel($reports($tema->id, '2026-09-15', null))['rows'][0]['total']);
        $this->assertSame([], $service->issuesByModel($reports($accra->id, '2026-09-01', null))['rows']->all());
    }

    // ---- Settings screen ----------------------------------------------------------------------------------------

    public function test_the_issue_categories_screen_is_gated_by_its_permission(): void
    {
        $this->get(route('assets.settings.issue-categories'))->assertOk()->assertSee('Issue Categories');

        $this->actingAs($this->headOfficeIct('ICT1'));
        $this->get(route('assets.settings.issue-categories'))->assertOk();
        Livewire::test(IssueCategoriesManager::class)->assertOk();

        Role::query()->where('name', 'ict_team')->firstOrFail()->permissions()->detach(Permission::query()->where('name', 'assets.manage_issue_categories')->value('id'));
        $this->actingAs($this->headOfficeIct('ICT2'));
        $this->get(route('assets.settings.issue-categories'))->assertForbidden();
        Livewire::test(IssueCategoriesManager::class)->assertForbidden();
    }

    public function test_the_permission_is_granted_to_super_admin_and_ict_team(): void
    {
        $granted = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'assets.manage_issue_categories'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['ict_team', 'super_admin'], $granted);
    }

    public function test_switching_a_category_off_removes_it_from_the_form_and_is_audited(): void
    {
        $software = IctIssueCategory::query()->where('label', 'Software')->firstOrFail();

        Livewire::test(IssueCategoriesManager::class)->call('toggleCategory', $software->id);

        $this->assertFalse($software->fresh()->is_active);
        $this->assertTrue(AuditLog::query()->where('action', 'update_issue_category')->where('target_id', $software->id)->exists());
        Livewire::test(IssueReports::class)->call('openCreate')
            ->assertViewHas('categoryOptions', fn ($o) => ! in_array('Software', $o, true));

        Livewire::test(IssueCategoriesManager::class)->call('toggleCategory', $software->id);
        $this->assertTrue($software->fresh()->is_active);
    }

    public function test_subtypes_can_be_added_switched_off_and_never_duplicated(): void
    {
        $phone = IctIssueCategory::query()->where('label', 'Phone')->firstOrFail();

        $manager = Livewire::test(IssueCategoriesManager::class)
            ->call('selectCategory', $phone->id)
            ->set('newSubtype', 'Water damage')->call('addSubtype')->assertHasNoErrors()
            ->set('newSubtype', 'water damage')->call('addSubtype');

        $subtype = IctIssueSubtype::query()->where('label', 'Water damage')->firstOrFail();
        $this->assertSame($phone->id, $subtype->ict_issue_category_id);
        $this->assertTrue(AuditLog::query()->where('action', 'create_issue_subtype')->exists());

        $manager->set('newSubtype', 'Screen')->call('addSubtype')->assertHasErrors(['newSubtype']);
        $manager->set('newSubtype', '')->call('addSubtype')->assertHasErrors(['newSubtype']);

        Livewire::test(IssueReports::class)->call('openCreate')->set('form.issue_type', 'Phone')
            ->assertViewHas('subtypeOptions', fn ($o) => in_array('Water damage', $o, true));

        $manager->call('toggleSubtype', $subtype->id);
        $this->assertFalse($subtype->fresh()->is_active);
        Livewire::test(IssueReports::class)->call('openCreate')->set('form.issue_type', 'Phone')
            ->assertViewHas('subtypeOptions', fn ($o) => ! in_array('Water damage', $o, true));
    }

    public function test_categories_and_subtypes_can_be_reordered(): void
    {
        $manager = Livewire::test(IssueCategoriesManager::class);

        $phone = IctIssueCategory::query()->where('label', 'Phone')->firstOrFail();
        $manager->call('moveCategory', $phone->id, 'up');

        $this->assertSame(['Computer', 'Phone', 'Printer/Photocopier', 'Network/Internet', 'Password Reset', 'BitLocker', 'Software', 'Other'], IctIssueCategory::query()->ordered()->pluck('label')->all());
        Livewire::test(IssueReports::class)->call('openCreate')->assertViewHas('categoryOptions', fn ($o) => array_slice($o, 0, 3) === ['Computer', 'Phone', 'Printer/Photocopier']);

        // The first item cannot go further up.
        $manager->call('moveCategory', IctIssueCategory::query()->ordered()->value('id'), 'up');
        $this->assertSame('Computer', IctIssueCategory::query()->ordered()->value('label'));

        $computer = IctIssueCategory::query()->where('label', 'Computer')->firstOrFail();
        $battery = $computer->subtypes()->where('label', 'Battery')->firstOrFail();
        $manager->call('moveSubtype', $battery->id, 'up');
        $this->assertSame(["Won't boot", 'Blue screen / crash', 'Slow performance', 'Keyboard', 'Battery', 'Display/Screen'], array_slice($computer->subtypes()->pluck('label')->all(), 0, 6));
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** @return array{0: IctAsset, 1: IctAssetIssueReport} */
    protected function linkedReport(): array
    {
        $asset = $this->asset(['asset_type' => 'PC']);
        $report = IctAssetIssueReport::query()->create([
            'title' => 'Will not start',
            'issue_type' => 'Computer',
            'issue_subtype' => "Won't boot",
            'status' => 'Open',
            'linked_asset_id' => $asset->id,
        ]);

        return [$asset, $report];
    }

    protected function issue(IctAsset $asset, ?string $subtype, ?District $district = null, string $createdAt = '2026-09-01'): IctAssetIssueReport
    {
        $report = new IctAssetIssueReport([
            'title' => 'Problem',
            'issue_type' => 'Computer',
            'issue_subtype' => $subtype,
            'status' => 'Open',
            'linked_asset_id' => $asset->id,
            'reporting_district_id' => $district?->id,
            'reporting_region_id' => $district?->region_id,
        ]);
        $report->created_at = $createdAt;
        $report->save();

        return $report;
    }

    protected function headOfficeIct(string $staffId): User
    {
        $user = $this->user($staffId, 'ict_team');
        $region = Region::query()->firstOrCreate(['region_name' => 'Greater Accra']);
        $district = District::query()->firstOrCreate(['district_name' => 'Head Office'], ['region_id' => $region->id]);
        \App\Models\Employee::query()->create([
            'staff_id' => $staffId, 'full_name' => 'HO '.$staffId, 'gender' => 'Male', 'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => \App\Models\JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => \App\Models\Department::query()->firstOrCreate(['department_name' => 'ICT'])->id,
            'region_id' => $region->id, 'district_id' => $district->id, 'location_type' => 'HeadOffice',
            'date_of_birth' => '1990-01-01', 'date_joined' => '2026-01-01', 'present_appointment' => '2026-01-01', 'is_active' => true,
        ]);

        return $user;
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
            'serial_number' => 'IT-'.$n,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }
}
