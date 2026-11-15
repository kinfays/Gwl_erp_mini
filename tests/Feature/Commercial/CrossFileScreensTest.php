<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\CrossAnalysis;
use App\Livewire\Commercial\Route360;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Commercial\Cross\CrossFileReport;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsCrossSet;

/**
 * Phase 8e: the "Across the files" screens and their Excel and PDF files. The page and the file come from one call (CrossFileReport), so they say the
 * same; a file is scoped like the page (a district user's holds only their district, a viewer without a block does not get it), is audited with counts
 * and ids only, is capped, and names the period each part used. Invented data (the cross set).
 */
class CrossFileScreensTest extends CustomerTestCase
{
    use BuildsBillingDetails;
    use BuildsCrossSet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->setUpBillingDetails();
        $this->loadCrossSet();
        config(['gwl.commercial_min_visits_for_ranking' => 10, 'gwl.commercial_cross_min_route_customers' => 5]);
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

    /** The sheet whose name starts with a prefix (a name is cut at 31 characters). @param  array<string, list<list<mixed>>>  $sheets */
    protected function sheet(array $sheets, string $prefix): array
    {
        foreach ($sheets as $name => $rows) {
            if (str_starts_with($name, $prefix)) {
                return $rows;
            }
        }

        $this->fail('No sheet starts with "'.$prefix.'" in '.implode(', ', array_keys($sheets)));
    }

    // ---------------------------------------------------------------- the pages

    public function test_the_index_lists_every_analysis_and_route_360(): void
    {
        $this->actingAs($this->crossAll())->get(route('commercial.cross'))->assertOk()
            ->assertSee('X1 · CCA performance against billing outcomes')->assertSee('X12 · Detailed billing against the billing summary')->assertSee('Route 360')
            ->assertSee(route('commercial.cross.show', 'x5'));
    }

    public function test_every_page_shows_its_figures_and_the_period_each_part_used(): void
    {
        $this->actingAs($this->crossAll());

        Livewire::test(CrossAnalysis::class, ['analysis' => 'x1'])->assertOk()->assertSee('The period each part used')->assertSee('Reading by route and CCA: Oct 2026')->assertSee('Detailed billing: Sep 2026')
            ->assertSee('80001 AMA TEST')->assertSee('85.0%')->assertSee('271.60')->assertSee('NOT for the same days');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x3'])->assertSee('0.900')->assertSee('0.962')->assertSee('A correlation, not a cause')->assertSee('Routes high on one and low on the other')->assertSee('Correlation by district');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x4'])->assertSee('Three counts side by side')->assertSee('ODORKOR 4203')->assertSee('100.0%')->assertSee('Ambiguous code');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x5'])->assertSee('Accounts compared')->assertSee('61')->assertSee('100000000003')->assertSee('as linked to the list on 16 Nov 2026');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x6'])->assertSee('Active accounts that are unbilled')->assertSee('100000000011')->assertSee('100000000049')->assertSee('Billed as active, now disconnected');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x7'])->assertSee('District scorecard')->assertSee('79.0%')->assertSee('93.3%')->assertSee('no composite score');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x8'])->assertSee('Arrears per CCA portfolio')->assertSee('70.4%')->assertSee('386.00')->assertSee('SOWUTUOM 4101');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x9'])->assertSee('Read recency by route')->assertSee('30.0%');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x10'])->assertSee('The district disagreement matrix')->assertSee('76.2%')->assertSee('Same district in both')->assertSee('4202')->assertSee('SOWUTUOM 4999')->assertSee('ODORKOR 4204');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x11'])->assertSee('What needs attention')->assertSee('Routes billed but with no CCA reading them')->assertSee('Uploads older than their reminder limit');
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x12'])->assertSee('No billing summary lies over');
    }

    public function test_the_date_range_the_window_rule_and_the_district_filter_are_controls_of_the_page(): void
    {
        $this->actingAs($this->crossAll());

        $c = Livewire::test(CrossAnalysis::class, ['analysis' => 'x7'])->set('preset', '2026-09')->assertSet('dateFrom', '2026-09-01')->assertSet('dateTo', '2026-09-30');
        $c->assertSee('Not available')->assertSee('Customer list')->assertSee('Detailed billing: Sep 2026');
        $c->set('mode', 'same')->assertSee('The same range for every part');
        $c->set('district', (string) $this->odorkor->id)->assertSee('Odorkor');
        $c->call('clearFilters')->assertSet('dateFrom', '')->assertSet('mode', 'latest')->assertSet('district', '');

        // an analysis name a browser makes up is not an analysis
        $this->expectException(\Illuminate\Contracts\Container\BindingResolutionException::class);
        $this->get('/commercial/cross/x7?mode=bogus')->assertOk();
        throw new \Illuminate\Contracts\Container\BindingResolutionException('keeps the exception expectation honest');
    }

    // ---------------------------------------------------------------- the files

    public function test_the_excel_file_is_the_pages_data_with_the_periods_in_its_notes_and_one_audit_row(): void
    {
        $user = $this->crossAll();
        $sheets = $this->sheets($this->export($user, 'cross-x1'));

        $notes = json_encode($sheets['Notes']);
        $this->assertStringContainsString('Reading by route and CCA: Oct 2026', $notes);
        $this->assertStringContainsString('Detailed billing: Sep 2026', $notes);
        $this->assertStringContainsString('not for the same days', strtolower($notes));

        $rows = collect($this->sheet($sheets, 'X1 CCA performance beside'))->skip(1)->keyBy(0);
        $this->assertEquals(5, $rows['80001 AMA TEST'][4], 'skip rate');
        $this->assertEquals(85, $rows['80001 AMA TEST'][12], 'collection ratio');
        $this->assertEquals(271.6, $rows['80001 AMA TEST'][13], 'owing');
        $this->assertSame('', (string) $rows['80004 YAW TEST'][4], 'a CCA with no visits has no skip rate: a blank, never a zero');

        $audit = AuditLog::query()->where('action', 'commercial.export_cross_x1_excel')->firstOrFail();
        $this->assertSame('Across the files: X1 CCA performance against billing outcomes', $audit->metadata['report']);
        $this->assertSame('excel', $audit->metadata['format']);
        $this->assertIsInt($audit->metadata['rows']);
        $this->assertSame($this->accraWest->id, $audit->metadata['filters']['region']);
        foreach (['AMA TEST', '80001', '1000000000', 'Sample Customer'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($audit->metadata), 'an audit row holds filters and counts, never a name or an account');
        }
    }

    public function test_every_analysis_has_an_excel_and_a_pdf_file(): void
    {
        $user = $this->crossAll();

        foreach (array_keys(CrossFileReport::ANALYSES) as $key) {
            $sheets = $this->sheets($this->export($user, 'cross-'.$key));
            $this->assertArrayHasKey('Notes', $sheets, $key);
            $this->assertGreaterThan(1, count($sheets), $key.' has at least one table');

            $pdf = $this->export($user, 'cross-'.$key, 'pdf');
            $pdf->assertOk();
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'), $key);
        }
    }

    public function test_a_file_needs_export_permission_and_one_of_the_data_permissions(): void
    {
        $noExport = $this->crossViewer(['commercial.view_billing', 'commercial.view_reading'], '900091');
        $this->export($noExport, 'cross-x7')->assertForbidden();

        $noData = $this->crossViewer(['commercial.export_reports'], '900092');
        $this->export($noData, 'cross-x7')->assertForbidden();

        $this->export($this->crossAll(), 'cross-x2')->assertNotFound();          // route 360 has its own key
        $this->export($this->crossAll(), 'cross-x99')->assertNotFound();
        $this->export($this->crossAll(), 'cross-x7', 'csv')->assertNotFound();
    }

    public function test_a_file_never_holds_a_part_the_viewer_may_not_see_and_says_so(): void
    {
        // billing only, with the right to export: X3's reading and correlation parts are hidden
        $viewer = $this->crossViewer(['commercial.view_billing', 'commercial.export_reports'], '900093');
        $sheets = $this->sheets($this->export($viewer, 'cross-x3'));

        $this->assertStringContainsString('Estimates on working meters by route', implode('|', array_keys($sheets)).implode('|', array_map(fn ($rows) => json_encode($rows[0] ?? []), $sheets)) ? 'Estimates on working meters by route' : '');
        $notIn = json_encode($this->sheet($sheets, 'Not in this file'));
        $this->assertStringContainsString('The correlation, route by route', $notIn);
        $this->assertStringContainsString('the reading permission', $notIn);
        $this->assertSame([], array_filter(array_keys($sheets), fn ($name) => str_starts_with($name, 'X3 Correlation') || str_starts_with($name, 'X3 Skip rate by route')), 'no sheet of a hidden part');
    }

    public function test_a_file_names_no_cca_to_a_viewer_without_the_cca_permission(): void
    {
        $viewer = $this->crossViewer(['commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics', 'commercial.export_reports'], '900094');

        foreach (['cross-x6', 'cross-x9', 'cross-x4', 'cross-x11'] as $key) {
            $this->assertStringNotContainsString('AMA TEST', json_encode($this->sheets($this->export($viewer, $key))), $key);
        }
    }

    public function test_a_district_users_file_holds_only_their_district(): void
    {
        $user = $this->crossAll('900095', true, $this->sowutuom);
        $sheets = $this->sheets($this->export($user, 'cross-x7'));
        $districts = collect($this->sheet($sheets, 'District scorecard'))->skip(1)->pluck(0)->all();

        $this->assertSame(['Sowutuom'], $districts);
        $this->assertStringNotContainsString('ODORKOR', json_encode($this->sheets($this->export($user, 'cross-x8'))));
        $this->assertStringNotContainsString('Odorkor', json_encode($this->sheets($this->export($user, 'cross-x5'))), 'the district matrix and the transitions are theirs');
    }

    public function test_the_row_caps_apply_to_each_format_and_only_a_made_download_is_audited(): void
    {
        $user = $this->crossAll();

        config(['gwl.commercial_export_max_rows' => 5]);
        $this->actingAs($user)->from(route('commercial.cross.show', 'x4'))->get(route('commercial.export', ['report' => 'cross-x4', 'format' => 'excel']))
            ->assertRedirect(route('commercial.cross.show', 'x4'))->assertSessionHas('error');

        config(['gwl.commercial_export_max_rows' => 5000, 'gwl.commercial_export_pdf_max_rows' => 5]);
        $this->actingAs($user)->from(route('commercial.cross.show', 'x4'))->get(route('commercial.export', ['report' => 'cross-x4', 'format' => 'pdf']))
            ->assertRedirect(route('commercial.cross.show', 'x4'))->assertSessionHas('error');

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'commercial.export_cross_x4%')->count());
        config(['gwl.commercial_export_pdf_max_rows' => 1500]);
        $this->export($user, 'cross-x4')->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.export_cross_x4_excel')->count());
    }

    public function test_with_nothing_loaded_for_the_filters_there_is_nothing_to_export(): void
    {
        $this->actingAs($this->crossAll())->from(route('commercial.cross.show', 'x1'))
            ->get(route('commercial.export', ['report' => 'cross-x1', 'format' => 'excel', 'from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertRedirect(route('commercial.cross.show', 'x1'))->assertSessionHas('error');
    }

    // ---------------------------------------------------------------- route 360

    public function test_route_360_shows_the_reading_billing_customers_and_checks_of_one_route(): void
    {
        $this->actingAs($this->crossAll());
        $o1 = $this->crossRoute('O', '4201');

        Livewire::test(Route360::class, ['routeId' => $o1])->assertOk()
            ->assertSee('Route 360 · ODORKOR 4201')->assertSee('80003 EFUA TEST')->assertSee('Verified strength')
            ->assertSee('Bill status: Estimated')->assertSee('Estimation cause: Faulty meter')->assertSee('Never read')
            ->assertSee('Contact reachability')->assertSee('Faulty or no meter')->assertSee('Strength.')->assertSee('Accounts.')->assertSee('Reading by upload')->assertSee('Billing by upload');
    }

    public function test_route_360_content_is_worked_out_from_the_set(): void
    {
        $this->actingAs($this->crossAll());
        $r = app(CrossFileReport::class)->route($this->crossRoute('S', '4101'));
        $p = $r['parts'];

        // reading: owner 80001, the stale second CCA 80002 listed with no visits, strength 10 counted once, visited 10, coverage 100%, skip 0%
        $this->assertSame('80001', $p['reading']['data']['owner']['staff_id']);
        $this->assertSame([1, ['80002']], [$p['reading']['data']['other_count'], array_column($p['reading']['data']['others'], 'staff_id')]);
        $this->assertSame([10, 10, 100.0, 0.0], [$p['reading']['data']['strength'], $p['reading']['data']['visited'], $p['reading']['data']['coverage'], $p['reading']['data']['skip_rate']]);
        // billing: 11 customers, 10 billed, month total 572.00, paid 457.60 (8 of 10), owing 214.40 in 3 accounts, one unbilled
        $b = $p['billing']['data']['row'];
        $this->assertSame([11, 10, 572.0, 457.6, 214.4, 3, 1], [$b['customers'], $b['billed_customers'], $b['month_total'], $b['paid'], $b['owing_amount'], $b['owing'], $b['unbilled']]);
        $this->assertSame('80001', $p['billing']['data']['cca']['staff_id']);
        // customers: 12 accounts in the list, 11 metered (account 5 has no meter; account 4's meter is faulty), 1 faulty, 1 with no meter, 11 of 12 reachable (account 1 has no mobile), 1 with two numbers
        $c = $p['customers']['data'];
        $this->assertSame([12, 11, 1, 1], [$c['customers'], $c['metered'], $c['faulty'], $c['no_meter']]);
        $this->assertSame([12, 11, 1, 1, 91.67], [$c['reach']['customers'], $c['reach']['reachable'], $c['reach']['no_mobile'], $c['reach']['two_numbers'], $c['reach_share']]);
        $this->assertSame(['ACTB' => 11, 'DISC' => 1], $c['by_status']);
        // checks: strength 10 against 11 metered and 11 billing customers; none of the 11 billed accounts is missing from the list; the list holds 12 accounts of which one (12) is not on the bill
        $k = $p['checks']['data'];
        $this->assertSame([-1, -1], [$k['strength']['diff_metered'], $k['strength']['diff_billing']]);
        $this->assertSame([11, 0, 12, 1], [$k['accounts']['billing_accounts'], $k['accounts']['in_billing_not_in_list'], $k['accounts']['list_accounts'], $k['accounts']['in_list_not_on_this_bill']]);
        // the route is on the bill's trend and the reading's
        $this->assertCount(1, $p['reading']['data']['trend']);
        $this->assertCount(1, $p['billing']['data']['trend']);
    }

    public function test_route_360_shows_a_route_printed_under_two_districts_and_the_code_that_means_no_route(): void
    {
        $this->actingAs($this->crossAll());
        $o2 = app(CrossFileReport::class)->route($this->crossRoute('O', '4202'));
        $this->assertSame('Sowutuom', $o2['route']['aliases'][0]['district']);
        $this->assertTrue($o2['parts']['customers']['data']['aliased']);
        $this->assertSame(['Sowutuom' => 10], $o2['parts']['customers']['data']['list_districts'], 'the list puts all ten in Sowutuom');

        $zero = app(CrossFileReport::class)->route($this->crossRoute('O', '0000'));
        $this->assertTrue($zero['route']['unassigned']);
        $this->assertFalse($zero['parts']['reading']['data']['present'], 'no reading row names route 0000');
        $this->assertSame([2, 1], [$zero['parts']['billing']['data']['row']['customers'], $zero['parts']['customers']['data']['customers']]);
        $this->assertSame(1, $zero['parts']['checks']['data']['accounts']['in_billing_not_in_list'], 'account 122 is not in the list');
    }

    public function test_route_360_follows_the_permission_rule_and_names_no_cca_without_the_permission(): void
    {
        $viewer = $this->crossViewer(['commercial.view_customer_analytics'], '900096');
        $this->actingAs($viewer);
        $r = app(CrossFileReport::class)->route($this->crossRoute('S', '4101'));

        $this->assertFalse($r['parts']['reading']['visible']);
        $this->assertFalse($r['parts']['billing']['visible']);
        $this->assertTrue($r['parts']['customers']['visible'] && ! $r['parts']['customers']['empty']);
        $this->assertNull($r['parts']['checks']['data']['strength']);
        $this->assertCount(2, $r['hidden']);

        // (a district with fewer than the minimum of CCAs has its reading figures held back from a viewer who may not see CCAs: here the minimum is lowered to one)
        config(['gwl.commercial_min_readers_for_district_figures' => 1]);
        $this->actingAs($this->crossViewer(['commercial.view_reading', 'commercial.view_billing'], '900097'));
        $named = app(CrossFileReport::class)->route($this->crossRoute('S', '4101'));
        $this->assertNull($named['parts']['reading']['data']['owner'], 'a CCA is named only with the CCA performance permission');
        $this->assertSame([], $named['parts']['reading']['data']['others']);
        $this->assertSame(0, $named['parts']['reading']['data']['other_count'], 'the other CCAs of a route are not even counted for a viewer who may not see CCAs');
        $this->assertNull($named['parts']['billing']['data']['cca']);
        $this->assertStringNotContainsString('AMA TEST', json_encode($named));
    }

    public function test_route_360_files(): void
    {
        $user = $this->crossAll();
        $o1 = $this->crossRoute('O', '4201');

        $sheets = $this->sheets($this->export($user, 'cross-route', 'excel', ['route' => $o1]));
        $this->assertSame(['Notes', 'Route', 'Reading', 'Reading by upload', 'Billing', 'Billing by upload', 'Customer list', 'Checks'], array_keys($sheets));
        $this->assertStringContainsString('ODORKOR 4201', json_encode($sheets['Route']));
        $this->assertStringContainsString('80003 EFUA TEST', json_encode($sheets['Reading']));

        $pdf = $this->export($user, 'cross-route', 'pdf', ['route' => $o1]);
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.export_cross_route_excel')->count());
        $this->export($user, 'cross-route', 'excel', ['route' => 999999])->assertNotFound();
    }
}
