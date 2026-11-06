<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Models\AuditLog;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class AddModelFromAssetFormTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_a_new_model_is_created_selected_and_the_rest_of_the_form_is_kept(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $maker = IctAssetManufacturer::query()->create(['name' => 'Lenovo', 'is_active' => true]);

        $component = Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_name', 'Typed Name')
            ->set('form.serial_number', 'TYPED-1')
            ->set('form.asset_type', 'Laptop')
            ->call('openNewModel')
            ->assertSet('showNewModel', true)
            ->assertSet('newModel.category', 'Laptop')
            ->set('newModel.name', 'ThinkPad T14')
            ->set('newModel.manufacturer_id', $maker->id)
            ->call('saveNewModel')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $model = IctAssetModel::query()->where('name', 'ThinkPad T14')->firstOrFail();
        $this->assertSame('Laptop', $model->category);
        $this->assertSame($maker->id, $model->ict_asset_manufacturer_id);
        $this->assertTrue($model->is_active);

        $component
            ->assertSet('form.ict_asset_model_id', $model->id)
            ->assertSet('form.asset_name', 'Typed Name')
            ->assertSet('form.serial_number', 'TYPED-1')
            ->assertSet('form.asset_type', 'Laptop')
            ->assertSet('showForm', true)
            ->assertSet('showNewModel', false);

        $this->assertTrue(AuditLog::query()->where('action', 'create_asset_model')->where('target_id', $model->id)->exists());
        $this->assertContains($model->id, $component->viewData('models')->pluck('id')->all());
    }

    public function test_with_no_type_chosen_yet_the_models_type_becomes_the_forms_type(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(AssetsList::class)->call('openCreate')
            ->call('openNewModel')
            ->set('newModel.name', 'Dell OptiPlex')
            ->call('saveNewModel')
            ->assertHasErrors(['newModel.category' => 'required'])
            ->set('newModel.category', 'PC')
            ->call('saveNewModel')
            ->assertHasNoErrors()
            ->assertSet('form.asset_type', 'PC');
    }

    public function test_the_modal_type_follows_the_form_type_and_cannot_be_tampered_with(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_type', 'PC')
            ->call('openNewModel')
            ->set('newModel.name', 'Some PC')
            ->set('newModel.category', 'RT')
            ->call('saveNewModel')
            ->assertHasNoErrors();

        $this->assertSame('PC', IctAssetModel::query()->where('name', 'Some PC')->value('category'));
    }

    public function test_validation_errors_show_inside_the_panel_and_duplicates_are_refused(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());
        $this->makeModel('Existing Model', 'PC');
        $inactive = IctAssetManufacturer::query()->create(['name' => 'Retired Maker', 'is_active' => false]);

        Livewire::test(AssetsList::class)->call('openCreate')
            ->set('form.asset_type', 'PC')
            ->call('openNewModel')
            ->call('saveNewModel')
            ->assertHasErrors(['newModel.name' => 'required'])
            ->set('newModel.name', 'Existing Model')
            ->call('saveNewModel')
            ->assertHasErrors(['newModel.name' => 'unique'])
            ->set('newModel.name', 'Fresh Model')
            ->set('newModel.manufacturer_id', $inactive->id)
            ->call('saveNewModel')
            ->assertHasErrors(['newModel.manufacturer_id' => 'exists']);

        $this->assertSame(1, IctAssetModel::query()->count());
    }

    public function test_users_without_the_manage_models_permission_do_not_see_the_action_and_the_server_refuses_it(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        // ict_team may create and edit assets but is not given assets.manage_models.
        $this->actingAs($this->ictUser($district));

        Livewire::test(AssetsList::class)->call('openCreate')
            ->assertDontSee('Add new model')
            ->call('openNewModel')->assertForbidden();

        Livewire::test(AssetsList::class)->call('openCreate')
            ->set('newModel.name', 'Sneaky')
            ->set('newModel.category', 'PC')
            ->call('saveNewModel')->assertForbidden();

        $this->assertSame(0, IctAssetModel::query()->count());
    }

    public function test_the_action_shows_for_users_allowed_to_manage_models(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(AssetsList::class)->call('openCreate')->assertSee('Add new model');
    }
}
