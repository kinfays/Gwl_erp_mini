<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * The Model filter (several at once, limited to models on the list's own visible assets), the search box matching the
 * model (and "Manufacturer Model"), and the multi-select District filter on all three lists and their exports. Whatever
 * ids the browser sends are cut down to what the person may see.
 */
class AssetModelAndDistrictFilterTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $headOfficeIct;

    protected User $accraIct;

    protected User $kumasiIct;

    protected IctAssetModel $elitebook;

    protected IctAssetModel $thinkpad;

    protected IctAssetModel $galaxy;

    protected IctAssetModel $kumasiOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildUacOrg();

        $this->headOfficeIct = $this->person('ICT001', $this->headOffice, ['ict_team']);
        $this->accraIct = $this->person('ICT002', $this->temaDistrict, ['ict_team']);
        $this->kumasiIct = $this->person('ICT003', $this->kumasiOffice, ['ict_team']);

        $hp = IctAssetManufacturer::query()->create(['name' => 'HP', 'is_active' => true]);
        $lenovo = IctAssetManufacturer::query()->create(['name' => 'Lenovo', 'is_active' => true]);
        $samsung = IctAssetManufacturer::query()->create(['name' => 'Samsung', 'is_active' => true]);

        $this->elitebook = IctAssetModel::query()->create(['name' => 'EliteBook 840', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $hp->id, 'is_active' => true]);
        $this->thinkpad = IctAssetModel::query()->create(['name' => 'ThinkPad T14', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $lenovo->id, 'is_active' => true]);
        $this->kumasiOnly = IctAssetModel::query()->create(['name' => 'ProBook 450', 'category' => 'Laptop', 'ict_asset_manufacturer_id' => $hp->id, 'is_active' => true]);
        $this->galaxy = IctAssetModel::query()->create(['name' => 'Galaxy A15', 'category' => 'Ph', 'ict_asset_manufacturer_id' => $samsung->id, 'is_active' => true]);

        $this->make('Tema EliteBook', $this->temaDistrict, $this->elitebook);
        $this->make('Tema ThinkPad', $this->temaDistrict, $this->thinkpad);
        $this->make('Obuasi ProBook', $this->obuasiDistrict, $this->kumasiOnly);
        $this->make('Tema Galaxy', $this->temaDistrict, $this->galaxy, 'phone', ['asset_type' => 'Ph']);
        $this->make('Obuasi Router', $this->obuasiDistrict, null, 'network');
    }

    protected function make(string $name, $district, ?IctAssetModel $model, string $kind = 'asset', array $overrides = []): IctAsset
    {
        $factory = match ($kind) {
            'phone' => IctAsset::factory()->phone(),
            'network' => IctAsset::factory()->network(),
            default => IctAsset::factory(),
        };

        return $factory->create([
            'asset_name' => $name,
            'asset_type' => $kind === 'asset' ? 'Laptop' : ($overrides['asset_type'] ?? 'RT'),
            'ict_asset_model_id' => $model?->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            ...$overrides,
        ]);
    }

    // ---- model filter and search -----------------------------------------------------------------------------------

    public function test_the_model_filter_takes_several_models(): void
    {
        $screen = Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->assertSee('Tema EliteBook')->assertSee('Tema ThinkPad')->assertSee('Obuasi ProBook');

        $screen->set('modelIds', [$this->elitebook->id])
            ->assertSee('Tema EliteBook')->assertDontSee('Tema ThinkPad')->assertDontSee('Obuasi ProBook');

        $screen->set('modelIds', [$this->elitebook->id, $this->kumasiOnly->id])
            ->assertSee('Tema EliteBook')->assertSee('Obuasi ProBook')->assertDontSee('Tema ThinkPad');

        $screen->call('clearModels')->assertSee('Tema ThinkPad');
        $screen->call('toggleModel', (string) $this->thinkpad->id)->assertSet('modelIds', [$this->thinkpad->id])->assertDontSee('Tema EliteBook');
    }

    public function test_model_options_follow_the_list_category_and_the_viewing_scope(): void
    {
        // Assets list: laptop models only; the phone model is not offered there.
        $assetsHead = Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)->viewData('modelOptions');
        $this->assertEqualsCanonicalizing(['HP EliteBook 840', 'Lenovo ThinkPad T14', 'HP ProBook 450'], array_values($assetsHead));

        // A regional user is offered only the models of assets in their own region.
        $assetsAccra = Livewire::actingAs($this->accraIct)->test(AssetsList::class)->viewData('modelOptions');
        $this->assertEqualsCanonicalizing(['HP EliteBook 840', 'Lenovo ThinkPad T14'], array_values($assetsAccra));

        // Phones list: the phone model only.
        $this->assertSame(['Samsung Galaxy A15'], array_values(Livewire::actingAs($this->headOfficeIct)->test(PhonesList::class)->viewData('modelOptions')));
        // Nobody in Kumasi sees an Accra phone model.
        $this->assertSame([], array_values(Livewire::actingAs($this->kumasiIct)->test(PhonesList::class)->viewData('modelOptions')));
    }

    public function test_a_model_id_that_is_not_on_this_list_or_not_visible_is_ignored(): void
    {
        // The phone model sent to the assets list, and Kumasi's model sent by a regional Accra user: neither filters.
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->set('modelIds', [$this->galaxy->id, $this->kumasiOnly->id, 999999])
            ->assertSee('Tema EliteBook')->assertSee('Tema ThinkPad')
            ->assertDontSee('Obuasi ProBook')
            ->assertViewHas('selectedModels', []);
    }

    public function test_search_matches_the_model_and_the_manufacturer_and_model_together(): void
    {
        $screen = Livewire::actingAs($this->headOfficeIct)->test(PhonesList::class);

        $screen->set('search', 'galaxy')->assertSee('Tema Galaxy');
        $screen->set('search', 'Samsung A15')->assertSee('Tema Galaxy');
        $screen->set('search', 'samsung')->assertSee('Tema Galaxy');
        $screen->set('search', 'Lenovo A15')->assertDontSee('Tema Galaxy');

        $assets = Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class);
        $assets->set('search', 'hp elite')->assertSee('Tema EliteBook')->assertDontSee('Tema ThinkPad');
        $assets->set('search', 'lenovo')->assertSee('Tema ThinkPad')->assertDontSee('Tema EliteBook');
    }

    public function test_percent_and_underscore_in_the_search_are_plain_text(): void
    {
        Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->set('search', '%')->assertDontSee('Tema EliteBook')->assertDontSee('Tema ThinkPad')
            ->set('search', 'Tema_Elite')->assertDontSee('Tema EliteBook');

        IctAsset::factory()->create(['asset_name' => '100% spare', 'asset_type' => 'PC', 'region_id' => $this->accra->id, 'district_id' => $this->temaDistrict->id]);

        Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->set('search', '100%')->assertSee('100% spare');
    }

    // ---- district multi-select -------------------------------------------------------------------------------------

    public function test_the_district_filter_takes_several_districts_on_every_list(): void
    {
        $lists = [
            AssetsList::class => [['Tema EliteBook', 'Tema ThinkPad'], ['Obuasi ProBook']],
            PhonesList::class => [['Tema Galaxy'], []],
            NetworkList::class => [[], ['Obuasi Router']],
        ];

        foreach ($lists as $component => [$tema, $obuasi]) {
            $screen = Livewire::actingAs($this->headOfficeIct)->test($component);

            $screen->set('districtIds', [$this->temaDistrict->id]);
            foreach ($tema as $name) {
                $screen->assertSee($name);
            }
            foreach ($obuasi as $name) {
                $screen->assertDontSee($name);
            }

            $screen->set('districtIds', [$this->temaDistrict->id, $this->obuasiDistrict->id]);
            foreach ([...$tema, ...$obuasi] as $name) {
                $screen->assertSee($name);
            }

            $screen->assertViewHas('selectedDistricts', [(string) $this->temaDistrict->id, (string) $this->obuasiDistrict->id]);
        }
    }

    public function test_a_district_outside_the_persons_view_is_ignored_not_obeyed(): void
    {
        // A regional Accra user hand-edits ?districts[]= to Obuasi (Ashanti): the id is dropped, they still see only Accra rows.
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->set('districtIds', [$this->obuasiDistrict->id])
            ->assertViewHas('selectedDistricts', [])
            ->assertSee('Tema EliteBook')
            ->assertDontSee('Obuasi ProBook');

        // Mixed: only the permitted one survives.
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->set('districtIds', [$this->obuasiDistrict->id, $this->temaDistrict->id, 'abc', -4, 0])
            ->assertViewHas('selectedDistricts', [(string) $this->temaDistrict->id]);

        // And the options they are offered never name another region.
        $options = Livewire::actingAs($this->accraIct)->test(AssetsList::class)->viewData('districtOptions');
        $this->assertArrayNotHasKey((string) $this->obuasiDistrict->id, $options);
        $this->assertArrayHasKey((string) $this->temaDistrict->id, $options);
    }

    public function test_head_office_is_a_district_and_head_office_ict_may_pick_it_beside_any_region(): void
    {
        IctAsset::factory()->create(['asset_name' => 'Head Office PC', 'asset_type' => 'PC', 'region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id]);

        $options = Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)->viewData('districtOptions');
        $this->assertContains('Head Office (Greater Accra)', $options);
        $this->assertContains('Obuasi District (Ashanti)', $options);

        Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->set('districtIds', [$this->headOffice->id])
            ->assertSee('Head Office PC')->assertDontSee('Tema EliteBook');

        // select all / clear
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->call('selectAllDistricts')
            ->assertViewHas('selectedDistricts', fn ($ids) => count($ids) === count(array_unique($ids)) && ! in_array((string) $this->obuasiDistrict->id, $ids, true) && in_array((string) $this->temaDistrict->id, $ids, true))
            ->call('clearDistricts')
            ->assertSet('districtIds', []);
    }

    public function test_an_old_single_district_link_still_works(): void
    {
        Livewire::withQueryParams(['district' => (string) $this->obuasiDistrict->id])->actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->assertSee('Obuasi ProBook')->assertDontSee('Tema EliteBook')
            ->assertSet('districtIds', [$this->obuasiDistrict->id])
            ->assertSet('legacyDistrict', '');

        Livewire::withQueryParams(['district_id' => (string) $this->temaDistrict->id])->actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->assertSee('Tema EliteBook')->assertDontSee('Obuasi ProBook');

        // And it is cut down to the person's view like any other.
        Livewire::withQueryParams(['district' => (string) $this->obuasiDistrict->id])->actingAs($this->accraIct)->test(AssetsList::class)
            ->assertSee('Tema EliteBook')->assertDontSee('Obuasi ProBook');
    }

    public function test_the_filters_live_in_the_url_and_survive_page_changes_and_other_filters(): void
    {
        $component = Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->set('districtIds', [$this->temaDistrict->id])
            ->set('modelIds', [$this->elitebook->id])
            ->set('search', 'Tema');

        $component->assertSee('Tema EliteBook')->assertDontSee('Tema ThinkPad');
        $component->assertViewHas('exportParams', fn ($params) => $params['districts'] === [$this->temaDistrict->id] && $params['models'] === [$this->elitebook->id] && $params['q'] === 'Tema');
        $component->assertViewHas('filtersActive', true);
    }

    // ---- exports ----------------------------------------------------------------------------------------------------

    protected function names($response): array
    {
        $response->assertOk();
        $rows = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);

        return collect($rows)->skip(1)->pluck(0)->map(fn ($cell) => trim(explode("\n", (string) $cell)[0]))->sort()->values()->all();
    }

    public function test_the_exports_honour_the_district_and_model_filters(): void
    {
        $this->actingAs($this->headOfficeIct);

        $this->assertSame(['Tema EliteBook'], $this->names($this->get(route('assets.assets.export.excel', ['districts' => [$this->temaDistrict->id], 'models' => [$this->elitebook->id]]))));
        $this->assertSame(['Obuasi ProBook', 'Tema EliteBook'], $this->names($this->get(route('assets.assets.export.excel', ['models' => [$this->elitebook->id, $this->kumasiOnly->id]]))));
        $this->assertSame(['Tema Galaxy'], $this->names($this->get(route('assets.phones.export.excel', ['districts' => [$this->temaDistrict->id]]))));
        $this->assertSame(['Obuasi Router'], $this->names($this->get(route('assets.network.export.excel', ['districts' => [$this->obuasiDistrict->id]]))));
        $this->assertSame([], $this->names($this->get(route('assets.network.export.excel', ['districts' => [$this->temaDistrict->id]]))));

        // Search by model reaches the file too.
        $this->assertSame(['Tema Galaxy'], $this->names($this->get(route('assets.phones.export.excel', ['q' => 'Samsung A15']))));

        // The audit row of the download records the ids that were actually applied.
        $log = AuditLog::query()->where('action', 'export_assets_excel')->get()->first(fn ($l) => ($l->new_values['filters']['districts'] ?? null) === [$this->temaDistrict->id]);
        $this->assertNotNull($log);
    }

    public function test_a_tampered_district_in_an_export_link_is_ignored(): void
    {
        $this->actingAs($this->accraIct);

        // Asking for Obuasi as an Accra user neither leaks Kumasi rows nor empties the file: the id is dropped.
        $this->assertSame(['Tema EliteBook', 'Tema ThinkPad'], $this->names($this->get(route('assets.assets.export.excel', ['districts' => [$this->obuasiDistrict->id]]))));
        $this->assertSame(['Tema EliteBook', 'Tema ThinkPad'], $this->names($this->get(route('assets.assets.export.excel', ['district' => $this->obuasiDistrict->id]))));
        // A model from another region is ignored the same way.
        $this->assertSame(['Tema EliteBook', 'Tema ThinkPad'], $this->names($this->get(route('assets.assets.export.excel', ['models' => [$this->kumasiOnly->id]]))));
    }
}
