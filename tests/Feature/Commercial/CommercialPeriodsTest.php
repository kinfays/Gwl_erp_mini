<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Billing;
use App\Livewire\Commercial\Reading;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingAnalyticsService;
use App\Services\Commercial\CommercialPeriods;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Phase 8a, the period model (design 8.30). A file's period is whatever it prints (a day, a range, a month): the five rules of
 * CommercialPeriods decide which uploads a view counts, how they add up and what is said about the days not covered.
 */
class CommercialPeriodsTest extends CommercialTestCase
{
    protected CommercialPeriods $periods;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periods = new CommercialPeriods;
        Carbon::setTestNow('2026-11-14 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{id: int, from: string, to: string, order: int} */
    protected function unit(int $id, string $from, string $to): array
    {
        return ['id' => $id, 'from' => $from, 'to' => $to, 'order' => $id];
    }

    // ---------------------------------------------------------------- labels and typed dates

    public function test_a_period_is_labelled_from_its_length_and_typed_bounds_are_read(): void
    {
        $this->assertSame('day', CommercialPeriods::label('2026-10-05', '2026-10-05'));
        $this->assertSame('month', CommercialPeriods::label('2026-10-01', '2026-10-31'));
        $this->assertSame('month', CommercialPeriods::label('2026-02-01', '2026-02-28'));
        $this->assertSame('range', CommercialPeriods::label('2026-10-01', '2026-10-15'), 'month to date is a range');
        $this->assertSame('range', CommercialPeriods::label('2026-10-02', '2026-10-31'));
        $this->assertSame('range', CommercialPeriods::label('2026-07-01', '2026-09-30'));

        $this->assertSame('5 Oct 2026', CommercialPeriods::describe('2026-10-05', '2026-10-05'));
        $this->assertSame('Oct 2026', CommercialPeriods::describe('2026-10-01', '2026-10-31'));
        $this->assertSame('1 Oct to 15 Oct 2026', CommercialPeriods::describe('2026-10-01', '2026-10-15'));
        $this->assertSame('15 Dec 2026 to 10 Jan 2027', CommercialPeriods::describe('2026-12-15', '2027-01-10'));

        $this->assertSame('2026-07-01', CommercialPeriods::bound('2026-07', false)->toDateString(), 'a typed month starts on its first day');
        $this->assertSame('2026-07-31', CommercialPeriods::bound('2026-07', true)->toDateString(), 'and ends on its last');
        $this->assertSame('2026-07-15', CommercialPeriods::bound('2026-07-15', true)->toDateString());
        $this->assertNull(CommercialPeriods::bound('2026-13-01', false));
        $this->assertNull(CommercialPeriods::bound('2026-02-30', false));
        $this->assertNull(CommercialPeriods::bound('July', false));
    }

    // ---------------------------------------------------------------- rule 1: identical period

    public function test_rule_1_of_two_batches_with_the_same_period_the_newest_wins(): void
    {
        $r = $this->periods->select([$this->unit(1, '2026-09-01', '2026-09-30'), $this->unit(2, '2026-09-01', '2026-09-30')]);

        $this->assertSame([2], $r['chosen']);
        $this->assertSame([['id' => 1, 'by' => 2, 'reason' => 'identical']], $r['shadowed']);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$r['from'], $r['to']], 'the default range is the latest uploaded period');
    }

    // ---------------------------------------------------------------- rule 2: a newer period that contains an older one shadows it

    public function test_rule_2_month_to_date_replaces_a_shorter_month_to_date_and_a_whole_month_replaces_its_days(): void
    {
        $mtd = $this->periods->select([$this->unit(1, '2026-10-01', '2026-10-10'), $this->unit(2, '2026-10-01', '2026-10-20')]);
        $this->assertSame([2], $mtd['chosen']);
        $this->assertSame('contained', $mtd['shadowed'][0]['reason']);

        $month = $this->periods->select([$this->unit(1, '2026-10-05', '2026-10-05'), $this->unit(2, '2026-10-06', '2026-10-06'), $this->unit(3, '2026-10-01', '2026-10-31')]);
        $this->assertSame([3], $month['chosen'], 'the whole month, uploaded last, replaces both days');
        $this->assertEqualsCanonicalizing([1, 2], array_column($month['shadowed'], 'id'));

        // an OLDER batch that contains a newer one does not shadow it: the newer one is more recent for its own days
        $older = $this->periods->select([$this->unit(1, '2026-10-01', '2026-10-31'), $this->unit(2, '2026-10-05', '2026-10-05')], '2026-10-01', '2026-10-31');
        $this->assertSame([], $older['shadowed']);
        $this->assertSame([2], $older['chosen'], 'newest first: the day, and the month overlaps it so it is left out of this view');
        $this->assertSame([['kept' => 2, 'dropped' => 1]], $older['overlaps']);
    }

    // ---------------------------------------------------------------- rule 3: partial overlap

    public function test_rule_3_a_partial_overlap_keeps_both_picks_the_newest_for_a_view_and_says_which_days_are_not_covered(): void
    {
        $r = $this->periods->select([$this->unit(1, '2026-08-01', '2026-08-15'), $this->unit(2, '2026-08-10', '2026-08-31')], '2026-08-01', '2026-08-31');

        $this->assertSame([], $r['shadowed'], 'neither contains the other: both stay stored');
        $this->assertSame([2], $r['chosen'], 'the newest first');
        $this->assertSame([['kept' => 2, 'dropped' => 1]], $r['overlaps']);
        $this->assertSame([['2026-08-10', '2026-08-31']], $r['covered']);
        $this->assertSame([['2026-08-01', '2026-08-09']], $r['gaps']);
        $this->assertSame([22, 31], [$r['covered_days'], $r['range_days']]);
        $this->assertSame('1 Aug to 9 Aug 2026', CommercialPeriods::describeIntervals($r['gaps']));

        // what an upload says before it is stored
        $effect = $this->periods->effectOfUpload('2026-08-10', '2026-08-31', [$this->unit(1, '2026-08-01', '2026-08-15'), $this->unit(2, '2026-08-10', '2026-08-31'), $this->unit(3, '2026-08-12', '2026-08-13'), $this->unit(4, '2026-07-01', '2026-07-31')]);
        $this->assertSame([2], $effect['replaces']);
        $this->assertSame([3], $effect['shadows']);
        $this->assertSame([1], $effect['overlaps']);
    }

    // ---------------------------------------------------------------- rule 4 and 5: ranges, flows add, stocks are the latest

    public function test_rule_5_a_range_counts_only_the_batches_inside_it_and_lists_the_one_partly_outside(): void
    {
        $units = [$this->unit(1, '2026-06-15', '2026-07-14'), $this->unit(2, '2026-07-01', '2026-07-31'), $this->unit(3, '2026-08-01', '2026-08-31')];

        $r = $this->periods->select($units, '2026-07-01', '2026-07-31');
        $this->assertSame([2], $r['chosen']);
        $this->assertSame([1], $r['partly_outside'], 'batch 1 overlaps the range\'s edge: excluded and listed');
        $this->assertSame([], $r['gaps']);

        $wide = $this->periods->select($units, '2026-06-01', '2026-08-31');
        $this->assertEqualsCanonicalizing([2, 3], $wide['chosen'], 'all three lie inside the range, but 1 and 2 overlap: the newer of the two');
        $this->assertSame([['kept' => 2, 'dropped' => 1]], $wide['overlaps']);
        $this->assertSame([['2026-06-01', '2026-06-30']], $wide['gaps'], 'June is covered by nothing that was chosen');

        // nothing inside: nothing is counted and the whole range is a gap
        $none = $this->periods->select($units, '2026-09-01', '2026-09-30');
        $this->assertSame([], $none['chosen']);
        $this->assertSame([['2026-09-01', '2026-09-30']], $none['gaps']);
        $this->assertSame(0, $none['covered_days']);
    }

    public function test_rule_4_distinct_periods_add_up_flows_are_summed_stocks_are_the_latest_and_ratios_are_recomputed(): void
    {
        $day = $this->seedBilling($this->billingFixture(), '2026-09-05', ['period_to' => '2026-09-05', 'districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id]]);
        $range = $this->seedBilling($this->billingFixture(), '2026-09-06', ['period_to' => '2026-09-20', 'districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id]]);
        $month = $this->seedBilling($this->billingFixture(), '2026-09-21', ['period_to' => '2026-09-30', 'districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id]]);

        // make the latest period's balances different so a stock can be told from a sum
        CommercialBillingRoute::query()->where('batch_id', $month->id)->where('route_code', 'SOWUTUOM 1')->update(['closing_balance' => 777, 'customers_count' => 9000, 'billed_total' => 12]);

        $byBatch = CommercialBillingRoute::query()->whereIn('batch_id', [$day->id, $range->id, $month->id])->get()->groupBy('batch_id')->all();
        $combined = $this->periods->combineBillingRoutes($byBatch, [$day->id, $range->id, $month->id]);
        $first = $combined->firstWhere('route_code', 'SOWUTUOM 1');

        $this->assertCount(4, $combined, 'one row per route');
        $this->assertEquals(3000.0, $first->billing_for_period, 'flows add up: 1,000 x 3');
        $this->assertEquals(2400.0, $first->total_payments);
        $this->assertEquals(300.0, $first->volume_total);
        $this->assertEquals(777.0, $first->closing_balance, 'a stock is the latest period\'s');
        $this->assertSame(9000, (int) $first->customers_count);
        $this->assertSame(12, (int) $first->billed_total, 'customers billed is a count: the latest period\'s');
        $this->assertEquals(0.0, $first->opening_balance, 'the opening balance is the earliest period\'s');
        $this->assertEquals(3000.0, $first->total_receivable, 'recomputed: opening + billing + adjustments');

        // ratios are worked out from the SUMS (not averaged): payments 3 x 1,200 over billing 3 x 1,600
        $totals = app(BillingAnalyticsService::class)->collections($combined);
        $this->assertEquals(75.0, $totals['total']['cash_ratio']);

        // one batch is itself, unchanged
        $one = $this->periods->combineBillingRoutes([$day->id => $byBatch[$day->id]], [$day->id]);
        $this->assertEquals(1000.0, $one->firstWhere('route_code', 'SOWUTUOM 1')->billing_for_period);
    }

    // ---------------------------------------------------------------- the Billing screen and its export

    protected function septemberInThreeUploads(): void
    {
        foreach ([['2026-09-01', '2026-09-10'], ['2026-09-11', '2026-09-11'], ['2026-09-12', '2026-09-30']] as [$from, $to]) {
            $this->seedBilling($this->billingFixture(), $from, ['period_to' => $to, 'segment' => 'new_service', 'districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id]]);
        }
    }

    public function test_the_billing_screen_adds_up_the_uploads_inside_a_typed_range_and_says_what_it_did(): void
    {
        $this->septemberInThreeUploads();
        $officer = $this->officer();

        $page = Livewire::actingAs($officer)->test(Billing::class)->set('dateFrom', '2026-09-01')->set('dateTo', '2026-09-30');
        $overview = $page->viewData('overview');

        $this->assertEquals(4800.0, $overview['totals']['billing_for_period'], 'three uploads of 1,600');
        $page->assertSee('3 uploads added up')->assertSee('flows (billing, payments, volume) are summed');
        $this->assertSame('range', $page->viewData('chosen')['id']);

        // the same figures in the file
        $response = $this->actingAs($officer)->get(route('commercial.export', ['report' => 'billing', 'format' => 'excel', 'from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk();
        $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(), IOFactory::load($response->baseResponse->getFile()->getPathname())->getAllSheets()));
        $this->assertStringContainsString('4800', $text);
        $this->assertStringContainsString('3 uploads that do not overlap are added up', $text);

        // a month shortcut sets the same dates
        $preset = Livewire::actingAs($officer)->test(Billing::class)->set('preset', '2026-09');
        $this->assertSame(['2026-09-01', '2026-09-30'], [$preset->get('dateFrom'), $preset->get('dateTo')]);

        // no dates: the screen is what it always was (the latest uploaded period: one snapshot)
        $plain = Livewire::actingAs($officer)->test(Billing::class);
        $this->assertSame(1600.0, (float) $plain->viewData('overview')['totals']['billing_for_period']);
    }

    public function test_a_range_with_a_gap_a_partial_overlap_and_a_batch_partly_outside_says_so(): void
    {
        $districts = ['districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id], 'segment' => 'new_service'];
        $this->seedBilling($this->billingFixture(), '2026-08-01', ['period_to' => '2026-08-15'] + $districts);
        $this->seedBilling($this->billingFixture(), '2026-08-10', ['period_to' => '2026-08-25'] + $districts);   // overlaps the first: the newer one is used
        $this->seedBilling($this->billingFixture(), '2026-07-20', ['period_to' => '2026-08-04'] + $districts);   // partly outside August

        $page = Livewire::actingAs($this->officer())->test(Billing::class)->set('dateFrom', '2026-08-01')->set('dateTo', '2026-08-31');

        $page->assertSee('No upload covers 1 Aug to 9 Aug 2026 and 26 Aug to 31 Aug 2026 of the range')
            ->assertSee('overlaps batch #2 and is left out of this view')
            ->assertSee('Left out because their period is only partly inside the range: #3');
        $this->assertSame(1600.0, (float) $page->viewData('overview')['totals']['billing_for_period'], 'only the newest of the overlapping pair counts');

        // a range with nothing inside it
        $empty = Livewire::actingAs($this->officer())->test(Billing::class)->set('dateFrom', '2026-12-01')->set('dateTo', '2026-12-31');
        $empty->assertSee('No upload lies inside this range');
    }

    public function test_month_to_date_replaces_a_shorter_one_in_the_snapshot_list(): void
    {
        $districts = ['districts' => ['SOWUTUOM' => $this->sowutuom->id], 'segment' => 'new_service'];
        $short = $this->seedBilling($this->billingFixture(), '2026-10-01', ['period_to' => '2026-10-10'] + $districts);
        $long = $this->seedBilling($this->billingFixture(), '2026-10-01', ['period_to' => '2026-10-20'] + $districts);
        $older = $this->seedBilling($this->billingFixture(), '2026-09-01', ['period_to' => '2026-09-30'] + $districts);

        $ids = array_column(app(BillingAnalyticsService::class)->snapshots(CommercialImportBatch::query()), 'id');

        $this->assertSame([$long->id, $older->id], $ids, 'the shorter month to date is replaced by the longer, newer one');
    }

    // ---------------------------------------------------------------- the Reading screen

    public function test_the_reading_screen_counts_whole_months_inside_a_typed_range_and_lists_a_month_partly_outside(): void
    {
        $this->seedReading([
            'R1' => ['2026-06-01' => [100, 10], '2026-07-01' => [200, 20], '2026-08-01' => [300, 30], '2026-09-01' => [400, 40]],
        ], options: ['names' => ['R1' => 'ADWOA BOATENG'], 'districts' => ['R1' => $this->sowutuom->id], 'strength' => 5000]);
        $officer = $this->officer();

        // the month shortcuts are what they always were
        $months = Livewire::actingAs($officer)->test(Reading::class)->set('tab', 'readers')->set('from', '2026-07')->set('to', '2026-08');
        $this->assertSame(220 + 330, $months->viewData('readers')[0]['visited'], 'July + August');

        // a date range: July is only partly inside (from the 15th), so it is left out and listed; August and September count
        $page = Livewire::actingAs($officer)->test(Reading::class)->set('tab', 'readers')->set('dateFrom', '2026-07-15')->set('dateTo', '2026-09-30');
        $this->assertSame(330 + 440, $page->viewData('readers')[0]['visited']);
        $page->assertSee('the whole months Aug 2026, Sep 2026')->assertSee('Left out because they are only partly inside the range: Jul 2026');

        // a range inside no whole month counts nothing
        $none = Livewire::actingAs($officer)->test(Reading::class)->set('tab', 'readers')->set('dateFrom', '2026-07-05')->set('dateTo', '2026-07-20');
        $none->assertSee('no whole month lies inside it');
        $this->assertSame([], $none->viewData('readers'));

        // the export takes the same dates
        $response = $this->actingAs($officer)->get(route('commercial.export', ['report' => 'reading-trend', 'format' => 'excel', 'from' => '2026-07-15', 'to' => '2026-09-30']))->assertOk();
        $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(), IOFactory::load($response->baseResponse->getFile()->getPathname())->getAllSheets()));
        $this->assertStringContainsString('2026-07-15 to 2026-09-30', $text);
    }

    // ---------------------------------------------------------------- the upload preview

    public function test_the_billing_preview_names_the_batch_a_new_period_shadows_or_overlaps(): void
    {
        $districts = ['districts' => ['SOWUTUOM' => $this->sowutuom->id], 'segment' => 'new_service'];
        $inside = $this->seedBilling($this->billingFixture(), '2026-09-10', ['period_to' => '2026-09-20'] + $districts);
        $across = $this->seedBilling($this->billingFixture(), '2026-08-20', ['period_to' => '2026-09-05'] + $districts);
        $this->actingAs($this->officer());

        // a file for all of September (the report's bill period is month grained) with the matching segment
        $preview = $this->previewOf($this->billingFile(['period' => 'September-2026 - September-2026', 'status' => 'New Service Customers Only']));
        $messages = collect($preview['warnings'])->pluck('message')->implode(' | ');

        $this->assertStringContainsString("This period contains batch #{$inside->id} (10 Sep to 20 Sep 2026): this newer file replaces it in every view", $messages);
        $this->assertStringContainsString("This period overlaps batch #{$across->id} (20 Aug to 5 Sep 2026) only in part", $messages);
        $this->assertFalse($preview['blocked'], collect($preview['errors'])->pluck('message')->implode(' | '));
    }

    public function test_the_reading_preview_names_the_batch_whose_period_overlaps_in_part(): void
    {
        $existing = $this->seedReading(['R1' => ['2026-07-01' => [1, 1], '2026-08-01' => [1, 1], '2026-09-01' => [1, 1]]]);
        $this->actingAs($this->officer());

        // the default file covers Jun and Jul 2026
        $preview = $this->previewOf($this->readingFile());
        $messages = collect($preview['warnings'])->pluck('message')->implode(' | ');

        $this->assertStringContainsString("overlaps batch #{$existing->id} (1 Jul to 1 Sep 2026) only in part", $messages);
    }
}
