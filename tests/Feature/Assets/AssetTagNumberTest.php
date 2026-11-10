<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Models\Department;
use App\Models\IctAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

/** The optional "Asset Tag" (GWCL/FOE/YEAR/NUMBER) on the Assets form, shown right after Department. */
class AssetTagNumberTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_the_form_shows_tag_no_with_its_placeholder_right_after_department(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $html = Livewire::test(AssetsList::class)->call('openCreate')->html();

        $this->assertStringContainsString('Asset Tag', $html);
        $this->assertStringContainsString('placeholder="GWCL/FOE/YEAR/NUMBER"', $html);
        $this->assertLessThan(strpos($html, 'Asset Tag'), strpos($html, 'Select department'));
    }

    public function test_a_tag_number_is_saved_trimmed_and_found_by_search(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $holder = $this->makeEmployee('41001', 'Tag Holder', $district);

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Tagged Laptop', 'Laptop', $holder->id)
            ->set('form.tag_no', '  GWCL/FOE/2026/0042 ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('GWCL/FOE/2026/0042', IctAsset::query()->where('asset_name', 'Tagged Laptop')->value('tag_no'));

        Livewire::test(AssetsList::class)->set('search', 'FOE/2026/0042')->assertSee('Tagged Laptop');
    }

    public function test_the_tag_number_is_optional_and_a_blank_is_stored_as_null(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $holder = $this->makeEmployee('41002', 'No Tag Holder', $district);

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Untagged Laptop', 'Laptop', $holder->id)
            ->set('form.tag_no', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(IctAsset::query()->where('asset_name', 'Untagged Laptop')->value('tag_no'));
    }

    public function test_a_tag_number_over_100_characters_is_refused_and_editing_loads_it_back(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $holder = $this->makeEmployee('41003', 'Long Tag Holder', $district);

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district, 'Long Tag Laptop', 'Laptop', $holder->id)
            ->set('form.tag_no', str_repeat('A', 101))
            ->call('save')
            ->assertHasErrors(['form.tag_no' => 'max']);

        $asset = $this->makeAsset($district, [
            'asset_name' => 'Existing Tagged',
            'asset_type' => 'Laptop',
            'tag_no' => 'GWCL/FOE/2025/0007',
            'assigned_to_employee_id' => $holder->id,
            'ict_asset_model_id' => $this->makeModel()->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'ICT'])->id,
        ]);

        Livewire::test(AssetsList::class)->call('openEdit', $asset->id)->assertSet('form.tag_no', 'GWCL/FOE/2025/0007');
    }

    protected function fillForm($component, $district, string $name, string $type, int $holderId)
    {
        $department = Department::query()->firstOrCreate(['department_name' => 'ICT']);

        return $component
            ->set('form.asset_name', $name)
            ->set('form.serial_number', 'TG-'.uniqid())
            ->set('form.asset_type', $type)
            ->set('form.ict_asset_model_id', $this->makeModel('Tag model '.$type, $type)->id)
            ->set('form.assigned_to_employee_id', $holderId)
            ->set('form.department_id', $department->id)
            ->set('form.district_id', $district->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE);
    }
}
