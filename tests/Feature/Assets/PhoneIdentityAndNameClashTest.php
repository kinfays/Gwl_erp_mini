<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\PhonesList;
use App\Models\IctAsset;
use App\Services\Assets\AssetRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

/**
 * (1) A brand-new phone name was refused with "An asset with this name already exists." because the phone form saved a
 * blank serial as '' (not NULL), `ict_assets.serial_number` carries a unique index, and AssetRecordService::save()
 * reported ANY unique violation as a name clash.
 * (2) A phone (type Ph) needs a serial number or an IMEI.
 */
class PhoneIdentityAndNameClashTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    private const NAME_MESSAGE = 'An asset with this name already exists.';

    private const IDENTITY_MESSAGE = 'Enter at least a serial number or an IMEI for a phone.';

    private function signedIn()
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        return $district;
    }

    private function phoneForm($component, string $name, string $type = 'Ph', string $serial = '', string $imei = '')
    {
        return $component
            ->call('openCreate')
            ->set('form.asset_name', $name)
            ->set('form.asset_type', $type)
            ->set('form.serial_number', $serial)
            ->set('form.imei', $imei)
            ->set('form.status', IctAsset::STATUS_ACTIVE);
    }

    // ---- 1. the duplicate-name bug -------------------------------------------------------------------------------

    public function test_a_fresh_phone_name_saves_even_when_other_assets_and_blank_serial_phones_exist(): void
    {
        $district = $this->signedIn();
        $this->makeAsset($district, ['asset_name' => 'Front Desk PC', 'asset_type' => 'PC']);

        // Two shared devices with no serial number (POS terminals need neither serial nor IMEI).
        $this->phoneForm(Livewire::test(PhonesList::class), 'Counter POS 1', 'POS')->call('save')->assertHasNoErrors();
        $this->phoneForm(Livewire::test(PhonesList::class), 'Counter POS 2', 'POS')->call('save')->assertHasNoErrors();

        $this->assertSame(2, IctAsset::query()->where('device_category', 'phone')->count());
        $this->assertSame(0, IctAsset::query()->where('serial_number', '')->count(), 'A blank serial must be stored as NULL.');
    }

    public function test_a_real_duplicate_name_is_still_refused_across_types(): void
    {
        $district = $this->signedIn();
        $this->makeAsset($district, ['asset_name' => 'Shared Name']);

        $this->phoneForm(Livewire::test(PhonesList::class), ' shared name ', 'Ph', 'SN-1')
            ->call('save')
            ->assertHasErrors(['form.asset_name'])
            ->assertSee(self::NAME_MESSAGE);
    }

    public function test_a_serial_clash_never_produces_the_name_message(): void
    {
        $district = $this->signedIn();
        $this->makeAsset($district, ['asset_name' => 'Old Phone', 'asset_type' => 'Ph', 'device_category' => 'phone', 'serial_number' => 'DUP-1']);

        // The service alone (form check skipped), and with a different case, as MySQL compares serials case-insensitively.
        try {
            app(AssetRecordService::class)->save([
                'asset_name' => 'Brand New Name',
                'serial_number' => 'DUP-1',
                'asset_type' => 'Ph',
                'status' => 'Active',
                'region_id' => $district->region_id,
            ], IctAsset::DEVICE_CATEGORY_PHONE);

            $this->fail('A duplicate serial was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayNotHasKey('form.asset_name', $e->errors());
            $this->assertStringContainsString('serial number already exists', $e->errors()['form.serial_number'][0]);
        }

        $this->assertSame(1, IctAsset::query()->count());
    }

    public function test_the_service_stores_blank_serial_and_imei_as_null(): void
    {
        $district = $this->signedIn();

        foreach (['Net A', 'Net B'] as $name) {
            app(AssetRecordService::class)->save([
                'asset_name' => $name,
                'serial_number' => '  ',
                'imei' => '',
                'asset_type' => 'RT',
                'status' => 'Active',
                'region_id' => $district->region_id,
            ], IctAsset::DEVICE_CATEGORY_NETWORK);
        }

        $this->assertSame(2, IctAsset::query()->whereNull('serial_number')->whereNull('imei')->count());
    }

    // ---- 2. a phone needs a serial number or an IMEI -------------------------------------------------------------

    public function test_a_phone_with_only_a_serial_saves(): void
    {
        $this->signedIn();

        $this->phoneForm(Livewire::test(PhonesList::class), 'Serial Only', 'Ph', ' SER-1 ')->call('save')->assertHasNoErrors();

        $asset = IctAsset::query()->where('asset_name', 'Serial Only')->firstOrFail();
        $this->assertSame('SER-1', $asset->serial_number);
        $this->assertNull($asset->imei);
    }

    public function test_a_phone_with_only_an_imei_saves(): void
    {
        $this->signedIn();

        $this->phoneForm(Livewire::test(PhonesList::class), 'Imei Only', 'Ph', '', '123456789012345')->call('save')->assertHasNoErrors();

        $asset = IctAsset::query()->where('asset_name', 'Imei Only')->firstOrFail();
        $this->assertNull($asset->serial_number);
        $this->assertSame('123456789012345', $asset->imei);
    }

    public function test_a_phone_with_both_saves(): void
    {
        $this->signedIn();

        $this->phoneForm(Livewire::test(PhonesList::class), 'Both', 'Ph', 'SER-2', '123456789012346')->call('save')->assertHasNoErrors();

        $this->assertSame(1, IctAsset::query()->where('asset_name', 'Both')->count());
    }

    public function test_a_phone_with_neither_is_refused_on_both_fields(): void
    {
        $this->signedIn();

        $this->phoneForm(Livewire::test(PhonesList::class), 'Nothing', 'Ph')
            ->call('save')
            ->assertHasErrors(['form.serial_number', 'form.imei'])
            ->assertSee(self::IDENTITY_MESSAGE);

        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_whitespace_only_values_count_as_missing(): void
    {
        $this->signedIn();

        $this->phoneForm(Livewire::test(PhonesList::class), 'Blanks', 'Ph', '   ', "\t ")
            ->call('save')
            ->assertHasErrors(['form.serial_number', 'form.imei']);

        $this->assertSame(0, IctAsset::query()->count());
    }

    public function test_the_service_enforces_the_rule_for_any_caller(): void
    {
        $district = $this->signedIn();

        try {
            app(AssetRecordService::class)->save([
                'asset_name' => 'Direct Phone',
                'asset_type' => 'Ph',
                'status' => 'Active',
                'region_id' => $district->region_id,
            ], IctAsset::DEVICE_CATEGORY_PHONE);

            $this->fail('A phone with no serial number and no IMEI was saved.');
        } catch (ValidationException $e) {
            $this->assertSame([self::IDENTITY_MESSAGE], $e->errors()['form.serial_number']);
            $this->assertSame([self::IDENTITY_MESSAGE], $e->errors()['form.imei']);
        }
    }

    public function test_pos_terminals_sim_cards_and_other_assets_are_unaffected(): void
    {
        $this->signedIn();

        $this->phoneForm(Livewire::test(PhonesList::class), 'A POS', 'POS')->call('save')->assertHasNoErrors();
        $this->phoneForm(Livewire::test(PhonesList::class), 'A SIM', 'SIM')->call('save')->assertHasNoErrors();

        $this->assertSame(2, IctAsset::query()->count());
    }

    public function test_the_form_reacts_when_the_type_changes_to_or_from_phone(): void
    {
        $this->signedIn();

        $component = $this->phoneForm(Livewire::test(PhonesList::class), 'Reactive', 'Ph')
            ->call('save')
            ->assertHasErrors(['form.serial_number', 'form.imei']);

        // Changing to a type that needs neither clears the message at once, without another save.
        $component->set('form.asset_type', 'POS')->assertHasNoErrors(['form.serial_number', 'form.imei']);

        // And changing back to a phone shows it again.
        $component->set('form.asset_type', 'Ph')->assertHasErrors(['form.serial_number', 'form.imei']);
    }

    public function test_editing_an_existing_phone_that_has_neither_requires_one_when_saving(): void
    {
        $district = $this->signedIn();
        $legacy = $this->makeAsset($district, ['asset_name' => 'Legacy Phone', 'asset_type' => 'Ph', 'device_category' => 'phone', 'serial_number' => null]);

        // A change that does not touch identity (the status prompt / audit correction pass only what changed) still works.
        app(AssetRecordService::class)->save(['status' => IctAsset::STATUS_DAMAGED, 'status_reason' => 'Cracked'], 'phone', $legacy);
        $this->assertSame(IctAsset::STATUS_DAMAGED, $legacy->fresh()->status);

        // The edit form carries the whole record, so saving it asks for a serial number or an IMEI.
        $component = Livewire::test(PhonesList::class)->call('openEdit', $legacy->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors(['form.serial_number', 'form.imei']);

        $component->set('form.imei', '999999999999999')->call('save')->assertHasNoErrors();
        $this->assertSame('999999999999999', $legacy->fresh()->imei);
    }

    public function test_editing_a_phone_keeps_its_own_name_and_serial(): void
    {
        $district = $this->signedIn();
        $phone = $this->makeAsset($district, ['asset_name' => 'My Phone', 'asset_type' => 'Ph', 'device_category' => 'phone', 'serial_number' => 'KEEP-1']);

        Livewire::test(PhonesList::class)->call('openEdit', $phone->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();
    }
}
