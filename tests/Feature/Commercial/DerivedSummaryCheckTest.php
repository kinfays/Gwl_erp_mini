<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\CrossAnalysis;
use App\Models\CommercialImportBatch;
use App\Services\Commercial\CommercialExportService;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\Cross\DerivedSummaryCheck;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsDerivedSummary;

/**
 * Phase 8h, X12 extended: the billing summary derived from the detailed report against the uploaded one, every column, route by route and in total.
 * The uploaded twin is built from the HAND-WORKED figures (BuildsDerivedSummary), never from the derived summary, so "agrees" proves the derivation.
 */
class DerivedSummaryCheckTest extends CommercialTestCase
{
    use BuildsBillingDetails;
    use BuildsDerivedSummary;

    protected const TOLERANCE = ['money' => 0.10, 'volume' => 0.5];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        config(['gwl.commercial_customer_list_enabled' => false, 'gwl.commercial_module_enabled' => true]);
        $this->setUpBillingDetails();
        $this->tolerant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> the hand-worked routes as the check takes them (district 1 or 2 by name) */
    protected function rows(array $change = []): array
    {
        return array_map(function (array $r) use ($change) {
            return array_merge(['district_id' => $r['district'] === 'Sowutuom' ? 1 : 2, 'district_label_raw' => $r['district'], 'route_code' => $r['code']], collect($r)->except(['district', 'code'])->all(), $change[$r['code']] ?? []);
        }, $this->expectedRoutes());
    }

    protected function bands(): array
    {
        return [['band' => '<=5', 'customers' => 3, 'volume' => 14.00, 'amount' => 83.28], ['band' => '>5', 'customers' => 6, 'volume' => 53.50, 'amount' => 307.32]];
    }

    protected function column(array $result, string $key): array
    {
        return collect($result['columns'])->firstWhere('key', $key);
    }

    // ---------------------------------------------------------------- the pure check

    public function test_identical_summaries_agree_in_every_column_and_the_verdict_says_so(): void
    {
        $r = (new DerivedSummaryCheck)->compare($this->rows(), $this->rows(), $this->bands(), $this->bands(), self::TOLERANCE);

        $this->assertTrue($r['agrees']);
        $this->assertSame('The detailed report reproduces the billing summary for this period', $r['verdict']);
        $this->assertSame(23, count($r['columns']));
        $this->assertSame(array_fill(0, 23, 'agrees'), array_column($r['columns'], 'status'));
        $this->assertSame(['agrees', 'agrees', 'agrees'], array_column($r['bands'], 'status'));
        $this->assertSame([], $r['worst']);
        $this->assertSame(3, $r['routes_compared']);
    }

    public function test_a_differing_column_says_by_how_much_in_total_and_which_routes_differ_most(): void
    {
        $upload = $this->rows(['4101' => ['payment_for_month' => 194.12, 'total_payments' => 200.12, 'closing_balance' => 303.32], '4201' => ['billed_actual_reading' => 4, 'billed_total' => 4]]);
        $r = (new DerivedSummaryCheck)->compare($upload, $this->rows(), $this->bands(), $this->bands(), self::TOLERANCE);

        $this->assertFalse($r['agrees']);
        $paid = $this->column($r, 'payment_for_month');
        $this->assertSame('differs', $paid['status']);
        $this->assertSame(-5.00, $paid['difference'], 'derived minus uploaded: 189.12 - 194.12');
        $this->assertSame(-1.42, $paid['difference_pct']);   // -5.00 of the uploaded total 353.28 (194.12 + 101.96 + 57.20)
        $this->assertStringStartsWith('differs by -5.00', $paid['text']);
        $this->assertSame(1, $paid['routes_differing']);
        $this->assertSame('agrees', $this->column($r, 'billing_for_period')['status']);
        $this->assertSame('differs', $this->column($r, 'billed_total')['status']);
        $this->assertStringContainsString('differs by', $this->column($r, 'billed_actual_reading')['text']);
        $this->assertStringStartsWith('The detailed report differs from the billing summary in: ', $r['verdict']);
        $this->assertStringContainsString('Payment for the month', $r['verdict']);
        $this->assertStringContainsString('Billed: total', $r['verdict']);
        $this->assertStringNotContainsString('Billing for the period', $r['verdict']);
        // the routes that differ most: 4101 (three money columns) before 4201 (two count columns)
        $this->assertSame('Sowutuom 4101', $r['worst'][0]['route']);
        $this->assertSame(3, $r['worst'][0]['columns']);
        $this->assertSame('Odorkor 4201', $r['worst'][1]['route']);
    }

    public function test_the_tolerances_money_and_volume_per_route_and_counts_exactly(): void
    {
        $check = new DerivedSummaryCheck;
        $ok = $check->compare($this->rows(['4101' => ['billing_for_period' => 271.36, 'volume_total' => 46.60]]), $this->rows(), $this->bands(), $this->bands(), self::TOLERANCE);
        $this->assertSame('agrees', $this->column($ok, 'billing_for_period')['status'], 'within GH¢ 0.10 per route');
        $this->assertSame('agrees', $this->column($ok, 'volume_total')['status'], 'within 0.5 thousand litres per route');

        $money = $check->compare($this->rows(['4101' => ['billing_for_period' => 271.30]]), $this->rows(), $this->bands(), $this->bands(), self::TOLERANCE);
        $this->assertSame('differs', $this->column($money, 'billing_for_period')['status'], '14 pesewas is beyond 10');
        $volume = $check->compare($this->rows(['4101' => ['volume_total' => 46.40]]), $this->rows(), $this->bands(), $this->bands(), self::TOLERANCE);
        $this->assertSame('differs', $this->column($volume, 'volume_total')['status']);
        $count = $check->compare($this->rows(['4101' => ['customers_count' => 15]]), $this->rows(), $this->bands(), $this->bands(), self::TOLERANCE);
        $this->assertSame('differs', $this->column($count, 'customers_count')['status'], 'one customer is a difference');

        // the tolerance is a setting, not a constant
        $wide = $check->compare($this->rows(['4101' => ['billing_for_period' => 271.30]]), $this->rows(), $this->bands(), $this->bands(), ['money' => 0.5, 'volume' => 0.5]);
        $this->assertSame('agrees', $this->column($wide, 'billing_for_period')['status']);
    }

    public function test_a_column_or_a_band_table_missing_on_one_side_is_not_in_one_of_them(): void
    {
        $upload = $this->rows();

        foreach ($upload as $i => $row) {
            $upload[$i]['customers_count'] = null;   // the older summaries have no usable "number of customers"
        }

        $r = (new DerivedSummaryCheck)->compare($upload, $this->rows(), [], $this->bands(), self::TOLERANCE);

        $customers = $this->column($r, 'customers_count');
        $this->assertSame('missing', $customers['status']);
        $this->assertSame('not in the uploaded summary', $customers['text']);
        $this->assertSame(['missing', 'missing', 'missing'], array_column($r['bands'], 'status'));
        $this->assertSame('not in the uploaded summary', $r['bands'][0]['text']);
        $this->assertFalse($r['agrees']);

        $none = (new DerivedSummaryCheck)->compare($this->rows(), $this->rows(), $this->bands(), [], self::TOLERANCE);
        $this->assertSame('not in the derived summary', $none['bands'][0]['text']);
    }

    public function test_a_route_in_only_one_of_the_two_is_listed_and_the_verdict_differs(): void
    {
        $derived = array_slice($this->rows(), 0, 2);
        $r = (new DerivedSummaryCheck)->compare($this->rows(), $derived, $this->bands(), $this->bands(), self::TOLERANCE);

        $this->assertSame(['Odorkor 4202'], $r['only_in_uploaded']);
        $this->assertSame([], $r['only_in_derived']);
        $this->assertSame(2, $r['routes_compared']);
        $this->assertStringContainsString('routes found in only one of the two (1)', $r['verdict']);
    }

    public function test_a_differing_band_table_is_named(): void
    {
        $bands = $this->bands();
        $bands[1]['customers'] = 7;
        $r = (new DerivedSummaryCheck)->compare($this->rows(), $this->rows(), $bands, $this->bands(), self::TOLERANCE);

        $this->assertSame('differs', $r['bands'][0]['status']);
        $this->assertStringContainsString('Band customers (category 611)', $r['verdict']);
        $this->assertFalse($r['bands'][0]['rows'][1]['agrees']);
    }

    // ---------------------------------------------------------------- the real batches, the page and the files

    protected function crossX12(): array
    {
        return app(CommercialReportData::class)->cross('x12', ['region' => $this->accraWest->id]);
    }

    public function test_the_derived_summary_reproduces_the_hand_worked_summary_end_to_end(): void
    {
        $this->importBilling($this->derivedSpec());
        $uploaded = $this->uploadedTwin();
        $this->actingAs($this->superAdmin());

        $d = $this->crossX12()['parts']['derived']['data'];

        $this->assertTrue($d['available']);
        $this->assertCount(1, $d['pairs']);
        $this->assertSame($uploaded->id, $d['pairs'][0]['uploaded_batch']);
        $this->assertSame('The detailed report reproduces the billing summary for this period', $d['pairs'][0]['result']['verdict'], json_encode($d['pairs'][0]['result']['verdict']));
        $this->assertSame('the uploaded summary', $d['pairs'][0]['counted']);
        $this->assertSame(['money' => 0.10, 'volume' => 0.5], $d['tolerance']);
    }

    public function test_a_route_that_differs_is_found_and_the_page_and_the_export_say_so(): void
    {
        $this->importBilling($this->derivedSpec());
        $this->uploadedTwin(['4101' => ['payment_for_month' => 194.12, 'total_payments' => 200.12, 'closing_balance' => 303.32]]);
        $this->actingAs($this->superAdmin());

        $result = $this->crossX12()['parts']['derived']['data']['pairs'][0]['result'];
        $this->assertSame('differs', $this->column($result, 'payment_for_month')['status']);
        $this->assertSame(['Sowutuom 4101'], array_column($result['worst'], 'route'));

        Livewire::test(CrossAnalysis::class, ['analysis' => 'x12'])
            ->assertSeeHtml('data-testid="x12-verdict"')
            ->assertSee('The detailed report differs from the billing summary in: ')
            ->assertSee('Payment for the month')
            ->assertSee('Sowutuom 4101');

        $tables = app(CommercialExportService::class)->cross('cross-x12', $this->crossX12())['tables'];
        $titles = array_column($tables, 'title');
        $this->assertTrue((bool) collect($titles)->first(fn ($t) => str_starts_with($t, 'X12 Derived against uploaded billing summary')));
        $this->assertTrue((bool) collect($titles)->first(fn ($t) => str_starts_with($t, 'X12 Routes that differ most')));
        $main = collect($tables)->first(fn ($t) => str_starts_with($t['title'], 'X12 Derived against uploaded'));
        $this->assertStringStartsWith('The detailed report differs', $main['rows'][0][0]);
        $this->assertCount(1 + 23 + 3, $main['rows'], 'the verdict, the 23 columns and the 3 band measures');
    }

    public function test_with_an_uploaded_summary_but_no_derived_one_x12_says_there_is_nothing_to_compare(): void
    {
        $this->importBilling($this->derivedSpec());
        $this->derivedBatch()->delete();   // a derived summary that was never built (a detailed upload from before this phase)
        $this->uploadedTwin();
        $this->actingAs($this->superAdmin());

        $d = $this->crossX12()['parts']['derived']['data'];
        $this->assertFalse($d['available']);
        $this->assertSame([], $d['pairs']);
        $tables = app(CommercialExportService::class)->cross('cross-x12', $this->crossX12())['tables'];
        $this->assertTrue((bool) collect($tables)->first(fn ($t) => $t['title'] === 'X12 Derived summary: nothing to compare'));
    }

    public function test_the_comparison_works_whichever_summary_wins(): void
    {
        config(['gwl.commercial_billing_summary_preference' => 'details']);
        $this->importBilling($this->derivedSpec());
        $this->uploadedTwin();
        $this->actingAs($this->superAdmin());

        $pair = $this->crossX12()['parts']['derived']['data']['pairs'][0];
        $this->assertSame('the summary derived from the detailed report', $pair['counted']);
        $this->assertTrue($pair['result']['agrees']);
        $this->assertSame(2, CommercialImportBatch::query()->where('report_type', 'billing_summary')->count(), 'both stay stored');
    }

    public function test_a_district_user_is_compared_on_their_own_district_with_no_region_wide_total_and_no_band_table(): void
    {
        $this->importBilling($this->derivedSpec());
        $this->uploadedTwin();
        $viewer = $this->userWithRoles('900984', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom);
        $this->actingAs($viewer);

        $d = $this->crossX12()['parts']['derived']['data'];
        $result = $d['pairs'][0]['result'];

        $this->assertTrue($d['district_limited']);
        $this->assertSame(1, $result['routes_compared'], 'only route 4101 of their own district');
        $this->assertSame([], $result['only_in_uploaded'], 'no route of another district is even named');
        $this->assertSame([], $result['bands'], 'the band table is region-wide: not compared for a district user, and not called missing');
        $this->assertTrue($result['agrees']);
    }
}
