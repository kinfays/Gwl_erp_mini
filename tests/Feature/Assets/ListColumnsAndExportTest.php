<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\IssueReports;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetModel;
use App\Models\JobTitle;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetRecordService;
use App\Support\ListColumns;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ListColumnsAndExportTest extends TestCase
{
    use RefreshDatabase;

    protected District $district;

    protected Employee $holder;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);

        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->district = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $region->id]);

        $this->admin = $this->user('SA001', 'super_admin');
        $this->employee('SA001', 'Super Admin');
        $this->holder = $this->employee('E1', 'Kwame Mensah');

        $this->actingAs($this->admin);
    }

    // ---- Part A: processor ------------------------------------------------------------------------------------

    public function test_the_processor_only_persists_for_computers_and_servers(): void
    {
        $this->assertSame(['PC', 'AIO', 'Laptop', 'Server'], IctAsset::PROCESSOR_ASSET_TYPES);

        foreach (['PC', 'AIO', 'Laptop', 'Server'] as $type) {
            $this->fillForm($type, 'CPU-'.$type)->set('form.cpu_name', 'i7 13th Gen @ 1.6GHz')->call('save')->assertHasNoErrors();
            $this->assertSame('i7 13th Gen @ 1.6GHz', IctAsset::query()->where('serial_number', 'CPU-'.$type)->value('cpu_name'), $type);
        }

        foreach (['PRT', 'PTC'] as $type) {
            $this->fillForm($type, 'CPU-'.$type)->set('form.cpu_name', 'Should not stick')->call('save')->assertHasNoErrors();
            $this->assertNull(IctAsset::query()->where('serial_number', 'CPU-'.$type)->value('cpu_name'), $type);
        }

        // Blank is NULL, not an empty string.
        $this->fillForm('PC', 'CPU-BLANK')->set('form.cpu_name', '')->call('save');
        $this->assertNull(IctAsset::query()->where('serial_number', 'CPU-BLANK')->value('cpu_name'));
    }

    public function test_the_processor_is_cleared_when_the_type_changes_away(): void
    {
        Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_type', 'Laptop')
            ->assertViewHas('formSupportsProcessor', true)->assertSee('Ultra 7-115U')
            ->set('form.cpu_name', 'Ultra 7 155U')
            ->set('form.asset_type', 'PRT')
            ->assertSet('form.cpu_name', '')
            ->assertViewHas('formSupportsProcessor', false)->assertDontSee('Ultra 7-115U');

        // The service holds the same line for a stored asset whose type is changed.
        $pc = $this->asset(['asset_type' => 'PC', 'cpu_name' => 'i5']);
        app(AssetRecordService::class)->save(['asset_type' => 'PRT'], 'asset', $pc);
        $this->assertNull($pc->fresh()->cpu_name);

        // And a save that says nothing about the processor leaves it alone (the agent fills it elsewhere).
        $pc2 = $this->asset(['asset_type' => 'PC', 'cpu_name' => 'i9']);
        app(AssetRecordService::class)->save(['status' => 'Retired'], 'asset', $pc2);
        $this->assertSame('i9', $pc2->fresh()->cpu_name);
    }

    public function test_the_processor_is_loaded_into_the_edit_form_and_phones_and_network_have_none(): void
    {
        $model = IctAssetModel::query()->create(['name' => 'M', 'category' => 'PC', 'is_active' => true]);
        $pc = $this->asset(['asset_type' => 'PC', 'cpu_name' => 'Ryzen 7', 'ict_asset_model_id' => $model->id, 'assigned_to_employee_id' => $this->holder->id, 'department_id' => Department::query()->value('id')]);

        Livewire::test(AssetsList::class)->call('openEdit', $pc->id)->assertSet('form.cpu_name', 'Ryzen 7');

        foreach ([PhonesList::class, NetworkList::class] as $list) {
            $this->assertArrayNotHasKey('cpu_name', Livewire::test($list)->call('openCreate')->get('form'));
        }
    }

    public function test_the_processor_column_only_shows_a_value_where_it_applies(): void
    {
        $this->asset(['asset_name' => 'Has cpu', 'asset_type' => 'Laptop', 'cpu_name' => 'Core Ultra 9']);
        // A printer cannot carry one, even if the column holds data (e.g. from an agent report).
        $this->asset(['asset_name' => 'Printer', 'asset_type' => 'PRT', 'cpu_name' => 'Stray Value']);
        $this->asset(['asset_name' => 'Server', 'asset_type' => 'Server']);

        $html = Livewire::test(AssetsList::class)->html();

        $this->assertStringContainsString('Processor', $html);
        $this->assertStringContainsString('Core Ultra 9', $html);
        $this->assertStringNotContainsString('Stray Value', $html);
        $this->assertStringContainsString('—', $html, 'a server with no processor yet shows a dash');
    }

    // ---- Part B: column selection -----------------------------------------------------------------------------

    public function test_the_resolver_defaults_ignores_tampering_and_keeps_locked_columns(): void
    {
        $manifest = AssetListColumns::manifest(AssetListColumns::NETWORK);

        // No parameter: the defaults (credentials is off by default).
        $this->assertSame(['device', 'type_model', 'network', 'location', 'status', 'actions'], ListColumns::resolve($manifest, ''));
        $this->assertSame(['device', 'type_model', 'network', 'location', 'status', 'actions'], ListColumns::resolve($manifest, null));

        // Unknown keys are ignored, order follows the manifest, locked columns are always there.
        $this->assertSame(['device', 'status', 'actions'], ListColumns::resolve($manifest, 'status,<script>,../etc,nonsense'));
        $this->assertSame(['device', 'network', 'credentials', 'actions'], ListColumns::resolve($manifest, 'credentials, network'));

        // A parameter that tries to drop the locked columns cannot.
        $this->assertSame(['device', 'status', 'actions'], ListColumns::resolve($manifest, 'status'));

        // Nothing valid in it: back to the defaults rather than an empty table.
        $this->assertSame(['device', 'type_model', 'network', 'location', 'status', 'actions'], ListColumns::resolve($manifest, 'bogus,more-bogus'));
    }

    public function test_the_column_manifests_match_the_decided_locked_and_toggleable_keys(): void
    {
        $locked = fn (string $list) => array_keys(array_filter(AssetListColumns::manifest($list), fn ($c) => $c['locked'] ?? false));
        $toggleable = fn (string $list) => array_keys(array_filter(AssetListColumns::manifest($list), fn ($c) => ! ($c['locked'] ?? false)));

        $this->assertSame(['asset', 'actions'], $locked('assets'));
        $this->assertSame(['type_model', 'processor', 'department', 'location', 'assigned_to', 'status', 'ups'], $toggleable('assets'));
        $this->assertSame(['device', 'actions'], $locked('phones'));
        $this->assertSame(['type_model', 'numbers', 'assigned_to', 'status'], $toggleable('phones'));
        $this->assertSame(['device', 'actions'], $locked('network'));
        $this->assertSame(['type_model', 'network', 'credentials', 'location', 'status'], $toggleable('network'));
        $this->assertSame(['title', 'actions'], $locked('reports'));
        $this->assertSame(['type', 'asset', 'location', 'status', 'date_solved'], $toggleable('reports'));

        $this->assertFalse(AssetListColumns::manifest('network')['credentials']['default'], 'credentials is hidden by default');
    }

    public function test_the_lists_use_the_selection_from_the_url_and_hide_the_other_columns(): void
    {
        $this->asset(['asset_name' => 'Wide', 'asset_type' => 'PC']);

        // Defaults first: Livewire's test helper keeps query parameters set by an earlier call.
        $this->assertSame(['Asset', 'Type / Model', 'Processor', 'Department', 'Location', 'Assigned To', 'Status', 'UPS', 'Action'], $this->tableHeaders(Livewire::test(AssetsList::class)->html()));

        Livewire::withQueryParams(['cols' => 'asset,status,bogus'])->test(AssetsList::class)
            ->assertViewHas('visibleColumns', ['asset', 'status', 'actions']);

        $this->assertSame(['Asset', 'Status', 'Action'], $this->tableHeaders(Livewire::withQueryParams(['cols' => 'asset,status,bogus'])->test(AssetsList::class)->html()));
    }

    public function test_toggling_columns_rewrites_the_url_selection_and_never_touches_locked_ones(): void
    {
        Livewire::test(AssetsList::class)
            ->call('toggleColumn', 'status')
            ->assertSet('colsParam', 'asset,type_model,processor,department,location,assigned_to,ups,actions')
            ->call('toggleColumn', 'status')
            ->assertSet('colsParam', 'asset,type_model,processor,department,location,assigned_to,status,ups,actions')
            ->call('toggleColumn', 'asset')
            ->call('toggleColumn', 'actions')
            ->call('toggleColumn', 'not_a_column')
            ->assertViewHas('visibleColumns', fn ($v) => in_array('asset', $v, true) && in_array('actions', $v, true))
            ->call('resetColumns')
            ->assertSet('colsParam', '');
    }

    public function test_credentials_default_to_hidden_on_the_network_list_and_can_be_turned_on(): void
    {
        $this->asset(['asset_name' => 'Router', 'asset_type' => 'RT', 'device_category' => 'network', 'login_password' => 'router-secret-1', 'ssid_password' => 'wifi-secret-1']);

        Livewire::test(NetworkList::class)
            ->assertViewHas('visibleColumns', fn ($v) => ! in_array('credentials', $v, true))
            ->assertDontSee('router-secret-1')
            ->call('toggleColumn', 'credentials')
            ->assertViewHas('visibleColumns', fn ($v) => in_array('credentials', $v, true))
            // Still masked until revealed, exactly as before.
            ->assertDontSee('router-secret-1');

        $this->assertNotContains('Credentials', $this->tableHeaders(Livewire::test(NetworkList::class)->html()));
        $this->assertContains('Credentials', $this->tableHeaders(Livewire::withQueryParams(['cols' => 'credentials'])->test(NetworkList::class)->html()));
    }

    public function test_the_column_picker_is_the_three_dot_menu_in_the_action_header(): void
    {
        $html = Livewire::test(AssetsList::class)->html();

        // The header cell says Action and holds the menu; the old toolbar button is gone.
        $this->assertSame(1, preg_match('/<th class="actions">.*?Action.*?Choose columns.*?<\/th>/s', $html));
        $this->assertStringContainsString('Default Columns', $html);
        $this->assertStringContainsString('All Columns', $html);
        $this->assertStringContainsString('Customize Columns', $html);
        $this->assertStringNotContainsString('btn-secondary" x-on:click="open', $html);

        // Every column is listed; locked ones have no toggle, the others do.
        $this->assertStringContainsString("toggleColumn('processor')", $html);
        $this->assertStringNotContainsString("toggleColumn('asset')", $html, 'a locked column has no toggle');
        $this->assertStringNotContainsString("toggleColumn('actions')", $html);

        foreach ([PhonesList::class, NetworkList::class, IssueReports::class] as $list) {
            $this->assertSame(['Action'], array_slice($this->tableHeaders(Livewire::test($list)->html()), -1));
            Livewire::test($list)->assertSee('Customize Columns');
        }
    }

    public function test_the_picker_modes_default_all_and_custom(): void
    {
        Livewire::test(AssetsList::class)
            ->assertViewHas('columnMode', 'default')
            ->call('showAllColumns')
            ->assertSet('colsParam', 'asset,type_model,processor,department,location,assigned_to,status,ups,actions')
            ->assertViewHas('columnMode', 'all')
            ->call('toggleColumn', 'ups')
            ->assertViewHas('columnMode', 'custom')
            ->call('resetColumns')
            ->assertViewHas('columnMode', 'default');

        // Network: "All" includes the credentials column (masked, permission-gated); Default does not.
        Livewire::test(NetworkList::class)
            ->assertViewHas('visibleColumns', fn ($v) => ! in_array('credentials', $v, true))
            ->call('showAllColumns')
            ->assertViewHas('visibleColumns', fn ($v) => in_array('credentials', $v, true));
    }

    // ---- several asset types at once ---------------------------------------------------------------------------

    public function test_the_assets_list_can_filter_by_several_types_at_once(): void
    {
        foreach (['PC' => 'A PC', 'Laptop' => 'A laptop', 'Server' => 'A server', 'PRT' => 'A printer', 'AIO' => 'An AIO'] as $type => $name) {
            $this->asset(['asset_name' => $name, 'asset_type' => $type]);
        }

        $names = fn ($component) => $component->viewData('assets')->getCollection()->pluck('asset_name')->sort()->values()->all();

        $component = Livewire::test(AssetsList::class);
        $this->assertCount(5, $names($component));

        $component->call('toggleAssetType', 'Laptop')->assertSet('assetType', 'Laptop')->assertViewHas('selectedTypes', ['Laptop']);
        $this->assertSame(['A laptop'], $names($component));

        $component->call('toggleAssetType', 'PC')->call('toggleAssetType', 'Server');
        // Kept in the list's own order, whatever order they were ticked in.
        $component->assertSet('assetType', 'PC,Laptop,Server')->assertViewHas('selectedTypes', ['PC', 'Laptop', 'Server']);
        $this->assertSame(['A PC', 'A laptop', 'A server'], $names($component));

        // Unticking one narrows it again; clearing means all types.
        $component->call('toggleAssetType', 'Laptop');
        $this->assertSame(['A PC', 'A server'], $names($component));

        $component->call('toggleAssetType', 'Spaceship')->assertSet('assetType', 'PC,Server');

        $component->call('clearAssetTypes')->assertSet('assetType', '')->assertViewHas('selectedTypes', []);
        $this->assertCount(5, $names($component));
    }

    public function test_the_type_filter_takes_a_comma_list_from_the_url_and_the_toolbar_shows_the_choice(): void
    {
        $this->asset(['asset_name' => 'A PC', 'asset_type' => 'PC']);
        $this->asset(['asset_name' => 'A laptop', 'asset_type' => 'Laptop']);
        $this->asset(['asset_name' => 'A printer', 'asset_type' => 'PRT']);

        $component = Livewire::withQueryParams(['type' => 'PC,Laptop,nonsense'])->test(AssetsList::class);

        $component->assertViewHas('selectedTypes', ['PC', 'Laptop']);
        $this->assertSame(['A PC', 'A laptop'], $component->viewData('assets')->getCollection()->pluck('asset_name')->sort()->values()->all());
        $component->assertSee('2 selected');

        // A single value (the old links) still works, and the button then names it.
        Livewire::withQueryParams(['type' => 'PRT'])->test(AssetsList::class)->assertSee('Printer')->assertViewHas('selectedTypes', ['PRT']);
    }

    public function test_the_export_follows_the_several_types_filter(): void
    {
        $this->asset(['asset_name' => 'A PC', 'serial_number' => 'MT-1', 'asset_type' => 'PC']);
        $this->asset(['asset_name' => 'A laptop', 'serial_number' => 'MT-2', 'asset_type' => 'Laptop']);
        $this->asset(['asset_name' => 'A printer', 'serial_number' => 'MT-3', 'asset_type' => 'PRT']);

        $serials = fn (array $query) => collect($this->readSheet($this->get(route('assets.assets.export.excel', [...$query, 'cols' => 'status']))))->skip(1)->pluck(1)->sort()->values()->all();

        $this->assertSame(['MT-1', 'MT-2'], $serials(['type' => 'PC,Laptop']));
        $this->assertSame(['MT-3'], $serials(['type' => 'PRT']));
        $this->assertSame(['MT-1', 'MT-2', 'MT-3'], $serials([]));

        // The toolbar's export link carries the whole selection.
        Livewire::test(AssetsList::class)->call('toggleAssetType', 'PC')->call('toggleAssetType', 'Laptop')
            ->assertViewHas('exportParams', ['type' => 'PC,Laptop']);
    }

    public function test_phones_and_network_also_filter_by_several_types(): void
    {
        $this->asset(['asset_name' => 'Handset', 'asset_type' => 'Ph', 'device_category' => 'phone']);
        $this->asset(['asset_name' => 'Pos', 'asset_type' => 'POS', 'device_category' => 'phone']);
        $this->asset(['asset_name' => 'Sim', 'asset_type' => 'SIM', 'device_category' => 'phone']);
        $this->asset(['asset_name' => 'Router', 'asset_type' => 'RT', 'device_category' => 'network']);
        $this->asset(['asset_name' => 'Switch', 'asset_type' => 'SW', 'device_category' => 'network']);
        $this->asset(['asset_name' => 'Radio', 'asset_type' => 'P2P', 'device_category' => 'network']);

        $names = fn ($component) => $component->viewData('assets')->getCollection()->pluck('asset_name')->sort()->values()->all();

        $phones = Livewire::test(PhonesList::class)->call('toggleAssetType', 'POS')->call('toggleAssetType', 'SIM');
        $phones->assertSet('assetType', 'POS,SIM')->assertViewHas('selectedTypes', ['POS', 'SIM'])->assertSee('2 selected');
        $this->assertSame(['Pos', 'Sim'], $names($phones));
        $phones->call('toggleAssetType', 'RT')->assertSet('assetType', 'POS,SIM'); // a Network type is not a phone type
        $phones->call('clearAssetTypes');
        $this->assertCount(3, $names($phones));

        $network = Livewire::test(NetworkList::class)->call('toggleAssetType', 'RT')->call('toggleAssetType', 'SW');
        $this->assertSame(['Router', 'Switch'], $names($network));
        $network->assertViewHas('selectedTypes', ['RT', 'SW'])->call('toggleAssetType', 'RT');
        $this->assertSame(['Switch'], $names($network));

        // Their exports follow the same selection.
        $serials = fn (string $route, string $types) => collect($this->readSheet($this->get(route($route, ['type' => $types]))))->skip(1)->pluck(0)->sort()->values()->all();
        $this->assertSame(['Pos', 'Sim'], $serials('assets.phones.export.excel', 'POS,SIM'));
        $this->assertSame(['Radio', 'Switch'], $serials('assets.network.export.excel', 'SW,P2P'));
    }

    public function test_exports_are_icon_buttons_and_the_total_sits_at_the_right_of_the_toolbar(): void
    {
        foreach (range(1, 17) as $i) {
            $this->asset(['serial_number' => 'TOT-'.$i, 'status' => $i <= 4 ? 'Damaged' : 'Active', 'status_reason' => $i <= 4 ? 'x' : null]);
        }

        $html = Livewire::test(AssetsList::class)->html();

        // Icon-only links that still have a name for screen readers.
        $this->assertMatchesRegularExpression('/<a [^>]*class="btn btn-secondary btn-icon"[^>]*aria-label="Export Excel"/', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*class="btn btn-secondary btn-icon"[^>]*aria-label="Export PDF"/', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*Export (Excel|PDF)\s*</', $html);

        // The total follows the export icons in the toolbar (pushed to the far right), and the table footer is as it was.
        $this->assertMatchesRegularExpression('/aria-label="Export PDF".*?<p class="pager-total".*?<b>17<\/b>.*?in total/s', $html);
        $this->assertStringContainsString('Showing 1 - 15 of 17 assets', preg_replace('/\s+/', ' ', $html));
        $this->assertStringNotContainsString('ui-perpage-stack', $html);

        Livewire::test(AssetsList::class)->set('status', 'Damaged')->assertSeeHtml('<b>4</b>')->assertSee('match your filters');
        Livewire::test(AssetsList::class)->set('status', 'Lost')->assertSee('match your filters')->assertSeeHtml('<b>0</b>');

        // Phones and Network put it in the same place.
        foreach ([PhonesList::class, NetworkList::class] as $list) {
            $this->assertMatchesRegularExpression('/Rows per page.*aria-label="Export PDF".*pager-total.*<b>0<\/b>.*in total/s', Livewire::test($list)->html());
        }

        // Issue Reports has no per-page select, so the total sits in the toolbar.
        IctAssetIssueReport::query()->create(['title' => 'One', 'issue_type' => 'Other', 'status' => 'Open']);
        Livewire::test(IssueReports::class)->assertSeeHtml('<b>1</b>')->assertSee('in total');
        Livewire::test(IssueReports::class)->set('status', 'Closed')->assertSee('match your filters');
    }

    // ---- Part C: export ---------------------------------------------------------------------------------------

    public function test_excel_has_only_the_selected_columns_headings_and_rows(): void
    {
        $holder = $this->holder;
        $this->asset(['asset_name' => 'Boardroom PC', 'serial_number' => 'EX-1', 'asset_type' => 'PC', 'assigned_to_employee_id' => $holder->id]);

        $response = $this->get(route('assets.assets.export.excel', ['cols' => 'status,assigned_to']));
        $response->assertOk();

        $sheet = $this->readSheet($response);

        // Locked identity column first, actions never exported, the rest in manifest order.
        $this->assertSame(['Asset Name', 'Serial Number', 'Asset Tag', 'Assigned To', 'Status'], $sheet[0]);
        $this->assertSame(['Boardroom PC', 'EX-1', null, 'Kwame Mensah', 'Active'], $sheet[1]);
        $this->assertCount(2, $sheet);

        // No cols parameter: every default column except actions.
        $all = $this->readSheet($this->get(route('assets.assets.export.excel')));
        $this->assertSame(['Asset Name', 'Serial Number', 'Asset Tag', 'Type', 'Manufacturer', 'Model', 'Processor', 'Department', 'District', 'Region', 'Actual Location', 'Assigned To', 'Status', 'On UPS', 'UPS Condition'], $all[0]);
    }

    public function test_pdf_has_only_the_selected_columns_headings_and_rows(): void
    {
        $this->asset(['asset_name' => 'Pdf PC', 'serial_number' => 'PD-1', 'asset_type' => 'PC']);

        $data = null;
        View::composer('exports.list-pdf', function ($view) use (&$data) {
            $data = $view->getData();
        });

        $response = $this->get(route('assets.assets.export.pdf', ['cols' => 'status']));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(['Asset Name', 'Serial Number', 'Asset Tag', 'Status'], $data['headings']);
        $this->assertSame([['Pdf PC', 'PD-1', '', 'Active']], $data['rows']->map(fn ($r) => array_values($r))->all());
        $this->assertSame(1, $data['rowCount']);
        $this->assertSame('Assets', $data['title']);
    }

    public function test_every_list_splits_stacked_columns_into_their_own_file_columns(): void
    {
        $maker = \App\Models\IctAssetManufacturer::query()->create(['name' => 'Dell']);
        $model = IctAssetModel::query()->create(['name' => 'Latitude 5420', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $maker->id, 'is_active' => true]);
        $this->asset(['asset_name' => 'Lap', 'serial_number' => 'SP-1', 'asset_type' => 'Laptop', 'ict_asset_model_id' => $model->id, 'cpu_name' => 'Ultra 7', 'has_ups' => false]);
        $this->asset(['asset_name' => 'Phone', 'serial_number' => 'SP-2', 'asset_type' => 'Ph', 'device_category' => 'phone', 'imei' => '3569', 'device_phone_number' => '0200000001', 'user_phone_number' => '0200000002']);
        $this->asset(['asset_name' => 'Router', 'serial_number' => 'SP-3', 'asset_type' => 'RT', 'device_category' => 'network', 'device_ip' => '10.1.1.1', 'ssid' => 'NET', 'actual_location' => 'Server room']);
        IctAssetIssueReport::query()->create(['title' => 'T', 'issue_type' => 'Computer', 'issue_subtype' => 'Battery', 'status' => 'Open']);

        $assets = $this->readSheet($this->get(route('assets.assets.export.excel')));
        $this->assertSame(['Lap', 'SP-1', null, 'Laptop', 'Dell', 'Latitude 5420', 'Ultra 7'], array_slice($assets[1], 0, 7));

        $phones = $this->readSheet($this->get(route('assets.phones.export.excel')));
        $this->assertSame(['Device Name', 'Serial Number', 'IMEI', 'Type', 'Model', 'Device Number', 'User Number', 'Assigned To', 'Status'], $phones[0]);
        $this->assertSame(['Phone', 'SP-2', '3569'], array_slice($phones[1], 0, 3));
        $this->assertSame(['0200000001', '0200000002'], array_slice($phones[1], 5, 2));

        $network = $this->readSheet($this->get(route('assets.network.export.excel')));
        $this->assertSame(['Device Name', 'Serial Number', 'Type', 'Model', 'IP Address', 'SSID', 'District', 'Location Note', 'Status'], $network[0]);
        $this->assertSame(['10.1.1.1', 'NET'], array_slice($network[1], 4, 2));
        $this->assertSame('Server room', $network[1][7]);

        $reports = $this->readSheet($this->get(route('assets.reports.export.excel')));
        $this->assertSame(['Title', 'Issue Type', 'Issue Subtype', 'Linked Asset', 'Asset Serial Number', 'District', 'Region', 'Status', 'Date Solved'], $reports[0]);

        // No heading combines two things.
        foreach ([$assets, $phones, $network, $reports] as $sheet) {
            foreach ($sheet[0] as $heading) {
                $this->assertStringNotContainsString('/', $heading);
            }
        }
    }

    public function test_the_network_export_never_includes_credentials_even_when_asked(): void
    {
        $this->asset(['asset_name' => 'Office router', 'serial_number' => 'RT-1', 'asset_type' => 'RT', 'device_category' => 'network', 'device_ip' => '10.0.0.1', 'ssid' => 'GWL-OFFICE', 'login_password' => 'router-secret-777', 'ssid_password' => 'wifi-secret-888']);

        $query = ['cols' => 'network,credentials,status'];

        $excel = $this->get(route('assets.network.export.excel', $query));
        $excel->assertOk();
        $sheet = $this->readSheet($excel);

        $this->assertSame(['Device Name', 'Serial Number', 'IP Address', 'SSID', 'Status'], $sheet[0]);
        $this->assertNotContains('Credentials', $sheet[0]);
        $this->assertSame(['Office router', 'RT-1', '10.0.0.1', 'GWL-OFFICE', 'Active'], $sheet[1]);
        $this->assertStringNotContainsString('secret', json_encode($sheet));

        $data = null;
        View::composer('exports.list-pdf', function ($view) use (&$data) {
            $data = $view->getData();
        });
        $this->get(route('assets.network.export.pdf', $query))->assertOk();

        $this->assertSame(['Device Name', 'Serial Number', 'IP Address', 'SSID', 'Status'], $data['headings']);
        $this->assertStringNotContainsString('secret', json_encode([$data['headings'], $data['rows']]));

        $log = AuditLog::query()->where('action', 'export_network_excel')->firstOrFail();
        $this->assertSame(['device', 'network', 'status'], $log->new_values['columns']);
    }

    public function test_a_filtered_list_exports_only_the_filtered_rows(): void
    {
        $this->asset(['asset_name' => 'Damaged laptop', 'serial_number' => 'F-1', 'asset_type' => 'Laptop', 'status' => 'Damaged', 'status_reason' => 'Dropped']);
        $this->asset(['asset_name' => 'Healthy laptop', 'serial_number' => 'F-2', 'asset_type' => 'Laptop']);
        $this->asset(['asset_name' => 'Damaged PC', 'serial_number' => 'F-3', 'asset_type' => 'PC', 'status' => 'Damaged', 'status_reason' => 'Dropped']);
        $this->asset(['asset_name' => 'UPS PC', 'serial_number' => 'F-4', 'asset_type' => 'PC', 'has_ups' => true, 'ups_status' => 'Spoiled']);
        $this->asset(['asset_name' => 'A phone', 'serial_number' => 'F-5', 'asset_type' => 'Ph', 'device_category' => 'phone', 'status' => 'Damaged', 'status_reason' => 'Dropped']);

        $names = fn (array $query) => collect($this->readSheet($this->get(route('assets.assets.export.excel', [...$query, 'cols' => 'status']))))->skip(1)->pluck(1)->sort()->values()->all();

        $this->assertSame(['F-1', 'F-3'], $names(['status' => 'Damaged']));
        $this->assertSame(['F-1'], $names(['status' => 'Damaged', 'type' => 'Laptop']));
        $this->assertSame(['F-2'], $names(['q' => 'healthy']));
        $this->assertSame(['F-4'], $names(['ups' => 'spoiled']));
        // The category stays fixed: the Assets export never lists phones, with or without filters.
        $this->assertNotContains('F-5', $names([]));

        $phones = collect($this->readSheet($this->get(route('assets.phones.export.excel', ['status' => 'Damaged']))))->skip(1)->pluck(1)->all();
        $this->assertSame(['F-5'], $phones);

        $this->assertSame(['status' => 'Damaged', 'type' => 'Laptop'], AuditLog::query()->where('action', 'export_assets_excel')->get()->first(fn ($l) => ($l->new_values['filters']['type'] ?? null) === 'Laptop')->new_values['filters']);
    }

    public function test_a_regional_ict_user_only_exports_their_own_regions_rows(): void
    {
        $other = District::query()->create(['district_name' => 'Kumasi', 'region_id' => Region::query()->create(['region_name' => 'Ashanti'])->id]);
        $this->asset(['asset_name' => 'Local', 'serial_number' => 'R-1', 'district_id' => $this->district->id, 'region_id' => $this->district->region_id]);
        $this->asset(['asset_name' => 'Far away', 'serial_number' => 'R-2', 'district_id' => $other->id, 'region_id' => $other->region_id]);

        $this->actingAs($this->regionalIct('ICT1'));

        $rows = collect($this->readSheet($this->get(route('assets.assets.export.excel'))))->skip(1)->pluck(1)->all();

        $this->assertSame(['R-1'], $rows);
    }

    public function test_issue_reports_export_follows_filters_and_columns(): void
    {
        $asset = $this->asset(['asset_name' => 'Linked PC', 'serial_number' => 'IR-1']);
        $open = IctAssetIssueReport::query()->create(['title' => 'Will not boot', 'issue_type' => 'Computer', 'issue_subtype' => "Won't boot", 'status' => 'Open', 'linked_asset_id' => $asset->id, 'reporting_district_id' => $this->district->id, 'reporting_region_id' => $this->district->region_id]);
        IctAssetIssueReport::query()->create(['title' => 'Locked out', 'issue_type' => 'Password Reset', 'status' => 'Resolved', 'date_solved' => '2026-10-01']);

        $sheet = $this->readSheet($this->get(route('assets.reports.export.excel', ['status' => 'Open', 'cols' => 'type,asset'])));

        $this->assertSame(['Title', 'Issue Type', 'Issue Subtype', 'Linked Asset', 'Asset Serial Number'], $sheet[0]);
        $this->assertSame(['Will not boot', 'Computer', "Won't boot", 'Linked PC', 'IR-1'], $sheet[1]);
        $this->assertCount(2, $sheet);

        $this->assertSame(1, collect($this->readSheet($this->get(route('assets.reports.export.excel', ['q' => 'locked']))))->skip(1)->count());

        $this->get(route('assets.reports.export.pdf', ['status' => 'Open']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($open->id, IctAssetIssueReport::query()->where('title', 'Will not boot')->value('id'));
    }

    public function test_the_row_cap_redirects_with_a_message_and_writes_no_file_or_audit_row(): void
    {
        foreach (range(1, 3) as $i) {
            $this->asset(['serial_number' => 'CAP-'.$i]);
        }

        config(['gwl.assets_list_export_max_rows' => 2, 'gwl.assets_list_export_pdf_max_rows' => 2]);

        $excel = $this->from(route('assets.assets'))->get(route('assets.assets.export.excel'));
        $excel->assertRedirect(route('assets.assets'))->assertSessionHas('error');
        $this->assertStringContainsString('limit of 2', session('error'));

        $pdf = $this->from(route('assets.assets'))->get(route('assets.assets.export.pdf'));
        $pdf->assertRedirect(route('assets.assets'));
        $this->assertStringContainsString('PDF limit of 2', session('error'));
        $this->assertStringContainsString('Excel', session('error'));

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'export_assets_%')->count());

        // At the cap it still works.
        config(['gwl.assets_list_export_max_rows' => 3]);
        $this->get(route('assets.assets.export.excel'))->assertOk();
    }

    public function test_a_formula_looking_value_stays_a_string_in_the_xlsx(): void
    {
        $this->asset(['asset_name' => '=1+1', 'serial_number' => 'FX-1', 'asset_type' => 'PC']);
        $this->asset(['asset_name' => '@SUM(1,2)', 'serial_number' => 'FX-2', 'asset_type' => 'PC']);

        $response = $this->get(route('assets.assets.export.excel', ['cols' => 'status']));
        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $spreadsheet->getActiveSheet();

        foreach (['A2', 'A3'] as $address) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($address)->getDataType(), $address);
        }

        $values = [$sheet->getCell('A2')->getValue(), $sheet->getCell('A3')->getValue()];
        sort($values);
        $this->assertSame(['=1+1', '@SUM(1,2)'], $values);
    }

    public function test_exports_write_an_audit_row_with_filters_columns_and_row_count(): void
    {
        $this->asset(['serial_number' => 'AU-1', 'status' => 'Damaged', 'status_reason' => 'x']);
        $this->asset(['serial_number' => 'AU-2', 'status' => 'Damaged', 'status_reason' => 'x']);
        $this->asset(['serial_number' => 'AU-3']);

        $this->get(route('assets.assets.export.excel', ['status' => 'Damaged', 'cols' => 'status,location']))->assertOk();
        $this->get(route('assets.assets.export.pdf', ['status' => 'Damaged']))->assertOk();
        $this->get(route('assets.phones.export.excel'))->assertOk();
        $this->get(route('assets.reports.export.excel'))->assertOk();

        $excel = AuditLog::query()->where('action', 'export_assets_excel')->firstOrFail();
        $this->assertSame(Permission::MODULE_ASSETS, $excel->module);
        $this->assertSame('ict_assets', $excel->target_type);
        $this->assertSame(['status' => 'Damaged'], $excel->new_values['filters']);
        $this->assertSame(['asset', 'location', 'status'], $excel->new_values['columns']);
        $this->assertSame(2, $excel->new_values['rows']);
        $this->assertSame($this->admin->id, $excel->user_id);

        $pdf = AuditLog::query()->where('action', 'export_assets_pdf')->firstOrFail();
        $this->assertSame(['asset', 'type_model', 'processor', 'department', 'location', 'assigned_to', 'status', 'ups'], $pdf->new_values['columns']);

        $this->assertSame(0, AuditLog::query()->where('action', 'export_phones_excel')->firstOrFail()->new_values['rows']);
        $this->assertSame('ict_asset_issue_reports', AuditLog::query()->where('action', 'export_issue_reports_excel')->firstOrFail()->target_type);
    }

    // ---- permissions ------------------------------------------------------------------------------------------

    public function test_the_export_routes_need_their_permissions(): void
    {
        $this->asset(['serial_number' => 'PM-1']);

        $this->actingAs($this->headOfficeIct('ICT1'));
        foreach (['assets.assets', 'assets.phones', 'assets.network'] as $route) {
            $this->get(route($route.'.export.excel'))->assertOk();
        }
        $this->get(route('assets.reports.export.pdf'))->assertOk();

        $role = Role::query()->where('name', 'ict_team')->firstOrFail();

        $role->permissions()->detach(Permission::query()->where('name', 'assets.export_inventory')->value('id'));
        foreach (['assets.assets', 'assets.phones', 'assets.network'] as $route) {
            $this->get(route($route.'.export.excel'))->assertForbidden();
            $this->get(route($route.'.export.pdf'))->assertForbidden();
        }
        $this->get(route('assets.reports.export.excel'))->assertOk();

        $role->permissions()->detach(Permission::query()->where('name', 'assets.export_reports')->value('id'));
        $this->get(route('assets.reports.export.excel'))->assertForbidden();
        $this->get(route('assets.reports.export.pdf'))->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action', 'export_reports_excel')->count());
    }

    public function test_the_permissions_are_granted_to_super_admin_ict_team_and_ict_manager_only(): void
    {
        foreach (['assets.export_inventory', 'assets.export_reports'] as $permission) {
            $roles = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $permission))->pluck('name')->sort()->values()->all();
            $this->assertSame(['ict_manager', 'ict_team', 'super_admin'], $roles, $permission);
        }
    }

    public function test_the_toolbar_shows_export_links_with_the_current_filters_and_columns_only_to_exporters(): void
    {
        Livewire::withQueryParams(['cols' => 'asset,status'])->test(AssetsList::class)
            ->set('status', 'Damaged')
            ->assertViewHas('canExport', true)
            ->assertViewHas('exportParams', ['status' => 'Damaged', 'cols' => 'asset,status'])
            ->assertSee('Export Excel')
            ->assertSee('Export PDF')
            ->assertSeeHtml(e(route('assets.assets.export.excel', ['status' => 'Damaged', 'cols' => 'asset,status'])));

        Livewire::test(IssueReports::class)->assertSee(route('assets.reports.export.pdf'), false);

        $this->actingAs($this->headOfficeIct('ICT1'));
        Role::query()->where('name', 'ict_team')->firstOrFail()->permissions()->detach(Permission::query()->where('name', 'assets.export_inventory')->value('id'));

        foreach ([AssetsList::class, PhonesList::class, NetworkList::class] as $list) {
            Livewire::test($list)->assertViewHas('canExport', false)->assertDontSee('Export Excel');
        }
        Livewire::test(IssueReports::class)->assertViewHas('canExport', true);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /** @return array<int, string> the text of the table's header cells */
    protected function tableHeaders(string $html): array
    {
        preg_match_all('/<th(?:\s[^>]*)?>(.*?)<\/th>/s', $html, $matches); // not <thead>

        // The Action header also holds the column menu; its text starts at "Default Columns".
        return array_map(fn (string $cell) => trim(preg_replace('/ Default Columns.*$/s', '', trim(preg_replace('/\s+/', ' ', strip_tags($cell))))), $matches[1]);
    }

    /** @return array<int, array<int, mixed>> the first sheet of the downloaded .xlsx, row by row */
    protected function readSheet($response): array
    {
        $response->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);
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

    protected function user(string $staffId, string $role): User
    {
        $user = User::query()->create([
            'staff_id' => $staffId, 'full_name' => 'User '.$staffId, 'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    protected function headOfficeIct(string $staffId): User
    {
        $user = $this->user($staffId, 'ict_team');
        $headOffice = District::query()->firstOrCreate(['district_name' => 'Head Office'], ['region_id' => $this->district->region_id]);
        $this->employee($staffId, 'HO '.$staffId, $headOffice);

        return $user;
    }

    protected function regionalIct(string $staffId): User
    {
        $user = $this->user($staffId, 'ict_team');
        $this->employee($staffId, 'Regional '.$staffId);

        return $user;
    }

    protected function employee(string $staffId, string $name, ?District $district = null): Employee
    {
        $district ??= $this->district;

        return Employee::query()->create([
            'staff_id' => $staffId, 'full_name' => $name, 'gender' => 'Male', 'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Administration'])->id,
            'region_id' => $district->region_id, 'district_id' => $district->id,
            'location_type' => $district->district_name === 'Head Office' ? 'HeadOffice' : 'District',
            'date_of_birth' => '1990-01-01', 'date_joined' => '2026-01-01', 'present_appointment' => '2026-01-01', 'is_active' => true,
        ]);
    }

    protected function asset(array $overrides = []): IctAsset
    {
        static $n = 0;
        $n++;

        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset '.$n,
            'serial_number' => 'LC-'.$n,
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
            'district_id' => $this->district->id,
            'region_id' => $this->district->region_id,
        ], $overrides));
    }
}
