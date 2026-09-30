<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AgentReports;
use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\Dashboard;
use App\Livewire\Assets\IssueReports;
use App\Livewire\Assets\MaintenanceLog;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\AgentReport;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetMaintenance;
use App\Models\MdmDevice;
use App\Models\User;
use App\Services\Assets\Mdm\MdmAccessGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * The Head Office ICT team can list and filter every region's assets; a regional ICT user still sees only their own
 * region. Seeing is wider than doing: creating and editing stay inside the ICT user's own region, and MDM (which can
 * lock and reset phones) is unchanged.
 */
class HeadOfficeIctAssetsTest extends TestCase
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

        Notification::fake();
        $this->buildUacOrg();

        $this->headOfficeIct = $this->person('ICT001', $this->headOffice, ['ict_team']);
        $this->accraIct = $this->person('ICT002', $this->temaDistrict, ['ict_team']);
        $this->kumasiIct = $this->person('ICT003', $this->kumasiOffice, ['ict_team']);
        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);

        $this->accraLaptop = $this->asset($this->temaDistrict, 'Accra Laptop');
        $this->kumasiLaptop = $this->asset($this->obuasiDistrict, 'Kumasi Laptop');
    }

    protected function asset($district, string $name, string $kind = 'asset'): IctAsset
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
        ]);
    }

    public function test_the_head_office_ict_team_sees_every_regions_assets_and_regional_ict_only_their_own(): void
    {
        Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->assertSee('Accra Laptop')
            ->assertSee('Kumasi Laptop');

        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->assertSee('Accra Laptop')
            ->assertDontSee('Kumasi Laptop');

        Livewire::actingAs($this->kumasiIct)->test(AssetsList::class)
            ->assertSee('Kumasi Laptop')
            ->assertDontSee('Accra Laptop');

        Livewire::actingAs($this->super)->test(AssetsList::class)
            ->assertSee('Accra Laptop')
            ->assertSee('Kumasi Laptop');
    }

    public function test_the_head_office_ict_team_can_filter_by_any_district_in_any_region(): void
    {
        $screen = Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->assertSee('Tema District (Greater Accra)')
            ->assertSee('Obuasi District (Ashanti)');

        $screen->set('districtId', (string) $this->obuasiDistrict->id)
            ->assertSee('Kumasi Laptop')
            ->assertDontSee('Accra Laptop');

        $screen->set('districtId', (string) $this->temaDistrict->id)
            ->assertSee('Accra Laptop')
            ->assertDontSee('Kumasi Laptop');

        // A regional ICT user is offered only their own region's districts.
        Livewire::actingAs($this->accraIct)->test(AssetsList::class)
            ->assertSee('Tema District')
            ->assertDontSee('Obuasi District');
    }

    public function test_phones_network_devices_and_the_dashboard_follow_the_same_rule(): void
    {
        $this->asset($this->temaDistrict, 'Accra Phone', 'phone');
        $this->asset($this->obuasiDistrict, 'Kumasi Phone', 'phone');
        $this->asset($this->temaDistrict, 'Accra Router', 'network');
        $this->asset($this->obuasiDistrict, 'Kumasi Router', 'network');

        Livewire::actingAs($this->headOfficeIct)->test(PhonesList::class)->assertSee('Accra Phone')->assertSee('Kumasi Phone');
        Livewire::actingAs($this->kumasiIct)->test(PhonesList::class)->assertSee('Kumasi Phone')->assertDontSee('Accra Phone');

        Livewire::actingAs($this->headOfficeIct)->test(NetworkList::class)->assertSee('Accra Router')->assertSee('Kumasi Router');
        Livewire::actingAs($this->accraIct)->test(NetworkList::class)->assertSee('Accra Router')->assertDontSee('Kumasi Router');

        Livewire::actingAs($this->headOfficeIct)->test(Dashboard::class)
            ->assertViewHas('recentAssets', fn ($assets) => $assets->count() === 6);
        Livewire::actingAs($this->accraIct)->test(Dashboard::class)
            ->assertViewHas('recentAssets', fn ($assets) => $assets->count() === 3);
    }

    public function test_maintenance_issue_and_agent_reports_are_listed_for_every_region_too(): void
    {
        IctAssetMaintenance::factory()->create(['ict_asset_id' => $this->accraLaptop->id, 'technician' => 'Tech Accra']);
        IctAssetMaintenance::factory()->create(['ict_asset_id' => $this->kumasiLaptop->id, 'technician' => 'Tech Kumasi']);
        IctAssetIssueReport::factory()->create(['title' => 'Accra issue', 'reporting_region_id' => $this->accra->id]);
        IctAssetIssueReport::factory()->create(['title' => 'Kumasi issue', 'reporting_region_id' => $this->ashanti->id]);

        Livewire::actingAs($this->headOfficeIct)->test(MaintenanceLog::class)->assertSee('Tech Accra')->assertSee('Tech Kumasi');
        Livewire::actingAs($this->kumasiIct)->test(MaintenanceLog::class)->assertSee('Tech Kumasi')->assertDontSee('Tech Accra');

        Livewire::actingAs($this->headOfficeIct)->test(IssueReports::class)->assertSee('Accra issue')->assertSee('Kumasi issue');
        Livewire::actingAs($this->accraIct)->test(IssueReports::class)->assertSee('Accra issue')->assertDontSee('Kumasi issue');

        Livewire::actingAs($this->headOfficeIct)->test(AgentReports::class)->assertOk();
    }

    public function test_seeing_every_region_does_not_mean_changing_every_region(): void
    {
        // Rows in another region show as read-only; the actor's own region keeps its edit button.
        Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->assertSeeHtml('wire:click="openEdit('.$this->accraLaptop->id.')"')
            ->assertDontSeeHtml('wire:click="openEdit('.$this->kumasiLaptop->id.')"')
            ->assertSee('Read only');

        // And the server refuses it however the call is made: the write scope is unchanged.
        Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->call('openEdit', $this->kumasiLaptop->id)
            ->assertNotFound();

        Livewire::actingAs($this->headOfficeIct)->test(AssetsList::class)
            ->call('openEdit', $this->accraLaptop->id)
            ->assertHasNoErrors()
            ->assertSet('editingAssetId', $this->accraLaptop->id);

        // A maintenance record in another region can be read, not edited.
        $record = IctAssetMaintenance::factory()->create(['ict_asset_id' => $this->kumasiLaptop->id]);
        Livewire::actingAs($this->headOfficeIct)->test(MaintenanceLog::class)
            ->assertDontSeeHtml('wire:click="openEdit('.$record->id.')"')
            ->call('openEdit', $record->id)
            ->assertNotFound();
    }

    public function test_unscoped_actors_keep_their_edit_buttons_everywhere(): void
    {
        Livewire::actingAs($this->super)->test(AssetsList::class)
            ->assertSeeHtml('wire:click="openEdit('.$this->accraLaptop->id.')"')
            ->assertSeeHtml('wire:click="openEdit('.$this->kumasiLaptop->id.')"');
    }

    public function test_mdm_is_unchanged_a_head_office_ict_user_still_only_reaches_their_own_regions_phones(): void
    {
        $accraPhone = $this->asset($this->temaDistrict, 'Accra Phone', 'phone');
        $kumasiPhone = $this->asset($this->obuasiDistrict, 'Kumasi Phone', 'phone');
        $accraDevice = MdmDevice::factory()->forAsset($accraPhone)->create();
        $kumasiDevice = MdmDevice::factory()->forAsset($kumasiPhone)->create();

        $guard = app(MdmAccessGuard::class);
        $this->assertSame([$accraDevice->id], $guard->devices($this->headOfficeIct)->pluck('id')->all());
        $this->assertTrue($guard->isRegionScopedIct($this->headOfficeIct));

        // The phone is listed, but its MDM record isn't linked to someone who can't open it.
        Livewire::actingAs($this->headOfficeIct)->test(PhonesList::class)
            ->assertSee('Kumasi Phone')
            ->assertSeeHtml(route('assets.mdm.devices.show', $accraDevice->id))
            ->assertDontSeeHtml(route('assets.mdm.devices.show', $kumasiDevice->id));
    }
}
