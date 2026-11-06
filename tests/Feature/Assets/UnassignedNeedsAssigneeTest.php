<?php

namespace Tests\Feature\Assets;

use App\Exports\ListExport;
use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\Dashboard;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Livewire\Assets\Summary;
use App\Models\IctAsset;
use App\Services\Assets\AssetDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class UnassignedNeedsAssigneeTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    /** How every existing asset type is classified: true = issued to a person, so "unassigned" can apply. */
    private const CLASSIFICATION = [
        'PC' => true, 'AIO' => true, 'Laptop' => true, 'PRT' => true, 'PTC' => true,
        'Ph' => true, 'SIM' => true,
        'POS' => false, 'Server' => false,
        'RT' => false, 'SW' => false, 'AP' => false, 'MiFi' => false, 'P2P' => false, '4GRT' => false,
    ];

    public function test_every_asset_type_is_classified_and_the_classification_is_the_documented_one(): void
    {
        $allTypes = collect(IctAsset::ASSET_TYPES)->flatMap(fn ($types) => array_keys($types))->all();

        $this->assertEqualsCanonicalizing(array_keys(self::CLASSIFICATION), $allTypes, 'a new asset type must be classified here and in IctAsset::PERSONALLY_ASSIGNED_TYPES');

        foreach (self::CLASSIFICATION as $type => $personal) {
            $this->assertSame($personal, IctAsset::isPersonallyAssignedType($type), $type);
        }
    }

    public function test_only_devices_that_need_a_holder_and_have_none_are_unassigned(): void
    {
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $holder = $this->makeEmployee('50001', 'Holder One', $district);

        $counted = [];
        foreach (['PC', 'AIO', 'Laptop', 'PRT', 'PTC'] as $type) {
            $counted[] = $this->makeAsset($district, ['asset_type' => $type])->id;
        }
        foreach (['Ph', 'SIM'] as $type) {
            $counted[] = $this->makeAsset($district, ['asset_type' => $type, 'device_category' => 'phone'])->id;
        }

        // Never counted: servers, POS terminals (general use), network devices, a holder, general use.
        $this->makeAsset($district, ['asset_type' => 'Server']);
        $this->makeAsset($district, ['asset_type' => 'POS', 'device_category' => 'phone']);
        foreach (['RT', 'SW', 'AP', 'MiFi', 'P2P', '4GRT'] as $type) {
            $this->makeAsset($district, ['asset_type' => $type, 'device_category' => 'network']);
        }
        $this->makeAsset($district, ['asset_type' => 'Laptop', 'assigned_to_employee_id' => $holder->id]);
        $this->makeAsset($district, ['asset_type' => 'Laptop', 'is_general_use' => true]);
        $this->makeAsset($district, ['asset_type' => 'Ph', 'device_category' => 'phone', 'is_general_use' => true]);

        $this->assertEqualsCanonicalizing($counted, IctAsset::query()->needsAssignee()->pluck('id')->all());

        $row = app(AssetDashboardService::class)->unassigned(IctAsset::query());
        $this->assertSame(7, $row['total']);
        $this->assertSame(['asset' => 5, 'phone' => 2, 'network' => 0], $row['by_category']);
    }

    public function test_the_dashboard_summary_lists_and_exports_all_use_the_same_definition(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $laptop = $this->makeAsset($district, ['asset_name' => 'Spare laptop', 'asset_type' => 'Laptop']);
        $phone = $this->makeAsset($district, ['asset_name' => 'Spare phone', 'asset_type' => 'Ph', 'device_category' => 'phone']);
        $this->makeAsset($district, ['asset_name' => 'Core switch', 'asset_type' => 'SW', 'device_category' => 'network']);
        $this->makeAsset($district, ['asset_name' => 'Office printer', 'asset_type' => 'PRT']);
        $this->makeAsset($district, ['asset_name' => 'Counter POS', 'asset_type' => 'POS', 'device_category' => 'phone']);
        $this->makeAsset($district, ['asset_name' => 'Shared PC', 'asset_type' => 'PC', 'is_general_use' => true]);

        // Dashboard card and Summary page.
        $this->assertSame(3, Livewire::test(Dashboard::class)->viewData('unassigned')['total']);
        $this->assertSame(3, Livewire::withQueryParams(['tab' => 'assignment'])->test(Summary::class)->viewData('unassigned')['total']);

        // The links on the card (?assigned=none) land on lists that show exactly these rows.
        $params = Livewire::test(Dashboard::class)->viewData('unassigned')['params'];
        $names = fn ($component) => $component->viewData('assets')->getCollection()->pluck('asset_name')->sort()->values()->all();
        $this->assertSame(['Office printer', 'Spare laptop'], $names(Livewire::withQueryParams($params)->test(AssetsList::class)));
        $this->assertSame(['Spare phone'], $names(Livewire::withQueryParams($params)->test(PhonesList::class)));
        $this->assertSame([], $names(Livewire::withQueryParams($params)->test(NetworkList::class)));

        // And so do the exports of those filtered lists.
        Excel::fake();
        $this->get(route('assets.assets.export.excel', $params))->assertOk();
        Excel::assertDownloaded('assets_'.now()->format('Y_m_d').'.xlsx', function (ListExport $export) {
            $this->assertEqualsCanonicalizing(['Spare laptop', 'Office printer'], $export->collection()->pluck(0)->all());

            return true;
        });
    }

    public function test_a_regional_user_only_counts_their_own_regions_unassigned_devices(): void
    {
        $this->seedCoreAssetsAccess();
        $home = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $other = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $this->makeAsset($home, ['asset_type' => 'Laptop']);
        $this->makeAsset($other, ['asset_type' => 'Laptop']);
        $this->makeAsset($other, ['asset_type' => 'RT', 'device_category' => 'network']);
        $this->actingAs($this->ictUser($home));

        $this->assertSame(1, Livewire::test(Dashboard::class)->viewData('unassigned')['total']);
    }
}
