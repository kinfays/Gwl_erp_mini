<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsTrendsSet;

/**
 * Phase 8f: T2 to T8 on the invented series (TrendsSampleSet), loaded through the real import paths. Every figure below is worked out by hand from the
 * schedule table of that class: visited is always 10 of a strength of 10 on each of the 6 routes (60 per upload), so the skip rate of an upload is its
 * skips / 60, and its billing is 60 customers of 57.20 each with `unpaid` customers not paying and `estimated` estimated.
 *
 *   upload        skips             skip rate   unpaid  estimated   estimated share   collection ratio
 *   week-4-late   3+0+2+1+1+3 = 10   16.67%      11       7         11.67%            81.67%   (owing 11 x 57.20 = 629.20)
 *   week-2        1+1+2+3+3+4 = 14   23.33%      15       9         15.00%            75.00%   (owing 858.00)
 *   week-1        2+1+3+4+3+5 = 18   30.00%      19      13         21.67%            68.33%
 *   month (Sep)   1+1+1+3+3+3 = 12   20.00%      15       9         15.00%            75.00%
 *   last-year     2+2+3+4+4+5 = 20   33.33%      22      15         25.00%            63.33%
 */
class TrendsSeriesTest extends CustomerTestCase
{
    use BuildsBillingDetails;
    use BuildsTrendsSet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->setUpBillingDetails();
        $this->loadTrendsSeries();
        $this->actingAs($this->trendViewer());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_series_loads_through_the_real_import_paths(): void
    {
        foreach (['reading', 'billing'] as $kind) {
            $this->assertCount(5, $this->series[$kind]);

            foreach ($this->series[$kind] as $key => $batch) {
                $this->assertContains($batch->status, [CommercialImportBatch::STATUS_IMPORTED, CommercialImportBatch::STATUS_SUPERSEDED], $kind.' '.$key.': '.$batch->error_message);
            }
        }

        $this->assertCount(3, $this->series['customers']);
    }

    public function test_t2_compares_the_newest_week_with_the_previous_week_and_never_with_the_month(): void
    {
        $r = $this->trend('t2');
        $m = $this->measuresOf($r['parts']['reading']);
        $p = $r['parts']['reading']['data'];

        $this->assertSame('2026-10-22', $p['current']['from']);
        $this->assertSame('2026-10-14', $p['compared']['to'], 'the previous week, not the month of September');
        $this->assertSame(7, $p['compared']['length']);

        $this->assertEquals(16.67, $m['skip_rate']['now']);
        $this->assertEquals(23.33, $m['skip_rate']['then']);
        $this->assertEquals(-6.66, $m['skip_rate']['change']);   // 16.67 - 23.33 in whole hundredths
        $this->assertSame('good', $m['skip_rate']['tone']);       // a skip rate is good when it falls
        $this->assertEquals(0.0, $m['coverage']['change']);
        $this->assertSame('neutral', $m['coverage']['tone']);
    }

    public function test_t2_billing_figures_and_their_tone(): void
    {
        $m = $this->measuresOf($this->trend('t2')['parts']['billing']);

        $this->assertEquals(11.67, $m['estimated_share']['now']);
        $this->assertEquals(15.0, $m['estimated_share']['then']);
        $this->assertEquals(-3.33, $m['estimated_share']['change']);
        $this->assertSame('good', $m['estimated_share']['tone']);
        $this->assertEquals(81.67, $m['collection_ratio']['now']);
        $this->assertEquals(6.67, $m['collection_ratio']['change']);
        $this->assertSame('good', $m['collection_ratio']['tone']);
        $this->assertEquals(629.2, $m['owing_amount']['now']);
        $this->assertEquals(858.0, $m['owing_amount']['then']);
        $this->assertEquals(-228.8, $m['owing_amount']['change']);
        $this->assertEquals(-26.67, $m['owing_amount']['change_pct']);
        $this->assertSame('neutral', $m['month_total']['tone']);   // no direction: the change is shown, never judged
    }

    public function test_a_period_with_no_comparable_previous_one_says_so_and_shows_no_change(): void
    {
        // the month of September: the earlier uploads are 7 days long, which is not comparable with 30
        $r = $this->trend('t2', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $p = $r['parts']['reading']['data'];

        $this->assertNull($p['compared']);
        $this->assertStringContainsString('No comparable previous period', $p['note']);

        foreach ($p['measures'] as $m) {
            $this->assertNull($m['then']);
            $this->assertNull($m['change']);
            $this->assertNull($m['tone']);
        }
    }

    public function test_t6_year_on_year_uses_the_same_dates_a_year_earlier_or_says_none(): void
    {
        $r = $this->trend('t6', ['from' => '2026-10-01', 'to' => '2026-10-07']);
        $m = $this->measuresOf($r['parts']['reading']);

        $this->assertSame('2025-10-01', $r['parts']['reading']['data']['compared']['from']);
        $this->assertEquals(30.0, $m['skip_rate']['now']);
        $this->assertEquals(33.33, $m['skip_rate']['then']);
        $this->assertEquals(-3.33, $m['skip_rate']['change']);

        $b = $this->measuresOf($r['parts']['billing']);
        $this->assertEquals(21.67, $b['estimated_share']['now']);
        $this->assertEquals(25.0, $b['estimated_share']['then']);

        // the newest week (22 to 28 October) has no upload a year earlier
        $none = $this->trend('t6')['parts']['reading']['data'];
        $this->assertNull($none['compared']);
        $this->assertStringContainsString('year', strtolower($none['note']));
    }

    public function test_t4_rolling_windows_add_only_the_uploads_inside_them_and_state_the_days_covered(): void
    {
        $w = collect($this->trend('t4')['parts']['reading']['data']['windows'])->keyBy('days');

        $this->assertSame('2026-10-28', $w[7]['to']);
        $this->assertSame(7, $w[7]['covered_days']);
        $this->assertEquals(60, $w[7]['figures']['visited']);
        $this->assertEquals(16.67, $w[7]['figures']['skip_rate']);

        // 30 days = 29 September to 28 October: the three weeks (21 days, a gap of 15 to 21 October); the September month is only partly inside
        $this->assertSame(21, $w[30]['covered_days']);
        $this->assertSame('21 of 30 days are covered', $w[30]['coverage_text']);
        $this->assertEquals(180, $w[30]['figures']['visited']);
        $this->assertEquals(23.33, $w[30]['figures']['skip_rate']);   // (18 + 14 + 10) / 180

        // 90 days reaches back past the month of September: 30 + 21 days
        $this->assertSame(51, $w[90]['covered_days']);
        $this->assertSame('51 of 90 days are covered', $w[90]['coverage_text']);
        $this->assertEquals(240, $w[90]['figures']['visited']);
        $this->assertEquals(22.5, $w[90]['figures']['skip_rate']);   // (12 + 42) / 240
    }

    public function test_t5_rank_movement_of_ccas_routes_and_districts(): void
    {
        $r = $this->trend('t5');
        $cca = collect($r['parts']['ccas']['data']['rows'])->keyBy('id');

        // skip rate now: 80003 10%, 80001 15%, 80002 20%, 80004 30%; before: 80001 10%, 80002 20%, 80003 30%, 80004 40%
        $this->assertSame(1, $cca['80003']['rank_now']);
        $this->assertSame(3, $cca['80003']['rank_then']);
        $this->assertSame('up', $cca['80003']['direction']);
        $this->assertSame(2, $cca['80003']['move']);
        $this->assertSame('down', $cca['80001']['direction']);
        $this->assertSame('down', $cca['80002']['direction']);
        $this->assertSame('same', $cca['80004']['direction']);

        $routes = collect($r['parts']['routes_skip']['data']['rows']);
        $this->assertSame(1, $routes->first(fn ($x) => str_ends_with($x['label'], '4102'))['rank_now']);   // 0% skipped
        $this->assertCount(6, $routes);

        // districts: Sowutuom (3+0+2 skips) 5/30 = 16.67% now, (1+1+2)/30 = 13.33% before; Odorkor 5/30 now, 10/30 before
        $districts = collect($r['parts']['districts_skip']['data']['rows']);
        $this->assertCount(2, $districts);

        $collection = $r['parts']['districts_collection']['data'];
        $this->assertTrue($collection['available']);
    }

    public function test_t7_projects_flows_only_and_labels_it_not_a_forecast(): void
    {
        $p = $this->trend('t7')['parts']['reading']['data'];

        $this->assertSame('2026-10', $p['month']);
        $this->assertSame(21, $p['covered_days']);
        $this->assertSame('projection, not a forecast', $p['label']);
        $this->assertSame(['visited'], array_column($p['measures'], 'key'));

        $visited = $p['measures'][0];
        $this->assertSame('projected', $visited['state']);
        $this->assertEquals(180, $visited['actual']);
        $this->assertEquals(265.71, $visited['projection']);   // 180 / 21 x 31
        $this->assertNull($visited['target']);

        $billing = collect($this->trend('t7')['parts']['billing']['data']['measures'])->keyBy('key');
        $this->assertSame(['month_total', 'paid'], $billing->keys()->all());   // never the owing balance, a stock
        $this->assertEquals(10296.0, $billing['month_total']['actual']);
        $this->assertEquals(15198.86, $billing['month_total']['projection']);   // 10296 / 21 x 31
        $this->assertEquals(7722.0, $billing['paid']['actual']);
        $this->assertEquals(11399.14, $billing['paid']['projection']);
    }

    public function test_t7_withholds_below_the_minimum_days_and_a_complete_month_is_not_projected(): void
    {
        config(['gwl.commercial_projection_min_days' => 22]);
        $this->assertSame('withheld', $this->trend('t7')['parts']['reading']['data']['measures'][0]['state']);

        config(['gwl.commercial_projection_min_days' => 7]);
        $september = $this->trend('t7', ['month' => '2026-09'])['parts']['reading']['data'];
        $this->assertSame(30, $september['covered_days']);
        $this->assertSame('complete', $september['measures'][0]['state']);
        $this->assertNull($september['measures'][0]['projection']);
    }

    public function test_t8_lateness_the_gaps_and_the_on_time_share(): void
    {
        $r = $this->trend('t8', ['month' => '2026-10']);
        $part = $r['parts']['reading_route']['data'];

        $this->assertSame(3, $part['deadline_days']);
        $this->assertSame(5, $part['total']);
        $this->assertSame(4, $part['on_time']);   // lateness 1, 2, 2, 0 and 6 days against a deadline of 3
        $this->assertEquals(80.0, $part['on_time_share']);

        $late = collect($part['uploads'])->where('late', true);
        $this->assertCount(1, $late);
        $this->assertSame(6, $late->first()['lateness']);
        $this->assertSame('2026-11-03', $late->first()['imported']);

        $sowutuom = collect($part['districts'])->firstWhere('id', $this->sowutuom->id);
        $this->assertSame(21, $sowutuom['covered_days']);
        $this->assertSame([['2026-10-15', '2026-10-21'], ['2026-10-29', '2026-10-31']], $sowutuom['gaps']);
        $this->assertSame(4, $sowutuom['on_time']);
        $this->assertSame('2026-11-03', $sowutuom['last_import']);
        $this->assertSame(13, $sowutuom['days_ago']);

        // the customer list is on time (imported the day after its as-of date) and a daily snapshot is labelled by its date
        $list = $r['parts']['customers']['data'];
        $this->assertSame(6, $list['total']);   // one snapshot per district and file: 3 files x 2 districts
        $this->assertEquals(100.0, $list['on_time_share']);
        $this->assertContains('31 Oct 2026', array_column($list['uploads'], 'label'));
    }
}
