<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialRoute;
use App\Models\CommercialImportBatch;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\ReadingRouteAnalyticsService;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8c: the analyses R1 to R8 of the reading report BY ROUTE, with the arithmetic done by hand. Invented CCAs and routes only.
 *
 * The fixture (BuildsRouteReading::routeFixtureRows, October 2026, Accra West):
 *   80001 AMA TEST    S1 (strength 100, read 70, skipped 10, visited 80)   S2 (60, 40, 20, 60)
 *   80002 KWAME TEST  S3 (120, 90, 30, 120)   and the STALE row of S1 (strength 95, no visits, so 80001 owns S1)
 *   80003 EFUA TEST   O1 (200, 150, 50, 200)
 *   80004 YAW TEST    O2 (80, 0, 0, 0)       routes but ZERO visits
 *   80005 KOFI TEST   listed with no route at all
 * S1-S3 are in Sowutuom, O1-O2 in Odorkor. Ranking needs 100 visits (set below); the workload bands are 60% and 140% of the median.
 */
class ReadingRouteAnalyticsTest extends CommercialTestCase
{
    use BuildsRouteReading;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gwl.commercial_min_visits_for_ranking' => 100, 'gwl.commercial_skip_outlier_sigma' => 2.0,
            'gwl.commercial_workload_low_pct' => 60, 'gwl.commercial_workload_high_pct' => 140,
            'gwl.commercial_customer_list_enabled' => false,
        ]);
    }

    /** @return array<string, mixed> */
    protected function report(?string $from = null, ?string $to = null): array
    {
        $this->actingAs($this->superAdmin());

        return app(CommercialReportData::class)->routeReading(null, null, $from, $to);
    }

    /** @return array<string, mixed> */
    protected function route(array $analysis, string $label): array
    {
        return collect($analysis['routes'])->firstWhere('label', $label) ?? $this->fail("No route {$label}");
    }

    protected function fixture(): CommercialImportBatch
    {
        return $this->seedRouteReading($this->routeFixtureRows());
    }

    // ---------------------------------------------------------------- R1 CCA scorecard

    public function test_r1_cca_scorecard_is_worked_out_by_hand(): void
    {
        $this->fixture();
        $c = $this->report()['analysis']['ccas'];

        // 80001: owns S1 (100) and S2 (60) = 160; visits 80 + 60 = 140; skipped 10 + 20 = 30; unvisited 160 - 140 = 20
        $this->assertSame(2, $c['80001']['owned_routes']);
        $this->assertSame(160, $c['80001']['owned_strength']);
        $this->assertSame(110, $c['80001']['read']);
        $this->assertSame(30, $c['80001']['skipped']);
        $this->assertSame(140, $c['80001']['visited']);
        $this->assertSame(20, $c['80001']['unvisited']);
        $this->assertEquals(87.5, $c['80001']['coverage']);      // 140 / 160
        $this->assertEquals(21.43, $c['80001']['skip_rate']);    // 30 / 140
        $this->assertSame(1, $c['80001']['rank']);

        // 80002: owns S3 only; the stale S1 row (strength 95) is NOT counted, and is still a row of theirs
        $this->assertSame(1, $c['80002']['owned_routes']);
        $this->assertSame(120, $c['80002']['owned_strength']);
        $this->assertSame(120, $c['80002']['visited']);
        $this->assertSame(2, $c['80002']['route_rows']);
        $this->assertEquals(25.0, $c['80002']['skip_rate']);     // 30 / 120
        $this->assertEquals(100.0, $c['80002']['coverage']);

        // 80003: 200 strength, 200 visited, 50 skipped
        $this->assertEquals(25.0, $c['80003']['skip_rate']);
        $this->assertSame(0, $c['80003']['unvisited']);

        // ranked on skip rate among the eligible (100+ visits): 80001 21.43, then 80003 and 80002 tied at 25.0 (the one with more visits first)
        $this->assertSame(2, $c['80003']['rank']);
        $this->assertSame(3, $c['80002']['rank']);

        // routes and no visits: kept, unranked, no skip rate; no routes: kept
        $this->assertTrue($c['80004']['zero_visits']);
        $this->assertNull($c['80004']['rank']);
        $this->assertNull($c['80004']['skip_rate']);
        $this->assertSame(80, $c['80004']['unvisited']);
        $this->assertEquals(0.0, $c['80004']['coverage']);
        $this->assertTrue($c['80005']['no_routes']);
        $this->assertSame(0, $c['80005']['owned_routes']);
    }

    public function test_a_cca_below_the_minimum_visits_is_not_ranked(): void
    {
        $this->fixture();
        config(['gwl.commercial_min_visits_for_ranking' => 130]);
        $c = $this->report()['analysis']['ccas'];

        // only 80001 (140) and 80003 (200) have 130 visits; 80002 has 120
        $this->assertNull($c['80002']['rank']);
        $this->assertFalse($c['80002']['eligible']);
        $this->assertSame(1, $c['80001']['rank']);
        $this->assertSame(2, $c['80003']['rank']);
    }

    // ---------------------------------------------------------------- R2 route league table

    public function test_r2_each_route_counts_its_strength_once_in_the_owner_row(): void
    {
        $this->fixture();
        $a = $this->report()['analysis'];

        $s1 = $this->route($a, 'SOWUTUOM S1');
        $this->assertSame(100, $s1['strength']);         // the owner's, not 100 + 95
        $this->assertSame(70, $s1['read']);
        $this->assertSame(10, $s1['skipped']);
        $this->assertSame(80, $s1['visited']);
        $this->assertSame(20, $s1['unvisited']);
        $this->assertEquals(80.0, $s1['coverage']);
        $this->assertEquals(12.5, $s1['skip_rate']);     // 10 / 80
        $this->assertSame('80001', $s1['owner_cca']);

        $s2 = $this->route($a, 'SOWUTUOM S2');
        $this->assertEquals(33.33, $s2['skip_rate']);    // 20 / 60
        $this->assertEquals(100.0, $s2['coverage']);

        $o2 = $this->route($a, 'ODORKOR O2');
        $this->assertSame(80, $o2['unvisited']);
        $this->assertNull($o2['skip_rate']);             // no visits: no rate
        $this->assertEquals(0.0, $o2['coverage']);

        $t = $a['totals'];
        $this->assertSame(560, $t['strength']);          // 100 + 60 + 120 + 200 + 80
        $this->assertSame(350, $t['read']);              // 70 + 40 + 90 + 150
        $this->assertSame(110, $t['skipped']);           // 10 + 20 + 30 + 50
        $this->assertSame(460, $t['visited']);           // 80 + 60 + 120 + 200
        $this->assertSame(100, $t['unvisited']);
        $this->assertEquals(82.14, $t['coverage']);      // 460 / 560
        $this->assertEquals(23.91, $t['skip_rate']);     // 110 / 460
    }

    // ---------------------------------------------------------------- R3 districts

    public function test_r3_a_district_is_the_sum_of_its_routes(): void
    {
        $this->fixture();
        $d = collect($this->report()['analysis']['districts'])->keyBy('name');

        $s = $d['Sowutuom'];
        $this->assertSame(3, $s['routes']);
        $this->assertSame(280, $s['strength']);          // 100 + 60 + 120
        $this->assertSame(200, $s['read']);              // 70 + 40 + 90
        $this->assertSame(60, $s['skipped']);            // 10 + 20 + 30
        $this->assertSame(260, $s['visited']);           // 80 + 60 + 120
        $this->assertSame(20, $s['unvisited']);
        $this->assertEquals(92.86, $s['coverage']);      // 260 / 280
        $this->assertEquals(23.08, $s['skip_rate']);     // 60 / 260
        $this->assertSame(2, $s['active_ccas']);

        $o = $d['Odorkor'];
        $this->assertSame(2, $o['routes']);
        $this->assertSame(280, $o['strength']);          // 200 + 80
        $this->assertSame(200, $o['visited']);
        $this->assertSame(80, $o['unvisited']);
        $this->assertEquals(71.43, $o['coverage']);      // 200 / 280
        $this->assertEquals(25.0, $o['skip_rate']);      // 50 / 200
        $this->assertSame(1, $o['active_ccas']);         // only 80003 has visits there
    }

    // ---------------------------------------------------------------- R4 workload

    public function test_r4_workload_balance(): void
    {
        $this->fixture();
        $w = $this->report()['analysis']['workload'];

        // CCAs that own routes: 160 (2 routes), 120, 200, 80
        $this->assertSame(4, $w['ccas']);
        $this->assertSame(5, $w['routes']);
        $this->assertSame(560, $w['strength']);
        $this->assertSame(200, $w['largest']);
        $this->assertSame('80003', $w['largest_cca']);
        $this->assertEquals(140, $w['median']);          // (120 + 160) / 2
        $this->assertEquals(1, $w['median_routes']);     // [1, 1, 1, 2]
        $this->assertEquals(140.0, $w['mean']);
        // population standard deviation: sqrt((20^2 + 20^2 + 60^2 + 60^2) / 4) = sqrt(2000) = 44.72; over the mean 140 = 31.9%
        $this->assertEquals(31.9, $w['cv']);
        // 200 / 140 = 142.9% (above 140%), 80 / 140 = 57.1% (below 60%)
        $this->assertSame(['80003'], array_column($w['high'], 'staff_id'));
        $this->assertSame(['80004'], array_column($w['low'], 'staff_id'));
    }

    // ---------------------------------------------------------------- R5 backlog

    public function test_r5_unvisited_backlog(): void
    {
        $this->fixture();
        $b = $this->report()['analysis']['backlog'];

        $this->assertSame(100, $b['unvisited_total']);   // 80 + 20
        $this->assertSame(['ODORKOR O2', 'SOWUTUOM S1'], array_column($b['routes'], 'label'));
        $this->assertSame(['80004', '80001'], array_column($b['ccas'], 'staff_id'));
        $this->assertSame([80, 20], array_column($b['ccas'], 'unvisited'));
        $this->assertSame(['ODORKOR O2'], array_column($b['routes_zero_visits'], 'label'));
        $this->assertSame(['80004'], array_column($b['ccas_zero_visits'], 'staff_id'));
    }

    // ---------------------------------------------------------------- R6 owners, no routes, routes with no reading

    public function test_r6_routes_with_several_ccas_ccas_without_routes_and_routes_never_read(): void
    {
        $this->fixture();

        // known to the system from elsewhere, with no reading row: listed. Not listed: a route a reading report filed, the "no route" code,
        // an alias of another route, a route the range does read.
        CommercialRoute::query()->create(['district_id' => $this->sowutuom->id, 'region_id' => $this->accraWest->id, 'name' => 'S9', 'code' => 'S9', 'source' => CommercialRoute::SOURCE_BILLING, 'active' => true]);
        CommercialRoute::query()->create(['district_id' => $this->odorkor->id, 'region_id' => $this->accraWest->id, 'name' => 'O9', 'code' => 'O9', 'source' => CommercialRoute::SOURCE_CUSTOMER_LIST, 'active' => true]);
        CommercialRoute::query()->create(['district_id' => $this->odorkor->id, 'region_id' => $this->accraWest->id, 'name' => 'R9', 'code' => 'R9', 'source' => CommercialRoute::SOURCE_READING, 'active' => true]);
        CommercialRoute::query()->create(['district_id' => $this->odorkor->id, 'region_id' => $this->accraWest->id, 'name' => '0000', 'code' => CommercialRoute::UNASSIGNED_CODE, 'source' => CommercialRoute::SOURCE_CUSTOMER_LIST, 'active' => true]);
        $s9 = CommercialRoute::query()->where('code', 'S9')->firstOrFail();
        CommercialRoute::query()->create(['district_id' => $this->odorkor->id, 'region_id' => $this->accraWest->id, 'name' => 'S9', 'code' => 'S9', 'source' => CommercialRoute::SOURCE_CUSTOMER_LIST, 'active' => true, 'canonical_route_id' => $s9->id]);
        CommercialRoute::query()->where('code', 'O1')->update(['source' => CommercialRoute::SOURCE_BILLING]);   // known from billing AND read: not unread

        $r = $this->report();

        $multi = $r['analysis']['coverage']['multi_cca_routes'];
        $this->assertCount(1, $multi);
        $this->assertSame('SOWUTUOM S1', $multi[0]['label']);
        $this->assertSame(['80001' => 80, '80002' => 0], collect($multi[0]['multi_rows'])->pluck('visited', 'cca')->all());
        $this->assertSame(['80005'], array_column($r['analysis']['coverage']['ccas_no_routes'], 'staff_id'));

        $this->assertSame(2, $r['unread_routes']['total']);
        $this->assertEqualsCanonicalizing(['S9', 'O9'], array_column($r['unread_routes']['shown'], 'label'));
    }

    // ---------------------------------------------------------------- R7 outliers (pure)

    public function test_r7_a_district_needs_five_eligible_ccas_and_the_outlier_is_more_than_two_sigma_above_the_mean(): void
    {
        $service = new ReadingRouteAnalyticsService;
        $rows = [];
        $id = 0;

        // one district, six CCAs on their own route, each 1000 visits: skip rates 10, 10, 10, 10, 10, 40 (percent)
        foreach ([['70001', 100], ['70002', 100], ['70003', 100], ['70004', 100], ['70005', 100], ['70006', 400]] as [$cca, $skipped]) {
            $rows[] = ['id' => ++$id, 'b' => 1, 'route_id' => $id, 'district_id' => 5, 'cca' => $cca, 'name' => 'CCA '.$cca, 'employee_id' => null, 'label' => 'D '.$id, 'strength' => 1000, 'read' => 1000 - $skipped, 'skipped' => $skipped, 'visited' => 1000, 'owner' => true, 'flagged' => false, 'cca_only' => false];
        }

        // a second district with only four eligible CCAs: too few to judge anyone, however different
        foreach ([['71001', 100], ['71002', 100], ['71003', 100], ['71004', 800]] as [$cca, $skipped]) {
            $rows[] = ['id' => ++$id, 'b' => 1, 'route_id' => $id, 'district_id' => 6, 'cca' => $cca, 'name' => 'CCA '.$cca, 'employee_id' => null, 'label' => 'E '.$id, 'strength' => 1000, 'read' => 1000 - $skipped, 'skipped' => $skipped, 'visited' => 1000, 'owner' => true, 'flagged' => false, 'cca_only' => false];
        }

        $a = $service->analyse(collect($rows), [1 => ['from' => '2026-10-01', 'to' => '2026-10-31']], [5 => 'Alpha', 6 => 'Beta'], 100);
        $o = $a['outliers'];
        $alpha = collect($o['districts'])->firstWhere('name', 'Alpha');
        $beta = collect($o['districts'])->firstWhere('name', 'Beta');

        // Alpha: mean (10 * 5 + 40) / 6 = 15; population stdev sqrt((5 * 25 + 625) / 6) = sqrt(125) = 11.18; 40 is (40 - 15) / 11.18 = 2.24 sigma above
        $this->assertTrue($alpha['enough']);
        $this->assertEquals(15.0, $alpha['mean']);
        $this->assertEquals(11.18, $alpha['stdev']);
        $this->assertEquals(37.36, $alpha['threshold']);   // 15 + 2 * 11.18 (40 is above it, so it is flagged)
        $this->assertSame(['70006'], array_column($o['flagged'], 'staff_id'));
        $this->assertEquals(2.24, $o['flagged'][0]['z']);
        $this->assertSame(1, $alpha['flagged']);

        // Beta: four peers is too few, so nobody is called an outlier even at 80%
        $this->assertFalse($beta['enough']);
        $this->assertNull($beta['threshold']);
        $this->assertSame(0, $beta['flagged']);
    }

    public function test_r7_on_the_fixture_says_there_are_too_few_peers(): void
    {
        $this->fixture();
        $o = $this->report()['analysis']['outliers'];

        $this->assertSame([], $o['flagged']);
        $this->assertSame(3, $o['eligible']);            // 80001, 80002, 80003 have 100+ visits
        $this->assertFalse(collect($o['districts'])->firstWhere('name', 'Sowutuom')['enough']);
    }

    // ---------------------------------------------------------------- R8 strength against metered accounts (pure)

    public function test_r8_strength_against_metered_accounts(): void
    {
        $service = new ReadingRouteAnalyticsService;
        $route = fn (int $id, string $label, int $strength) => ['placed' => true, 'route_id' => $id, 'label' => $label, 'district' => 'Sowutuom', 'district_id' => 1, 'strength' => $strength];
        $routes = ['a' => $route(1, 'S1', 100), 'b' => $route(2, 'S2', 60), 'c' => $route(3, 'S3', 120), 'd' => $route(4, 'O1', 200), 'e' => $route(5, 'O2', 80)];

        // metered accounts: S1 98, S2 50, S3 100 (but its code is printed under two districts), O1 205, O2 none
        $r = $service->strengthAgainstCustomers($routes, [1 => 98, 2 => 50, 3 => 100, 4 => 205], [3 => true], 15.0);

        $this->assertSame(3, $r['compared']);            // S1, S2, O1: S3 is ambiguous, O2 has no match
        $this->assertSame(1, $r['no_match']);
        $this->assertSame(['S3'], array_column($r['ambiguous'], 'label'));
        // S1 (100 - 98) / 98 = +2.0%; S2 (60 - 50) / 50 = +20.0%; O1 (200 - 205) / 205 = -2.4%
        $this->assertEquals(2.4, $r['median_abs_pct']);  // median of 2.0, 20.0, 2.4
        $this->assertEquals(2.0, $r['median_signed_pct']);
        $this->assertSame(['S2'], array_column($r['beyond'], 'label'));
        $this->assertSame(10, $r['beyond'][0]['difference']);
        $this->assertEquals(20.0, $r['beyond'][0]['relative_pct']);
    }

    /** One imported customer list per district, with route-level rollups: route code => [W accounts, F accounts, N accounts (no meter)]. */
    protected function seedCustomerList(array $perRoute, \App\Models\District $district): void
    {
        $batch = \Illuminate\Support\Facades\DB::table('commercial_customer_batches')->insertGetId([
            'region_id' => $district->region_id, 'district_id' => $district->id, 'period_type' => 'monthly', 'period_key' => '2026-10', 'as_of_date' => '2026-10-31',
            'file_hash' => hash('sha256', uniqid('list', true)), 'status' => 'imported', 'phase' => 'done', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $meter = \Illuminate\Support\Facades\DB::table('commercial_meter_statuses')->pluck('id', 'code');

        foreach ($perRoute as $code => $counts) {
            $routeId = CommercialRoute::query()->where('district_id', $district->id)->where('code', $code)->value('id');

            foreach (['W' => $counts[0], 'F' => $counts[1], 'N' => $counts[2]] as $status => $accounts) {
                if ($accounts > 0) {
                    \Illuminate\Support\Facades\DB::table('commercial_customer_rollups')->insert([
                        'batch_id' => $batch, 'region_id' => $district->region_id, 'district_id' => $district->id, 'route_id' => $routeId, 'category_id' => 1, 'status_id' => 1,
                        'meter_status_id' => $meter[$status], 'customer_count' => $accounts,
                    ]);
                }
            }
        }
    }

    public function test_r8_reads_the_newest_customer_list_by_canonical_route_and_sets_ambiguous_codes_apart(): void
    {
        config(['gwl.commercial_customer_list_enabled' => true, 'gwl.commercial_strength_mismatch_pct' => 15]);
        $this->fixture();
        // metered accounts = working + faulty meters (a no-meter account is not counted): S1 60 + 38 = 98, S2 50, S3 100, O1 205, O2 none on the list
        $this->seedCustomerList(['S1' => [60, 38, 20], 'S2' => [50, 0, 0], 'S3' => [100, 0, 0]], $this->sowutuom);
        $this->seedCustomerList(['O1' => [200, 5, 0]], $this->odorkor);
        // S3's code is also printed under another district of the region: an alias points at the canonical S3, so the join cannot tell them apart
        $s3 = CommercialRoute::query()->where('code', 'S3')->firstOrFail();
        CommercialRoute::query()->create(['district_id' => $this->odorkor->id, 'region_id' => $this->accraWest->id, 'name' => 'S3', 'code' => 'S3', 'source' => CommercialRoute::SOURCE_CUSTOMER_LIST, 'active' => true, 'canonical_route_id' => $s3->id]);

        $this->actingAs($this->officer());
        $s = app(CommercialReportData::class)->routeReading()['strength'];

        $this->assertTrue($s['available']);
        $this->assertSame(2, $s['customer_batches']);
        $this->assertSame('2026-10-31', $s['as_of']);
        $this->assertSame(3, $s['compared']);                                  // S1, S2, O1
        $this->assertSame(1, $s['no_match']);                                  // O2
        $this->assertSame(['SOWUTUOM S3'], array_column($s['ambiguous'], 'label'));
        // S1 (100 - 98) / 98 = +2.0%; S2 (60 - 50) / 50 = +20.0%; O1 (200 - 205) / 205 = -2.4%
        $this->assertEquals(2.4, $s['median_abs_pct']);
        $this->assertEquals(2.0, $s['median_signed_pct']);
        $this->assertSame(['SOWUTUOM S2'], array_column($s['beyond'], 'label'));
        $this->assertSame([60, 50, 10], [$s['beyond'][0]['strength'], $s['beyond'][0]['metered'], $s['beyond'][0]['difference']]);

        // the threshold is a setting: at 1% S1 (2.0) and O1 (2.4) are beyond too
        config(['gwl.commercial_strength_mismatch_pct' => 1]);
        $this->assertCount(3, app(CommercialReportData::class)->routeReading()['strength']['beyond']);
    }

    public function test_r8_needs_the_customer_list_the_flag_and_the_permission(): void
    {
        $this->fixture();
        $this->seedCustomerList(['S1' => [98, 0, 0]], $this->sowutuom);

        config(['gwl.commercial_customer_list_enabled' => false]);
        $this->actingAs($this->officer());
        $this->assertFalse(app(CommercialReportData::class)->routeReading()['strength']['available']);

        config(['gwl.commercial_customer_list_enabled' => true]);
        $viewer = $this->userWithRoles('900980', []);
        $role = \App\Models\Role::query()->create(['name' => 'reading_only', 'display_name' => 'Reading only', 'is_system' => false]);
        $role->permissions()->attach(\App\Models\Permission::query()->whereIn('name', ['commercial.view_reading', 'commercial.view_all_districts'])->pluck('id'));
        \App\Models\ModuleAccess::query()->create(['role_id' => $role->id, 'module' => \App\Models\Permission::MODULE_COMMERCIAL, 'can_access' => true]);
        $viewer->roles()->attach($role);
        $this->actingAs($viewer->fresh());
        $this->assertStringContainsString('permission', app(CommercialReportData::class)->routeReading()['strength']['reason']);

        // no customer list at all for the region
        \Illuminate\Support\Facades\DB::table('commercial_customer_batches')->delete();
        $this->actingAs($this->officer());
        $this->assertStringContainsString('No customer list', app(CommercialReportData::class)->routeReading()['strength']['reason']);
    }
    // ---------------------------------------------------------------- uploads added up (the period model)

    public function test_two_uploads_that_do_not_overlap_add_flows_and_take_the_latest_strength_and_coverage_is_not_capped(): void
    {
        // first half: S1 strength 100, visited 60; second half: the route has grown to 110 and is visited 70 more
        $this->seedRouteReading([['80001', 'AMA TEST', 'S1', $this->sowutuom, 100, 50, 10, 60]], ['from' => '2026-10-01', 'to' => '2026-10-15']);
        $this->seedRouteReading([['80001', 'AMA TEST', 'S1', $this->sowutuom, 110, 60, 10, 70]], ['from' => '2026-10-16', 'to' => '2026-10-31']);

        $r = $this->report('2026-10-01', '2026-10-31');
        $s1 = $this->route($r['analysis'], 'SOWUTUOM S1');

        $this->assertSame(110, $s1['strength']);         // the latest period's, counted once
        $this->assertSame(110, $s1['read']);             // 50 + 60
        $this->assertSame(20, $s1['skipped']);           // 10 + 10
        $this->assertSame(130, $s1['visited']);          // 60 + 70
        $this->assertSame(-20, $s1['unvisited']);        // 110 - 130: visited more often than the strength
        $this->assertEquals(118.18, $s1['coverage']);    // 130 / 110, NOT capped at 100%
        $this->assertCount(2, $r['range']['batch_ids']);
        $this->assertSame(31, $r['range']['covered_days']);
        $this->assertContains(true, array_map(fn (string $n) => str_contains($n, 'not capped at 100%'), $r['notes']));
        $this->assertSame([], $r['analysis']['backlog']['routes']);   // only a positive backlog is listed
        $this->assertSame(0, $r['analysis']['backlog']['unvisited_total']);
    }

    public function test_the_trend_has_one_point_per_upload_oldest_first_labelled_with_dates(): void
    {
        $this->seedRouteReading([['80001', 'AMA TEST', 'S1', $this->sowutuom, 100, 50, 10, 60]], ['from' => '2026-10-01', 'to' => '2026-10-15']);
        $this->seedRouteReading([['80001', 'AMA TEST', 'S1', $this->sowutuom, 110, 60, 10, 70]], ['from' => '2026-10-16', 'to' => '2026-10-31']);

        $trend = $this->report()['trend'];

        $this->assertCount(2, $trend);
        $this->assertSame(['1 Oct to 15 Oct 2026', '16 Oct to 31 Oct 2026'], array_column($trend, 'label'));
        $this->assertSame([60, 70], array_column($trend, 'visited'));
        $this->assertSame([100, 110], array_column($trend, 'strength'));
        $this->assertEquals([60.0, 63.64], array_column($trend, 'coverage'));    // 60 / 100 and 70 / 110
    }
}
