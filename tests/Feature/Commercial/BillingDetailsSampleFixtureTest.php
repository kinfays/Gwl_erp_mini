<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialBillingDetailRollup;
use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\BillingDetails\BillingDetailsParser;
use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\Customers\XlsxStreamReader;
use Illuminate\Support\Carbon;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8d: THE SAMPLE FIXTURE (tests/Fixtures/billing-details-sample.xlsx, made by `php artisan commercial:billing-details:sample`; invented
 * districts SAMPLE ONE, TWO and THREE) through the real pipeline, with every analysis B1 to B13 worked out BY HAND from the design in
 * BillingDetailsSampleFile::sampleSpec(). Nothing here is read from a real export.
 *
 *   the file        19 routes of 30 customers + the repeated account + route 0000 (4) + one route in an unknown district (3) = 578 rows
 *   SAMPLE ONE      routes 4101 to 4108 (n = 1 to 240) and route 0000 (n = 571 to 574): 244 rows, holding every special customer (n = 1 to 21)
 *   SAMPLE TWO      routes 4201 to 4206 (n = 241 to 420): 180 rows, all ordinary; the totals row of 4202 is one row out
 *   SAMPLE THREE    routes 4301 to 4305 (n = 421 to 570) and the repeated account: 151 rows, all ordinary
 *   unknown         ATLANTIS 9901 (n = 575 to 577): 3 rows, ordinary
 *   an ordinary customer   consume 10 at rate 4: water 40.00, rural 0.80, fire 0.40, sewage 14.00, service 2.00, month total 57.20, regular bill,
 *                          read 11 days before the end of the period, paid in full, except every fifth account, which paid nothing
 */
class BillingDetailsSampleFixtureTest extends CommercialTestCase
{
    use BuildsBillingDetails;

    protected const FIXTURE = 'tests/Fixtures/billing-details-sample.xlsx';

    protected District $one;

    protected District $two;

    protected District $three;

    protected CommercialImportBatch $batch;

    /** @var array<string, mixed> */
    protected array $r = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        config(['gwl.commercial_customer_list_enabled' => false, 'gwl.commercial_min_readers_for_district_figures' => 1]);
        $this->tolerant();

        foreach (['one' => 'Sample One', 'two' => 'Sample Two', 'three' => 'Sample Three'] as $property => $name) {
            $this->{$property} = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => $name]);
        }

        $service = app(BillingDetailsImportService::class);
        ['batch' => $batch] = $service->register(base_path(self::FIXTURE), 'rptBillingDetails_ExP.xlsx', null);
        $this->batch = $service->process($batch);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $this->batch->status, (string) $this->batch->error_message);

        $this->actingAs($this->officer());
        $this->r = app(CommercialReportData::class)->billingDetails();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function district(string $name): array
    {
        return collect($this->r['analysis']['districts'])->firstWhere('name', $name) ?? $this->fail("no district {$name}");
    }

    /** The analysis row of one route heading. */
    protected function routeRow(string $label): array
    {
        return collect($this->r['analysis']['routes'])->firstWhere('label', $label) ?? $this->fail("no route {$label}");
    }

    public function test_the_fixture_is_what_the_writer_makes(): void
    {
        $fresh = BillingDetailsSampleFile::write(BillingDetailsSampleFile::sampleSpec('ACCRA WEST', ['SAMPLE ONE', 'SAMPLE TWO', 'SAMPLE THREE']));
        $this->workbooks[] = $fresh;

        $read = function (string $path): array {
            $reader = new XlsxStreamReader($path);
            $events = [];

            foreach ((new BillingDetailsParser)->parse($reader->rows($reader->sheetNames()[0])) as $event) {
                unset($event['row_no']);
                $events[] = $event;
            }

            return $events;
        };

        $this->assertEquals($read(base_path(self::FIXTURE)), $read($fresh), 'the fixture is stale: run php artisan commercial:billing-details:sample --districts="SAMPLE ONE,SAMPLE TWO,SAMPLE THREE" --output=tests/Fixtures/billing-details-sample.xlsx');
        $this->assertLessThan(150 * 1024, filesize(base_path(self::FIXTURE)), 'a small fixture');
    }

    // ---------------------------------------------------------------- B1: bill status

    public function test_b1_the_bill_status_mix_by_district_category_and_route(): void
    {
        $t = $this->r['analysis']['totals'];
        $this->assertSame(578, $t['customers']);
        $this->assertSame(574, $t['billed_customers'], '578 less the four unbilled');
        $this->assertSame(569, $t['bill']['REGULAR']['customers']);
        $this->assertSame(2, $t['bill']['ESTIMATED']['customers']);
        $this->assertSame(3, $t['bill']['ESTIMATE']['customers']);
        $this->assertSame(4, $t['bill']['UNBILLED']['customers']);
        $this->assertSame(0.87, $t['estimated_share'], '5 / 574');
        $this->assertSame(0.69, $t['unbilled_share'], '4 / 578');
        $this->assertSame(32439.2, $t['bill']['REGULAR']['month_total'], '565 ordinary rows... + 84.80 + 2.00 + 57.20 + 60.00 - 25.60 and the rest');
        $this->assertSame(92.32, $t['bill']['ESTIMATED']['month_total'], '2 x 46.16');
        $this->assertSame(138.48, $t['bill']['ESTIMATE']['month_total'], '3 x 46.16');
        $this->assertSame(0.0, $t['bill']['UNBILLED']['month_total']);

        // every special customer sits in the first district; the others hold ordinary rows only
        $one = $this->district('Sample One');
        $this->assertSame([244, 235, 2, 3, 4], [$one['customers'], $one['bill']['REGULAR']['customers'], $one['bill']['ESTIMATED']['customers'], $one['bill']['ESTIMATE']['customers'], $one['bill']['UNBILLED']['customers']]);
        $this->assertSame(13565.2, $one['month_total'], '809.60 for n = 1 to 21 and 223 ordinary rows x 57.20');
        $two = $this->district('Sample Two');
        $this->assertSame([180, 180, 0, 0, 0], [$two['customers'], $two['bill']['REGULAR']['customers'], $two['bill']['ESTIMATED']['customers'], $two['bill']['ESTIMATE']['customers'], $two['bill']['UNBILLED']['customers']]);
        $this->assertSame(10296.0, $two['month_total'], '180 x 57.20');
        $three = $this->district('Sample Three');
        $this->assertSame(151, $three['customers'], '150 and the repeated account');
        $this->assertSame(8637.2, $three['month_total'], '151 x 57.20');
        $unknown = $this->district('District not known yet');
        $this->assertSame(3, $unknown['customers']);
        $this->assertSame(171.6, $unknown['month_total']);

        // the districts add up to the whole
        $this->assertSame($t['customers'], array_sum(array_column($this->r['analysis']['districts'], 'customers')));
        $this->assertEqualsWithDelta($t['month_total'], array_sum(array_column($this->r['analysis']['districts'], 'month_total')), 0.001);

        // by category: 193 rows are category 612 (every third: n = 3, 6 ... 576, and the repeated 21), 385 are 611
        $categories = collect($this->r['analysis']['categories'])->keyBy('code');
        $this->assertSame(385, $categories['611']['customers']);
        $this->assertSame(193, $categories['612']['customers']);

        // by route: a route of 30 ordinary customers; the first route holds the specials and the repeated account is on the last route of the third district
        $this->assertSame(30, $this->routeRow('SAMPLE TWO 4201')['customers']);
        $this->assertSame(31, $this->routeRow('SAMPLE THREE 4305')['customers']);
        $first = $this->routeRow('SAMPLE ONE 4101');
        $this->assertSame([30, 4, 2, 3], [$first['customers'], $first['bill']['UNBILLED']['customers'], $first['bill']['ESTIMATED']['customers'], $first['bill']['ESTIMATE']['customers']]);
        $this->assertCount(21, $this->r['analysis']['routes'], '21 route headings, the unknown district\'s and route 0000 included');
    }

    // ---------------------------------------------------------------- B2, B3

    public function test_b2_the_estimation_cause_comes_from_the_meter_status_not_the_spelling(): void
    {
        $t = $this->r['analysis']['totals'];

        // ESTIMATED (n = 6, 7) on WORKING meters; ESTIMATE on a FAULTY meter (8), a missing one (N, 9) and a blank one (10): blank counts as no meter
        $this->assertSame(1, $t['est']['F']['customers']);
        $this->assertSame(2, $t['est']['N']['customers']);
        $this->assertSame(2, $t['est']['W']['customers']);
        $this->assertSame(0, $t['est']['O']['customers']);
        $this->assertSame(46.16, $t['est']['F']['month_total']);
        $this->assertSame(92.32, $t['est']['N']['month_total']);
        $this->assertSame(92.32, $t['est']['W']['month_total']);
        $this->assertSame(230.8, $t['estimated_value'], '5 x 46.16');
        $this->assertSame(20.0, $t['est']['F']['share']);
        $this->assertSame(40.0, $t['est']['W']['share']);

        // the spelling is shown, but the cause does not follow it
        $this->assertSame(2, $t['spelling']['ESTIMATED']['customers']);
        $this->assertSame(3, $t['spelling']['ESTIMATE']['customers']);
        $this->assertSame(5, $t['estimated_customers']);

        $route = $this->routeRow('SAMPLE ONE 4101');
        $this->assertSame([5, 1, 2, 2], [$route['estimated_customers'], $route['est']['F']['customers'], $route['est']['N']['customers'], $route['est']['W']['customers']]);
        $this->assertSame(0, $this->routeRow('SAMPLE TWO 4201')['estimated_customers']);
    }

    public function test_b3_the_unbilled_by_status_and_the_active_billing_accounts_that_are_unbilled(): void
    {
        $t = $this->r['analysis']['totals'];
        $unbilled = collect($this->r['analysis']['unbilled'])->keyBy('status');

        $this->assertSame(['ACTB', 'DISC', 'HOLD', 'NSC'], $unbilled->keys()->sort()->values()->all());
        $this->assertTrue($unbilled['ACTB']['active_billing']);
        $this->assertTrue($unbilled['NSC']['active_billing'], 'NSC is a billing status, so it counts');
        $this->assertFalse($unbilled['HOLD']['active_billing'], 'billing is paused on HOLD');
        $this->assertFalse($unbilled['DISC']['active_billing']);
        $this->assertSame(['W' => 1], $unbilled['ACTB']['by_meter']);

        $this->assertSame(4, $t['unbilled']);
        $this->assertSame(2, $t['unbilled_active'], 'n = 1 (ACTB) and n = 4 (NSC)');
        $this->assertSame(1, $t['unbilled_hold'], 'n = 2, shown apart');

        // the exception list: active billing accounts that are unbilled, the larger balance first (n = 1 owes 100.00, n = 4 nothing)
        $list = $this->r['lists']['active_unbilled'];
        $this->assertSame(2, $list['total']);
        $this->assertSame(['100000000001', '100000000004'], array_column($list['rows'], 'account'));
        $this->assertSame([100.0, 0.0], array_column($list['rows'], 'amount'));
        $this->assertSame('SAMPLE ONE 4101', $list['rows'][0]['route']);
        // a disconnected account that was billed (n = 5)
        $this->assertSame(['100000000005'], array_column($this->r['lists']['billed_not_active']['rows'], 'account'));
    }

    // ---------------------------------------------------------------- B4, B5

    public function test_b4_consumption_total_per_bill_bands_and_the_zero_consumption_regular_bills(): void
    {
        $t = $this->r['analysis']['totals'];

        // 567 customers consumed 10, five estimated bills 8, one regular bill nothing (n = 12), one negative (n = 15: -5): 5,670 + 40 - 5
        $this->assertSame(5705.0, $t['consume']);
        $this->assertSame(9.939, $t['consume_per_bill'], '5,705 over the 574 billed');
        $this->assertSame(1, $t['zero_regular']);
        $this->assertSame(0.18, $t['zero_regular_share'], '1 / 569 regular bills');
        $this->assertSame(['100000000012'], array_column($this->r['lists']['zero_consume_regular']['rows'], 'account'));
        $this->assertSame(2365.0, $this->district('Sample One')['consume'], '2,330 + 40 - 5');
        $this->assertSame(1800.0, $this->district('Sample Two')['consume']);
        $this->assertSame(1510.0, $this->district('Sample Three')['consume']);

        // the bands (billed customers; the negative consumption is in no band): 0 -> n = 12; 1-10 -> the other 572
        $bands = collect($this->r['analysis']['bands'])->keyBy('band');
        $this->assertSame(1, $bands['0']['customers']);
        $this->assertSame(572, $bands['1-10']['customers']);
        $this->assertSame(0, $bands['11-20']['customers']);
        $this->assertSame(0, $bands['500+']['customers']);
        $this->assertSame(573, array_sum(array_column($this->r['analysis']['bands'], 'customers')));
        $this->assertSame(99.83, $bands['1-10']['share']);
    }

    public function test_b5_the_charge_breakdown_and_each_components_share(): void
    {
        $t = $this->r['analysis']['totals'];

        // water: 566 customers at 40.00 + one at 60.00 (n = 11) + five estimated at 32.00 - 20.00 (n = 15) = 22,840.00; the other four follow at 2%, 1%, 35%
        $this->assertSame(22840.0, $t['water']);
        $this->assertSame(456.8, $t['rural']);
        $this->assertSame(228.4, $t['fire']);
        $this->assertSame(7994.0, $t['sewage']);
        $this->assertSame(1148.0, $t['service'], '574 billed x 2.00');
        // the month total is the five charges (32,667.20) and the 2.80 the row n = 14 prints on top of its charges
        $this->assertSame(32670.0, $t['month_total']);
        $this->assertSame(69.92, $t['component_share']['water'], '22,840 / 32,667.20');
        $this->assertSame(24.47, $t['component_share']['sewage']);
        $this->assertSame(3.51, $t['component_share']['service']);
        $sample = $this->district('Sample Two');
        $this->assertSame(7200.0, $sample['water'], '180 x 40.00');
        $this->assertSame(2520.0, $sample['sewage'], '180 x 14.00');
    }

    // ---------------------------------------------------------------- B6 to B9

    public function test_b6_collections_and_the_collected_of_the_amount_due(): void
    {
        $t = $this->r['analysis']['totals'];

        // paid in full except every fifth account (115) and n = 17 and 20: month total 32,670.00 less 6,541.36 not paid
        $this->assertSame(26128.64, $t['paid']);
        $this->assertSame(20.0, $t['prev_payment']);
        $this->assertSame(5.0, $t['offset']);
        $this->assertSame(79.98, $t['collection_ratio'], '26,128.64 / 32,670.00');
        $this->assertSame(32970.0, $t['amount_due'], 'previous balance 450 + month total 32,670 + adjusted -150');
        $this->assertSame(79.25, $t['collected_of_due'], '26,128.64 / 32,970.00');

        // the second district: 36 of its 180 customers (every fifth, n = 245 to 420) paid nothing: 144 x 57.20 = 8,236.80 of 10,296.00
        $two = $this->district('Sample Two');
        $this->assertSame(8236.8, $two['paid']);
        $this->assertSame(80.0, $two['collection_ratio']);
        $three = $this->district('Sample Three');
        $this->assertSame(6921.2, $three['paid'], '30 of 151 paid nothing: 121 x 57.20');
        $this->assertSame(80.13, $three['collection_ratio']);
    }

    public function test_b7_receivables_never_netted_and_the_share_held_by_the_top_ten_percent(): void
    {
        $t = $this->r['analysis']['totals'];

        $this->assertSame(450.0, $t['previous_balance'], '100 + 50 + 500 - 200');
        $this->assertSame(-150.0, $t['adjusted']);
        $this->assertSame(6816.36, $t['balance_due']);
        $this->assertSame(117, $t['owing'], 'n = 1, 2, 5, 10, 17, 20 and 111 ordinary fifth accounts');
        $this->assertSame(7191.96, $t['owing_amount']);
        $this->assertSame(3, $t['credit'], 'n = 15, 16 and 18');
        $this->assertSame(375.6, $t['credit_amount'], '25.60 + 150 + 200: shown as a positive part, never netted off the owing');
        $this->assertEqualsWithDelta($t['balance_due'], $t['owing_amount'] - $t['credit_amount'], 0.001);

        $this->assertSame(50, $this->district('Sample One')['owing']);
        $this->assertSame(3359.56, $this->district('Sample One')['owing_amount']);
        $this->assertSame(36, $this->district('Sample Two')['owing']);
        $this->assertSame(2059.2, $this->district('Sample Two')['owing_amount']);

        // top debtors: the largest balance first (n = 17: 557.20, n = 1: 100.00, then 57.20 ...)
        $top = $this->r['lists']['top_debtor'];
        $this->assertSame(117, $top['total']);
        $this->assertSame(['100000000017', '100000000001'], array_slice(array_column($top['rows'], 'account'), 0, 2));
        $this->assertSame([557.2, 100.0, 57.2], array_slice(array_column($top['rows'], 'amount'), 0, 3));

        // the top 10 percent of 117 owing accounts is 12 (rounded up): 557.20 + 100.00 + ten of 57.20 = 1,229.20 of 7,191.96
        $c = $this->r['concentration'];
        $this->assertTrue($c['available']);
        $this->assertSame([117, 12, 7191.96, 1229.2, 17.09], [$c['owing'], $c['top_n'], $c['amount'], $c['top_amount'], $c['share']]);

        // for one district: Sample Two owes 2,059.20 on 36 accounts, all of 57.20: the top 4 hold 228.80 (11.11%)
        $two = app(CommercialReportData::class)->billingDetails(null, $this->two->id)['concentration'];
        $this->assertSame([36, 4, 2059.2, 228.8, 11.11], [$two['owing'], $two['top_n'], $two['amount'], $two['top_amount'], $two['share']]);
    }

    public function test_b8_adjustments(): void
    {
        $t = $this->r['analysis']['totals'];

        $this->assertSame(1, $t['adjusted_count']);
        $this->assertSame(-150.0, $t['adjusted']);
        $this->assertSame(1, $this->routeRow('SAMPLE ONE 4101')['adjusted_count']);
        $this->assertSame(0, $this->routeRow('SAMPLE TWO 4201')['adjusted_count']);
        $this->assertSame(['100000000016'], array_column($this->r['lists']['large_adjustment']['rows'], 'account'));
        $this->assertSame([-150.0], array_column($this->r['lists']['large_adjustment']['rows'], 'amount'));
    }

    public function test_b9_billed_but_not_paid(): void
    {
        $t = $this->r['analysis']['totals'];

        // a month total above zero and nothing paid: every fifth account (n = 5, 10 and 111 ordinary ones) and n = 17, 20 = 115, GH¢ 6,566.96
        $this->assertSame(115, $t['billed_unpaid']);
        $this->assertSame(6566.96, $t['billed_unpaid_amount']);
        $this->assertSame(20.03, $t['billed_unpaid_share'], '115 / 574 billed');
        $this->assertSame([48, 2734.56], [$this->district('Sample One')['billed_unpaid'], $this->district('Sample One')['billed_unpaid_amount']]);
        $this->assertSame([36, 2059.2], [$this->district('Sample Two')['billed_unpaid'], $this->district('Sample Two')['billed_unpaid_amount']]);
        $this->assertSame([30, 1716.0], [$this->district('Sample Three')['billed_unpaid'], $this->district('Sample Three')['billed_unpaid_amount']]);
        $this->assertSame(115, $this->r['lists']['billed_unpaid']['total']);
    }

    // ---------------------------------------------------------------- B10 to B12

    public function test_b10_the_tariff_recompute_check(): void
    {
        $t = $this->r['analysis']['totals'];

        // 572 bills have a rate and a consumption; one (n = 11) charged 60.00 for 10 units at rate 5.00 (50.00): 10.00 and 16.7% apart
        $this->assertSame(572, $t['tariff_checked']);
        $this->assertSame(1, $t['tariff_mismatch']);
        $this->assertSame(0.17, $t['tariff_mismatch_pct']);
        $this->assertSame(['100000000011'], array_column($this->r['lists']['tariff_mismatch']['rows'], 'account'));
        $this->assertSame([10.0], array_column($this->r['lists']['tariff_mismatch']['rows'], 'amount'), 'charged less rate x consume');
        $this->assertSame(1, $this->routeRow('SAMPLE ONE 4101')['tariff_mismatch']);

        // the tolerance: widen the amount beyond the difference and the bill is no longer a mismatch (a re-import with the new setting)
        $this->assertSame(0, $this->importWith(['gwl.commercial_tariff_tolerance_amount' => 10.5])['tariff_mismatch']);
        $this->assertSame(0, $this->importWith(['gwl.commercial_tariff_tolerance_amount' => 1.0, 'gwl.commercial_tariff_tolerance_pct' => 20.0])['tariff_mismatch'], '16.7% is within a 20% tolerance');
    }

    /** Imports the same file again as a NEWER upload of the same period with some settings changed; returns that batch's totals. */
    protected function importWith(array $config): array
    {
        config($config);
        $service = app(BillingDetailsImportService::class);
        // (a different sheet name makes it different bytes, so it is a new upload and not the same file again)
        $path = BillingDetailsSampleFile::write(BillingDetailsSampleFile::sampleSpec('ACCRA WEST', ['SAMPLE ONE', 'SAMPLE TWO', 'SAMPLE THREE']) + ['sheet' => 'rptBillingDetails_'.substr(uniqid(), -8)]);
        $this->workbooks[] = $path;
        ['batch' => $batch] = $service->register($path, 'again.xlsx', null);
        $batch = $service->process($batch);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status, (string) $batch->error_message);

        return app(CommercialReportData::class)->billingDetails()['analysis']['totals'];
    }

    public function test_b11_read_recency_is_counted_from_the_last_read_to_the_end_of_the_bill_period(): void
    {
        $t = $this->r['analysis']['totals'];

        // the period ends 21 September: a read on 10 September is 11 days before (0-30); 1 June is 112 days (over 90; n = 6 and 7); 2000-01-01 is never (n = 13)
        $this->assertSame(575, $t['read_0_30']);
        $this->assertSame(0, $t['read_31_60']);
        $this->assertSame(0, $t['read_61_90']);
        $this->assertSame(2, $t['read_over_90']);
        $this->assertSame(1, $t['read_never']);
        $this->assertSame(578, $t['read_0_30'] + $t['read_31_60'] + $t['read_61_90'] + $t['read_over_90'] + $t['read_never']);
        $this->assertSame(0.52, $t['read_over_90_share'], '3 / 578');

        // the list is the REGULAR bills with a read over 90 days old (or never): n = 13 only (n = 6 and 7 are estimated, so a stale read is expected)
        $stale = $this->r['lists']['stale_read'];
        $this->assertSame(['100000000013'], array_column($stale['rows'], 'account'));
        $this->assertSame([9999.0], array_column($stale['rows'], 'amount'), 'never read is 9999 days');
    }

    public function test_b12_the_integrity_checks(): void
    {
        $i = $this->r['integrity'];

        $this->assertSame(1, $i['components_off'], 'n = 14: month total 60.00 against 57.20 of charges');
        $this->assertSame(1, $i['negative_consume'], 'n = 15');
        $this->assertSame(1, $i['bill_month_outside'], 'n = 19: August against a September period');
        $b = $i['batches'][0];
        $this->assertSame(21, $b['routes_checked']);
        $this->assertSame(1, $b['routes_failed']);
        $this->assertSame('SAMPLE TWO 4202', $b['flagged'][0]['label']);
        $this->assertSame([30, 31], $b['flagged'][0]['diffs']['count']);
        $this->assertSame(1, $b['duplicates']);

        $this->assertSame(['100000000021'], array_column($this->r['lists']['duplicate_account']['rows'], 'account'));
        $this->assertSame(['100000000015'], array_column($this->r['lists']['negative_consume']['rows'], 'account'));
        $this->assertSame([-5.0], array_column($this->r['lists']['negative_consume']['rows'], 'amount'));
    }

    // ---------------------------------------------------------------- B13

    public function test_b13_the_trend_has_one_point_per_upload_labelled_with_its_dates(): void
    {
        $this->assertCount(1, $this->r['trend']);
        $p = $this->r['trend'][0];
        $this->assertSame('1 Sep to 21 Sep 2026', $p['label']);
        $this->assertSame($this->batch->id, $p['batch_id']);
        $this->assertSame([578, 574, 32670.0, 26128.64, 79.98, 6816.36], [$p['customers'], $p['billed_customers'], $p['month_total'], $p['paid'], $p['collection_ratio'], $p['balance_due']]);
        $this->assertSame(0.87, $p['estimated_share']);
        $this->assertEqualsCanonicalizing(['Sample One', 'Sample Three', 'Sample Two', 'District not known yet'], collect($p['districts'])->pluck('name')->all());

        // a second upload makes a second point; each upload is on its own
        $service = app(BillingDetailsImportService::class);
        $path = ReportWorkbooks::billingDetails($this->spec([$this->route2('SAMPLE ONE 4101', 1, 10)], '22-September-2026 - 30-September-2026'));
        $this->workbooks[] = $path;
        ['batch' => $b2] = $service->register($path, 'second.xlsx', null);
        $service->process($b2);

        $trend = app(CommercialReportData::class)->billingDetails()['trend'];
        $this->assertSame(['1 Sep to 21 Sep 2026', '22 Sep to 30 Sep 2026'], array_column($trend, 'label'));
        $this->assertSame([578, 10], array_column($trend, 'customers'));
        $this->assertSame(572.0, $trend[1]['month_total']);

        // one route's trend: the Route filter narrows every point to it
        $route = (int) CommercialBillingDetailRollup::query()->where('route_label_raw', 'SAMPLE ONE 4101')->value('route_id');
        $one = app(CommercialReportData::class)->billingDetails(null, null, null, null, $route)['trend'];
        $this->assertSame([30, 10], array_column($one, 'customers'));
    }

    protected function route2(string $label, int $from, int $to): array
    {
        return ['label' => $label, 'customers' => array_map(fn (int $n) => BillingDetailsSampleFile::customer($n), range($from, $to))];
    }
}
