<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\ReadingRoutes;
use App\Models\AuditLog;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\CommercialReportData;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8c: the "By route and CCA" screen, the small-group rule, district scope and the export. Invented CCAs and routes only; the
 * fixture and its arithmetic are in BuildsRouteReading / ReadingRouteAnalyticsTest ("today" is 15 Nov 2026).
 *
 * Sowutuom has two CCAs with visits (80001, 80002), Odorkor one (80003): with the minimum set to 2, Sowutuom's figures are shown and
 * Odorkor's are held back from a viewer who may not see individual CCAs.
 */
class ReadingRouteScreensTest extends CommercialTestCase
{
    use BuildsRouteReading;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 10:00:00');
        config([
            'gwl.commercial_min_visits_for_ranking' => 100, 'gwl.commercial_min_readers_for_district_figures' => 2,
            'gwl.commercial_customer_list_enabled' => false,
        ]);
        $this->seedRouteReading($this->routeFixtureRows());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A user holding exactly the given permissions; $allDistricts false = only the district on their employee record. */
    protected function userWith(string $staffId, array $permissions, bool $allDistricts = true): User
    {
        $role = Role::query()->create(['name' => 'custom_'.$staffId, 'display_name' => 'Custom '.$staffId, 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('name', [...$permissions, ...($allDistricts ? ['commercial.view_all_districts'] : [])])->pluck('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);

        $user = $this->userWithRoles($staffId, [], $this->accraWest, $this->sowutuom);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    protected function export(User $user, string $format = 'excel', array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('commercial.export', ['report' => 'reading-routes', 'format' => $format, ...$query]));
    }

    /** @return array<string, list<list<mixed>>> */
    protected function sheets(TestResponse $response): array
    {
        $out = [];

        foreach (IOFactory::load($response->baseResponse->getFile()->getPathname())->getAllSheets() as $sheet) {
            $out[$sheet->getTitle()] = $sheet->toArray(null, false, false, false);
        }

        return $out;
    }

    // ---------------------------------------------------------------- the screen

    public function test_a_permitted_user_sees_the_figures_on_each_tab(): void
    {
        $this->actingAs($this->officer());

        $overview = Livewire::test(ReadingRoutes::class)->assertOk()->assertSee('Meter reading by route and CCA')
            ->assertSee('Oct 2026')->assertSee('82.1%')->assertSee('23.9%')->assertSee('Sowutuom')->assertSee('Odorkor');
        $this->assertStringContainsString('not capped at 100%', $overview->html());

        Livewire::test(ReadingRoutes::class)->set('tab', 'ccas')->assertSee('80001 AMA TEST')->assertSee('21.4%')->assertSee('80004 YAW TEST')->assertSee('No visits')->assertSee('80005 KOFI TEST')->assertSee('No route');
        Livewire::test(ReadingRoutes::class)->set('tab', 'routes')->assertSee('SOWUTUOM S1')->assertSee('ODORKOR O2')->assertSee('80001 AMA TEST');
        Livewire::test(ReadingRoutes::class)->set('tab', 'checks')->assertSee('SOWUTUOM S1')->assertSee('80002 KWAME TEST')->assertSee('80005 KOFI TEST');
    }

    public function test_the_date_range_is_typed_like_billings_and_an_empty_range_says_so(): void
    {
        $this->actingAs($this->officer());

        Livewire::test(ReadingRoutes::class)->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-31')->assertSee('460')->assertSee('31 of 31 days covered');
        Livewire::test(ReadingRoutes::class)->set('dateFrom', '2026-01-01')->set('dateTo', '2026-01-31')->assertSee('No upload lies inside this range.');
        Livewire::test(ReadingRoutes::class)->set('preset', '2026-10')->assertSet('dateFrom', '2026-10-01')->assertSet('dateTo', '2026-10-31')->assertSee('460');

        // an upload only partly inside the range is left out and said so
        Livewire::test(ReadingRoutes::class)->set('dateFrom', '2026-10-10')->set('dateTo', '2026-11-30')->assertSee('No upload lies inside this range.');
    }

    public function test_the_screen_and_the_route_need_view_reading(): void
    {
        $nobody = $this->userWith('900951', ['commercial.view_billing']);

        $this->actingAs($nobody)->get(route('commercial.reading.routes'))->assertForbidden();
        $this->actingAs($this->officer())->get(route('commercial.reading.routes'))->assertOk();

        $this->actingAs($nobody);
        Livewire::test(ReadingRoutes::class)->assertForbidden();
    }

    public function test_with_no_upload_the_screen_explains_how_to_load_one(): void
    {
        \App\Models\CommercialImportBatch::query()->update(['status' => \App\Models\CommercialImportBatch::STATUS_VOIDED]);
        $this->actingAs($this->officer());

        Livewire::test(ReadingRoutes::class)->assertSee('No reading report by route has been loaded yet.')->assertSee('rptReadingSumm');
    }

    public function test_the_sidebar_offers_the_screen_only_with_view_reading(): void
    {
        $this->actingAs($this->officer())->get(route('commercial.reading.routes'))->assertSee('Reading by route');
    }

    // ---------------------------------------------------------------- the small-group rule

    public function test_without_ccas_performance_no_cca_is_named_and_a_small_district_is_held_back(): void
    {
        $viewer = $this->userWith('900952', ['commercial.view_reading']);
        $this->assertFalse($viewer->hasPermission('commercial.view_reader_performance'));
        $this->actingAs($viewer);

        // the district table: Sowutuom has two CCAs with visits (shown), Odorkor one (held back)
        $overview = Livewire::test(ReadingRoutes::class)->assertSee('Sowutuom')->assertSee('Odorkor')->assertSee('Held back: fewer than 2 CCAs')->assertDontSee('AMA TEST')->assertDontSee('80001');
        $this->assertStringContainsString('260', $overview->html());   // Sowutuom's visits

        // CCA tab: counts, never names
        Livewire::test(ReadingRoutes::class)->set('tab', 'ccas')->assertSee('5 CCAs are listed, 4 of them with routes.')->assertDontSee('AMA TEST')->assertDontSee('KWAME TEST')->assertDontSee('YAW TEST')->assertDontSee('KOFI TEST');
        Livewire::test(ReadingRoutes::class)->set('tab', 'routes')->assertSee('SOWUTUOM S1')->assertDontSee('AMA TEST')->assertDontSee('80001');
        Livewire::test(ReadingRoutes::class)->set('tab', 'checks')->assertDontSee('KWAME TEST')->assertDontSee('KOFI TEST')->assertDontSee('80002');
    }

    public function test_the_data_behind_the_screen_names_nobody_and_blanks_a_small_district(): void
    {
        $this->actingAs($this->userWith('900952', ['commercial.view_reading']));
        $report = app(CommercialReportData::class)->routeReading();

        $this->assertFalse($report['named']);
        $this->assertSame([], $report['analysis']['ccas']);
        $this->assertSame([], $report['analysis']['backlog']['ccas']);
        $this->assertSame([], $report['analysis']['coverage']['ccas_no_routes']);
        $this->assertSame(5, $report['counts']['ccas']);
        $this->assertSame(['Odorkor'], $report['held_back']);

        $odorkor = collect($report['analysis']['districts'])->firstWhere('name', 'Odorkor');
        $this->assertNull($odorkor['visited']);
        $this->assertNull($odorkor['skip_rate']);
        $this->assertSame(280, $odorkor['strength']);   // the verified strength is not a CCA's own figure
        $this->assertSame(260, collect($report['analysis']['districts'])->firstWhere('name', 'Sowutuom')['visited']);
        $this->assertSame(460, $report['analysis']['totals']['visited']);   // the region total still adds all districts

        // no route of the held-back district is listed, and nobody is named as an owner on the others
        $labels = array_column($report['analysis']['routes'], 'label');
        $this->assertNotContains('ODORKOR O1', $labels);
        $this->assertContains('SOWUTUOM S1', $labels);
        $this->assertSame([null], array_unique(array_column($report['analysis']['routes'], 'owner_cca')));

        // a person who may see individual CCAs gets all of it
        $this->actingAs($this->officer());
        $full = app(CommercialReportData::class)->routeReading();
        $this->assertTrue($full['named']);
        $this->assertCount(5, $full['analysis']['ccas']);
        $this->assertSame([], $full['held_back']);
    }

    // ---------------------------------------------------------------- district scope

    public function test_a_district_user_sees_only_their_district_and_no_region_wide_row(): void
    {
        // the district viewer role: view_* without view_all_districts; their employee record puts them in Sowutuom
        $viewer = $this->userWithRoles('900960', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom);
        $this->actingAs($viewer);
        $report = app(CommercialReportData::class)->routeReading();

        $this->assertTrue($report['district_limited']);
        $this->assertSame(['Sowutuom'], array_column($report['analysis']['districts'], 'name'));
        $this->assertSame(280, $report['analysis']['totals']['strength']);     // only Sowutuom's three routes
        $this->assertSame(260, $report['analysis']['totals']['visited']);
        $this->assertSame([$this->sowutuom->id => 'Sowutuom'], $report['districts']);

        Livewire::test(ReadingRoutes::class)->assertSee('Sowutuom')->assertDontSee('Odorkor')->assertDontSee('ODORKOR O1');
        // a district typed into the URL that is not theirs is ignored, never confirmed to exist
        $other = app(CommercialReportData::class)->routeReading(null, $this->odorkor->id);
        $this->assertNull($other['district_id']);
        $this->assertSame(280, $other['analysis']['totals']['strength']);
    }

    public function test_a_district_user_whose_district_is_small_is_shown_no_figure_at_all(): void
    {
        config(['gwl.commercial_min_readers_for_district_figures' => 3]);   // Sowutuom has two CCAs with visits
        $viewer = $this->userWithRoles('900960', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom);
        $this->actingAs($viewer);
        $report = app(CommercialReportData::class)->routeReading();

        $this->assertTrue($report['small_group']);
        $this->assertNull($report['analysis']);
        Livewire::test(ReadingRoutes::class)->assertSee('These figures are held back.')->assertDontSee('260')->assertDontSee('AMA TEST');
    }

    public function test_a_user_with_no_employee_record_sees_nothing(): void
    {
        $this->actingAs($this->userWithoutEmployee('900961', ['commercial_officer']));
        $report = app(CommercialReportData::class)->routeReading();

        $this->assertFalse($report['available']);
    }

    public function test_another_regions_uploads_are_never_offered(): void
    {
        $ashantiDistrict = \App\Models\District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi Central']);
        $this->seedRouteReading([['90001', 'ASHANTI TEST', 'K1', $ashantiDistrict, 50, 40, 5, 45]], ['region' => $this->ashanti, 'from' => '2026-11-01', 'to' => '2026-11-30']);

        // an Accra West officer never sees Ashanti, however the URL is edited
        $this->actingAs($this->officer());
        $report = app(CommercialReportData::class)->routeReading($this->ashanti->id);
        $this->assertSame($this->accraWest->id, $report['region_id']);
        $this->assertSame([$this->accraWest->id => 'Accra West'], $report['regions']);
        Livewire::test(ReadingRoutes::class)->set('region', (string) $this->ashanti->id)->assertDontSee('Kumasi Central')->assertDontSee('ASHANTI TEST');

        // a viewer of every region may pick it
        $this->actingAs($this->superAdmin());
        $all = app(CommercialReportData::class)->routeReading($this->ashanti->id);
        $this->assertSame($this->ashanti->id, $all['region_id']);
        $this->assertSame(45, $all['analysis']['totals']['visited']);
    }

    // ---------------------------------------------------------------- the export

    public function test_the_excel_export_has_a_sheet_per_analysis_with_the_screens_figures(): void
    {
        $response = $this->export($this->officer())->assertOk();
        $sheets = $this->sheets($response);

        $this->assertArrayHasKey('Notes', $sheets);
        $this->assertContains('R1 CCA scorecard', array_keys($sheets));

        $r1 = collect($sheets['R1 CCA scorecard'])->slice(1)->keyBy(1);
        $this->assertSame(160, (int) $r1['80001'][5]);            // strength owned
        $this->assertEquals(21.43, $r1['80001'][11]);              // skip rate
        $this->assertSame(1, (int) $r1['80001'][0]);               // rank
        $r2 = collect($sheets['R2 Route league table'])->slice(1)->keyBy(0);
        $this->assertSame(100, (int) $r2['SOWUTUOM S1'][3]);       // strength counted once
        $d = collect($sheets['R3 Districts'])->slice(1)->keyBy(0);
        $this->assertSame(260, (int) $d['Sowutuom'][7]);
        $this->assertArrayHasKey('Trend by upload', $sheets);
    }

    public function test_the_pdf_export_downloads(): void
    {
        $response = $this->export($this->officer(), 'pdf')->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('commercial_reading_routes_accra-west_', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_export_is_scoped_to_the_viewer(): void
    {
        // a district user who may export: only their district, whatever they type
        $viewer = $this->userWith('900962', ['commercial.view_reading', 'commercial.export_reports', 'commercial.view_reader_performance'], allDistricts: false);
        $response = $this->export($viewer, 'excel', ['district' => $this->odorkor->id])->assertOk();
        $everything = json_encode($this->sheets($response));

        $this->assertStringContainsString('SOWUTUOM S1', $everything);
        $this->assertStringNotContainsString('ODORKOR', $everything);
        $this->assertStringNotContainsString('Odorkor', $everything);
        $this->assertStringNotContainsString('EFUA TEST', $everything);
    }

    public function test_the_export_of_a_viewer_who_may_not_see_ccas_names_none(): void
    {
        $viewer = $this->userWith('900963', ['commercial.view_reading', 'commercial.export_reports']);
        $sheets = $this->sheets($this->export($viewer)->assertOk());
        $everything = json_encode($sheets);

        foreach (['AMA TEST', 'KWAME TEST', 'EFUA TEST', 'YAW TEST', 'KOFI TEST', '80001', '80003'] as $name) {
            $this->assertStringNotContainsString($name, $everything, $name);
        }

        $this->assertArrayNotHasKey('R1 CCA scorecard', $sheets);
        $this->assertArrayHasKey('R3 Districts', $sheets);
    }

    public function test_the_export_needs_export_reports_and_view_reading(): void
    {
        $this->export($this->userWith('900964', ['commercial.view_reading']))->assertForbidden();
        $this->export($this->userWith('900965', ['commercial.export_reports', 'commercial.view_billing']))->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'commercial.export_reading_routes%')->count());
    }

    public function test_every_download_is_audited_with_ids_and_counts_only(): void
    {
        $this->export($this->officer(), 'excel')->assertOk();
        $log = AuditLog::query()->where('action', 'commercial.export_reading_routes_excel')->sole();

        $this->assertSame('excel', $log->metadata['format']);
        $this->assertGreaterThan(0, $log->metadata['rows']);
        $this->assertSame('2026-10-01', $log->metadata['filters']['from']);
        $encoded = json_encode($log->metadata);

        foreach (['AMA TEST', 'KWAME TEST', 'SOWUTUOM S1', '80001'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }

        $this->export($this->officer(), 'pdf')->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.export_reading_routes_pdf')->count());
    }

    public function test_a_file_over_the_row_cap_is_refused_and_not_audited(): void
    {
        config(['gwl.commercial_export_max_rows' => 5]);

        $this->export($this->officer())->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('limit of 5', (string) session('error'));
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'commercial.export_reading_routes%')->count());

        config(['gwl.commercial_export_max_rows' => 5000, 'gwl.commercial_export_pdf_max_rows' => 5]);
        $this->export($this->officer(), 'pdf')->assertRedirect()->assertSessionHas('error');
    }

    public function test_with_no_upload_there_is_nothing_to_export(): void
    {
        \App\Models\CommercialImportBatch::query()->update(['status' => \App\Models\CommercialImportBatch::STATUS_VOIDED]);

        $this->export($this->officer())->assertRedirect()->assertSessionHas('error');
    }

    // ---------------------------------------------------------------- the legacy report is untouched

    public function test_loading_a_route_report_changes_no_figure_of_the_by_month_reading_report_or_billing(): void
    {
        \App\Models\CommercialImportBatch::query()->delete();   // start from the legacy data alone
        $this->seedReading(['R1' => ['2026-09-01' => [400, 100]], 'R2' => ['2026-09-01' => [200, 100]]], options: ['names' => ['R1' => 'ADWOA TEST', 'R2' => 'KWESI TEST'], 'districts' => ['R1' => $this->sowutuom->id, 'R2' => $this->odorkor->id]]);
        $this->seedBilling($this->billingFixture(), '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id]]);

        $this->actingAs($this->officer());
        $data = app(CommercialReportData::class);
        $snapshot = fn () => json_encode([$data->readingTrend(null, null, null, null), $data->readers(null, null, null, null), $data->snapshots(), $data->billingSnapshot(null)['overview'], $data->kpis(), $data->summary()['summary']['reading'] ?? null]);
        $before = $snapshot();

        $this->seedRouteReading($this->routeFixtureRows());

        $this->assertSame($before, $snapshot());
        $this->assertSame(1, \App\Models\CommercialImportBatch::query()->where('report_type', 'reading_summary')->count());
    }
}
