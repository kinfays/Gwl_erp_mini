<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Models\IctAssetMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class MaintenanceAndAssetNameMigrationsTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    public function test_reported_date_is_backfilled_from_creation_or_an_earlier_completion_date(): void
    {
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $asset = $this->makeAsset($district);
        $migration = require base_path('database/migrations/2026_11_05_000001_add_reported_date_to_ict_asset_maintenances_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ict_asset_maintenances', 'reported_date'));

        $row = fn (array $extra) => DB::table('ict_asset_maintenances')->insertGetId([
            'ict_asset_id' => $asset->id,
            'maintenance_type' => 'Repair',
            'status' => 'Open',
            'created_at' => '2026-09-10 08:00:00',
            'updated_at' => '2026-09-10 08:00:00',
            ...$extra,
        ]);
        $plain = $row([]);
        $backDated = $row(['status' => 'Completed', 'completion_date' => '2026-09-01']);
        $normal = $row(['status' => 'Completed', 'completion_date' => '2026-09-12']);

        $migration->up();

        $this->assertSame('2026-09-10', IctAssetMaintenance::find($plain)->reported_date->toDateString());
        // Completed before the day it was entered: reported date follows, so the stored record stays valid.
        $this->assertSame('2026-09-01', IctAssetMaintenance::find($backDated)->reported_date->toDateString());
        $this->assertSame('2026-09-10', IctAssetMaintenance::find($normal)->reported_date->toDateString());
    }

    public function test_existing_duplicate_names_are_never_renamed_and_the_index_is_left_off_until_they_are_fixed(): void
    {
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $migration = require base_path('database/migrations/2026_11_05_000002_add_unique_asset_name_key_to_ict_assets_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ict_assets', 'asset_name_key'));

        foreach (['Same Name', ' same name ', 'Different'] as $i => $name) {
            DB::table('ict_assets')->insert([
                'asset_name' => $name,
                'serial_number' => 'S-'.$i,
                'asset_type' => 'Laptop',
                'device_category' => 'asset',
                'status' => 'Active',
                'region_id' => $district->region_id,
                'district_id' => $district->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();

        // Nothing renamed or deleted; every row has its key; no unique index (it would have failed).
        $this->assertSame(['Same Name', ' same name ', 'Different'], DB::table('ict_assets')->orderBy('id')->pluck('asset_name')->all());
        $this->assertSame(['same name', 'same name', 'different'], DB::table('ict_assets')->orderBy('id')->pluck('asset_name_key')->all());
        $this->assertFalse(collect(Schema::getIndexes('ict_assets'))->contains(fn ($i) => $i['name'] === 'ict_assets_asset_name_key_unique'));

        // The form still refuses new duplicates meanwhile.
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin($district));
        Livewire::test(AssetsList::class)->call('openCreate')->set('form.asset_name', 'DIFFERENT')->call('save')->assertHasErrors(['form.asset_name']);

        // Once the clash is fixed by hand, the command adds the index.
        DB::table('ict_assets')->where('serial_number', 'S-1')->update(['asset_name' => 'Renamed by a person']);
        $this->artisan('assets:duplicate-names', ['--add-index' => true])->expectsOutputToContain('Unique index added.')->assertSuccessful();
        $this->assertTrue(collect(Schema::getIndexes('ict_assets'))->contains(fn ($i) => $i['name'] === 'ict_assets_asset_name_key_unique'));
    }
}
