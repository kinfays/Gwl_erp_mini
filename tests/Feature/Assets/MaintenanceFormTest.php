<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\MaintenanceLog;
use App\Models\AuditLog;
use App\Models\IctAssetMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class MaintenanceFormTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_the_asset_picker_offers_nothing_until_a_type_is_chosen_and_then_only_that_type(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $laptop = $this->makeAsset($district, ['asset_name' => 'Dell Latitude', 'asset_type' => 'Laptop']);
        $this->makeAsset($district, ['asset_name' => 'HP Desktop', 'asset_type' => 'PC']);

        $component = Livewire::test(MaintenanceLog::class)->call('openCreate')
            ->assertSee('Choose the asset type first.');

        $this->assertSame([], $component->instance()->searchAssets(''));

        $component->set('form.asset_type', 'Laptop');
        $found = $component->instance()->searchAssets('');
        $this->assertSame([$laptop->id], array_column($found, 'value'));
        $this->assertSame('Dell Latitude', $found[0]['label']);
        $this->assertStringContainsString($laptop->serial_number, $found[0]['description']);
    }

    public function test_search_matches_name_or_serial_number_escapes_wildcards_and_returns_at_most_20(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $byName = $this->makeAsset($district, ['asset_name' => 'Finance Laptop', 'serial_number' => 'ZZ-1']);
        $bySerial = $this->makeAsset($district, ['asset_name' => 'Other', 'serial_number' => 'ABC-777']);

        $component = Livewire::test(MaintenanceLog::class)->call('openCreate')->set('form.asset_type', 'Laptop');

        $this->assertSame([$byName->id], array_column($component->instance()->searchAssets('finance'), 'value'));
        $this->assertSame([$bySerial->id], array_column($component->instance()->searchAssets('abc-777'), 'value'));
        // A typed % or _ is text, not a wildcard.
        $this->assertSame([], $component->instance()->searchAssets('%'));
        $this->assertSame([], $component->instance()->searchAssets('F_nance'));

        foreach (range(1, 25) as $i) {
            $this->makeAsset($district, ['asset_name' => 'Bulk '.$i]);
        }

        $this->assertCount(20, $component->instance()->searchAssets('Bulk'));
    }

    public function test_search_is_confined_to_the_actors_own_region(): void
    {
        $this->seedCoreAssetsAccess();
        $home = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $other = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $mine = $this->makeAsset($home, ['asset_name' => 'Mine']);
        $this->makeAsset($other, ['asset_name' => 'Theirs']);
        $this->actingAs($this->ictUser($home));

        $component = Livewire::test(MaintenanceLog::class)->call('openCreate')->set('form.asset_type', 'Laptop');

        $this->assertSame([$mine->id], array_column($component->instance()->searchAssets(''), 'value'));
    }

    public function test_changing_the_type_clears_the_selected_asset(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $laptop = $this->makeAsset($district, ['asset_type' => 'Laptop']);

        Livewire::test(MaintenanceLog::class)->call('openCreate')
            ->set('form.asset_type', 'Laptop')
            ->set('form.ict_asset_id', $laptop->id)
            ->set('form.asset_type', 'PC')
            ->assertSet('form.ict_asset_id', null);
    }

    public function test_the_server_refuses_an_asset_of_another_type_or_outside_the_actors_region(): void
    {
        $this->seedCoreAssetsAccess();
        $home = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $other = $this->makeDistrict('Kumasi Central District', 'Ashanti');
        $laptop = $this->makeAsset($home, ['asset_type' => 'Laptop']);
        $foreign = $this->makeAsset($other, ['asset_type' => 'Laptop']);
        $this->actingAs($this->ictUser($home));

        // Type says PC, asset is a laptop.
        $this->filled(Livewire::test(MaintenanceLog::class)->call('openCreate'), 'PC', $laptop->id)
            ->call('save')->assertHasErrors(['form.ict_asset_id']);

        // Right type, but the asset belongs to another region.
        $this->filled(Livewire::test(MaintenanceLog::class)->call('openCreate'), 'Laptop', $foreign->id)
            ->call('save')->assertHasErrors(['form.ict_asset_id']);

        $this->assertSame(0, IctAssetMaintenance::query()->count());

        $this->filled(Livewire::test(MaintenanceLog::class)->call('openCreate'), 'Laptop', $laptop->id)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1, IctAssetMaintenance::query()->count());
    }

    public function test_the_form_has_no_location_and_the_list_shows_the_assets_location(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $asset = $this->makeAsset($district);

        $component = Livewire::test(MaintenanceLog::class)->call('openCreate');
        $this->assertArrayNotHasKey('location', $component->get('form'));

        $this->filled($component, 'Laptop', $asset->id)->call('save')->assertHasNoErrors();

        $this->assertNull(IctAssetMaintenance::query()->firstOrFail()->location);

        Livewire::test(MaintenanceLog::class)->assertSee('Accra Central District');
    }

    public function test_completion_date_appears_prefilled_with_today_only_for_completed_and_is_cleared_when_left(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $component = Livewire::test(MaintenanceLog::class)->call('openCreate')
            ->assertSet('form.completion_date', null)
            ->assertDontSee('Completion Date')
            ->set('form.status', 'Completed')
            ->assertSet('form.completion_date', today()->toDateString())
            ->assertSee('Completion Date')
            ->set('form.completion_date', today()->subDay()->toDateString())
            ->set('form.status', 'In Progress')
            ->assertSet('form.completion_date', null)
            ->assertDontSee('Completion Date');
    }

    public function test_completed_needs_a_completion_date_not_before_reported_and_not_in_the_future(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $asset = $this->makeAsset($district);

        $component = fn () => $this->filled(Livewire::test(MaintenanceLog::class)->call('openCreate'), 'Laptop', $asset->id)
            ->set('form.status', 'Completed')
            ->set('form.reported_date', today()->subDays(3)->toDateString());

        $component()->set('form.completion_date', null)->call('save')->assertHasErrors(['form.completion_date' => 'required']);
        $component()->set('form.completion_date', today()->subDays(5)->toDateString())->call('save')->assertHasErrors(['form.completion_date' => 'after_or_equal']);
        $component()->set('form.completion_date', today()->addDay()->toDateString())->call('save')->assertHasErrors(['form.completion_date' => 'before_or_equal']);
        $this->assertSame(0, IctAssetMaintenance::query()->count());

        $component()->set('form.completion_date', today()->subDay()->toDateString())->call('save')->assertHasNoErrors();

        $record = IctAssetMaintenance::query()->firstOrFail();
        $this->assertSame(today()->subDay()->toDateString(), $record->completion_date->toDateString());
        $this->assertSame(today()->subDays(3)->toDateString(), $record->reported_date->toDateString());
    }

    public function test_a_completion_date_sent_for_a_record_that_is_not_completed_is_ignored(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $asset = $this->makeAsset($district);

        $this->filled(Livewire::test(MaintenanceLog::class)->call('openCreate'), 'Laptop', $asset->id)
            ->set('form.status', 'In Progress')
            ->set('form.completion_date', today()->addYear()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(IctAssetMaintenance::query()->firstOrFail()->completion_date);
    }

    public function test_editing_a_completed_record_shows_its_stored_date_and_never_overwrites_it_with_today(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $asset = $this->makeAsset($district);
        $record = IctAssetMaintenance::query()->create([
            'ict_asset_id' => $asset->id,
            'maintenance_type' => 'Repair',
            'status' => 'Completed',
            'reported_date' => today()->subDays(10)->toDateString(),
            'completion_date' => today()->subDays(4)->toDateString(),
        ]);

        Livewire::test(MaintenanceLog::class)
            ->call('openEdit', $record->id)
            ->assertSet('form.asset_type', 'Laptop')
            ->assertSet('form.completion_date', today()->subDays(4)->toDateString())
            // Away and back again restores the stored date instead of today's.
            ->set('form.status', 'Open')
            ->assertSet('form.completion_date', null)
            ->set('form.status', 'Completed')
            ->assertSet('form.completion_date', today()->subDays(4)->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(today()->subDays(4)->toDateString(), $record->fresh()->completion_date->toDateString());
    }

    public function test_saving_writes_an_audit_row_with_ids_and_dates_but_no_free_text(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $asset = $this->makeAsset($district);

        $this->filled(Livewire::test(MaintenanceLog::class)->call('openCreate'), 'Laptop', $asset->id)
            ->set('form.notes', 'confidential note text')
            ->set('form.technician', 'Kofi Mensah')
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()->where('action', 'create_maintenance')->firstOrFail();
        $dump = json_encode($log->toArray());
        $this->assertStringNotContainsString('confidential note text', $dump);
        $this->assertStringNotContainsString('Kofi Mensah', $dump);
    }

    protected function filled($component, string $type, int $assetId)
    {
        return $component
            ->set('form.asset_type', $type)
            ->set('form.ict_asset_id', $assetId)
            ->set('form.maintenance_type', 'Repair')
            ->set('form.status', 'Open');
    }
}
