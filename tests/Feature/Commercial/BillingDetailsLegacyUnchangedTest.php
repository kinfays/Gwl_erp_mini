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

        // Phase 8h: a live Detailed Billing Report now ALSO gives a billing summary of its own (derived, segment "all"), which is new data and appears in the
        // snapshot lists, the segment list and the default snapshot. What must not move is every figure that was there: the UPLOADED summary read by its id,
        // the KPIs of its segment, and every reading screen.
        $uploadedId = (string) \App\Models\CommercialImportBatch::query()->where('report_type', 'billing_summary')->value('id');
        $snapshot = function () use ($data, $uploadedId): array {
            $summary = $data->summary($uploadedId);
            // the route-grain block is additive (8f); the freshness line (newest billing upload, list of periods) and the data-quality count of multi-month
            // snapshots now also see the derived summary (8h); every other figure of the summary is compared
            unset($summary['route_grain'], $summary['snapshots'], $summary['summary']['freshness'], $summary['summary']['quality']);

            return [
                'summary' => $summary,
                'snapshots' => array_values(array_filter($data->snapshots(), fn (array $s) => ! ($s['is_derived'] ?? false))),
                'billing' => $data->billingSnapshot($uploadedId),
                'kpis' => array_diff_key($data->kpis(null, 'new_service'), ['segments' => true]),
                'trend' => $data->readingTrend(null, null, null, null),
                'readers' => $data->readers(null, null, null, null),
                'route_reading' => $data->routeReading(),
            ];
        };

        $before = $snapshot();
        $this->assertNotSame([], $before);
        $this->assertNull($data->summary()['route_grain'], 'nothing route-grain is loaded yet');

        $batch = $this->importBilling();
        $this->assertTrue($batch->isLive());
        // a billing details upload that is still being read, and one that waits for its check, change nothing either
        $this->registerBilling($this->spec([$this->route('SOWUTUOM 4101', 900, 905)], 'July-2026 - July-2026'));
        $this->importBilling($this->spec([$this->route('SOWUTUOM 4101', 910, 915)], 'June-2026 - June-2026'), ['review' => true]);

        $after = $snapshot();

        foreach ($before as $part => $figures) {
            $this->assertSame(json_encode($figures), json_encode($after[$part]), "no figure of the uploaded summary, the KPIs of its segment or the reading screens moved: {$part}");
        }

        $this->assertCount(1, array_filter($data->snapshots(), fn (array $s) => $s['is_derived'] ?? false), 'the one new thing is the derived billing summary (Phase 8h)');
        $this->assertNotNull($data->summary()['route_grain']['billing'], 'only the new route-grain block appears');

        // and the other way round: a billing summary or a reading upload does not appear in the detailed analyses
        $details = $data->billingDetails();
        $this->assertSame(578, $details['analysis']['totals']['customers']);
        $this->assertSame([$batch->id], $details['range']['batch_ids']);
    }
}
