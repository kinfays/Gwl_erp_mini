<?php

namespace Tests\Feature\Commercial;

use App\Services\Commercial\BillingDetails\BillingDetailsParser;
use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;
use App\Services\Commercial\Customers\XlsxStreamReader;

/**
 * Phase 8d: `php artisan commercial:billing-details:sample` writes ONE invented workbook in the real layout, for trying the upload and the
 * screens without a real file. It refuses production and needs three district names.
 */
class BillingDetailsSampleCommandTest extends CommercialTestCase
{
    public function test_the_command_writes_the_real_layout_with_invented_data(): void
    {
        $out = sys_get_temp_dir().'/bd-sample-'.uniqid().'.xlsx';
        $this->workbooks[] = $out;

        $this->artisan('commercial:billing-details:sample', ['--districts' => 'ALPHA,BETA,GAMMA', '--output' => $out])->assertExitCode(0);

        $this->assertFileExists($out);
        $found = app(BillingDetailsParser::class)->detect($out);
        $this->assertNotNull($found);
        $this->assertSame(BillingDetailsSampleFile::SAMPLE_PERIOD, $found['period'], 'a part-month bill period');

        $reader = new XlsxStreamReader($out);
        $headings = [];
        $rows = 0;
        $totals = 0;

        foreach ((new BillingDetailsParser)->parse($reader->rows($found['sheet'])) as $event) {
            $headings += $event['kind'] === 'heading' ? [$event['label'] => true] : [];
            $rows += $event['kind'] === 'row' ? 1 : 0;
            $totals += $event['kind'] === 'totals' ? 1 : 0;
        }

        $this->assertSame(578, $rows);
        $this->assertCount(21, $headings);
        $this->assertSame(21, $totals);
        $this->assertArrayHasKey('ALPHA 4101', $headings);
        $this->assertArrayHasKey('ALPHA 0000', $headings, 'the unassigned route');
        $this->assertArrayHasKey('ATLANTIS 9901', $headings, 'a district nobody can place');
    }

    public function test_it_needs_three_district_names_and_refuses_production(): void
    {
        $out = sys_get_temp_dir().'/bd-sample-'.uniqid().'.xlsx';
        $this->workbooks[] = $out;

        $this->artisan('commercial:billing-details:sample', ['--districts' => 'ONE,TWO', '--output' => $out])->assertExitCode(1);
        $this->assertFileDoesNotExist($out);

        $this->app['env'] = 'production';
        $this->artisan('commercial:billing-details:sample', ['--districts' => 'A,B,C', '--output' => $out])->assertExitCode(1);
        $this->assertFileDoesNotExist($out);
    }
}
