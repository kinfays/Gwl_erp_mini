<?php

namespace Tests\Feature\Commercial;

use App\Services\Commercial\CommercialReportData;
use Tests\Support\Commercial\BuildsBillingDetails;

/**
 * Phase 8d: loading a Detailed Billing Report moves no figure of what already existed. ExistingFiguresPinnedTest hashes every export and the
 * billing analyses from before Phase 8a and still passes untouched; this one proves it for the new report type directly: the Summary, the
 * billing snapshot, the KPIs, the reading trend and the readers are byte for byte the same before and after a Detailed Billing Report is
 * loaded for the same region. Invented data only.
 */
class BillingDetailsLegacyUnchangedTest extends CommercialTestCase
{
    use BuildsBillingDetails;

    public function test_loading_a_detailed_billing_report_changes_no_figure_of_the_older_reports(): void
    {
        config(['gwl.commercial_customer_list_enabled' => false]);
        $this->setUpBillingDetails();
        $this->tolerant();

        $this->seedBilling($this->billingFixture(), '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id]]);
        $this->seedReading([
            '10001' => ['2026-08-01' => [100, 20], '2026-09-01' => [110, 10]],
            '10002' => ['2026-08-01' => [90, 30], '2026-09-01' => [95, 25]],
            '10003' => ['2026-08-01' => [80, 40], '2026-09-01' => [85, 35]],
        ], options: ['districts' => ['10001' => $this->sowutuom->id, '10002' => $this->sowutuom->id, '10003' => $this->odorkor->id]]);

        $officer = $this->officer();
        $this->actingAs($officer);
        $data = app(CommercialReportData::class);

        $snapshot = function () use ($data): string {
            return json_encode([
                // Phase 8f: the summary gained an ADDITIVE route-grain block (it appears once a detailed billing report is live): every figure that was there is compared
                'summary' => array_diff_key($data->summary(), ['route_grain' => true]),
                'snapshots' => $data->snapshots(),
                'billing' => $data->billingSnapshot(null),
                'kpis' => $data->kpis(),
                'trend' => $data->readingTrend(null, null, null, null),
                'readers' => $data->readers(null, null, null, null),
                'route_reading' => $data->routeReading(),
            ]);
        };

        $before = $snapshot();
        $this->assertNotSame('', $before);
        $this->assertNull($data->summary()['route_grain'], 'nothing route-grain is loaded yet');

        $batch = $this->importBilling();
        $this->assertTrue($batch->isLive());
        // a billing details upload that is still being read, and one that waits for its check, change nothing either
        $this->registerBilling($this->spec([$this->route('SOWUTUOM 4101', 900, 905)], 'July-2026 - July-2026'));
        $this->importBilling($this->spec([$this->route('SOWUTUOM 4101', 910, 915)], 'June-2026 - June-2026'), ['review' => true]);

        $this->assertSame($before, $snapshot(), 'no figure of the summary, the billing snapshots, the KPIs or the reading screens moved');
        $this->assertNotNull($data->summary()['route_grain']['billing'], 'only the new route-grain block appears');

        // and the other way round: a billing summary or a reading upload does not appear in the detailed analyses
        $details = $data->billingDetails();
        $this->assertSame(578, $details['analysis']['totals']['customers']);
        $this->assertSame([$batch->id], $details['range']['batch_ids']);
    }
}
