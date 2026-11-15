<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\CommercialImportService;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\ReadingRouteSampleFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8c: the small generated fixture (tests/Fixtures/reading-route-sample.xlsx, made by `php artisan commercial:reading-routes:sample`),
 * imported through the real pipeline, with every figure of the analyses worked out by hand. Invented CCAs, ids, names and routes only.
 *
 * The design is in ReadingRouteSampleFile::sampleSpec(). In short: routes 7001-7040, route n in district (n - 1) / 10; an ordinary route
 * is strength 100, read 70, skipped 10, visited 80; CCA 9100c owns routes 4c-3 to 4c. The exceptions:
 *   7001  strength 169 / read 100 / skipped 20 / visited 120 (owner 91001) and the stale row 165 / 0 / 0 / 0 under 91002
 *   7013  visited 85 where read + skipped is 80 (a flagged row)      91003's TOTALS row says visited 321 (the rows add up to 320)
 *   7037-7040 (91010) strength 100, no visits      91011 no route      91012 one route in an unknown district: 50 / 20 / 5 / 25
 */
class ReadingRouteSampleFixtureTest extends CommercialTestCase
{
    protected Region $sample;

    /** @var array<int, District> */
    protected array $districts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.commercial_customer_list_enabled' => false, 'gwl.commercial_reconcile_max_route_failures_pct' => 10, 'gwl.commercial_min_visits_for_ranking' => 300]);
        $this->sample = Region::query()->create(['region_name' => 'Sample Region']);

        foreach (['Sample One', 'Sample Two', 'Sample Three', 'Sample Four'] as $name) {
            $this->districts[] = District::query()->create(['region_id' => $this->sample->id, 'district_name' => $name]);
        }
    }

    /** A copy of the fixture (the test tidies its own files up, never the committed one). */
    protected function fixture(): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'rrs').'.xlsx';
        copy(base_path('tests/Fixtures/reading-route-sample.xlsx'), $copy);

        return $this->asUpload($copy, 'rptReadingSumm.xlsx');
    }

    protected function user(): \App\Models\User
    {
        return $this->officer('900001', $this->sample, $this->districts[0]);
    }

    protected function import(): CommercialImportBatch
    {
        $this->actingAs($this->user());

        return $this->importFile($this->fixture());
    }

    // ---------------------------------------------------------------- the preview

    public function test_the_preview_of_the_fixture_reads_every_awkward_case(): void
    {
        $this->actingAs($this->user());
        $preview = app(CommercialImportService::class)->preview($this->fixture());
        $r = $preview['route_report'];

        $this->assertSame(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY, $preview['report_type']);
        $this->assertFalse($preview['blocked'], json_encode($preview['errors']));
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-21'], $preview['period']);
        $this->assertSame('range', $r['period_kind'], 'a part month is a range, not a month');
        $this->assertSame('1 Oct to 21 Oct 2026', $r['period_label']);
        $this->assertSame('Sample Region', $preview['region']['name']);
        $this->assertSame(12, $r['sheets']);           // 12 CCA sheets; the 13th is the excluded account
        $this->assertSame(12, $r['ccas']);
        $this->assertSame(41, $r['routes']);           // 7001-7040 and the one in the unknown district
        $this->assertSame(4, $r['districts']);
        $this->assertSame(42, $preview['total_rows']); // 4 + 5 (7001 twice) + 8 x 4 + 91011 none + 91012 one
        $this->assertSame(1, $preview['system_count']);
        $this->assertSame(1, $r['owner_decision_count']);
        $this->assertSame('SAMPLE ONE 7001', $r['owner_decisions'][0]['route']);
        $this->assertSame('91001', $r['owner_decisions'][0]['owner']);
        $this->assertSame(['91002'], $r['owner_decisions'][0]['others']);
        $this->assertSame([['staff_id' => '91011', 'name' => 'SAMPLE CCA 11']], $r['no_routes']);
        $this->assertSame([['staff_id' => '91010', 'name' => 'SAMPLE CCA 10', 'routes' => 4]], $r['no_visits']);
        $this->assertSame(1, $r['flagged_rows']);
        $this->assertSame(1, $r['flagged_sheets']);
        $this->assertSame(1, $r['unplaced_rows']);
        $this->assertStringContainsString('"ATLANTIS" is not recognised', collect($preview['warnings'])->pluck('message')->implode(' '));
    }

    // ---------------------------------------------------------------- the stored batch

    public function test_the_fixture_imports_with_its_findings_kept_and_the_excluded_account_skipped(): void
    {
        $batch = $this->import();

        $this->assertSame('2026-10-01', $batch->period_from->toDateString());
        $this->assertSame('2026-10-21', $batch->period_to->toDateString());
        $this->assertFalse($batch->reconciliation_passed, 'one row and one sheet disagree with themselves');
        $this->assertSame(42, $batch->routeStats()->whereNotNull('route_label_raw')->count());
        $this->assertSame(0, $batch->routeStats()->where('cca_staff_id', '00000')->count());

        $flagged = $batch->routeStats()->where('is_flagged', true)->get();
        $this->assertSame(['SAMPLE TWO 7013'], $flagged->pluck('route_label_raw')->all());
        $this->assertSame(CommercialReadingRouteStat::FLAG_VISITED, $flagged[0]->flag_reason);
        $this->assertSame([['staff_id' => '91003', 'detail' => 'visited 320 on the rows, 321 on the TOTALS row']], $batch->control_totals['sheets_failed']);

        $parked = $batch->routeStats()->where('cca_staff_id', '91012')->firstOrFail();
        $this->assertNull($parked->route_id);
        $this->assertSame('ATLANTIS', $parked->district_label_raw);

        // route 7001: both rows kept, the owner counts the strength once (169, not 169 + 165)
        $rows = $batch->routeStats()->where('route_label_raw', 'SAMPLE ONE 7001')->orderBy('cca_staff_id')->get();
        $this->assertSame([['91001', true, 169, 120], ['91002', false, 165, 0]], $rows->map(fn ($r) => [$r->cca_staff_id, $r->is_route_owner, $r->verified_strength, $r->visited_count])->all());
        $this->assertSame(4069 + 50, (int) $batch->routeStats()->where('is_route_owner', true)->sum('verified_strength'));
    }

    // ---------------------------------------------------------------- R1 to R8, by hand

    public function test_every_analysis_of_the_fixture_matches_the_arithmetic_done_by_hand(): void
    {
        $this->import();
        $report = app(CommercialReportData::class)->routeReading();
        $a = $report['analysis'];

        // ---- totals: 40 routes of the four districts (4069) plus the one in the unknown district (50)
        //      strength 169 + 39 x 100 = 4069      read 100 + 34 x 70 + 70 = 2550      skipped 20 + 34 x 10 + 10 = 370      visited 120 + 34 x 80 + 85 = 2925
        $t = $a['totals'];
        $this->assertSame(4069 + 50, $t['strength']);
        $this->assertSame(2550 + 20, $t['read']);
        $this->assertSame(370 + 5, $t['skipped']);
        $this->assertSame(2925 + 25, $t['visited']);
        $this->assertSame(1169, $t['unvisited']);                 // 4119 - 2950
        $this->assertEquals(71.62, $t['coverage']);               // 2950 / 4119
        $this->assertEquals(12.71, $t['skip_rate']);              // 375 / 2950

        // ---- R3: a district is the sum of its routes
        $d = collect($a['districts'])->keyBy('name');
        $this->assertSame(['Sample Four', 'Sample One', 'Sample Three', 'Sample Two'], collect($a['districts'])->pluck('name')->all());
        $this->assertSame([10, 1069, 730, 110, 840, 229], [$d['Sample One']['routes'], $d['Sample One']['strength'], $d['Sample One']['read'], $d['Sample One']['skipped'], $d['Sample One']['visited'], $d['Sample One']['unvisited']]);
        $this->assertEquals(78.58, $d['Sample One']['coverage']);   // 840 / 1069
        $this->assertEquals(13.1, $d['Sample One']['skip_rate']);   // 110 / 840
        $this->assertSame([1000, 700, 100, 805, 195], [$d['Sample Two']['strength'], $d['Sample Two']['read'], $d['Sample Two']['skipped'], $d['Sample Two']['visited'], $d['Sample Two']['unvisited']]);
        $this->assertEquals(80.5, $d['Sample Two']['coverage']);
        $this->assertEquals(12.42, $d['Sample Two']['skip_rate']);  // 100 / 805
        $this->assertSame([1000, 800, 200], [$d['Sample Three']['strength'], $d['Sample Three']['visited'], $d['Sample Three']['unvisited']]);
        $this->assertEquals(12.5, $d['Sample Three']['skip_rate']);
        $this->assertSame([1000, 420, 60, 480, 520], [$d['Sample Four']['strength'], $d['Sample Four']['read'], $d['Sample Four']['skipped'], $d['Sample Four']['visited'], $d['Sample Four']['unvisited']]);
        $this->assertEquals(48.0, $d['Sample Four']['coverage']);
        $this->assertSame(2925, $d->sum('visited'), 'the four districts add up to the placed routes');

        // ---- R1: the CCA scorecard
        $c = $a['ccas'];
        $this->assertSame([4, 469, 310, 50, 360, 109], [$c['91001']['owned_routes'], $c['91001']['owned_strength'], $c['91001']['read'], $c['91001']['skipped'], $c['91001']['visited'], $c['91001']['unvisited']]);
        $this->assertEquals(76.76, $c['91001']['coverage']);        // 360 / 469
        $this->assertEquals(13.89, $c['91001']['skip_rate']);       // 50 / 360
        $this->assertSame([5, 4, 400, 320, 80], [$c['91002']['route_rows'], $c['91002']['owned_routes'], $c['91002']['owned_strength'], $c['91002']['visited'], $c['91002']['unvisited']], '91002 lists 7001 too, but owns four routes');
        $this->assertEquals(12.5, $c['91002']['skip_rate']);
        $this->assertSame([400, 325, 75], [$c['91004']['owned_strength'], $c['91004']['visited'], $c['91004']['unvisited']]);
        $this->assertEquals(12.31, $c['91004']['skip_rate']);       // 40 / 325
        $this->assertTrue($c['91010']['zero_visits']);
        $this->assertSame(400, $c['91010']['unvisited']);
        $this->assertTrue($c['91011']['no_routes']);
        $this->assertNull($c['91012']['district_id'], 'a CCA whose only route is in an unknown district has no district yet');
        // ranked on skip rate among the 9 CCAs with 300+ visits: 91004 (12.31) first, then the seven at 12.5 by staff id, then 91001 (13.89)
        $this->assertSame([1, 2, 3, 4, 8, 9, null], [$c['91004']['rank'], $c['91002']['rank'], $c['91003']['rank'], $c['91005']['rank'], $c['91009']['rank'], $c['91001']['rank'], $c['91010']['rank']]);

        // ---- R2: the route league table
        $r = collect($a['routes'])->keyBy('label');
        $this->assertSame([169, 100, 120, 49], [$r['SAMPLE ONE 7001']['strength'], $r['SAMPLE ONE 7001']['read'], $r['SAMPLE ONE 7001']['visited'], $r['SAMPLE ONE 7001']['unvisited']]);
        $this->assertEquals(71.01, $r['SAMPLE ONE 7001']['coverage']);   // 120 / 169
        $this->assertEquals(16.67, $r['SAMPLE ONE 7001']['skip_rate']);  // 20 / 120
        $this->assertSame([100, 85, 15], [$r['SAMPLE TWO 7013']['strength'], $r['SAMPLE TWO 7013']['visited'], $r['SAMPLE TWO 7013']['unvisited']]);
        $this->assertNull($r['SAMPLE FOUR 7037']['skip_rate']);

        // ---- R4: workload (the 9 + 1 CCAs that own routes and the one that owns the unplaced route)
        $w = $a['workload'];
        $this->assertSame(11, $w['ccas']);
        $this->assertSame(41, $w['routes']);
        $this->assertSame(469, $w['largest']);
        $this->assertSame('91001', $w['largest_cca']);
        $this->assertEquals(400, $w['median']);                           // sorted: 50, 400 x 9, 469
        $this->assertSame(['91012'], array_column($w['low'], 'staff_id'), '50 is 12.5% of the median, below the 50% line');
        $this->assertSame([], $w['high']);

        // ---- R5: the backlog
        $b = $a['backlog'];
        $this->assertSame(['SAMPLE FOUR 7037', 'SAMPLE FOUR 7038', 'SAMPLE FOUR 7039', 'SAMPLE FOUR 7040', 'SAMPLE ONE 7001'], array_slice(array_column($b['routes'], 'label'), 0, 5));   // 100 unvisited x 4, then 49
        $this->assertSame(['91010'], array_column($b['ccas_zero_visits'], 'staff_id'));
        $this->assertSame(['SAMPLE FOUR 7037', 'SAMPLE FOUR 7038', 'SAMPLE FOUR 7039', 'SAMPLE FOUR 7040'], array_column($b['routes_zero_visits'], 'label'));

        // ---- R6: more than one CCA on a route, a CCA with no route
        $multi = $a['coverage']['multi_cca_routes'];
        $this->assertSame(['SAMPLE ONE 7001'], array_column($multi, 'label'));
        $this->assertSame(['91001' => 120, '91002' => 0], collect($multi[0]['multi_rows'])->pluck('visited', 'cca')->all());
        $this->assertSame(['91011'], array_column($a['coverage']['ccas_no_routes'], 'staff_id'));
        $this->assertSame(1, $a['coverage']['unplaced_rows']);

        // ---- R7: the nine CCAs with 300+ visits are compared inside their own district (a district needs five to judge anyone)
        $this->assertSame(9, $a['outliers']['eligible']);
        $this->assertSame([], $a['outliers']['flagged'], 'nobody is two standard deviations above a district whose CCAs skip alike');

        // ---- the trend has this one upload, labelled with the dates it covers
        $this->assertSame(['1 Oct to 21 Oct 2026'], array_column($report['trend'], 'label'));
        $this->assertSame(2950, $report['trend'][0]['visited']);
    }

    // ---------------------------------------------------------------- the command

    public function test_the_sample_command_writes_the_same_workbook(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rrs-'.uniqid().'.xlsx';
        $this->workbooks[] = $path;

        $this->assertSame(0, Artisan::call('commercial:reading-routes:sample', ['--region' => 'SAMPLE REGION', '--districts' => 'SAMPLE ONE,SAMPLE TWO,SAMPLE THREE,SAMPLE FOUR', '--output' => $path]));
        $this->assertFileExists($path);

        $this->actingAs($this->user());
        $service = app(CommercialImportService::class);
        $made = $service->preview(ReportWorkbooks::upload($path, 'made.xlsx'));
        $fixture = $service->preview($this->fixture());

        $this->assertSame($fixture['route_report']['sheets'], $made['route_report']['sheets']);
        $this->assertSame($fixture['total_rows'], $made['total_rows']);
        $this->assertSame($fixture['route_report']['flagged_rows'], $made['route_report']['flagged_rows']);
        $this->assertSame($fixture['period'], $made['period']);
    }

    public function test_the_sample_command_refuses_production_and_a_short_district_list(): void
    {
        $this->artisan('commercial:reading-routes:sample', ['--districts' => 'ONE,TWO', '--output' => sys_get_temp_dir().'/never.xlsx'])->assertFailed();

        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('commercial:reading-routes:sample', ['--output' => sys_get_temp_dir().'/never.xlsx'])->assertFailed();
        $this->assertFileDoesNotExist(sys_get_temp_dir().'/never.xlsx');
    }

    public function test_the_sample_spec_is_all_invented(): void
    {
        $spec = ReadingRouteSampleFile::sampleSpec();

        $this->assertCount(13, $spec['ccas']);
        $this->assertSame(['SAMPLE ONE', 'SAMPLE TWO', 'SAMPLE THREE', 'SAMPLE FOUR'], ReadingRouteSampleFile::INVENTED_DISTRICTS);
        $this->assertSame(['00000'], collect($spec['ccas'])->where('name', 'System Administrator')->pluck('id')->all());
        $this->assertMatchesRegularExpression('/^SAMPLE CCA \d\d$/', $spec['ccas'][0]['name']);
    }
}
