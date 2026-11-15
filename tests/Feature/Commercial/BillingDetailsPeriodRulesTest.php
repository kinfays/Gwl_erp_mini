<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\BatchLifecycleService;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\CommercialReportData;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8d: the period model of the Detailed Billing Report is CommercialPeriods exactly as the billing summary and the reading by route
 * (family = region + report type): identical period, the newest wins; a newer period that contains an older one shadows it; a partial
 * overlap keeps both with a warning naming the other batch; distinct periods ADD the flows and take the right period's stock (the previous
 * balance the EARLIEST period's, the balance due and every count of customers the LATEST period's). Hand arithmetic, invented customers.
 *
 * The building block: ten customers on one route, each with a month total of 57.20 (consume 10 at rate 4 and the five charges), a previous
 * balance and a payment that the test chooses, so a route's month total is 572.00 per upload.
 */
class BillingDetailsPeriodRulesTest extends CommercialTestCase
{
    use BuildsBillingDetails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBillingDetails();
        config(['gwl.commercial_customer_list_enabled' => false]);
        $this->actingAs($this->officer());
    }

    /** One upload of ten customers on route 4101 (and optionally ten on 4102), with the given previous balance and payment each. */
    protected function upload(string $period, float $previous, float $paid, bool $second = false): CommercialImportBatch
    {
        $routes = [$this->route('SOWUTUOM 4101', 1, 10, ['previous_balance' => $previous, 'paid' => $paid])];

        if ($second) {
            $routes[] = $this->route('SOWUTUOM 4102', 11, 20, ['previous_balance' => $previous, 'paid' => $paid]);
        }

        $batch = $this->importBilling($this->spec($routes, $period));
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->fresh()->status, (string) $batch->error_message);

        return $batch->fresh();
    }

    /** @return array<string, mixed> the report's totals for a range (as the signed-in officer) */
    protected function totals(?string $from = null, ?string $to = null): array
    {
        return app(CommercialReportData::class)->billingDetails(null, null, null, null, null, $from, $to);
    }

    public function test_two_periods_that_do_not_overlap_add_their_flows_and_take_the_right_stock_from_each(): void
    {
        // 1 to 10 September: previous balance 100.00 each, paid 20.00 each: balance due 100 + 57.20 - 20 = 137.20 each
        $first = $this->upload('01-September-2026 - 10-September-2026', 100.0, 20.0);
        // 11 to 20 September: the balance carried on (137.20 each), paid 30.00 each: balance due 137.20 + 57.20 - 30 = 164.40 each
        $second = $this->upload('11-September-2026 - 20-September-2026', 137.20, 30.0);

        $alone = $this->totals('2026-09-01', '2026-09-10');
        $this->assertSame([$first->id], $alone['range']['batch_ids']);
        $one = $alone['analysis']['totals'];
        $this->assertSame(10, $one['customers']);
        $this->assertSame(572.0, $one['month_total']);
        $this->assertSame(200.0, $one['paid']);
        $this->assertSame(1000.0, $one['previous_balance']);
        $this->assertSame(1372.0, $one['balance_due']);

        $range = $this->totals('2026-09-01', '2026-09-20');
        $this->assertSame([$first->id, $second->id], $range['range']['batch_ids']);
        $t = $range['analysis']['totals'];

        $this->assertSame(1144.0, $t['month_total'], 'the flows add: 572 + 572');
        $this->assertSame(500.0, $t['paid'], '200 + 300');
        $this->assertSame(200.0, $t['consume'], '100 + 100');
        $this->assertSame(1000.0, $t['previous_balance'], 'the EARLIEST period\'s previous balance: the balance the range started from');
        $this->assertSame(1644.0, $t['balance_due'], 'the LATEST period\'s balance due: 10 x 164.40');
        $this->assertSame(10, $t['customers'], 'customer counts are the LATEST period\'s: the same ten customers');
        $this->assertSame(20, $t['bills'], 'the bills (customers added over the periods) are the denominator of a per-bill figure');
        $this->assertSame(10.0, $t['consume_per_bill'], '200 units over 20 bills');
        // the ratios are recomputed from the sums, never averaged
        $this->assertSame(43.71, $t['collection_ratio'], '500 / 1,144');
        $this->assertSame(2144.0, $t['amount_due'], 'previous 1,000 + month total 1,144 + adjusted 0');
        $this->assertSame(23.32, $t['collected_of_due'], '500 / 2,144');
        $this->assertStringContainsString('2 uploads that do not overlap are added up', $range['notes'][0]);
        $this->assertSame(20, $range['range']['covered_days']);
        $this->assertSame(20, $range['range']['range_days']);
    }

    public function test_a_route_that_is_not_in_the_later_upload_keeps_its_latest_figures(): void
    {
        // routes 4101 and 4102 in the first upload, only 4101 in the second: 4102's stock is the first upload's (the latest it appears in)
        $this->upload('01-September-2026 - 10-September-2026', 100.0, 20.0, second: true);
        $this->upload('11-September-2026 - 20-September-2026', 137.20, 30.0);

        $t = $this->totals('2026-09-01', '2026-09-20')['analysis'];
        $routes = collect($t['routes'])->keyBy('label');

        $this->assertSame(10, $routes['SOWUTUOM 4101']['customers']);
        $this->assertSame(1144.0, $routes['SOWUTUOM 4101']['month_total']);
        $this->assertSame(1644.0, $routes['SOWUTUOM 4101']['balance_due'], 'the second upload\'s');
        $this->assertSame(10, $routes['SOWUTUOM 4102']['customers'], 'in the first upload only: its latest');
        $this->assertSame(572.0, $routes['SOWUTUOM 4102']['month_total']);
        $this->assertSame(1372.0, $routes['SOWUTUOM 4102']['balance_due']);
        $this->assertSame(20, $t['totals']['customers']);
        $this->assertSame(1716.0, $t['totals']['month_total'], '572 x 3 route-periods');
        $this->assertSame(30, $t['totals']['bills']);
    }

    public function test_the_default_is_the_latest_uploaded_period_and_a_range_with_a_gap_says_which_days_are_not_covered(): void
    {
        $first = $this->upload('01-September-2026 - 10-September-2026', 100.0, 20.0);
        $second = $this->upload('21-September-2026 - 30-September-2026', 137.20, 30.0);

        $default = $this->totals();
        $this->assertSame([$second->id], $default['range']['batch_ids'], 'with no dates: the latest uploaded period');
        $this->assertFalse($default['range']['typed']);

        $r = $this->totals('2026-09-01', '2026-09-30');
        $this->assertSame([$first->id, $second->id], $r['range']['batch_ids']);
        $this->assertSame(20, $r['range']['covered_days']);
        $this->assertSame(30, $r['range']['range_days']);
        $this->assertSame([['2026-09-11', '2026-09-20']], $r['range']['gaps']);
        $this->assertStringContainsString('No upload covers 11 Sep to 20 Sep 2026 of the range (20 of 30 days are covered)', implode(' ', $r['notes']));

        // an upload only partly inside the range is left out and listed
        $partly = $this->totals('2026-09-05', '2026-09-30');
        $this->assertSame([$second->id], $partly['range']['batch_ids']);
        $this->assertStringContainsString('only partly inside the range: #'.$first->id, implode(' ', $partly['notes']));
        $this->assertSame('No upload lies inside this range.', $this->totals('2026-01-01', '2026-01-31')['notes'][0]);
    }

    public function test_a_newer_period_that_contains_an_older_one_shadows_it_and_the_same_period_is_replaced_by_the_newest(): void
    {
        $a = $this->upload('01-September-2026 - 10-September-2026', 100.0, 20.0);
        $b = $this->upload('11-September-2026 - 20-September-2026', 137.20, 30.0);
        $whole = $this->upload('September-2026 - September-2026', 0.0, 10.0);

        $this->assertSame(CommercialImportBatch::STATUS_SUPERSEDED, $a->fresh()->status, 'the whole month contains the first ten days');
        $this->assertSame(CommercialImportBatch::STATUS_SUPERSEDED, $b->fresh()->status);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $whole->fresh()->status);
        $this->assertSame($b->id, $whole->fresh()->supersedes_batch_id, 'it names the batch it replaced');

        $r = $this->totals('2026-09-01', '2026-09-30');
        $this->assertSame([$whole->id], $r['range']['batch_ids']);
        $this->assertSame(572.0, $r['analysis']['totals']['month_total'], 'only the whole month\'s figures');
        $this->assertStringContainsString('is replaced by the newer batch #'.$whole->id, implode(' ', $r['notes']));

        // the same period again: the newest wins, and voiding it brings the earlier one back
        $again = $this->upload('September-2026 - September-2026', 0.0, 40.0);
        $this->assertSame(CommercialImportBatch::STATUS_SUPERSEDED, $whole->fresh()->status);
        $this->assertSame(400.0, $this->totals('2026-09-01', '2026-09-30')['analysis']['totals']['paid'], 'ten customers x 40.00');

        app(BatchLifecycleService::class)->void($again, 'wrong file', 1);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $whole->fresh()->status);
        $this->assertSame(100.0, $this->totals('2026-09-01', '2026-09-30')['analysis']['totals']['paid'], 'ten customers x 10.00 again');
    }

    public function test_a_partial_overlap_keeps_both_and_the_preview_names_the_other_batch(): void
    {
        $first = $this->upload('01-September-2026 - 15-September-2026', 100.0, 20.0);

        // the check before the upload: this period overlaps the first only in part
        $path = ReportWorkbooks::billingDetails($this->spec([$this->route('SOWUTUOM 4101', 1, 10)], '10-September-2026 - 25-September-2026'));
        $this->workbooks[] = $path;
        $inspect = app(BillingDetailsImportService::class)->inspect($path);
        $this->assertStringContainsString('overlaps batch #'.$first->id.' (1 Sep to 15 Sep 2026) only in part', implode(' ', array_column($inspect['warnings'], 'message')));

        $second = $this->upload('10-September-2026 - 25-September-2026', 137.20, 30.0);

        // both stay imported; a view of the whole range uses the newest first and says so
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $first->fresh()->status);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $second->fresh()->status);
        $r = $this->totals('2026-09-01', '2026-09-30');
        $this->assertSame([$second->id], $r['range']['batch_ids']);
        $this->assertStringContainsString('Batch #'.$first->id.' overlaps batch #'.$second->id.' and is left out of this view', implode(' ', $r['notes']));

        // the same period and a containing one are announced too
        $same = ReportWorkbooks::billingDetails($this->spec([$this->route('SOWUTUOM 4101', 31, 40)], '01-September-2026 - 15-September-2026'));
        $this->workbooks[] = $same;
        $this->assertStringContainsString('already covers this region and exactly this period', implode(' ', array_column(app(BillingDetailsImportService::class)->inspect($same)['warnings'], 'message')));
        $whole = ReportWorkbooks::billingDetails($this->spec([$this->route('SOWUTUOM 4101', 41, 50)], 'September-2026 - September-2026'));
        $this->workbooks[] = $whole;
        $this->assertStringContainsString('This period contains batch #', implode(' ', array_column(app(BillingDetailsImportService::class)->inspect($whole)['warnings'], 'message')));
    }

    public function test_a_batch_that_is_not_live_is_not_a_period(): void
    {
        $this->upload('01-September-2026 - 10-September-2026', 100.0, 20.0);
        // a newer upload that is waiting for its check, one that is blocked and one still queued: none of them shadows or adds
        $waiting = $this->importBilling($this->spec([$this->route('SOWUTUOM 4101', 21, 30)], 'September-2026 - September-2026'), ['review' => true]);
        $queued = $this->registerBilling($this->spec([$this->route('SOWUTUOM 4101', 31, 40)], '2026-09-11 to 2026-09-20'));

        $this->assertSame(CommercialImportBatch::STATUS_AWAITING_REVIEW, $waiting->status);
        $this->assertSame(CommercialImportBatch::STATUS_QUEUED, $queued->status);

        $r = $this->totals('2026-09-01', '2026-09-30');
        $this->assertCount(1, $r['range']['batch_ids']);
        $this->assertSame(10, $r['analysis']['totals']['customers']);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, CommercialImportBatch::query()->orderBy('id')->first()->status, 'the earlier upload is not marked superseded by a run that never went live');
    }
}
