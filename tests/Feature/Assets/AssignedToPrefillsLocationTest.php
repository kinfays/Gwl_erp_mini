<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * Picking "Assigned To" suggests the person's district as the Location, which stays editable; a district the actor
 * could not save is never put in the field. Also: a Counting Machine starts as general use.
 */
class AssignedToPrefillsLocationTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $accraIct;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildUacOrg();

        $this->accraIct = $this->person('ICT002', $this->temaDistrict, ['ict_team']);
        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);
    }

    protected function fillAssetForm($screen, string $name = 'Prefill PC')
    {
        $model = IctAssetModel::query()->firstOrCreate(['name' => 'Prefill Model'], ['category' => 'PC', 'is_active' => true]);

        return $screen
            ->set('form.asset_type', 'PC')
            ->set('form.asset_name', $name)
            ->set('form.serial_number', 'SN-'.uniqid())
            ->set('form.ict_asset_model_id', $model->id)
            ->set('form.department_id', $this->finance->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE);
    }

    public function test_picking_a_person_fills_the_location_with_their_district(): void
    {
        $person = $this->makeEmployee('E300', $this->accraOffice);

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate')
            ->assertSet('form.district_id', null)
            ->set('form.assigned_to_employee_id', $person->id)
            ->assertSet('form.district_id', $this->accraOffice->id)
            ->assertSet('locationHint', null);
    }

    public function test_the_filled_location_is_a_default_the_person_can_change_and_it_saves_as_changed(): void
    {
        $person = $this->makeEmployee('E301', $this->accraOffice);

        $screen = Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate');
        $this->fillAssetForm($screen)
            ->set('form.assigned_to_employee_id', $person->id)
            ->assertSet('form.district_id', $this->accraOffice->id)
            ->set('form.district_id', $this->temaDistrict->id)
            ->assertSet('form.district_id', $this->temaDistrict->id)
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->where('asset_name', 'Prefill PC')->firstOrFail();
        $this->assertSame($this->temaDistrict->id, (int) $asset->district_id);
        $this->assertSame($person->id, (int) $asset->assigned_to_employee_id);
    }

    public function test_picking_a_different_person_fills_again_even_after_a_manual_change(): void
    {
        $first = $this->makeEmployee('E302', $this->accraOffice);
        $second = $this->makeEmployee('E303', $this->headOffice);

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate')
            ->set('form.assigned_to_employee_id', $first->id)
            ->set('form.district_id', $this->temaDistrict->id)
            ->set('form.assigned_to_employee_id', $second->id)
            ->assertSet('form.district_id', $this->headOffice->id);
    }

    public function test_clearing_the_person_does_not_wipe_the_location(): void
    {
        $person = $this->makeEmployee('E304', $this->accraOffice);

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate')
            ->set('form.assigned_to_employee_id', $person->id)
            ->set('form.assigned_to_employee_id', null)
            ->assertSet('form.district_id', $this->accraOffice->id)
            ->assertSet('locationHint', null);
    }

    public function test_a_person_based_outside_the_actors_region_is_not_filled_in_and_a_hint_says_so(): void
    {
        $kumasiPerson = $this->makeEmployee('E305', $this->obuasiDistrict);

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate')
            ->set('form.district_id', $this->temaDistrict->id)
            ->set('form.assigned_to_employee_id', $kumasiPerson->id)
            ->assertSet('form.district_id', $this->temaDistrict->id)
            ->assertSee('based in Obuasi District')
            ->assertSee('left as it is');
    }

    public function test_the_server_still_refuses_a_location_outside_the_region_whatever_the_form_sent(): void
    {
        $kumasiPerson = $this->makeEmployee('E306', $this->obuasiDistrict);

        $screen = Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate');
        $this->fillAssetForm($screen, 'Tampered PC')
            ->set('form.assigned_to_employee_id', $kumasiPerson->id)
            ->set('form.district_id', $this->obuasiDistrict->id)
            ->call('save')
            ->assertHasErrors(['form.district_id']);

        $this->assertDatabaseMissing('ict_assets', ['asset_name' => 'Tampered PC']);
    }

    public function test_opening_an_existing_asset_keeps_its_location_and_only_a_changed_person_refills(): void
    {
        $holder = $this->makeEmployee('E307', $this->accraOffice);
        $newHolder = $this->makeEmployee('E308', $this->headOffice);
        $asset = IctAsset::factory()->create([
            'asset_name' => 'Existing PC', 'asset_type' => 'PC',
            'region_id' => $this->accra->id, 'district_id' => $this->temaDistrict->id,
            'department_id' => $this->finance->id,
        ]);
        \App\Models\IctAsset::query()->whereKey($asset->id)->update(['assigned_to_employee_id' => $holder->id]);

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->call('openEdit', $asset->id)
            // Opening the form never touches the location, though the holder's own district differs.
            ->assertSet('form.district_id', $this->temaDistrict->id)
            ->assertSet('locationHint', null)
            ->set('form.assigned_to_employee_id', $newHolder->id)
            ->assertSet('form.district_id', $this->headOffice->id);
    }

    public function test_the_phones_form_does_the_same(): void
    {
        $person = $this->makeEmployee('E309', $this->accraOffice);
        $kumasiPerson = $this->makeEmployee('E310', $this->obuasiDistrict);

        Livewire::actingAs($this->accraIct)->test(PhonesList::class)->call('openCreate')
            ->set('form.assigned_to_employee_id', $person->id)
            ->assertSet('form.district_id', $this->accraOffice->id)
            ->set('form.district_id', $this->temaDistrict->id)
            ->assertSet('form.district_id', $this->temaDistrict->id)
            ->set('form.assigned_to_employee_id', $kumasiPerson->id)
            ->assertSet('form.district_id', $this->temaDistrict->id)
            ->assertSee('based in Obuasi District');
    }

    public function test_the_network_form_has_no_assignee_so_nothing_changes_there(): void
    {
        Livewire::actingAs($this->accraIct)->test(NetworkList::class)->call('openCreate')->assertSet('form.district_id', null);
    }

    // ---- Counting Machine -------------------------------------------------------------------------------------------

    public function test_counting_machine_is_an_asset_type_that_is_general_use_by_default(): void
    {
        $this->assertSame('Counting Machine', IctAsset::ASSET_TYPES['asset']['CM']);
        $this->assertFalse(IctAsset::isPersonallyAssignedType('CM'));

        $screen = Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate')
            ->assertSet('form.is_general_use', false)
            ->assertSee('Counting Machine')
            ->set('form.asset_type', 'CM')
            ->assertSet('form.is_general_use', true);

        // A Counting Machine needs no holder, so saving it raises no "nobody is assigned" warning, and it can be unticked.
        $model = IctAssetModel::query()->create(['name' => 'Glory 200', 'category' => 'CM', 'is_active' => true]);
        $screen->set('form.asset_name', 'Teller Counter')->set('form.serial_number', 'CM-1')
            ->set('form.ict_asset_model_id', $model->id)->set('form.department_id', $this->finance->id)
            ->set('form.district_id', $this->temaDistrict->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('confirmNoAssignee', false);

        $asset = IctAsset::query()->where('asset_name', 'Teller Counter')->firstOrFail();
        $this->assertTrue((bool) $asset->is_general_use);
        $this->assertNull($asset->assigned_to_employee_id);

        // Another type does not tick it, and picking Counting Machine on a form that already has a holder leaves the holder.
        $holder = $this->makeEmployee('E311', $this->accraOffice);
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openCreate')
            ->set('form.assigned_to_employee_id', $holder->id)
            ->set('form.asset_type', 'CM')
            ->assertSet('form.is_general_use', false)
            ->set('form.asset_type', 'PC')
            ->assertSet('form.is_general_use', false);
    }

    public function test_editing_an_existing_counting_machine_is_not_re_ticked(): void
    {
        $asset = IctAsset::factory()->create([
            'asset_name' => 'Old Counter', 'asset_type' => 'CM', 'is_general_use' => false,
            'region_id' => $this->accra->id, 'district_id' => $this->temaDistrict->id,
        ]);

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)->call('openEdit', $asset->id)
            ->assertSet('form.is_general_use', false);
    }
}
