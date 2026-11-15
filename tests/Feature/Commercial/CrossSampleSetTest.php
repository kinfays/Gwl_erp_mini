<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialCustomerBatch;
use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\Cross\CrossFileReport;
use App\Services\Commercial\Cross\CrossSampleLoader;
use App\Services\Commercial\Cross\CrossSampleSet;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use ZipArchive;

/**
 * Phase 8e: `php artisan commercial:cross:sample` loads ONE consistent set of invented files (reading by route, detailed billing, a region-wide customer
 * list) for one invented region through the real import paths, and refuses production. The small fixture set under tests/Fixtures/cross-set is what its
 * writer makes (a test fails when it is stale) and imports to the figures the design of the set (CrossSampleSet) documents.
 */
class CrossSampleSetTest extends CustomerTestCase
{
    protected const FIXTURES = 'tests/Fixtures/cross-set';

    protected const FILES = ['reading' => 'rptReadingSumm-cross-sample.xlsx', 'billing' => 'rptBillingDetails-cross-sample.xlsx', 'customers' => 'rptCustomerDetails-cross-sample.xlsx'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> the text of every sheet and of the shared strings of a workbook, by part name (the zip's own timestamps are left out) */
    protected function content(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, $path);
        $out = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (str_starts_with($name, 'xl/worksheets/') || $name === 'xl/sharedStrings.xml') {
                $out[$name] = $zip->getFromIndex($i);
            }
        }

        $zip->close();
        ksort($out);

        return $out;
    }

    public function test_the_command_loads_the_set_into_an_invented_region_and_will_not_load_it_twice(): void
    {
        config(['gwl.commercial_customer_list_enabled' => true]);
        $out = sys_get_temp_dir().'/cross-cmd-'.uniqid();

        $this->artisan('commercial:cross:sample', ['--output' => $out])->assertExitCode(0);

        $region = Region::query()->where('region_name', 'Sample Cross Region')->firstOrFail();
        $this->assertSame(['Sample One', 'Sample Two'], District::query()->where('region_id', $region->id)->orderBy('district_name')->pluck('district_name')->all());
        $this->assertSame(
            [CommercialImportBatch::STATUS_IMPORTED],
            CommercialImportBatch::query()->where('region_id', $region->id)->where('report_type', CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY)->pluck('status')->unique()->all()
        );
        $this->assertSame(
            [CommercialImportBatch::STATUS_IMPORTED],
            CommercialImportBatch::query()->where('region_id', $region->id)->where('report_type', CommercialImportBatch::TYPE_BILLING_DETAILS)->pluck('status')->unique()->all()
        );
        $this->assertSame(2, CommercialCustomerBatch::query()->where('region_id', $region->id)->where('status', CommercialCustomerBatch::STATUS_IMPORTED)->count(), 'one batch per district of the region-wide list');

        // a second run would add a second copy of the same uploads: it says so and stops
        $this->artisan('commercial:cross:sample', ['--output' => $out])->assertExitCode(1);
        $this->assertSame(1, CommercialImportBatch::query()->where('region_id', $region->id)->where('report_type', CommercialImportBatch::TYPE_BILLING_DETAILS)->count());

        foreach (glob($out.'/*.xlsx') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_the_command_can_only_write_the_files_and_refuses_production_and_a_switched_off_list(): void
    {
        $out = sys_get_temp_dir().'/cross-files-'.uniqid();
        $this->artisan('commercial:cross:sample', ['--files-only' => true, '--output' => $out])->assertExitCode(0);

        foreach (self::FILES as $file) {
            $this->assertFileExists($out.'/'.$file);
            @unlink($out.'/'.$file);
        }

        $this->assertSame(0, CommercialImportBatch::query()->count(), 'writing the files loads nothing');

        config(['gwl.commercial_customer_list_enabled' => false]);
        $this->artisan('commercial:cross:sample', ['--output' => $out])->assertExitCode(1);
        $this->assertNull(Region::query()->where('region_name', 'Sample Cross Region')->first(), 'it made nothing before it refused');

        config(['gwl.commercial_customer_list_enabled' => true]);
        $this->app['env'] = 'production';
        $this->artisan('commercial:cross:sample', ['--output' => $out])->assertExitCode(1);
        $this->artisan('commercial:cross:sample', ['--files-only' => true, '--output' => $out])->assertExitCode(1);
        $this->assertFileDoesNotExist($out.'/'.self::FILES['reading']);
        $this->assertNull(Region::query()->where('region_name', 'Sample Cross Region')->first());
    }

    public function test_the_small_fixture_set_is_what_the_writer_makes(): void
    {
        $fresh = sys_get_temp_dir().'/cross-fresh-'.uniqid();
        $files = CrossSampleSet::write($fresh, CrossSampleSet::INVENTED_DISTRICTS, 'SAMPLE CROSS REGION');

        foreach (self::FILES as $kind => $name) {
            $this->workbooks[] = $files[$kind];
            $this->assertEquals($this->content(base_path(self::FIXTURES.'/'.$name)), $this->content($files[$kind]), "the {$kind} fixture is stale: run php artisan commercial:cross:sample --files-only --output=tests/Fixtures/cross-set");
            $this->assertLessThan(60 * 1024, filesize(base_path(self::FIXTURES.'/'.$name)), 'a small fixture');
        }
    }

    public function test_the_fixture_set_imports_to_the_figures_the_set_documents(): void
    {
        Carbon::setTestNow('2026-11-16 09:00:00');
        config(['gwl.commercial_customer_list_enabled' => true, 'gwl.commercial_min_visits_for_ranking' => 10, 'gwl.commercial_cross_min_route_customers' => 5]);

        $region = Region::query()->create(['region_name' => 'Sample Cross Region']);
        $one = District::query()->create(['region_id' => $region->id, 'district_name' => 'Sample One']);
        $two = District::query()->create(['region_id' => $region->id, 'district_name' => 'Sample Two']);

        $loaded = app(CrossSampleLoader::class)->loadFiles(array_map(fn (string $name) => base_path(self::FIXTURES.'/'.$name), self::FILES));

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $loaded['reading']->status, (string) $loaded['reading']->error_message);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $loaded['billing']->status, (string) $loaded['billing']->error_message);
        $link = $loaded['billing']->fresh()->control_totals['link'];
        $this->assertSame([62, 61, 61, 3, 1], [$link['accounts'], $link['in_customer_list'], $link['compared'], $link['status_changed'], $link['category_changed']]);

        $this->actingAs($this->superAdmin());
        $report = app(CrossFileReport::class);
        $q = ['region' => $region->id];

        // X1: 80001 owns the first two routes: 20 visits, 1 skipped = 5%, billing 22 customers and 271.60 owing in 4 accounts
        $x1 = collect($report->build('x1', $q)['parts']['combined']['data']['rows'])->keyBy('staff_id');
        $this->assertSame([20, 5.0, 271.6, 4], [$x1['80001']['visited'], $x1['80001']['skip_rate'], $x1['80001']['billing']['owing_amount'], $x1['80001']['billing']['owing']]);
        // X3: the five routes give r = 0.9
        $this->assertSame(0.9, $report->build('x3', $q)['parts']['correlation']['data']['overall']['r_wm']);
        // X10: 61 linked accounts, 51 in the same district in both files, the other 10 on the route printed under the other district
        $m = $report->build('x10', $q)['parts']['matrix']['data'];
        $this->assertSame([61, 51, 10], [$m['total'], $m['agree'], $m['disagree']]);
        $this->assertSame(['Sample One', 'Sample Two'], array_column($m['columns'], 'district'));
    }
}
