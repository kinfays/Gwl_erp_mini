<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Models\CommercialImportBatch;
use App\Models\Region;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Customers\CustomerTestCase;

/**
 * Phase 8f: `commercial:trends:sample` loads the invented series (TrendsSampleSet) into an INVENTED region through the real import paths, with the late upload and
 * the year-earlier week in it; it refuses production and a second copy; --files-only writes the workbooks and loads nothing.
 */
class TrendsSampleCommandTest extends CustomerTestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 09:00:00');
        config(['gwl.commercial_customer_list_enabled' => true]);
        $this->directory = sys_get_temp_dir().'/trends-sample-'.uniqid();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_it_loads_the_series_into_an_invented_region_and_nothing_else(): void
    {
        $before = CommercialImportBatch::query()->count();
        $this->artisan('commercial:trends:sample', ['--output' => $this->directory])->assertSuccessful();

        $region = Region::query()->where('region_name', 'Sample Trends Region')->firstOrFail();
        $reading = CommercialImportBatch::query()->where('region_id', $region->id)->where('report_type', 'reading_route_summary')->orderBy('period_from')->get();
        $billing = CommercialImportBatch::query()->where('region_id', $region->id)->where('report_type', 'billing_details')->orderBy('period_from')->get();

        // Phase 8h: each live detailed upload also gets its derived billing summary (a batch of its own, no workbook)
        $this->assertSame($before + 10, CommercialImportBatch::query()->where('source', 'upload')->count(), 'five reading and five billing uploads, in the invented region only');
        $this->assertSame(5, CommercialImportBatch::query()->where('source', 'derived')->where('region_id', $region->id)->count(), 'one derived billing summary per detailed upload');
        $this->assertCount(5, $reading);
        $this->assertCount(5, $billing);
        $this->assertSame(['2025-10-01', '2026-09-01', '2026-10-01', '2026-10-08', '2026-10-22'], $reading->map(fn ($b) => $b->period_from->toDateString())->all());
        $this->assertEmpty($reading->concat($billing)->reject(fn ($b) => in_array($b->status, ['imported', 'superseded'], true))->all(), 'every upload went live');
        $this->assertSame(0, CommercialImportBatch::query()->where('region_id', $this->accraWest->id)->count(), 'no real region is touched');

        // the late upload: imported on 3 November, 6 days after its period ended
        $late = $reading->firstWhere('period_to', '2026-10-28 00:00:00') ?? $reading->last();
        $this->assertSame('2026-11-03', Carbon::parse($late->imported_at)->toDateString());

        $snapshots = CommercialCustomerBatch::query()->where('region_id', $region->id)->get();
        $this->assertSame(['daily'], $snapshots->pluck('period_type')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['2025-10-31', '2026-10-24', '2026-10-31'], $snapshots->pluck('period_key')->unique()->values()->all());
    }

    public function test_a_second_copy_is_refused(): void
    {
        $this->artisan('commercial:trends:sample', ['--output' => $this->directory])->assertSuccessful();
        $count = CommercialImportBatch::query()->count();

        $this->artisan('commercial:trends:sample', ['--output' => $this->directory])->assertFailed();
        $this->assertSame($count, CommercialImportBatch::query()->count());
    }

    public function test_it_refuses_production_and_a_switched_off_customer_list_and_files_only_loads_nothing(): void
    {
        config(['gwl.commercial_customer_list_enabled' => false]);
        $this->artisan('commercial:trends:sample', ['--output' => $this->directory])->assertFailed();
        $this->assertSame(0, CommercialImportBatch::query()->count());

        $this->artisan('commercial:trends:sample', ['--output' => $this->directory, '--files-only' => true])->assertSuccessful();
        $this->assertCount(13, glob($this->directory.'/*.xlsx'), '5 reading, 5 billing and 3 customer-list workbooks');
        $this->assertSame(0, CommercialImportBatch::query()->count());

        config(['gwl.commercial_customer_list_enabled' => true]);
        $this->app['env'] = 'production';
        $this->artisan('commercial:trends:sample', ['--output' => $this->directory])->assertFailed();
        $this->assertSame(0, CommercialImportBatch::query()->count());
        $this->app['env'] = 'testing';
    }
}
