<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\Racks\RackForm;
use App\Livewire\Assets\Racks\RackShow;
use App\Livewire\Assets\Racks\RacksList;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctRack;
use App\Models\Permission;
use App\Models\User;
use App\Support\ErpNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * Assets > Racks: the rack list, create / edit form, detail page and delete, who may do each (assets.view_racks /
 * assets.manage_racks, region scope) and what is audited. Placing a device in a rack is RackPlacementTest.
 */
class RackManagementTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $headOfficeIct;

    protected User $accraIct;

    protected User $kumasiIct;

    protected User $super;

    protected User $admin;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildUacOrg();

        $this->headOfficeIct = $this->person('ICT001', $this->headOffice, ['ict_team']);
        $this->accraIct = $this->person('ICT002', $this->temaDistrict, ['ict_team']);
        $this->kumasiIct = $this->person('ICT003', $this->kumasiOffice, ['ict_team']);
        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);
        $this->admin = $this->person('AD001', $this->headOffice, ['admin']);
        $this->staff = $this->person('EM001', $this->temaDistrict, ['employee']);
    }

    protected function rack(string $name, $district = null, array $overrides = []): IctRack
    {
        $district ??= $this->temaDistrict;

        return IctRack::query()->create([
            'name' => $name,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'total_units' => 42,
            'status' => IctRack::STATUS_ACTIVE,
            ...$overrides,
        ]);
    }

    protected function server(IctRack $rack, string $name, int $unit, int $span = 1): IctAsset
    {
        return IctAsset::factory()->create([
            'asset_name' => $name,
            'asset_type' => 'Server',
            'region_id' => $rack->region_id,
            'district_id' => $rack->district_id,
            'rack_id' => $rack->id,
            'rack_unit' => $unit,
            'rack_unit_span' => $span,
        ]);
    }

    protected function readSheet($response): array
    {
        $response->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);
    }

    // ---- permissions -----------------------------------------------------------------------------------------------

    public function test_the_two_permissions_go_to_super_admin_and_the_ict_roles_only(): void
    {
        $this->assertSame(2, Permission::query()->whereIn('name', ['assets.view_racks', 'assets.manage_racks'])->where('module', Permission::MODULE_ASSETS)->count());

        $ictManager = $this->person('ICT004', $this->temaDistrict, ['ict_manager']);

        foreach ([$this->accraIct, $this->kumasiIct, $this->headOfficeIct, $ictManager] as $user) {
            $this->assertTrue($user->hasPermission('assets.view_racks'), $user->staff_id);
            $this->assertTrue($user->hasPermission('assets.manage_racks'), $user->staff_id);
        }

        // super_admin passes every check by role; the grant row exists for it too.
        $this->assertTrue($this->role('super_admin')->permissions()->where('name', 'assets.manage_racks')->exists());

        foreach ([$this->admin, $this->staff] as $user) {
            $this->assertFalse($user->hasPermission('assets.view_racks'), $user->staff_id);
            $this->assertFalse($user->hasPermission('assets.manage_racks'), $user->staff_id);
        }
    }

    public function test_the_racks_pages_are_gated_by_their_permission(): void
    {
        $rack = $this->rack('Rack A');

        $this->actingAs($this->accraIct)->get(route('assets.racks'))->assertOk()->assertSee('Rack A');
        $this->actingAs($this->accraIct)->get(route('assets.racks.create'))->assertOk();
        $this->actingAs($this->accraIct)->get(route('assets.racks.edit', $rack))->assertOk();
        $this->actingAs($this->accraIct)->get(route('assets.racks.show', $rack))->assertOk();

        // An account with neither permission never reaches any of them (here: no module access either).
        foreach (['assets.racks', 'assets.racks.create'] as $name) {
            $this->actingAs($this->staff)->get(route($name))->assertRedirect('/dashboard');
        }

        // Take manage away from the ICT role: it can still read, but not create or edit.
        $this->role('ict_team')->permissions()->detach(Permission::query()->where('name', 'assets.manage_racks')->value('id'));
        $reader = User::query()->findOrFail($this->accraIct->id);

        $this->actingAs($reader)->get(route('assets.racks'))->assertOk();
        $this->actingAs($reader)->get(route('assets.racks.show', $rack))->assertOk();
        $this->actingAs($reader)->get(route('assets.racks.create'))->assertForbidden();
        $this->actingAs($reader)->get(route('assets.racks.edit', $rack))->assertForbidden();

        $list = Livewire::actingAs($reader)->test(RacksList::class)->assertDontSee('Add Rack');
        $list->call('confirmDelete', $rack->id)->assertForbidden();

        // ...and take view away too.
        $this->role('ict_team')->permissions()->detach(Permission::query()->where('name', 'assets.view_racks')->value('id'));
        $blind = User::query()->findOrFail($this->accraIct->id);

        $this->actingAs($blind)->get(route('assets.racks'))->assertForbidden();
        $this->actingAs($blind)->get(route('assets.racks.show', $rack))->assertForbidden();
    }

    public function test_the_sidebar_lists_racks_only_for_someone_who_may_view_them(): void
    {
        $labels = fn (User $user) => collect(app(ErpNavigation::class)->build($user, 'assets')['sidebar'])->pluck('label')->all();

        $this->assertContains('Racks', $labels($this->accraIct));
        $this->assertContains('Racks', $labels($this->super));

        $this->role('ict_team')->permissions()->detach(Permission::query()->where('name', 'assets.view_racks')->value('id'));
        $this->assertNotContains('Racks', $labels(User::query()->findOrFail($this->accraIct->id)));
    }

    // ---- create / edit ---------------------------------------------------------------------------------------------

    public function test_a_rack_is_created_in_the_actors_region_and_audited(): void
    {
        Livewire::actingAs($this->accraIct)->test(RackForm::class)
            ->set('form.name', '  Server Room Rack A ')
            ->set('form.code', 'HO-RK-01')
            ->set('form.district_id', $this->temaDistrict->id)
            ->set('form.location', 'Server Room, 2nd Floor')
            ->set('form.total_units', 24)
            ->set('form.status', IctRack::STATUS_UNDER_MAINTENANCE)
            ->set('form.notes', 'Near the window')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $rack = IctRack::query()->firstOrFail();
        $this->assertSame('Rack created.', session('status'));
        $this->assertSame('Server Room Rack A', $rack->name);
        $this->assertSame('HO-RK-01', $rack->code);
        $this->assertSame($this->accra->id, $rack->region_id);
        $this->assertSame($this->temaDistrict->id, $rack->district_id);
        $this->assertSame(24, $rack->total_units);
        $this->assertSame(IctRack::STATUS_UNDER_MAINTENANCE, $rack->status);

        $log = AuditLog::query()->where('action', 'create_rack')->sole();
        $this->assertSame('ict_racks', $log->target_type);
        $this->assertSame($rack->id, (int) $log->target_id);
        $this->assertSame($this->accraIct->id, (int) $log->user_id);
        $this->assertNull($log->old_values);
        $this->assertSame('Server Room Rack A', $log->new_values['name']);
    }

    public function test_a_blank_code_is_stored_as_null_so_two_racks_can_both_have_none(): void
    {
        foreach (['Rack One', 'Rack Two'] as $name) {
            Livewire::actingAs($this->accraIct)->test(RackForm::class)
                ->set('form.name', $name)
                ->set('form.code', '   ')
                ->set('form.district_id', $this->temaDistrict->id)
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, IctRack::query()->whereNull('code')->count());
        $this->assertSame(42, IctRack::query()->first()->total_units, 'a new rack starts 42U high');
    }

    public function test_name_and_code_must_be_unique_ignoring_case(): void
    {
        $this->rack('Rack A', null, ['code' => 'RK-1']);

        Livewire::actingAs($this->accraIct)->test(RackForm::class)
            ->set('form.name', 'rack a')
            ->set('form.district_id', $this->temaDistrict->id)
            ->call('save')
            ->assertHasErrors(['form.name']);

        Livewire::actingAs($this->accraIct)->test(RackForm::class)
            ->set('form.name', 'Rack B')
            ->set('form.code', 'rk-1')
            ->set('form.district_id', $this->temaDistrict->id)
            ->call('save')
            ->assertHasErrors(['form.code']);

        $this->assertSame(1, IctRack::query()->count());
    }

    public function test_the_form_validates_height_status_and_the_district_region(): void
    {
        $other = Livewire::actingAs($this->accraIct)->test(RackForm::class)
            ->set('form.name', 'Rack X')
            ->set('form.district_id', $this->obuasiDistrict->id)
            ->call('save');

        $other->assertHasErrors(['form.district_id']);

        foreach ([0, 101, 'tall'] as $height) {
            Livewire::actingAs($this->accraIct)->test(RackForm::class)
                ->set('form.name', 'Rack X')
                ->set('form.district_id', $this->temaDistrict->id)
                ->set('form.total_units', $height)
                ->call('save')
                ->assertHasErrors(['form.total_units']);
        }

        Livewire::actingAs($this->accraIct)->test(RackForm::class)
            ->set('form.name', 'Rack X')
            ->set('form.district_id', $this->temaDistrict->id)
            ->set('form.status', 'Haunted')
            ->call('save')
            ->assertHasErrors(['form.status']);

        $this->assertSame(0, IctRack::query()->count());
    }

    public function test_editing_a_rack_updates_it_and_audits_old_and_new(): void
    {
        $rack = $this->rack('Rack A', null, ['code' => 'RK-1', 'location' => 'Old room']);

        Livewire::actingAs($this->accraIct)->test(RackForm::class, ['rack' => $rack->id])
            ->assertSet('form.name', 'Rack A')
            ->assertSet('form.code', 'RK-1')
            ->set('form.name', 'Rack Alpha')
            ->set('form.location', 'New room')
            ->set('form.total_units', 30)
            ->call('save')
            ->assertHasNoErrors();

        $rack->refresh();
        $this->assertSame('Rack Alpha', $rack->name);
        $this->assertSame('New room', $rack->location);
        $this->assertSame(30, $rack->total_units);
        $this->assertSame($this->accra->id, $rack->region_id, 'the region is never changed by an edit');

        $log = AuditLog::query()->where('action', 'update_rack')->sole();
        $this->assertSame('Rack A', $log->old_values['name']);
        $this->assertSame('Rack Alpha', $log->new_values['name']);
    }

    public function test_a_rack_cannot_be_made_shorter_than_its_highest_device_or_decommissioned_while_full(): void
    {
        $rack = $this->rack('Rack A');
        $this->server($rack, 'Core Server', 10, 4); // U10-U13

        Livewire::actingAs($this->accraIct)->test(RackForm::class, ['rack' => $rack->id])
            ->set('form.total_units', 12)
            ->call('save')
            ->assertHasErrors(['form.total_units']);

        Livewire::actingAs($this->accraIct)->test(RackForm::class, ['rack' => $rack->id])
            ->set('form.status', IctRack::STATUS_DECOMMISSIONED)
            ->call('save')
            ->assertHasErrors(['form.status']);

        $rack->refresh();
        $this->assertSame(42, $rack->total_units);
        $this->assertSame(IctRack::STATUS_ACTIVE, $rack->status);

        // Exactly as tall as the highest device is fine.
        Livewire::actingAs($this->accraIct)->test(RackForm::class, ['rack' => $rack->id])
            ->set('form.total_units', 13)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(13, $rack->fresh()->total_units);
    }

    // ---- region scope ----------------------------------------------------------------------------------------------

    public function test_a_regional_user_sees_and_reaches_only_their_own_regions_racks(): void
    {
        $accraRack = $this->rack('Accra Rack');
        $kumasiRack = $this->rack('Kumasi Rack', $this->obuasiDistrict);

        $names = fn (User $user) => Livewire::actingAs($user)->test(RacksList::class)->viewData('racks')->getCollection()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Accra Rack'], $names($this->accraIct));
        $this->assertSame(['Kumasi Rack'], $names($this->kumasiIct));

        $this->actingAs($this->kumasiIct)->get(route('assets.racks.show', $accraRack))->assertNotFound();
        $this->actingAs($this->kumasiIct)->get(route('assets.racks.edit', $accraRack))->assertNotFound();
        $this->actingAs($this->accraIct)->get(route('assets.racks.show', $kumasiRack))->assertNotFound();
    }

    public function test_head_office_ict_and_super_admin_read_every_region_but_head_office_writes_only_its_own(): void
    {
        $accraRack = $this->rack('Accra Rack');
        $kumasiRack = $this->rack('Kumasi Rack', $this->obuasiDistrict);

        foreach ([$this->headOfficeIct, $this->super] as $user) {
            $list = Livewire::actingAs($user)->test(RacksList::class)->viewData('racks')->getCollection()->pluck('name')->sort()->values()->all();
            $this->assertSame(['Accra Rack', 'Kumasi Rack'], $list, $user->staff_id);
            $this->actingAs($user)->get(route('assets.racks.show', $kumasiRack))->assertOk();
        }

        // Head Office ICT can read the Ashanti rack but not change or delete it.
        $this->actingAs($this->headOfficeIct)->get(route('assets.racks.edit', $kumasiRack))->assertNotFound();
        Livewire::actingAs($this->headOfficeIct)->test(RacksList::class)->call('confirmDelete', $kumasiRack->id)->assertNotFound();
        $this->assertDatabaseHas('ict_racks', ['id' => $kumasiRack->id]);

        // ...and its own region's rack is editable.
        $this->actingAs($this->headOfficeIct)->get(route('assets.racks.edit', $accraRack))->assertOk();

        // super_admin writes anywhere.
        $this->actingAs($this->super)->get(route('assets.racks.edit', $kumasiRack))->assertOk();
    }

    public function test_the_service_refuses_to_change_a_rack_outside_the_writers_region(): void
    {
        $kumasiRack = $this->rack('Kumasi Rack', $this->obuasiDistrict);

        $this->actingAs($this->accraIct);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(\App\Services\Assets\RackService::class)->update($kumasiRack, ['name' => 'Hijacked']);
    }

    // ---- delete ----------------------------------------------------------------------------------------------------

    public function test_an_empty_rack_is_deleted_with_one_audit_row(): void
    {
        $rack = $this->rack('Spare Rack');

        Livewire::actingAs($this->accraIct)->test(RacksList::class)
            ->call('confirmDelete', $rack->id)
            ->assertSet('deletingRackId', $rack->id)
            ->assertSee('Delete rack')
            ->call('deleteRack')
            ->assertHasNoErrors()
            ->assertSet('deletingRackId', null);

        $this->assertDatabaseMissing('ict_racks', ['id' => $rack->id]);

        $log = AuditLog::query()->where('action', 'delete_rack')->sole();
        $this->assertSame($rack->id, (int) $log->target_id);
        $this->assertSame('Spare Rack', $log->old_values['name']);
        $this->assertNull($log->new_values);
    }

    public function test_a_rack_with_devices_is_never_deleted_and_nothing_is_orphaned(): void
    {
        $rack = $this->rack('Busy Rack');
        $server = $this->server($rack, 'Core Server', 5, 2);
        $this->server($rack, 'Backup Server', 8, 2);

        Livewire::actingAs($this->accraIct)->test(RacksList::class)
            ->call('confirmDelete', $rack->id)
            ->call('deleteRack')
            ->assertHasErrors(['delete'])
            ->assertSee('2 devices are mounted in it')
            ->assertSet('deletingRackId', $rack->id);

        $this->assertDatabaseHas('ict_racks', ['id' => $rack->id]);
        $this->assertSame($rack->id, $server->fresh()->rack_id);
        $this->assertSame(0, AuditLog::query()->where('action', 'delete_rack')->count());

        // Once the devices are out, the same rack can go.
        IctAsset::query()->where('rack_id', $rack->id)->update(['rack_id' => null, 'rack_unit' => null, 'rack_unit_span' => 1]);

        Livewire::actingAs($this->accraIct)->test(RacksList::class)
            ->call('confirmDelete', $rack->id)
            ->call('deleteRack')
            ->assertHasNoErrors();
        $this->assertDatabaseMissing('ict_racks', ['id' => $rack->id]);
    }

    public function test_deleting_without_manage_permission_is_forbidden(): void
    {
        $rack = $this->rack('Rack A');

        Livewire::actingAs($this->staff)->test(RacksList::class)->assertForbidden();
        $this->assertDatabaseHas('ict_racks', ['id' => $rack->id]);
    }

    // ---- detail page -----------------------------------------------------------------------------------------------

    public function test_the_detail_page_lists_the_mounted_devices_bottom_to_top_with_the_free_space(): void
    {
        $rack = $this->rack('Rack A', null, ['total_units' => 20, 'location' => 'Server Room']);
        $this->server($rack, 'Top Server', 15, 2);   // U15-U16
        $this->server($rack, 'Bottom Server', 1, 3); // U1-U3
        $this->server($rack, 'Middle Server', 8);    // U8
        // A device in another rack never shows here.
        $this->server($this->rack('Rack B'), 'Elsewhere', 1);

        $component = Livewire::actingAs($this->accraIct)->test(RackShow::class, ['rack' => $rack->id]);

        $this->assertSame(
            ['Bottom Server', 'Middle Server', 'Top Server'],
            $component->viewData('devices')->pluck('asset_name')->all()
        );
        $component
            ->assertViewHas('usedUnits', 6)
            ->assertViewHas('freeUnits', 14)
            ->assertViewHas('freeRanges', [[4, 7], [9, 14], [17, 20]])
            ->assertSee('U1-U3')
            ->assertSee('U15-U16')
            ->assertSee('U4-U7, U9-U14, U17-U20')
            ->assertDontSee('Elsewhere');

        // Each device links to its page.
        $device = IctAsset::query()->where('asset_name', 'Bottom Server')->firstOrFail();
        $component->assertSee(route('assets.show', $device), false);
    }

    public function test_an_empty_rack_says_so_and_is_all_free(): void
    {
        $rack = $this->rack('Empty Rack', null, ['total_units' => 10]);

        Livewire::actingAs($this->accraIct)->test(RackShow::class, ['rack' => $rack->id])
            ->assertViewHas('usedUnits', 0)
            ->assertViewHas('freeUnits', 10)
            ->assertViewHas('freeRanges', [[1, 10]])
            ->assertSee('Nothing is mounted in this rack yet.');
    }

    // ---- list: filters, columns, export ----------------------------------------------------------------------------

    public function test_the_list_shows_used_total_and_device_count_and_filters_by_search_status_and_district(): void
    {
        $a = $this->rack('Alpha Rack', $this->temaDistrict, ['code' => 'AL-1', 'location' => 'Server Room', 'total_units' => 20]);
        $this->rack('Beta Rack', $this->accraOffice, ['status' => IctRack::STATUS_DECOMMISSIONED, 'location' => 'Basement']);
        $this->server($a, 'S1', 1, 4);
        $this->server($a, 'S2', 10, 2);

        $names = fn (array $query) => Livewire::actingAs($this->accraIct)->withQueryParams($query)->test(RacksList::class)
            ->viewData('racks')->getCollection()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Alpha Rack', 'Beta Rack'], $names([]));
        $this->assertSame(['Alpha Rack'], $names(['q' => 'AL-1']));
        $this->assertSame(['Beta Rack'], $names(['q' => 'basement']));
        $this->assertSame(['Beta Rack'], $names(['status' => 'Decommissioned']));
        $this->assertSame(['Alpha Rack'], $names(['districts' => [(string) $this->temaDistrict->id]]));
        $this->assertSame([], $names(['q' => 'nothing like this']));

        // A district outside the person's region is ignored, not obeyed.
        $this->assertSame(['Alpha Rack', 'Beta Rack'], $names(['districts' => [(string) $this->obuasiDistrict->id]]));

        Livewire::actingAs($this->accraIct)->test(RacksList::class)
            ->assertSee('6 / 20 U')
            ->assertSee('14 U free')
            ->assertSee('0 / 42 U');
    }

    public function test_the_column_picker_hides_a_column_and_the_export_follows_it(): void
    {
        $rack = $this->rack('Alpha Rack', $this->temaDistrict, ['code' => 'AL-1', 'location' => 'Server Room', 'total_units' => 20]);
        $this->server($rack, 'S1', 1, 4);

        $list = Livewire::actingAs($this->accraIct)->test(RacksList::class)
            ->assertViewHas('visibleColumns', ['rack', 'location', 'district', 'usage', 'devices', 'status', 'actions'])
            ->call('toggleColumn', 'devices')
            ->call('toggleColumn', 'rack') // locked: ignored
            ->assertViewHas('visibleColumns', ['rack', 'location', 'district', 'usage', 'status', 'actions']);

        $this->assertSame('rack,location,district,usage,status,actions', $list->get('colsParam'));

        $sheet = $this->readSheet($this->actingAs($this->accraIct)->get(route('assets.racks.export.excel', ['cols' => 'rack,usage,devices'])));
        $this->assertSame(['Rack', 'Code', 'Total U', 'Used U', 'Free U', 'Devices'], $sheet[0]);
        $this->assertSame(['Alpha Rack', 'AL-1', '20', '4', '16', '1'], $sheet[1]);
    }

    public function test_the_export_is_scoped_filtered_gated_and_audited(): void
    {
        $this->rack('Accra Rack', $this->temaDistrict, ['status' => IctRack::STATUS_DECOMMISSIONED]);
        $this->rack('Accra Active', $this->accraOffice);
        $this->rack('Kumasi Rack', $this->obuasiDistrict);

        $names = fn (User $user, array $query = []) => collect($this->readSheet($this->actingAs($user)->get(route('assets.racks.export.excel', [...$query, 'cols' => 'rack']))))->skip(1)->pluck(0)->sort()->values()->all();

        $this->assertSame(['Accra Active', 'Accra Rack'], $names($this->accraIct));
        $this->assertSame(['Accra Active', 'Accra Rack', 'Kumasi Rack'], $names($this->super));
        $this->assertSame(['Accra Rack'], $names($this->accraIct, ['status' => 'Decommissioned']));
        $this->assertSame(['Accra Active'], $names($this->accraIct, ['q' => 'active']));

        $this->actingAs($this->accraIct)->get(route('assets.racks.export.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $log = AuditLog::query()->where('action', 'export_racks_pdf')->sole();
        $this->assertSame('ict_racks', $log->target_type);
        $this->assertSame(2, $log->new_values['rows']);

        // The export permission gates it, and an account without the module never gets there.
        $this->role('ict_team')->permissions()->detach(Permission::query()->where('name', 'assets.export_inventory')->value('id'));
        $this->actingAs(User::query()->findOrFail($this->accraIct->id))->get(route('assets.racks.export.excel'))->assertForbidden();
    }
}
