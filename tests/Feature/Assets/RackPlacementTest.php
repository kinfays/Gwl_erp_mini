<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\NetworkList;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\IctRack;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assets\AssetRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * Putting a device in a rack: the Rack / Starting U / U height fields on the Assets form (servers) and the Network form
 * (routers and switches), what gets stored and cleared, and the rules AssetRecordService enforces (the range fits, it
 * overlaps nothing, the rack is in the device's region and not decommissioned).
 */
class RackPlacementTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $accraIct;

    protected User $super;

    protected IctRack $rack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildUacOrg();

        $this->accraIct = $this->person('ICT002', $this->temaDistrict, ['ict_team']);
        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);

        $this->rack = $this->makeRack('Rack A', $this->temaDistrict, ['total_units' => 10]);

        $this->actingAs($this->accraIct);
    }

    protected function makeRack(string $name, $district, array $overrides = []): IctRack
    {
        return IctRack::query()->create([
            'name' => $name,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'total_units' => 42,
            'status' => IctRack::STATUS_ACTIVE,
            ...$overrides,
        ]);
    }

    /** A saved device, written the way the service writes one (so the placement rules run). */
    protected function place(string $name, string $type, ?IctRack $rack, ?int $unit, int $span = 1, array $extra = []): IctAsset
    {
        static $n = 0;
        $n++;

        $category = array_key_exists($type, IctAsset::ASSET_TYPES['network']) ? 'network' : 'asset';
        $model = IctAssetModel::query()->firstOrCreate(['name' => 'Model '.$type], ['category' => $type, 'is_active' => true]);

        return app(AssetRecordService::class)->save([
            'asset_name' => $name,
            'serial_number' => 'RK-'.$n,
            'asset_type' => $type,
            'ict_asset_model_id' => $model->id,
            'department_id' => $this->finance->id,
            'status' => IctAsset::STATUS_ACTIVE,
            'region_id' => $this->accra->id,
            'district_id' => $this->temaDistrict->id,
            'rack_id' => $rack?->id,
            'rack_unit' => $unit,
            'rack_unit_span' => $span,
            ...$extra,
        ], $category);
    }

    protected function serverForm(?User $user = null)
    {
        $model = IctAssetModel::query()->firstOrCreate(['name' => 'Model Server'], ['category' => 'Server', 'is_active' => true]);

        return Livewire::actingAs($user ?? $this->accraIct)->test(AssetsList::class)->call('openCreate')
            ->set('form.asset_type', 'Server')
            ->set('form.asset_name', 'Form Server')
            ->set('form.serial_number', 'FORM-1')
            ->set('form.ict_asset_model_id', $model->id)
            ->set('form.department_id', $this->finance->id)
            ->set('form.district_id', $this->temaDistrict->id);
    }

    protected function switchForm(string $type = 'SW', ?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->accraIct)->test(NetworkList::class)->call('openCreate')
            ->set('form.asset_type', $type)
            ->set('form.asset_name', 'Form Switch')
            ->set('form.serial_number', 'SWF-1')
            ->set('form.district_id', $this->temaDistrict->id);
    }

    protected function assertRackError(string $field, string $fragment, callable $save): void
    {
        try {
            $save();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($fragment, $e->errors()[$field][0]);

            return;
        }

        $this->fail("Expected a validation error on {$field}.");
    }

    // ---- which devices get the fields ------------------------------------------------------------------------------

    public function test_only_servers_routers_and_switches_are_rack_mounted_types(): void
    {
        $this->assertSame(['Server', 'RT', 'SW'], IctAsset::RACK_ASSET_TYPES);

        foreach (IctAsset::RACK_ASSET_TYPES as $type) {
            $this->assertTrue(
                array_key_exists($type, IctAsset::ASSET_TYPES['asset']) || array_key_exists($type, IctAsset::ASSET_TYPES['network']),
                "{$type} is a real asset type"
            );
        }

        $this->assertTrue(IctAsset::supportsRack('asset', 'Server'));
        $this->assertTrue(IctAsset::supportsRack('network', 'RT'));
        $this->assertTrue(IctAsset::supportsRack('network', 'SW'));

        foreach ([['asset', 'PC'], ['asset', 'Laptop'], ['asset', 'PRT'], ['asset', 'TPR'], ['phone', 'Ph'], ['network', 'AP'], ['network', 'MiFi'], ['network', 'P2P'], ['network', '4GRT'], ['network', 'Server'], ['asset', 'SW'], ['asset', null], [null, 'Server']] as [$category, $type]) {
            $this->assertFalse(IctAsset::supportsRack($category, $type), "{$category}/{$type}");
        }
    }

    public function test_the_assets_form_shows_the_rack_fields_for_a_server_only(): void
    {
        $component = Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate');

        $component->assertViewHas('formSupportsRack', false)->assertDontSee('Not in a rack');

        $component->set('form.asset_type', 'Server')
            ->assertViewHas('formSupportsRack', true)
            ->assertSee('Not in a rack')
            ->assertSee('Rack A')
            ->assertDontSee('Starting U');

        // Choosing a rack opens the position fields.
        $component->set('form.rack_id', (string) $this->rack->id)->assertSee('Starting U')->assertSee('U height');

        foreach (['Laptop', 'PC', 'AIO', 'PRT', 'PTC', 'TPR', 'CM'] as $type) {
            Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate')->set('form.asset_type', $type)
                ->assertViewHas('formSupportsRack', false)->assertDontSee('Not in a rack');
        }
    }

    public function test_the_network_form_shows_the_rack_fields_for_routers_and_switches_only(): void
    {
        foreach (['RT', 'SW'] as $type) {
            Livewire::actingAs($this->accraIct)->test(NetworkList::class)->call('openCreate')->set('form.asset_type', $type)
                ->assertViewHas('formSupportsRack', true)->assertSee('Not in a rack');
        }

        foreach (['AP', 'MiFi', 'P2P', '4GRT'] as $type) {
            Livewire::actingAs($this->accraIct)->test(NetworkList::class)->call('openCreate')->set('form.asset_type', $type)
                ->assertViewHas('formSupportsRack', false)->assertDontSee('Not in a rack');
        }

        Livewire::actingAs($this->accraIct)->test(NetworkList::class)->call('openCreate')->assertDontSee('Not in a rack');
    }

    // ---- saving and clearing ---------------------------------------------------------------------------------------

    public function test_a_server_is_saved_into_a_rack_from_the_assets_form_and_the_audit_row_carries_it(): void
    {
        $this->serverForm()
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '3')
            ->set('form.rack_unit_span', '2')
            ->call('save')
            ->assertHasNoErrors();

        $server = IctAsset::query()->where('serial_number', 'FORM-1')->firstOrFail();
        $this->assertSame($this->rack->id, $server->rack_id);
        $this->assertSame(3, $server->rack_unit);
        $this->assertSame(2, $server->rack_unit_span);
        $this->assertSame('U3-U4', $server->rackPositionLabel());

        $log = AuditLog::query()->where('action', 'create_asset')->sole();
        $this->assertSame($this->rack->id, $log->new_values['rack_id']);
        $this->assertSame(3, $log->new_values['rack_unit']);

        // Editing it loads the placement back into the form, and a changed position is audited as an update.
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openEdit', $server->id)
            ->assertSet('form.rack_id', $this->rack->id)
            ->assertSet('form.rack_unit', 3)
            ->assertSet('form.rack_unit_span', 2)
            ->set('form.rack_unit', '6')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(6, $server->fresh()->rack_unit);
        $update = AuditLog::query()->where('action', 'update_asset')->sole();
        $this->assertSame(3, $update->old_values['rack_unit']);
        $this->assertSame(6, $update->new_values['rack_unit']);
    }

    public function test_a_switch_is_saved_into_a_rack_from_the_network_form(): void
    {
        $this->switchForm('SW')
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '1')
            ->set('form.rack_unit_span', '1')
            ->call('save')
            ->assertHasNoErrors();

        $switch = IctAsset::query()->where('serial_number', 'SWF-1')->firstOrFail();
        $this->assertSame('network', $switch->device_category);
        $this->assertSame($this->rack->id, $switch->rack_id);
        $this->assertSame(1, $switch->rack_unit);

        $this->assertSame($this->rack->id, AuditLog::query()->where('action', 'create_network')->sole()->new_values['rack_id']);
    }

    public function test_a_device_saved_without_a_rack_stores_no_position(): void
    {
        $this->serverForm()->call('save')->assertHasNoErrors();

        $server = IctAsset::query()->where('serial_number', 'FORM-1')->firstOrFail();
        $this->assertNull($server->rack_id);
        $this->assertNull($server->rack_unit);
        $this->assertSame(1, $server->rack_unit_span);
    }

    public function test_picking_a_type_with_no_rack_clears_what_was_entered(): void
    {
        // Network form: a switch with a position, then it becomes an access point.
        $this->switchForm('SW')
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '4')
            ->set('form.rack_unit_span', '2')
            ->set('form.asset_type', 'AP')
            ->assertSet('form.rack_id', null)
            ->assertSet('form.rack_unit', null)
            ->assertSet('form.rack_unit_span', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(IctAsset::query()->where('serial_number', 'SWF-1')->value('rack_id'));

        // Assets form: a server becomes a laptop.
        $this->serverForm()
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '1')
            ->set('form.asset_type', 'Laptop')
            ->assertSet('form.rack_id', null)
            ->assertSet('form.rack_unit', null);

        // Choosing "Not in a rack" drops the position that went with it.
        $this->serverForm()
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '2')
            ->set('form.rack_id', '')
            ->assertSet('form.rack_id', null)
            ->assertSet('form.rack_unit', null)
            ->assertSet('form.rack_unit_span', 1);
    }

    public function test_the_service_refuses_to_store_a_rack_for_a_type_that_has_none_whatever_the_form_says(): void
    {
        $laptop = $this->place('Lappy', 'Laptop', $this->rack, 2, 2);
        $this->assertNull($laptop->rack_id);
        $this->assertNull($laptop->rack_unit);
        $this->assertSame(1, $laptop->rack_unit_span);

        $ap = $this->place('Hall AP', 'AP', $this->rack, 5);
        $this->assertNull($ap->fresh()->rack_id);

        // A server in a rack that is then changed to a laptop loses its position.
        $server = $this->place('Core Server', 'Server', $this->rack, 4, 2);
        $this->assertSame($this->rack->id, $server->rack_id);

        app(AssetRecordService::class)->save(['asset_type' => 'Laptop'], 'asset', $server);

        $server = $server->fresh();
        $this->assertNull($server->rack_id);
        $this->assertNull($server->rack_unit);
        $this->assertSame(1, $server->rack_unit_span);
    }

    // ---- the range must fit and overlap nothing --------------------------------------------------------------------

    public function test_a_range_that_runs_past_the_top_of_the_rack_is_rejected_and_one_that_just_fits_is_not(): void
    {
        $this->serverForm()
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '9')
            ->set('form.rack_unit_span', '3')
            ->call('save')
            ->assertHasErrors(['form.rack_unit'])
            ->assertSee('would reach U11')
            ->assertSee('only 10U high');

        $this->assertSame(0, IctAsset::query()->count());

        $this->assertRackError('form.rack_unit', 'does not fit', fn () => $this->place('Too high', 'Server', $this->rack, 11));

        $top = $this->place('Top Server', 'Server', $this->rack, 8, 3); // U8-U10: exactly the top
        $this->assertSame(8, $top->rack_unit);
    }

    public function test_an_overlap_is_rejected_and_the_conflicting_device_is_named(): void
    {
        $this->place('Core Server', 'Server', $this->rack, 5, 2); // U5-U6

        foreach ([[6, 1], [5, 1], [4, 3], [3, 4], [1, 10]] as [$unit, $span]) {
            $this->assertRackError('form.rack_unit', 'Core Server', fn () => $this->place("Clash {$unit}-{$span}", 'SW', $this->rack, $unit, $span));
        }

        // The same through the form, with the message on screen.
        $this->switchForm('SW')
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '6')
            ->set('form.rack_unit_span', '1')
            ->call('save')
            ->assertHasErrors(['form.rack_unit'])
            ->assertSee('overlaps Core Server')
            ->assertSee('U5-U6');

        // Touching is not overlapping: directly below and directly above are free.
        $this->assertSame(4, $this->place('Below', 'SW', $this->rack, 4)->rack_unit);
        $this->assertSame(7, $this->place('Above', 'SW', $this->rack, 7, 2)->rack_unit);

        $this->assertSame(3, IctAsset::query()->where('rack_id', $this->rack->id)->count());
    }

    public function test_two_racks_can_hold_devices_at_the_same_unit(): void
    {
        $other = $this->makeRack('Rack B', $this->temaDistrict, ['total_units' => 10]);

        $a = $this->place('Server A', 'Server', $this->rack, 1, 2);
        $b = $this->place('Server B', 'Server', $other, 1, 2);

        $this->assertSame([$this->rack->id, $other->id], [$a->rack_id, $b->rack_id]);
    }

    public function test_a_device_never_clashes_with_itself_when_it_is_edited_or_moved_within_the_rack(): void
    {
        $server = $this->place('Core Server', 'Server', $this->rack, 5, 2); // U5-U6

        // Taller by one U: now U5-U7, overlapping only its own old slot.
        app(AssetRecordService::class)->save(['rack_unit_span' => 3], 'asset', $server);
        $this->assertSame(3, $server->fresh()->rack_unit_span);

        // Moved up.
        app(AssetRecordService::class)->save(['rack_unit' => 8, 'rack_unit_span' => 2], 'asset', $server->fresh());
        $this->assertSame(8, $server->fresh()->rack_unit);

        // Through the form, an edit that changes nothing about the position saves.
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openEdit', $server->id)
            ->set('form.notes', 'moved')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(8, $server->fresh()->rack_unit);
    }

    public function test_a_starting_u_and_a_height_are_needed_once_a_rack_is_chosen(): void
    {
        $this->switchForm('SW')
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '')
            ->call('save')
            ->assertHasErrors(['form.rack_unit' => 'required']);

        foreach (['0', '-1', 'abc', '1.5'] as $bad) {
            $this->switchForm('SW')
                ->set('form.rack_id', (string) $this->rack->id)
                ->set('form.rack_unit', $bad)
                ->call('save')
                ->assertHasErrors(['form.rack_unit']);
        }

        $this->switchForm('SW')
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '2')
            ->set('form.rack_unit_span', '0')
            ->call('save')
            ->assertHasErrors(['form.rack_unit_span']);

        $this->assertSame(0, IctAsset::query()->count());

        // The service says the same to a caller that skipped the form.
        $this->assertRackError('form.rack_unit', 'starting U', fn () => $this->place('No unit', 'Server', $this->rack, null));
    }

    // ---- region and status of the rack -----------------------------------------------------------------------------

    public function test_a_rack_of_another_region_is_not_offered_and_is_refused(): void
    {
        $kumasi = $this->makeRack('Kumasi Rack', $this->obuasiDistrict);

        $component = $this->serverForm()->assertViewHas('rackOptions', fn ($racks) => $racks->pluck('name')->all() === ['Rack A']);

        $component->set('form.rack_id', (string) $kumasi->id)
            ->set('form.rack_unit', '1')
            ->call('save')
            ->assertHasErrors(['form.rack_id']);

        $this->assertSame(0, IctAsset::query()->count());

        // The service refuses it too, even for a caller that skipped the form.
        $this->assertRackError('form.rack_id', 'not in this device', fn () => $this->place('Far Server', 'Server', $kumasi, 1));

        // A saved device keeps working when a super_admin from another region edits it (placement unchanged is not re-judged).
        $kumasiServer = app(AssetRecordService::class)->save([
            'asset_name' => 'Kumasi Server', 'serial_number' => 'K-1', 'asset_type' => 'Server', 'status' => 'Active',
            'region_id' => $this->ashanti->id, 'district_id' => $this->obuasiDistrict->id,
            'rack_id' => $kumasi->id, 'rack_unit' => 1, 'rack_unit_span' => 1,
        ], 'asset');

        app(AssetRecordService::class)->save(['status' => 'In Repair'], 'asset', $kumasiServer);
        $this->assertSame($kumasi->id, $kumasiServer->fresh()->rack_id);
    }

    public function test_a_decommissioned_rack_is_not_offered_or_accepted_but_a_device_already_in_it_stays_editable(): void
    {
        $server = $this->place('Old Server', 'Server', $this->rack, 1, 2);
        $this->rack->update(['status' => IctRack::STATUS_DECOMMISSIONED]);
        $fresh = $this->makeRack('Rack New', $this->temaDistrict);
        $retired = $this->makeRack('Rack Gone', $this->temaDistrict, ['status' => IctRack::STATUS_DECOMMISSIONED]);

        // New devices are offered live racks only...
        $this->serverForm()->assertViewHas('rackOptions', fn ($racks) => $racks->pluck('name')->sort()->values()->all() === ['Rack New']);
        $this->assertRackError('form.rack_id', 'decommissioned', fn () => $this->place('New Server', 'Server', $retired, 1));

        // ...but the device already in a rack that was decommissioned still shows it and can be edited.
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openEdit', $server->id)
            ->assertViewHas('rackOptions', fn ($racks) => $racks->pluck('name')->sort()->values()->all() === ['Rack A', 'Rack New'])
            ->assertSet('form.rack_id', $this->rack->id)
            ->set('form.notes', 'still here')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame($this->rack->id, $server->fresh()->rack_id);

        // It can be moved to a live rack.
        app(AssetRecordService::class)->save(['rack_id' => $fresh->id, 'rack_unit' => 3, 'rack_unit_span' => 1], 'asset', $server->fresh());
        $this->assertSame($fresh->id, $server->fresh()->rack_id);
    }

    // ---- the rest of a save does not disturb it --------------------------------------------------------------------

    public function test_a_save_that_does_not_mention_the_rack_leaves_the_placement_alone(): void
    {
        $server = $this->place('Core Server', 'Server', $this->rack, 4, 3);

        // e.g. the issue-report popup, an audit correction or a status change.
        app(AssetRecordService::class)->save(['status' => IctAsset::STATUS_IN_REPAIR], 'asset', $server);

        $server = $server->fresh();
        $this->assertSame($this->rack->id, $server->rack_id);
        $this->assertSame(4, $server->rack_unit);
        $this->assertSame(3, $server->rack_unit_span);

        // A switch that is changed to a router (both rack types) keeps its place too.
        $switch = $this->place('Edge Switch', 'SW', $this->rack, 9);
        app(AssetRecordService::class)->save(['asset_type' => 'RT'], 'network', $switch);
        $this->assertSame(9, $switch->fresh()->rack_unit);
    }

    public function test_the_device_page_shows_the_rack_for_rack_types_only(): void
    {
        $server = $this->place('Core Server', 'Server', $this->rack, 4, 2);
        $loose = $this->place('Loose Server', 'Server', null, null);
        $laptop = $this->place('Lappy', 'Laptop', null, null);

        $this->actingAs($this->accraIct);

        $this->get(route('assets.show', $server))->assertOk()
            ->assertSee('Rack A')
            ->assertSee('U4-U5')
            ->assertSee(route('assets.racks.show', $this->rack), false);

        $this->get(route('assets.show', $loose))->assertOk()->assertSee('Not in a rack');
        $this->get(route('assets.show', $laptop))->assertOk()->assertDontSee('Not in a rack');
    }

    // ---- permission to see racks -----------------------------------------------------------------------------------

    public function test_without_view_racks_the_fields_are_hidden_and_a_forged_placement_is_ignored(): void
    {
        $server = $this->place('Core Server', 'Server', $this->rack, 4, 2);

        $this->role('ict_team')->permissions()->detach(Permission::query()->where('name', 'assets.view_racks')->value('id'));
        $user = User::query()->findOrFail($this->accraIct->id);

        // Hidden on the form...
        $this->serverForm($user)->assertViewHas('formSupportsRack', false)->assertDontSee('Not in a rack');

        // ...and a hand-made request cannot place a new device,
        $this->serverForm($user)
            ->set('form.rack_id', (string) $this->rack->id)
            ->set('form.rack_unit', '8')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertNull(IctAsset::query()->where('serial_number', 'FORM-1')->value('rack_id'));

        // ...nor move or remove an existing one.
        Livewire::actingAs($user)->test(AssetsList::class)->call('openEdit', $server->id)
            ->set('form.rack_id', null)
            ->set('form.rack_unit', null)
            ->call('save')
            ->assertHasNoErrors();

        $server = $server->fresh();
        $this->assertSame($this->rack->id, $server->rack_id);
        $this->assertSame(4, $server->rack_unit);
    }
}
