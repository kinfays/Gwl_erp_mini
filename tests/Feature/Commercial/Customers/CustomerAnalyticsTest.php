<?php

namespace Tests\Feature\Commercial\Customers;

use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerFilters;
use App\Services\Commercial\Customers\CustomerSnapshots;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commercial\ReportWorkbooks;

/** The numbers of the analytics, checked by hand against a ten-customer district. As-of date 5 Oct 2026. */
class CustomerAnalyticsTest extends CustomerTestCase
{
    /** @return list<array<string, mixed>> */
    protected function tenCustomers(): array
    {
        $c = fn (int $n, array $o = []) => ReportWorkbooks::customer($n, $o);

        return [
            $c(1, ['balance' => 101.0]), $c(2, ['balance' => 102.0]), $c(3, ['balance' => 103.0]), $c(4, ['balance' => 104.0]),
            $c(5, ['category' => '612', 'meter_status' => 'F', 'balance' => 0, 'last_bill_amount' => 50.0, 'last_read_date' => '2026-03-01']),
            $c(6, ['status' => 'ACTN', 'meter_status' => 'N', 'balance' => -20.0]),
            $c(7, ['status' => 'DISC', 'balance' => 300.0, 'last_bill_amount' => 100.0]),
            $c(8, ['status' => 'SUSP', 'category' => '613', 'balance' => 10.0]),
            $c(9, ['status' => 'TRFR', 'balance' => 0]),
            $c(10, ['balance' => 1000.0, 'last_bill_amount' => 100.0]),
        ];
    }

    protected function filters(?int $restriction = null, array $narrow = []): CustomerFilters
    {
        $snapshots = app(CustomerSnapshots::class);

        return $snapshots->filters($snapshots->current($restriction), $restriction, $narrow);
    }

    protected function loadTen(): void
    {
        $this->loadCustomers(['routes' => [
            ['name' => '1001', 'customers' => array_slice($this->tenCustomers(), 0, 6)],
            ['name' => '1002', 'customers' => array_slice($this->tenCustomers(), 6)],
        ]]);
    }

    public function test_the_customer_base_overview(): void
    {
        $this->loadTen();
        $service = app(CustomerAnalyticsService::class);
        $a = $service->overview($this->filters());

        $this->assertSame(10, $a['total']);
        $this->assertSame(6, $a['billing']);
        $this->assertSame(1, $a['active_non_billing']);
        $this->assertSame(1, $a['disconnected']);
        $this->assertSame(1, $a['suspended']);
        $this->assertSame(1, $a['other_status'], 'TRFR: meaning unconfirmed, so it is only "other"');
        $this->assertSame(60.0, $a['billing_share']);
        $this->assertSame(2, $a['route_count'], 'company-wide the route count comes from the batches, not from a scan of every route');
        $this->assertSame([], $a['top_routes'], 'the route list is only built for one district');

        $inDistrict = $service->overview($this->filters(null, ['district_id' => $this->sowutuom->id]));
        $this->assertSame(6, $inDistrict['top_routes'][0]['customers']);
        $this->assertSame(2, $inDistrict['route_count']);
        $this->assertSame(1, $inDistrict['top_routes'][0]['district_id'] === $this->sowutuom->id ? 1 : 0);

        $labels = collect($a['by_status'])->pluck('label')->all();
        $this->assertContains('TRFR', $labels, 'an unconfirmed status shows its raw code, not a guess');
        $this->assertContains('Active (billing) (ACTB)', $labels);
        $this->assertSame(['Domestic' => 8, 'Commercial' => 1, 'Industrial' => 1], collect($a['by_group'])->pluck('customers', 'label')->all());
    }

    public function test_meter_health(): void
    {
        $this->loadTen();
        $m = app(CustomerAnalyticsService::class)->meters($this->filters());

        $this->assertSame(8, $m['totals']['working']);
        $this->assertSame(1, $m['totals']['faulty']);
        $this->assertSame(1, $m['totals']['no_meter']);
        $this->assertSame(10.0, $m['totals']['faulty_pct']);
        $this->assertSame(1, $m['totals']['faulty_billing'], 'customer 5 is faulty and still ACTB');
        $this->assertSame(0, $m['totals']['no_meter_billing'], 'customer 6 has no meter but is ACTN');
        $this->assertSame(1, $m['faulty_ageing']['faulty']);
        $this->assertSame(1, $m['faulty_ageing']['bands'][3]['customers'], 'last read 1 Mar: more than 180 days before 5 Oct');
    }

    public function test_receivables_buckets_and_concentration(): void
    {
        $this->loadTen();
        $r = app(CustomerAnalyticsService::class)->receivables($this->filters());

        $this->assertSame(1720.0, $r['debit']);
        $this->assertSame(7, $r['debit_count']);
        $this->assertSame(-20.0, $r['credit']);
        $this->assertSame(1700.0, $r['net']);
        $this->assertSame(170.0, $r['average_balance']);

        $buckets = collect($r['buckets'])->pluck('customers', 'bucket')->all();
        $this->assertSame([0 => 1, 1 => 2, 2 => 1, 3 => 5, 4 => 0, 5 => 1, 6 => 0, 7 => 0], $buckets);

        $this->assertSame(7, $r['concentration']['debtors']);
        $this->assertEqualsWithDelta(58.1, $r['concentration']['top1_share'], 0.1, 'the single biggest debtor owes 1000 of 1720');
        $this->assertSame(2, $r['owing_after_leaving']['debtors']);
        $this->assertSame(310.0, $r['owing_after_leaving']['debit'], 'DISC owes 300 and SUSP owes 10');
        $this->assertSame(103.0, $r['median_debit'], 'the debtors are 10, 101, 102, 103, 104, 300, 1000');
        $this->assertSame(101.0, $r['median_balance']);
    }

    public function test_collection_dormancy_and_ghosts(): void
    {
        $this->loadTen();
        $service = app(CustomerAnalyticsService::class);

        $c = $service->collection($this->filters());
        $this->assertSame(10, $c['customers']);
        $this->assertSame(810.0, $c['billed']);
        $this->assertSame(600.0, $c['paid']);
        $this->assertSame(74.1, $c['paid_to_billed']);
        $this->assertSame(81.0, $c['average_bill']);
        $this->assertSame(0, $c['unpaid'][2]['customers'], 'every customer paid on 28 Sep');

        $d = $service->dormancy($this->filters());
        $this->assertSame(1, $d['windows'][0]['no_read'], '30 days: only customer 5');
        $this->assertSame(1, $d['windows'][3]['no_read'], '180 days: only customer 5 (last read 1 Mar)');
        $this->assertSame(0, $d['never_read']);
        $this->assertSame(0, $d['ghost'], 'every billing customer was billed on 25 Sep');
    }

    public function test_filters_narrow_every_figure(): void
    {
        $this->loadTen();
        $service = app(CustomerAnalyticsService::class);
        $faulty = DB::table('commercial_meter_statuses')->where('code', 'F')->value('id');
        $disc = DB::table('commercial_customer_statuses')->where('code', 'DISC')->value('id');

        $this->assertSame(1, $service->overview($this->filters(null, ['meter_status_id' => $faulty]))['total']);
        $this->assertSame(1, $service->overview($this->filters(null, ['status_id' => $disc]))['total']);
        $this->assertSame(8, $service->overview($this->filters(null, ['group' => 'domestic']))['total']);
        $this->assertSame(6, $service->overview($this->filters(null, ['billing_only' => true]))['total']);
        $this->assertSame(0, $service->overview($this->filters(null, ['district_id' => $this->odorkor->id]))['total']);
    }

    public function test_rollup_totals_equal_the_raw_customers(): void
    {
        $this->loadTen();
        $batchId = (int) DB::table('commercial_customer_batches')->value('id');

        $this->assertSame((int) DB::table('commercial_customers')->count(), (int) DB::table('commercial_customer_rollups')->where('batch_id', $batchId)->sum('customer_count'));
        $this->assertSame((int) DB::table('commercial_customers')->sum('balance'), (int) DB::table('commercial_customer_rollups')->where('batch_id', $batchId)->sum('balance_sum'));
        $this->assertSame((int) DB::table('commercial_customers')->where('balance', '>', 0)->sum('balance'), (int) DB::table('commercial_customer_rollups')->where('batch_id', $batchId)->sum('debit_sum'));
        $this->assertSame((int) DB::table('commercial_customers')->sum('last_paid_amount'), (int) DB::table('commercial_customer_rollups')->where('batch_id', $batchId)->sum('paid_sum'));
        $this->assertSame((int) DB::table('commercial_customers')->sum('last_bill_amount'), (int) DB::table('commercial_customer_rollups')->where('batch_id', $batchId)->sum('billed_sum'));
    }

    protected function sixCustomers(array $over = []): array
    {
        $c = fn (int $n, array $o = []) => ReportWorkbooks::customer($n, ($over[$n] ?? []) + $o);

        return [
            $c(1, ['connect_date' => '2026-09-10']),
            $c(2, ['connect_date' => '2026-09-20', 'meter_no' => 'M-SHARED']),
            $c(3, ['connect_date' => '2026-08-15', 'meter_no' => 'M-SHARED']),
            $c(4, ['mobile' => null]),
            $c(5, ['mobile' => '12345']),
            $c(6, ['email' => null, 'address' => null, 'average_consume' => 100]),
        ];
    }

    public function test_growth_quality_consumption_and_the_migration_matrix(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $this->sixCustomers()]]], '2026-10-05');
        $service = app(CustomerAnalyticsService::class);

        $g = $service->growth($this->filters());
        $this->assertSame(6, $g['new']);
        $this->assertSame(['2026-08-01' => 1, '2026-09-01' => 2], collect($g['connections_by_month'])->pluck('customers', 'month')->all(), 'only the last 24 months are kept');

        $q = collect($service->quality($this->filters())['issues'])->pluck('count', 'issue');
        $this->assertSame(2, $q['missing_mobile'], 'customer 4 has none, customer 5\'s is not a valid number');
        $this->assertSame(1, $q['invalid_phone']);
        $this->assertSame(1, $q['missing_email']);
        $this->assertSame(1, $q['missing_address']);
        $this->assertSame(2, $q['shared_meter']);
        $this->assertSame(0, $q['unknown_category']);

        $c = $service->consumption($this->filters());
        $this->assertSame(6, $c['customers']);
        $this->assertSame(8.0, $c['rows'][0]['typical']);
        $this->assertSame(1, $c['outliers'], '100 is above 5 times the median of 8');

        // A second upload: customer 2 is disconnected.
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $this->sixCustomers([2 => ['status' => 'DISC']])]]], '2026-11-05');
        $g = $service->growth($this->filters());

        $this->assertSame(1, $g['migration'][0]['customers']);
        $this->assertSame('ACTB', $g['migration'][0]['from_code']);
        $this->assertSame('DISC', $g['migration'][0]['to_code']);
    }

    public function test_a_month_key_means_the_last_upload_of_each_district_in_that_month(): void
    {
        $service = app(CustomerAnalyticsService::class);
        $snapshots = app(CustomerSnapshots::class);

        // weekly uploads: three in October, one in November
        foreach ([['2026-10-05', 1, 4], ['2026-10-12', 1, 5], ['2026-10-26', 1, 6], ['2026-11-02', 1, 7]] as [$date, $from, $to]) {
            $this->loadCustomers($this->simpleSpec($from, $to), $date, 'weekly');
        }

        $october = $snapshots->current(null, null, null, '2026-10');
        $this->assertCount(1, $october);
        $this->assertSame('2026-10-26', $october->first()->as_of_date->toDateString(), 'the last week of October');
        $this->assertSame(6, $service->overview($snapshots->filters($october, null))['total']);

        $this->assertSame(7, $service->overview($snapshots->filters($snapshots->current(null, null, null, '2026-11'), null))['total']);
        $this->assertSame(0, $snapshots->current(null, null, null, '2026-09')->count());
        $this->assertSame(['2026-11', '2026-10'], $snapshots->monthEnds(null));

        // an exact weekly key still means that one upload
        $week = $snapshots->current(null, null, null, '2026-W42');
        $this->assertSame(5, $service->overview($snapshots->filters($week, null))['total']);
    }

    public function test_the_trend_can_be_taken_at_month_end(): void
    {
        foreach ([['2026-10-05', 4], ['2026-10-26', 6], ['2026-11-02', 7], ['2026-11-30', 9]] as [$date, $to]) {
            $this->loadCustomers($this->simpleSpec(1, $to), $date, 'weekly');
        }

        $monthly = app(CustomerAnalyticsService::class)->trend(null, null, null, 12, true)['series'];

        $this->assertSame(['2026-10', '2026-11'], array_column($monthly, 'period'));
        $this->assertSame([6, 9], array_column($monthly, 'customers'), 'each month shows its LAST upload');
        $this->assertSame('month-end', $monthly[0]['type']);
        $this->assertSame(3, $monthly[1]['change']['customers']);

        $weekly = app(CustomerAnalyticsService::class)->trend(null, null, null, 12, false)['series'];
        $this->assertCount(4, $weekly);
    }

    public function test_the_trend_has_one_point_per_period_and_the_change_since_the_last(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $this->sixCustomers()]]], '2026-10-05');
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [...$this->sixCustomers(), ReportWorkbooks::customer(7)]]]], '2026-11-05');

        $trend = app(CustomerAnalyticsService::class)->trend(null, null, null)['series'];

        $this->assertSame(['2026-10', '2026-11'], array_column($trend, 'period'));
        $this->assertSame([6, 7], array_column($trend, 'customers'));
        $this->assertNull($trend[0]['change']);
        $this->assertSame(1, $trend[1]['change']['customers']);
    }
}
