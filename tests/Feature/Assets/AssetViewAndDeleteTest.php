<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetMaintenance;
use App\Models\MdmDevice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\AssetAssignmentService;
use App\Services\Assets\AssetRecordService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * View (the existing read-only device page, now reachable from a "View" action on each list) and Delete (a guarded
 * delete: only a record with no holder, no MDM link and no history; judged on the WRITE scope, never the viewing one).
 */
class AssetViewAndDeleteTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $headOfficeIct;

    protected User $accraIct;

    protected User $kumasiIct;

    protected User $super;

    protected IctAsset $accraLaptop;

    protected IctAsset $kumasiLaptop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildUacOrg();

        $this->headOfficeIct = $this->person('ICT001', $this->headOffice, ['ict_team']);
        $this->accraIct = $this->person('ICT002', $this->temaDistrict, ['ict_team']);
        $this->kumasiIct = $this->person('ICT003', $this->kumasiOffice, ['ict_team']);
        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);

        $this->accraLaptop = $this->asset($this->temaDistrict, 'Accra Laptop');
        $this->kumasiLaptop = $this->asset($this->obuasiDistrict, 'Kumasi Laptop');
    }

    protected function asset($district, string $name, string $kind = 'asset', array $overrides = []): IctAsset
    {
        $factory = match ($kind) {
            'phone' => IctAsset::factory()->phone(),
            'network' => IctAsset::factory()->network(),
            default => IctAsset::factory(),
        };

        return $factory->create([
            'asset_name' => $name,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            ...$overrides,
        ]);
    }

    // ---- delete ----------------------------------------------------------------------------------------------------

    public function test_an_asset_that_never_did_anything_is_deleted_with_one_audit_row_of_ids_and_codes(): void
    {
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->call('confirmDelete', $this->accraLaptop->id)
            ->assertSet('deletingAssetId', $this->accraLaptop->id)
            ->assertSee('Delete asset')
            ->call('deleteAsset')
            ->assertHasNoErrors()
            ->assertSet('deletingAssetId', null);

        $this->assertDatabaseMissing('ict_assets', ['id' => $this->accraLaptop->id]);

        $rows = AuditLog::query()->where('action', 'delete_asset')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->accraLaptop->id, (int) $rows[0]->target_id);
        $this->assertSame(['id', 'device_category', 'asset_type', 'status', 'ict_asset_model_id', 'region_id', 'district_id'], array_keys($rows[0]->old_values));
        $this->assertStringNotContainsString('Accra Laptop', json_encode($rows[0]->getAttributes()));
        $this->assertStringNotContainsString($this->accraLaptop->serial_number, json_encode($rows[0]->getAttributes()));
    }

    public function test_an_asset_with_a_holder_is_refused_and_nothing_changes(): void
    {
        $holder = $this->makeEmployee('E100', $this->temaDistrict);
        app(AssetAssignmentService::class)->assign($this->accraLaptop, $holder->id, $this->super, true);

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->call('confirmDelete', $this->accraLaptop->id)
            ->call('deleteAsset')
            ->assertHasErrors(['delete'])
            ->assertSee('currently assigned')
            ->assertSee('Retired');

        $this->assertDatabaseHas('ict_assets', ['id' => $this->accraLaptop->id]);
        $this->assertSame(0, AuditLog::query()->where('action', 'delete_asset')->count());
    }

    public function test_an_asset_that_was_assigned_before_is_refused_because_it_has_history(): void
    {
        $holder = $this->makeEmployee('E101', $this->temaDistrict);
        $assignments = app(AssetAssignmentService::class);
        $assignments->assign($this->accraLaptop, $holder->id, $this->super, true);
        $assignments->unassign($this->accraLaptop->fresh(), $this->super);

        $this->assertNull($this->accraLaptop->fresh()->assigned_to_employee_id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AssetRecordService::class)->delete($this->accraLaptop->fresh(), $this->accraIct);
    }

    public function test_a_phone_linked_to_mdm_is_refused(): void
    {
        $phone = $this->asset($this->temaDistrict, 'Linked Phone', 'phone');
        MdmDevice::factory()->forAsset($phone)->create();

        Livewire::actingAs($this->accraIct)->test(PhonesList::class)
            ->call('confirmDelete', $phone->id)
            ->call('deleteAsset')
            ->assertHasErrors(['delete'])
            ->assertSee('managed phone');

        $this->assertDatabaseHas('ict_assets', ['id' => $phone->id]);
    }

    public function test_an_asset_with_maintenance_history_is_refused(): void
    {
        IctAssetMaintenance::factory()->create(['ict_asset_id' => $this->accraLaptop->id]);

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->call('confirmDelete', $this->accraLaptop->id)
            ->call('deleteAsset')
            ->assertHasErrors(['delete'])
            ->assertSee('maintenance record');

        $this->assertDatabaseHas('ict_assets', ['id' => $this->accraLaptop->id]);
        $this->assertDatabaseCount('ict_asset_maintenances', 1);
    }

    public function test_without_the_permission_it_is_refused_and_the_button_is_hidden(): void
    {
        $permission = Permission::query()->where('name', 'assets.delete')->firstOrFail();
        DB::table('role_permissions')->where('role_id', Role::query()->where('name', 'ict_team')->value('id'))->where('permission_id', $permission->id)->delete();

        $screen = Livewire::actingAs($this->accraIct->fresh())->test(AssetsList::class)
            ->assertDontSeeHtml('wire:click="confirmDelete('.$this->accraLaptop->id.')"');

        $screen->call('confirmDelete', $this->accraLaptop->id)->assertForbidden();

        // The service checks it on its own, whatever the screen did.
        $this->expectException(AuthorizationException::class);
        app(AssetRecordService::class)->delete($this->accraLaptop, $this->accraIct->fresh());
    }

    public function test_it_is_judged_on_the_write_scope_so_another_regions_asset_is_a_404(): void
    {
        // Head Office ICT can SEE the Kumasi laptop but not change it.
        Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->assertSee('Kumasi Laptop')
            ->assertDontSeeHtml('wire:click="confirmDelete('.$this->kumasiLaptop->id.')"')
            ->assertSeeHtml('wire:click="confirmDelete('.$this->accraLaptop->id.')"')
            ->call('confirmDelete', $this->kumasiLaptop->id)
            ->assertNotFound();

        $this->assertDatabaseHas('ict_assets', ['id' => $this->kumasiLaptop->id]);

        // And the service refuses it too when called directly.
        try {
            app(AssetRecordService::class)->delete($this->kumasiLaptop, $this->headOfficeIct);
            $this->fail('A device outside the write scope was deleted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('ict_assets', ['id' => $this->kumasiLaptop->id]);
        }

        // An unscoped role (super_admin) may delete in any region.
        app(AssetRecordService::class)->delete($this->kumasiLaptop, $this->super);
        $this->assertDatabaseMissing('ict_assets', ['id' => $this->kumasiLaptop->id]);
    }

    public function test_phones_and_network_lists_delete_too(): void
    {
        $phone = $this->asset($this->temaDistrict, 'Spare Phone', 'phone');
        $router = $this->asset($this->temaDistrict, 'Spare Router', 'network');

        Livewire::actingAs($this->accraIct)->test(PhonesList::class)
            ->call('confirmDelete', $phone->id)->call('deleteAsset')->assertHasNoErrors();
        Livewire::actingAs($this->accraIct)->test(NetworkList::class)
            ->call('confirmDelete', $router->id)->call('deleteAsset')->assertHasNoErrors();

        $this->assertDatabaseMissing('ict_assets', ['id' => $phone->id]);
        $this->assertDatabaseMissing('ict_assets', ['id' => $router->id]);
        $this->assertSame(['delete_network', 'delete_phone'], AuditLog::query()->where('action', 'like', 'delete_%')->orderBy('action')->pluck('action')->all());

        // A phone cannot be deleted from the network list: the category is part of the lookup.
        $other = $this->asset($this->temaDistrict, 'Other Phone', 'phone');
        Livewire::actingAs($this->accraIct)->test(NetworkList::class)->call('confirmDelete', $other->id)->assertNotFound();
    }

    public function test_the_permission_is_granted_to_super_admin_and_both_ict_roles(): void
    {
        foreach (['super_admin', 'ict_team', 'ict_manager'] as $role) {
            $this->assertTrue(
                Role::query()->where('name', $role)->firstOrFail()->permissions()->where('name', 'assets.delete')->exists(),
                "{$role} should hold assets.delete"
            );
        }

        $this->assertFalse(Role::query()->where('name', 'admin')->firstOrFail()->permissions()->where('name', 'assets.delete')->exists());
    }

    // ---- view ------------------------------------------------------------------------------------------------------

    public function test_every_list_row_has_a_view_action_to_the_device_page(): void
    {
        $phone = $this->asset($this->temaDistrict, 'View Phone', 'phone');
        $router = $this->asset($this->temaDistrict, 'View Router', 'network');

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->assertSeeHtml('aria-label="View Accra Laptop"')->assertSeeHtml(route('assets.show', $this->accraLaptop));
        Livewire::actingAs($this->accraIct)->test(PhonesList::class)->assertSeeHtml('aria-label="View View Phone"')->assertSeeHtml(route('assets.show', $phone));
        Livewire::actingAs($this->accraIct)->test(NetworkList::class)->assertSeeHtml('aria-label="View View Router"')->assertSeeHtml(route('assets.show', $router));
    }

    public function test_the_device_page_is_readable_inside_the_viewing_scope_and_a_404_outside_it(): void
    {
        // Head Office ICT may read any region; a regional user only their own.
        $this->actingAs($this->headOfficeIct)->get(route('assets.show', $this->kumasiLaptop))->assertOk()->assertSee('Kumasi Laptop');
        $this->actingAs($this->accraIct)->get(route('assets.show', $this->accraLaptop))->assertOk();
        $this->actingAs($this->accraIct)->get(route('assets.show', $this->kumasiLaptop))->assertNotFound();
    }

    public function test_the_device_page_shows_a_phones_serial_and_imei_and_the_assignment_history(): void
    {
        $holder = $this->makeEmployee('E200', $this->temaDistrict);
        $phone = $this->asset($this->temaDistrict, 'Detail Phone', 'phone', ['asset_type' => 'Ph', 'serial_number' => 'SER-DETAIL-1', 'imei' => '356938035643809']);
        app(AssetAssignmentService::class)->assign($phone, $holder->id, $this->super, true);

        $this->actingAs($this->accraIct)->get(route('assets.show', $phone))
            ->assertOk()
            ->assertSee('SER-DETAIL-1')
            ->assertSee('356938035643809')
            ->assertSee('Assignment history')
            ->assertSee($holder->full_name);
    }
}
