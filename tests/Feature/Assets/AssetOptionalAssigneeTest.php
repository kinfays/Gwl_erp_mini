<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Models\Department;
use App\Models\IctAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

/** "Assigned To" is optional on the Assets form: leaving it empty warns first, and only a confirmation saves. */
class AssetOptionalAssigneeTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_creating_without_an_assignee_warns_first_and_saves_only_when_confirmed(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $component = $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Loose Laptop', 'Laptop')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('confirmNoAssignee', true)
            ->assertSee('Nobody is assigned to this asset')
            ->assertSee('Create anyway');

        $this->assertSame(0, IctAsset::query()->where('asset_name', 'Loose Laptop')->count());

        // "Go back" keeps the form and its values, nothing saved.
        $component->call('dismissNoAssigneeWarning')
            ->assertSet('confirmNoAssignee', false)
            ->assertSet('form.asset_name', 'Loose Laptop');
        $this->assertSame(0, IctAsset::query()->where('asset_name', 'Loose Laptop')->count());

        $component->call('saveWithoutAssignee')
            ->assertHasNoErrors()
            ->assertSet('confirmNoAssignee', false)
            ->assertSet('showForm', false);

        $asset = IctAsset::query()->where('asset_name', 'Loose Laptop')->firstOrFail();
        $this->assertNull($asset->assigned_to_employee_id);
        $this->assertSame([$asset->id], IctAsset::query()->needsAssignee()->pluck('id')->all());
    }

    public function test_confirming_still_validates_everything_else(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Half Done', 'Laptop')
            ->set('form.serial_number', '')
            ->call('saveWithoutAssignee')
            ->assertHasErrors(['form.serial_number' => 'required']);

        $this->assertSame(0, IctAsset::query()->where('asset_name', 'Half Done')->count());
    }

    public function test_no_warning_when_someone_is_assigned_or_the_type_is_not_issued_to_a_person(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $holder = $this->makeEmployee('42001', 'Has Holder', $district);
        $this->actingAs($this->superAdmin($district));

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Held Laptop', 'Laptop')
            ->set('form.assigned_to_employee_id', $holder->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('confirmNoAssignee', false);
        $this->assertSame($holder->id, IctAsset::query()->where('asset_name', 'Held Laptop')->value('assigned_to_employee_id'));

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Rack Server', 'Server')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('confirmNoAssignee', false);
        $this->assertSame(1, IctAsset::query()->where('asset_name', 'Rack Server')->count());
    }

    public function test_updating_an_asset_to_have_no_assignee_warns_with_the_update_wording(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $holder = $this->makeEmployee('42002', 'Soon Gone', $district);
        $this->actingAs($this->superAdmin($district));
        $asset = $this->makeAsset($district, [
            'asset_name' => 'Edit Me',
            'asset_type' => 'Laptop',
            'assigned_to_employee_id' => $holder->id,
            'ict_asset_model_id' => $this->makeModel()->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'ICT'])->id,
        ]);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)
            ->set('form.assigned_to_employee_id', null)
            ->call('save')
            ->assertSet('confirmNoAssignee', true)
            ->assertSee('Update anyway');
        $this->assertSame($holder->id, $asset->fresh()->assigned_to_employee_id);
    }

    protected function fillForm($component, $district, string $name, string $type)
    {
        $department = Department::query()->firstOrCreate(['department_name' => 'ICT']);

        return $component
            ->set('form.asset_name', $name)
            ->set('form.serial_number', 'OA-'.uniqid())
            ->set('form.asset_type', $type)
            ->set('form.ict_asset_model_id', $this->makeModel('Optional model '.$type, $type)->id)
            ->set('form.department_id', $department->id)
            ->set('form.district_id', $district->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE);
    }
}
