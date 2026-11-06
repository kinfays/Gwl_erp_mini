<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\Audits\AuditShow;
use App\Livewire\Assets\Audits\AuditsList;
use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\AuditLog;
use App\Models\IctAssetAudit;
use App\Models\IctAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class EmployeePickerAndInlineModelTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_assigned_to_is_a_server_search_on_name_or_staff_id_for_assets_and_phones(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $ama = $this->makeEmployee('90001', 'Ama Mensah', $district);
        $kofi = $this->makeEmployee('90002', 'Kofi Boateng', $district);

        foreach ([AssetsList::class, PhonesList::class] as $screen) {
            $component = Livewire::test($screen)->call('openCreate')
                ->assertSeeHtml('searchEmployees')
                ->assertSee('by name or staff ID', false);

            $this->assertSame([$ama->id], array_column($component->instance()->searchEmployees('ama'), 'value'));
            $this->assertSame([$kofi->id], array_column($component->instance()->searchEmployees('90002'), 'value'));
            $found = $component->instance()->searchEmployees('Kofi');
            $this->assertSame('Kofi Boateng', $found[0]['label']);
            $this->assertStringContainsString('90002', $found[0]['description']);
            // Wildcards typed by a user are text.
            $this->assertSame([], $component->instance()->searchEmployees('%'));
        }
    }

    public function test_the_employee_search_returns_at_most_20_and_a_regional_user_only_gets_their_own_region(): void
    {
        $this->seedCoreAssetsAccess();
        $home = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $other = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->ictUser($home));
        $mine = $this->makeEmployee('80001', 'Local Person', $home);
        $this->makeEmployee('80002', 'Faraway Person', $other);

        $component = Livewire::test(AssetsList::class)->call('openCreate');
        $this->assertSame([$mine->id], array_column($component->instance()->searchEmployees('Person'), 'value'));

        foreach (range(1, 25) as $i) {
            $this->makeEmployee('7'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'Bulk Employee '.$i, $home);
        }
        $this->assertCount(20, $component->instance()->searchEmployees('Bulk'));
    }

    public function test_a_holder_outside_the_pool_still_shows_their_name_when_editing(): void
    {
        $this->seedCoreAssetsAccess();
        $home = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $other = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $this->actingAs($this->ictUser($home));
        $holder = $this->makeEmployee('70001', 'Legacy Holder', $other);
        $asset = $this->makeAsset($home, ['assigned_to_employee_id' => $holder->id]);

        $component = Livewire::test(AssetsList::class)->call('openEdit', $asset->id);

        $this->assertSame('Legacy Holder', $component->viewData('assignedToLabel'));
        $component->assertSee('Legacy Holder', false);
    }

    public function test_actual_holder_on_an_audit_is_the_same_picker(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $this->makeAsset($district, ['asset_name' => 'Boardroom PC']);
        $employee = $this->makeEmployee('60001', 'Efua Owusu', $district);

        Livewire::test(AuditsList::class)->call('openCreate')->set('title', 'Stock check')->call('create')->assertHasNoErrors();
        $audit = IctAssetAudit::query()->sole();
        $line = $audit->lines()->firstOrFail();

        $component = Livewire::test(AuditShow::class, ['audit' => $audit])
            ->call('openRecord', $line->id, 'mismatch')
            ->assertSeeHtml('searchEmployees')
            ->assertSee('Actual holder');

        $this->assertSame([$employee->id], array_column($component->instance()->searchEmployees('efua'), 'value'));
    }

    public function test_phones_and_network_can_add_a_model_inline_in_their_own_category(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $phones = Livewire::test(PhonesList::class)->call('openCreate')
            ->assertSee('Add new model')
            ->set('form.asset_name', 'Typed Phone')
            ->set('form.asset_type', 'Ph')
            ->call('openNewModel')
            ->assertSet('newModel.category', 'Ph')
            ->set('newModel.name', 'Samsung Galaxy A15')
            ->call('saveNewModel')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $phone = IctAssetModel::query()->where('name', 'Samsung Galaxy A15')->firstOrFail();
        $this->assertSame('Ph', $phone->category);
        $phones->assertSet('form.ict_asset_model_id', $phone->id)
            ->assertSet('form.asset_name', 'Typed Phone')
            ->assertSet('showNewModel', false);

        $network = Livewire::test(NetworkList::class)->call('openCreate')
            ->assertSee('Add new model')
            ->set('form.asset_type', 'RT')
            ->call('openNewModel')
            ->set('newModel.name', 'MikroTik hAP')
            ->call('saveNewModel')
            ->assertHasNoErrors();

        $router = IctAssetModel::query()->where('name', 'MikroTik hAP')->firstOrFail();
        $this->assertSame('RT', $router->category);
        $network->assertSet('form.ict_asset_model_id', $router->id);

        $this->assertSame(2, AuditLog::query()->where('action', 'create_asset_model')->count());
    }

    public function test_the_inline_model_type_is_limited_to_the_screens_own_vocabulary(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        // No type chosen on the phone form, and a laptop type is posted: refused.
        Livewire::test(PhonesList::class)->call('openCreate')
            ->call('openNewModel')
            ->set('newModel.name', 'Wrong Place')
            ->set('newModel.category', 'Laptop')
            ->call('saveNewModel')
            ->assertHasErrors(['newModel.category' => 'in']);

        Livewire::test(NetworkList::class)->call('openCreate')
            ->call('openNewModel')
            ->set('newModel.name', 'Wrong Place')
            ->set('newModel.category', 'Ph')
            ->call('saveNewModel')
            ->assertHasErrors(['newModel.category' => 'in']);

        $this->assertSame(0, IctAssetModel::query()->count());
    }

    public function test_phones_and_network_hide_and_refuse_add_model_without_the_permission(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->ictUser($this->makeDistrict('Accra Central District', 'Greater Accra')));

        foreach ([PhonesList::class, NetworkList::class] as $screen) {
            Livewire::test($screen)->call('openCreate')
                ->assertDontSee('Add new model')
                ->call('openNewModel')->assertForbidden();

            Livewire::test($screen)->call('openCreate')
                ->set('newModel.name', 'Sneaky')
                ->set('newModel.category', 'Ph')
                ->call('saveNewModel')->assertForbidden();
        }

        $this->assertSame(0, IctAssetModel::query()->count());
    }
}
