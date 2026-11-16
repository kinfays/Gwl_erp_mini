<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BillingDetails;
use App\Livewire\Commercial\CrossAnalysis;
use App\Livewire\Commercial\Customers\Dashboard;
use App\Livewire\Commercial\Home;
use App\Livewire\Commercial\ReadingRoutes;
use App\Livewire\Commercial\Summary;
use App\Livewire\Commercial\Trends;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsTrendsSet;

/**
 * Phase 8f: the Trends screens and their files, who sees what (the permission-by-part rule and district scope on every page), the T2 blocks on the
 * single-file screens, and the Summary and Home screens on any date range. Invented data (the trends series, see TrendsSeriesTest for its table).
 */
class TrendsScreensTest extends CustomerTestCase
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

    protected function export(User $user, string $key, string $format = 'excel', array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('commercial.export', ['report' => $key, 'format' => $format, ...$query]));
    }

    /** @return array<string, list<list<mixed>>> */
    protected function sheets(TestResponse $response): array
    {
        $response->assertOk();
        $out = [];

        foreach (IOFactory::load($response->baseResponse->getFile()->getPathname())->getAllSheets() as $sheet) {
            $out[$sheet->getTitle()] = $sheet->toArray(null, false, false, false);
        }

        return $out;
    }

    // ---------------------------------------------------------------- the module

    public function test_the_trends_need_one_of_the_three_data_permissions_and_only_t2_to_t8_exist(): void
    {
        $none = $this->trendViewerWith(['commercial.view_dashboard'], '900401');
        $this->actingAs($none)->get(route('commercial.trends'))->assertForbidden();
        $this->actingAs($none)->get(route('commercial.trends.show', 't4'))->assertForbidden();

        foreach (['commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics'] as $i => $permission) {
            $viewer = $this->trendViewerWith([$permission], '90040'.($i + 2));
            $this->actingAs($viewer)->get(route('commercial.trends'))->assertOk()->assertSee('T2')->assertSee('Period on period');
        }

        $this->actingAs($this->trendViewer())->get('/commercial/trends/t9')->assertNotFound();
        $this->actingAs($this->trendViewer())->get('/commercial/trends/x1')->assertNotFound();
        $this->actingAs($this->trendViewer())->get(route('commercial.billing.details'))->assertSee('Trends');   // the sidebar
        $this->actingAs($this->trendViewerWith(['commercial.view_dashboard'], '900405'))->get(route('commercial.home'))->assertDontSee('Period on period');
    }

    public function test_every_trend_page_renders_and_names_the_period_it_used(): void
    {
        $this->actingAs($this->trendViewer());

        Livewire::test(Trends::class, ['trend' => 't2'])->assertSee('Period on period')->assertSee('Previous period')->assertSee('22 Oct to 28 Oct 2026')->assertSee('8 Oct to 14 Oct 2026')->assertSee('Skip rate')->assertSee('better');
        Livewire::test(Trends::class, ['trend' => 't4'])->assertSee('Rolling windows')->assertSee('21 of 30 days are covered')->assertSee('51 of 90 days are covered');
        Livewire::test(Trends::class, ['trend' => 't5'])->assertSee('Rank movement')->assertSee('up 2')->assertSee('AMA TEST');
        Livewire::test(Trends::class, ['trend' => 't6'])->assertSee('Year on year')->assertSee('No upload covers');
        Livewire::test(Trends::class, ['trend' => 't7'])->assertSee('projection, not a forecast')->assertSee('21 of 31 days are covered')->assertSee('no target set');
        Livewire::test(Trends::class, ['trend' => 't8'])->assertSee('Coverage and timeliness')->assertSee('Gaps')->assertSee('late');

        Livewire::test(Trends::class, ['trend' => 't6'])->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-07')->assertSee('Same dates a year earlier')->assertSee('1 Oct to 7 Oct 2025');
        Livewire::test(Trends::class, ['trend' => 't2'])->set('dateFrom', '2026-09-01')->set('dateTo', '2026-09-30')->assertSee('No comparable previous period')->assertSee('No change is shown');
        Livewire::test(Trends::class, ['trend' => 't2'])->call('clearFilters')->assertSet('dateFrom', '');
        Livewire::test(Trends::class, ['trend' => 't2'])->set('preset', '2026-09')->assertSet('dateFrom', '2026-09-01')->assertSet('dateTo', '2026-09-30');
    }

    // ---------------------------------------------------------------- the permission-by-part rule

    public function test_a_viewer_with_only_billing_sees_the_billing_part_and_a_line_about_what_is_hidden(): void
    {
        $viewer = $this->trendViewerWith(['commercial.view_billing', 'commercial.export_reports'], '900411');
        $this->actingAs($viewer);
        $r = $this->trend('t2');

        $this->assertTrue($r['parts']['billing']['visible']);
        $this->assertFalse($r['parts']['reading']['visible']);
        $this->assertFalse($r['parts']['customers']['visible']);
        $this->assertNull($r['parts']['reading']['data'], 'a hidden part is not computed');
        $this->assertStringContainsString('commercial.view_reading', $r['parts']['reading']['reason']);
        $this->assertCount(2, $r['hidden']);

        Livewire::test(Trends::class, ['trend' => 't2'])->assertSee('Hidden from you')->assertSee('commercial.view_reading')->assertSee('Estimated bills');

        // every other trend: the same rule
        foreach (['t4', 't7'] as $key) {
            $this->assertFalse($this->trend($key)['parts']['reading']['visible'], $key);
            $this->assertTrue($this->trend($key)['parts']['billing']['visible'], $key);
        }

        $t8 = $this->trend('t8')['parts'];
        $this->assertFalse($t8['reading_route']['visible']);
        $this->assertTrue($t8['billing_details']['visible']);
        $this->assertTrue($t8['billing_summary']['visible']);
        $this->assertFalse($t8['customers']['visible']);
    }

    public function test_a_viewer_with_only_the_customer_list_sees_only_that_part(): void
    {
        $this->actingAs($this->trendViewerWith(['commercial.view_customer_analytics'], '900412'));
        $r = $this->trend('t2');

        $this->assertTrue($r['parts']['customers']['visible']);
        $this->assertFalse($r['parts']['reading']['visible']);
        $this->assertFalse($r['parts']['billing']['visible']);
        $this->assertSame('customers', array_key_first(array_filter($r['parts'], fn ($p) => $p['visible'])));
    }

    public function test_the_customer_list_part_is_hidden_while_the_customer_list_is_switched_off(): void
    {
        $this->actingAs($this->trendViewer());
        config(['gwl.commercial_customer_list_enabled' => false]);

        $this->assertFalse($this->trend('t2')['parts']['customers']['visible']);
    }

    // ---------------------------------------------------------------- district scope

    public function test_a_district_user_sees_only_their_district_on_every_page_and_no_region_row(): void
    {
        $this->actingAs($this->trendViewer('900421', true, $this->sowutuom));

        $t2 = $this->trend('t2');
        $this->assertSame(['Sowutuom'], array_column($t2['parts']['reading']['data']['districts'], 'label'));
        $this->assertSame(['Sowutuom'], array_column($t2['parts']['billing']['data']['districts'], 'label'));
        $this->assertSame(['Sowutuom'], array_column($t2['parts']['customers']['data']['districts'], 'label'));
        $this->assertSame([], $t2['parts']['customers']['data']['measures'], 'no region-wide row for a district user');
        $this->assertSame(['80001', '80002'], array_map('strval', array_column($t2['parts']['reading']['data']['ccas'], 'id')));

        // their own district's figures: skips 3 + 0 + 2 = 5 of 30 visited, not the region's 10 of 60
        $skip = collect($t2['parts']['reading']['data']['measures'])->keyBy('key')['skip_rate'];
        $this->assertEquals(16.67, $skip['now']);
        $week2 = $this->trend('t2', ['from' => '2026-10-08', 'to' => '2026-10-14'])['parts']['reading']['data'];
        $this->assertEquals(13.33, collect($week2['measures'])->keyBy('key')['skip_rate']['now']);   // (1 + 1 + 2) / 30, the region's is 23.33

        $t8 = $this->trend('t8');
        foreach (['reading_route', 'billing_details', 'customers'] as $key) {
            $part = $t8['parts'][$key]['data'];
            $this->assertSame(['Sowutuom'], array_column($part['districts'], 'name'), $key);
            $this->assertNull($part['on_time_share'], $key.': no region-wide row');
        }

        foreach (['t2', 't4', 't5', 't6', 't7', 't8'] as $key) {
            $this->assertStringNotContainsString('Odorkor', json_encode($this->trend($key)), $key);
        }

        Livewire::test(Trends::class, ['trend' => 't5'])->assertDontSee('Odorkor')->assertSee('Sowutuom');
    }

    public function test_the_district_filter_narrows_every_figure(): void
    {
        $this->actingAs($this->trendViewer());
        $odorkor = $this->trend('t2', ['district' => $this->odorkor->id, 'from' => '2026-10-08', 'to' => '2026-10-14'])['parts']['reading']['data'];

        // Odorkor, 8 to 14 October: skips 3 + 3 + 4 = 10 of 30 visited
        $this->assertEquals(33.33, collect($odorkor['measures'])->keyBy('key')['skip_rate']['now']);
        $this->assertSame(['Odorkor'], array_column($odorkor['districts'], 'label'));
    }

    // ---------------------------------------------------------------- the files

    public function test_every_trend_has_an_excel_and_a_pdf_file_built_from_the_pages_data(): void
    {
        $user = $this->trendViewer('900431');

        foreach (['t2', 't4', 't5', 't6', 't7', 't8'] as $key) {
            $sheets = $this->sheets($this->export($user, 'trends-'.$key));
            $this->assertArrayHasKey('Notes', $sheets, $key);
            $this->assertGreaterThan(1, count($sheets), $key);

            $pdf = $this->export($user, 'trends-'.$key, 'pdf');
            $pdf->assertOk();
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'), $key);
        }

        // T2: the headline sheet holds the page's figures: skip rate 16.67 now, 23.33 before, a change of -6.66
        $sheets = $this->sheets($this->export($user, 'trends-t2'));
        $rows = collect(array_merge(...array_values(array_filter($sheets, fn ($rows, $name) => str_starts_with($name, 'Reading by route and CCA') && str_contains($name, 'headl'), ARRAY_FILTER_USE_BOTH))))->keyBy(0);
        $this->assertEquals(16.67, $rows['Skip rate'][1]);
        $this->assertEquals(23.33, $rows['Skip rate'][2]);
        $this->assertEquals(-6.66, $rows['Skip rate'][3]);
        $this->assertSame('Better', $rows['Skip rate'][6]);

        $notes = json_encode($sheets['Notes']);
        $this->assertStringContainsString('percentage points', $notes);
        $this->assertStringContainsString('percentage points', $notes);
        $this->assertStringContainsString('never a 7-day upload with a 31-day one', $notes);
    }

    public function test_a_file_is_audited_once_with_filters_and_counts_only(): void
    {
        $user = $this->trendViewer('900432');
        $this->export($user, 'trends-t5', 'excel', ['district' => $this->sowutuom->id])->assertOk();

        $audit = AuditLog::query()->where('action', 'commercial.export_trends_t5_excel')->firstOrFail();
        $this->assertSame('Trends: T5 Rank movement', $audit->metadata['report']);
        $this->assertSame($this->accraWest->id, $audit->metadata['filters']['region']);
        $this->assertSame($this->sowutuom->id, $audit->metadata['filters']['district']);
        $this->assertIsInt($audit->metadata['rows']);

        foreach (['AMA TEST', '80001', 'Sample Customer', 'SOWUTUOM 4101'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($audit->metadata), 'an audit row holds filters and counts, never a name, a staff id or a route');
        }
    }

    public function test_a_file_needs_export_permission_and_a_data_permission_and_unknown_keys_are_not_found(): void
    {
        $this->export($this->trendViewerWith(['commercial.view_reading', 'commercial.view_billing'], '900433'), 'trends-t2')->assertForbidden();
        $this->export($this->trendViewerWith(['commercial.export_reports'], '900434'), 'trends-t2')->assertForbidden();
        $this->export($this->trendViewer('900435'), 'trends-t9')->assertNotFound();
        $this->export($this->trendViewer('900436'), 'trends-t2', 'csv')->assertNotFound();
    }

    public function test_a_file_never_holds_a_part_the_viewer_may_not_see_and_says_so(): void
    {
        $viewer = $this->trendViewerWith(['commercial.view_billing', 'commercial.export_reports'], '900437');
        $sheets = $this->sheets($this->export($viewer, 'trends-t2'));

        $this->assertSame([], array_filter(array_keys($sheets), fn ($name) => str_starts_with($name, 'Reading by route')), 'no sheet of a hidden part');
        $notIn = json_encode($sheets['Not in this file']);
        $this->assertStringContainsString('Reading by route and CCA', $notIn);
        $this->assertStringContainsString('commercial.view_reading', $notIn);

        // and no CCA is named to someone without the CCA permission
        $reading = $this->trendViewerWith(['commercial.view_reading', 'commercial.export_reports'], '900438');
        $this->assertStringNotContainsString('AMA TEST', json_encode($this->sheets($this->export($reading, 'trends-t5'))));
        $this->assertStringNotContainsString('AMA TEST', json_encode($this->sheets($this->export($reading, 'trends-t2'))));
    }

    public function test_a_district_users_file_holds_only_their_district(): void
    {
        $user = $this->trendViewer('900439', true, $this->sowutuom);

        foreach (['t2', 't5', 't8'] as $key) {
            $this->assertStringNotContainsString('Odorkor', json_encode($this->sheets($this->export($user, 'trends-'.$key))), $key);
        }
    }

    public function test_the_row_caps_apply_to_each_format_and_only_a_made_download_is_audited(): void
    {
        $user = $this->trendViewer('900440');
        $back = route('commercial.trends.show', 't5');

        config(['gwl.commercial_export_max_rows' => 5]);
        $this->actingAs($user)->from($back)->get(route('commercial.export', ['report' => 'trends-t5', 'format' => 'excel']))->assertRedirect($back)->assertSessionHas('error');

        config(['gwl.commercial_export_max_rows' => 5000, 'gwl.commercial_export_pdf_max_rows' => 5]);
        $this->actingAs($user)->from($back)->get(route('commercial.export', ['report' => 'trends-t5', 'format' => 'pdf']))->assertRedirect($back)->assertSessionHas('error');

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'commercial.export_trends_t5%')->count());
        config(['gwl.commercial_export_pdf_max_rows' => 1500]);
        $this->export($user, 'trends-t5')->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.export_trends_t5_excel')->count());
    }

    public function test_with_nothing_in_the_range_there_is_nothing_to_export(): void
    {
        // (the customer list's snapshots do not take a range, so a viewer of the route-grain reports only)
        $this->actingAs($this->trendViewerWith(['commercial.view_reading', 'commercial.view_billing', 'commercial.export_reports'], '900441'))->from(route('commercial.trends.show', 't2'))
            ->get(route('commercial.export', ['report' => 'trends-t2', 'format' => 'excel', 'from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertRedirect(route('commercial.trends.show', 't2'))->assertSessionHas('error');
    }

    // ---------------------------------------------------------------- the T2 block on the single-file screens

    public function test_the_screens_that_show_the_figure_carry_the_change_on_the_previous_period(): void
    {
        $this->actingAs($this->trendViewer('900451'));

        Livewire::test(ReadingRoutes::class)->assertSee('Change on the previous comparable period')->assertSeeHtml('data-trend-block="reading"');
        Livewire::test(BillingDetails::class)->assertSeeHtml('data-trend-block="billing"');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x7'])->assertSeeHtml('data-trend-block="reading"')->assertSeeHtml('data-trend-block="billing"')->assertSeeHtml('data-trend-block="customers"');
        Livewire::test(Dashboard::class)->set('tab', 'compare')->assertSeeHtml('data-trend-block="customers"');

        // a block needs the permission of its data: billing only means no reading block
        $this->actingAs($this->trendViewerWith(['commercial.view_billing', 'commercial.view_reading'], '900452'));
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x7'])->assertDontSeeHtml('data-trend-block="customers"');
    }
}
