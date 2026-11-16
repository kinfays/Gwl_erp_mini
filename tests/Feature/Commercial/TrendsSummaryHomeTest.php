<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Home;
use App\Livewire\Commercial\Summary;
use App\Models\User;
use App\Services\Commercial\CommercialExportService;
use App\Services\Commercial\CommercialReportData;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsTrendsSet;

/**
 * Phase 8f: the Summary and Home screens on any date range. With no range typed each route-grain report uses its newest upload and every existing figure is what it
 * was (ExistingFiguresPinnedTest pins them); with a range the route-grain figures use it and say "n of d days are covered". A district user sees only their district;
 * a part the viewer may not have is not there. Invented data (the trends series, see TrendsSeriesTest).
 */
class TrendsSummaryHomeTest extends CustomerTestCase
{
    use BuildsBillingDetails;
    use BuildsTrendsSet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->setUpBillingDetails();
        $this->loadTrendsSeries();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function grain(?string $from = null, ?string $to = null): array
    {
        return app(CommercialReportData::class)->summary(null, $from, $to)['route_grain'];
    }

    protected function measure(array $part, string $key): array
    {
        return collect($part['measures'])->keyBy('key')[$key];
    }

    public function test_with_no_range_each_report_uses_its_newest_upload_and_says_which(): void
    {
        $this->actingAs($this->trendViewer());
        $g = $this->grain();

        $this->assertSame('Accra West', $g['region']);
        $this->assertFalse($g['typed']);
        $this->assertSame('22 Oct to 28 Oct 2026', $g['reading']['label']);
        $this->assertSame('7 of 7 days are covered', $g['reading']['coverage_text']);
        $this->assertEquals(100.0, $this->measure($g['reading'], 'coverage')['value']);
        $this->assertEquals(16.67, $this->measure($g['reading'], 'skip_rate')['value']);   // 10 skipped of 60 visited
        $this->assertTrue($this->measure($g['reading'], 'coverage')['met']);
        $this->assertFalse($this->measure($g['reading'], 'skip_rate')['met'], '16.67% is above the configured target of at most 10%');
        $this->assertSame(10.0, $this->measure($g['reading'], 'skip_rate')['target']);

        $this->assertSame('22 Oct to 28 Oct 2026', $g['billing']['label']);
        $this->assertEquals(81.67, $this->measure($g['billing'], 'collection_ratio')['value']);
        $this->assertEquals(11.67, $this->measure($g['billing'], 'estimated_share')['value']);
        $this->assertEquals(629.2, $this->measure($g['billing'], 'owing_amount')['value']);
    }

    public function test_a_typed_range_replaces_it_and_states_the_days_covered(): void
    {
        $this->actingAs($this->trendViewer());

        // 1 to 14 October: week-1 and week-2 (14 of 14 days); skipped 18 + 14 = 32 of 120 visited
        $g = $this->grain('2026-10-01', '2026-10-14');
        $this->assertTrue($g['typed']);
        $this->assertSame('14 of 14 days are covered', $g['reading']['coverage_text']);
        $this->assertEquals(26.67, $this->measure($g['reading'], 'skip_rate')['value']);
        // paid (41 + 45) x 57.20 of 120 x 57.20 = 71.67%: a flow ratio from sums. The estimated share and the owing balance are counts of customers and a balance, which are the
        // LATEST upload's (the period rules of the billing report): week-2's 9 of 60 = 15.00% and 15 x 57.20 = 858.00
        $this->assertEquals(71.67, $this->measure($g['billing'], 'collection_ratio')['value']);
        $this->assertEquals(15.0, $this->measure($g['billing'], 'estimated_share')['value']);
        $this->assertEquals(858.0, $this->measure($g['billing'], 'owing_amount')['value']);

        // the whole of October has a gap (15 to 21 and 29 to 31): 21 of 31 days
        $october = $this->grain('2026-10-01', '2026-10-31');
        $this->assertSame('21 of 31 days are covered', $october['reading']['coverage_text']);
        $this->assertSame('21 of 31 days are covered', $october['billing']['coverage_text']);
        $this->assertEquals(round(42 / 180 * 100, 2), $this->measure($october['reading'], 'skip_rate')['value']);   // (18 + 14 + 10) / 180

        // a range no upload lies in has no figure rather than an invented one
        $this->assertNull(app(CommercialReportData::class)->summary(null, '2026-01-01', '2026-01-31')['route_grain']);
    }

    public function test_the_screens_show_the_block_the_range_control_and_the_period(): void
    {
        $this->actingAs($this->trendViewer());

        Livewire::test(Summary::class)->assertSee('Route-grain headline figures')->assertSee('22 Oct to 28 Oct 2026')->assertSee('7 of 7 days are covered')->assertSeeHtml('data-range-control')->assertDontSeeHtml('data-range-note');
        Livewire::test(Summary::class)->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-14')->assertSee('14 of 14 days are covered')->assertSeeHtml('data-range-note');
        Livewire::test(Summary::class)->set('preset', '2026-10')->assertSet('dateFrom', '2026-10-01')->assertSet('dateTo', '2026-10-31')->assertSee('21 of 31 days are covered');
        Livewire::test(Summary::class)->set('dateFrom', '2026-10-01')->call('clearDates')->assertSet('dateFrom', '')->assertSet('dateTo', '');

        Livewire::test(Home::class)->assertSee('Route-grain headline figures')->assertSee('7 of 7 days are covered');
        Livewire::test(Home::class)->set('dateFrom', '2026-10-08')->set('dateTo', '2026-10-14')->assertSee('7 of 7 days are covered')->assertSee('8 Oct to 14 Oct 2026');
        Livewire::test(Home::class)->set('preset', '2026-10')->assertSee('21 of 31 days are covered');
    }

    public function test_a_part_the_viewer_may_not_have_is_not_there(): void
    {
        $this->actingAs($this->trendViewerWith(['commercial.view_billing', 'commercial.view_dashboard'], '900501'));
        $g = $this->grain();

        $this->assertNull($g['reading']);
        $this->assertNotNull($g['billing']);

        $this->actingAs($this->trendViewerWith(['commercial.view_dashboard'], '900502'));
        $this->assertNull(app(CommercialReportData::class)->summary()['route_grain'], 'with neither permission there is no block at all');
        Livewire::test(Summary::class)->assertDontSee('Route-grain headline figures');
        Livewire::test(Home::class)->assertDontSee('Route-grain headline figures');
    }

    public function test_a_district_user_sees_only_their_district_and_no_other_name(): void
    {
        $this->actingAs($this->trendViewer('900503', true, $this->sowutuom));

        // 8 to 14 October, Sowutuom alone: skipped 1 + 1 + 2 = 4 of 30 visited = 13.33% (the region's is 23.33%)
        $g = $this->grain('2026-10-08', '2026-10-14');
        $this->assertEquals(13.33, $this->measure($g['reading'], 'skip_rate')['value']);
        // billing: unpaid 2 + 2 + 2 = 6 of 30 customers: collection (24 / 30) = 80%
        $this->assertEquals(80.0, $this->measure($g['billing'], 'collection_ratio')['value']);

        $page = Livewire::test(Home::class)->set('dateFrom', '2026-10-08')->set('dateTo', '2026-10-14')->assertSee('13.3%')->assertDontSee('23.3%');
        $this->assertStringNotContainsString('Odorkor', $page->html());
        Livewire::test(Summary::class)->set('dateFrom', '2026-10-08')->set('dateTo', '2026-10-14')->assertSee('13.3%')->assertDontSee('Odorkor');
    }

    public function test_the_summary_file_carries_the_block_and_the_range_only_when_there_is_route_grain_data(): void
    {
        $user = $this->trendViewer('900504');
        $this->actingAs($user);

        $tables = collect(app(CommercialExportService::class)->summary(app(CommercialReportData::class)->summary(null, '2026-10-01', '2026-10-14'))['tables']);
        $block = $tables->firstWhere('title', 'Route-grain headline figures');
        $this->assertNotNull($block);
        $this->assertSame(['Report', 'Period used', 'Days covered', 'Measure', 'Value', 'Configured target', 'Within target'], $block['headings']);
        $this->assertContains('14 of 14 days are covered', array_column($block['rows'], 2));
        $this->assertContains('1 Oct to 14 Oct 2026', array_column($block['rows'], 1));

        $response = $this->actingAs($user)->get(route('commercial.export', ['report' => 'summary', 'format' => 'excel', 'from' => '2026-10-01', 'to' => '2026-10-14']));
        $sheets = $this->sheetNames($response);
        $this->assertTrue(collect($sheets)->contains(fn ($name) => str_starts_with($name, 'Route-grain headline')), implode(', ', $sheets));
        $this->actingAs($user)->get(route('commercial.export', ['report' => 'summary', 'format' => 'pdf', 'from' => '2026-10-01', 'to' => '2026-10-14']))->assertOk();

        // a region with no route-grain upload: the file is what it always was
        $ashanti = $this->userWith('900505', ['commercial.view_billing', 'commercial.view_reading', 'commercial.view_dashboard', 'commercial.export_reports'], $this->ashanti, $this->kumasi);
        $this->actingAs($ashanti);
        $this->assertNull(app(CommercialReportData::class)->summary()['route_grain']);
        $this->assertNull(collect(app(CommercialExportService::class)->summary(app(CommercialReportData::class)->summary())['tables'])->firstWhere('title', 'Route-grain headline figures'));
    }

    /** @return list<string> */
    protected function sheetNames(TestResponse $response): array
    {
        $response->assertOk();

        return array_map(fn ($sheet) => $sheet->getTitle(), IOFactory::load($response->baseResponse->getFile()->getPathname())->getAllSheets());
    }

    public function test_the_billing_headline_of_the_summary_follows_a_typed_range_and_the_snapshot_does_not_without_one(): void
    {
        $this->actingAs($this->trendViewer('900506'));
        $this->seedBilling($this->billingFixture(), '2026-09-01');
        $data = app(CommercialReportData::class);

        $plain = $data->summary();
        $this->assertFalse($plain['snapshot']['is_range'] ?? false);

        $range = $data->summary(null, '2026-09-01', '2026-09-30');
        $this->assertTrue($range['snapshot']['is_range']);
        $this->assertEquals($plain['summary']['billing']['cash_ratio'], $range['summary']['billing']['cash_ratio'], 'one upload in the month: the same figure');
        $this->assertSame('range', $range['snapshot']['id']);

        $page = Livewire::test(Summary::class)->set('dateFrom', '2026-09-01')->set('dateTo', '2026-09-30')->assertSeeHtml('data-range-note');
        $this->assertStringContainsString('from=2026-09-01', $page->html());
    }
}
