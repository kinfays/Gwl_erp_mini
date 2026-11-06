<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\IssueReports;
use App\Livewire\Assets\MaintenanceLog;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

/** What the browser is handed by <x-form.searchable-select> (the typing itself is covered by the manual checklist). */
class SearchableSelectRenderTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_the_model_picker_is_the_searchable_select_and_lists_models_with_their_manufacturer(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());
        $this->makeModel('HP EliteDesk 800 G6', 'PC');

        Livewire::test(AssetsList::class)->call('openCreate')->set('form.asset_type', 'PC')
            ->assertSeeHtml('searchableSelect(')
            ->assertSeeHtml('role="combobox"')
            ->assertSee('Search models')
            ->assertSee('No models found')
            ->assertSeeHtml('HP EliteDesk 800 G6');
    }

    public function test_model_names_are_escaped_and_never_become_markup(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());
        $this->makeModel('"><script>alert(1)</script>', 'PC');

        Livewire::test(AssetsList::class)->call('openCreate')->set('form.asset_type', 'PC')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_the_maintenance_asset_picker_is_disabled_until_a_type_is_chosen(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(MaintenanceLog::class)->call('openCreate');
        $this->assertMatchesRegularExpression('/id="ss-form-ict-asset-id"[^>]*\sdisabled/', $component->html());

        $component->set('form.asset_type', 'Laptop');
        $this->assertDoesNotMatchRegularExpression('/id="ss-form-ict-asset-id"[^>]*\sdisabled/', $component->html());
        $component->assertSeeHtml('searchAssets');
    }

    public function test_every_other_asset_or_model_dropdown_uses_the_same_component(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(PhonesList::class)->call('openCreate')->assertSeeHtml('searchableSelect(')->assertSee('Search models');
        Livewire::test(NetworkList::class)->call('openCreate')->assertSeeHtml('searchableSelect(')->assertSee('Search models');
        Livewire::test(IssueReports::class)->call('openCreate')->assertSeeHtml('searchLinkedAssets');
    }

    public function test_the_linked_asset_search_is_scoped_and_limited_like_the_maintenance_one(): void
    {
        $this->seedCoreAssetsAccess();
        $home = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $other = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $mine = $this->makeAsset($home, ['asset_name' => 'Mine PC']);
        $this->makeAsset($other, ['asset_name' => 'Their PC']);
        $this->actingAs($this->ictUser($home));

        $component = Livewire::test(IssueReports::class)->call('openCreate');

        $this->assertSame([$mine->id], array_column($component->instance()->searchLinkedAssets(''), 'value'));
        $this->assertSame([], $component->instance()->searchLinkedAssets('%'));
    }
}
