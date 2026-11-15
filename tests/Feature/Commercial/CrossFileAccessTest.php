<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\CrossAnalysis;
use App\Livewire\Commercial\Route360;
use App\Models\CommercialRoute;
use App\Services\Commercial\Cross\CrossFileReport;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsCrossSet;

/**
 * Phase 8e: WHO sees what across the files. Each block needs the permission of its own data (reading, billing, customer list; a CCA's name needs
 * the CCA performance permission) and a viewer who lacks one sees the other blocks and a line saying what is hidden; a district user sees only their
 * district, no region-wide row, and cannot open another district's route 360 (404). Invented data (the cross set).
 */
class CrossFileAccessTest extends CustomerTestCase
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

    /** @return array<string, mixed> */
    protected function build(string $key, array $q = []): array
    {
        return app(CrossFileReport::class)->build($key, $q);
    }

    protected function only(array $permissions, string $staffId, bool $districtLimited = false): \App\Models\User
    {
        return $this->crossViewer($permissions, $staffId, $districtLimited);
    }

    // ---------------------------------------------------------------- the module

    public function test_the_index_and_every_analysis_need_one_of_the_three_data_permissions(): void
    {
        $none = $this->only(['commercial.view_dashboard'], '900071');
        $this->actingAs($none)->get(route('commercial.cross'))->assertForbidden();
        $this->actingAs($none)->get(route('commercial.cross.show', 'x1'))->assertForbidden();

        foreach (['commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics'] as $i => $permission) {
            $viewer = $this->only([$permission], '90008'.$i);
            $this->actingAs($viewer)->get(route('commercial.cross'))->assertOk()->assertSee('Across the files');
            $this->actingAs($viewer)->get(route('commercial.cross.show', 'x7'))->assertOk();
        }

        $this->actingAs($this->crossAll())->get(route('commercial.cross.show', 'x2'))->assertNotFound();   // route 360 is its own page, not an analysis slug
        $this->actingAs($this->crossAll())->get('/commercial/cross/x99')->assertNotFound();
    }

    public function test_the_sidebar_shows_across_the_files_only_to_those_who_may_open_it(): void
    {
        $this->actingAs($this->only(['commercial.view_billing'], '900072'))->get(route('commercial.billing.details'))->assertSee('Across the files');
        $this->actingAs($this->only(['commercial.view_dashboard'], '900073'))->get(route('commercial.home'))->assertDontSee('Across the files');
    }

    // ---------------------------------------------------------------- the permission-by-block rule

    public function test_a_viewer_with_only_billing_sees_the_billing_blocks_and_a_line_about_what_is_hidden(): void
    {
        $this->actingAs($this->only(['commercial.view_billing'], '900074'));
        $r = $this->build('x3');

        $this->assertTrue($r['parts']['estimates']['visible'] && ! $r['parts']['estimates']['empty'], 'the billing block is shown');
        $this->assertFalse($r['parts']['skip']['visible'], 'the reading block is hidden');
        $this->assertFalse($r['parts']['correlation']['visible'], 'the correlation needs both');
        $this->assertNull($r['parts']['skip']['data'], 'a hidden block is not even computed');
        $this->assertStringContainsString('the reading permission (commercial.view_reading)', $r['parts']['skip']['reason']);
        $this->assertTrue($r['available']);
        $this->assertSame(['Skip rate by route (reading alone)', 'The correlation, route by route'], collect($r['hidden'])->pluck('title')->sort()->values()->all());
        $this->assertFalse($r['windows']['reading']['allowed']);
        $this->assertArrayNotHasKey('routes', (array) ($r['parts']['skip']['data'] ?? []));

        Livewire::test(CrossAnalysis::class, ['analysis' => 'x3'])->assertSee('Hidden from you')->assertSee('Bills estimated on a working meter by route');
    }

    public function test_a_viewer_without_the_customer_list_sees_reading_and_billing_columns_and_not_the_list_ones(): void
    {
        $this->actingAs($this->only(['commercial.view_reading', 'commercial.view_billing', 'commercial.view_reader_performance'], '900075'));

        $r = $this->build('x7');
        $row = collect($r['parts']['scorecard']['data']['rows'])->firstWhere('district', 'Sowutuom');
        $this->assertNotNull($row['reading']);
        $this->assertNotNull($row['billing']);
        $this->assertNull($row['customers'], 'the customer-list figures are not given');
        $this->assertStringContainsString('Hidden columns: the customer-list figures', $r['parts']['scorecard']['reason']);

        $x4 = $this->build('x4');
        $this->assertFalse($x4['parts']['reconciliation']['visible'], 'the three counts need the list');
        $this->assertTrue($x4['parts']['strength']['visible'] && ! $x4['parts']['strength']['empty'], 'the verified strength alone is shown');

        $x5 = $this->build('x5');
        $this->assertFalse($x5['parts']['link']['visible']);
        $this->assertStringContainsString('both the billing and the customer-list permissions', $x5['parts']['link']['reason']);
        $this->assertFalse($x5['available'], 'nothing of X5 is left to show');
    }

    public function test_a_viewer_with_only_the_customer_list_sees_its_column_of_the_scorecard(): void
    {
        $this->actingAs($this->only(['commercial.view_customer_analytics'], '900076'));
        $row = collect($this->build('x7')['parts']['scorecard']['data']['rows'])->firstWhere('district', 'Sowutuom');

        $this->assertNull($row['reading']);
        $this->assertNull($row['billing']);
        $this->assertSame(48, $row['customers']['base']);
    }

    public function test_a_cca_is_named_only_to_a_viewer_with_the_cca_performance_permission(): void
    {
        $this->actingAs($this->only(['commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics'], '900077'));

        $x1 = $this->build('x1');
        foreach (['combined', 'reading', 'billing'] as $part) {
            $this->assertFalse($x1['parts'][$part]['visible'], $part);
            $this->assertStringContainsString('the CCA performance permission (commercial.view_reader_performance)', $x1['parts'][$part]['reason']);
        }

        $this->assertFalse($this->build('x8')['parts']['portfolio']['visible']);
        $this->assertFalse($this->build('x9')['parts']['ccas']['visible']);

        // the per-route tables carry no CCA: the owner is not given, and the status exceptions fall into one row for "no CCA"
        $x6 = $this->build('x6');
        $this->assertSame([''], array_column($x6['parts']['exceptions']['data']['ccas'], 'staff_id'));
        $this->assertNull($this->build('x9')['parts']['routes']['data']['routes'][0]['owner_cca']);
        $this->assertStringNotContainsString('AMA TEST', json_encode($x6));
        $this->assertStringNotContainsString('AMA TEST', json_encode($this->build('x9')));
        $this->assertStringNotContainsString('AMA TEST', json_encode($this->build('x4')));
        Livewire::test(CrossAnalysis::class, ['analysis' => 'x6'])->assertDontSee('AMA TEST')->assertDontSee('80001');

        // a named viewer does see them
        $this->actingAs($this->crossAll());
        $this->assertStringContainsString('AMA TEST', json_encode($this->build('x6')));
    }

    // ---------------------------------------------------------------- a district user

    public function test_a_district_user_sees_only_their_district_everywhere_and_no_region_wide_row(): void
    {
        $this->actingAs($this->crossAll('900078', true, $this->sowutuom));

        // the scorecard: their district only
        $x7 = $this->build('x7');
        $this->assertSame(['Sowutuom'], collect($x7['parts']['scorecard']['data']['rows'])->pluck('district')->all());
        $this->assertTrue($x7['district_limited']);
        $this->assertSame([$this->sowutuom->id => 'Sowutuom'], $x7['districts'], 'the district choices are theirs only');

        // billing against the list: the transitions and the accounts are Sowutuom's; no region-wide totals
        $x5 = $this->build('x5')['parts']['link']['data'];
        $this->assertSame(['Sowutuom'], array_column($x5['districts'], 'district'));
        $this->assertSame(['Sowutuom'], array_column($x5['accounts'], 'district'));
        $this->assertSame([32], array_column($x5['districts'], 'compared'));
        $this->assertNull($x5['region_totals'], 'no region-wide count');
        $this->assertSame(['100000000003', '100000000049'], collect($x5['changed_accounts'])->pluck('account')->sort()->values()->all(), 'account 66 is Odorkor\'s');

        // the routes: reading, billing and X4 name only their district's routes
        $x4 = $this->build('x4')['parts']['reconciliation']['data'];
        $this->assertEqualsCanonicalizing(['SOWUTUOM 4101', 'SOWUTUOM 4102', 'SOWUTUOM 4103', 'SOWUTUOM 4109'], array_column($x4['rows'], 'label'));
        $x8 = $this->build('x8')['parts']['portfolio']['data'];
        $this->assertSame(271.6, $x8['owing_total'], 'the share is of their own district\'s owing balance');
        $this->assertStringNotContainsString('ODORKOR', json_encode($this->build('x8')['parts']));
        $this->assertStringNotContainsString('ODORKOR', json_encode($this->build('x6')['parts']));
        $this->assertStringNotContainsString('ODORKOR', json_encode($this->build('x1')['parts']));

        // the district matrix: only the cells that touch their district (as the list's or the billing's), and no grand total beyond them
        $m = $this->build('x10')['parts']['matrix']['data'];
        $this->assertSame(['Sowutuom'], array_column($m['rows'], 'list_district'), 'the Odorkor row is not theirs');
        $this->assertSame([32, 10], collect($m['rows'][0]['cells'])->sortByDesc('customers')->pluck('customers')->values()->all());

        // the flags of the executive summary count only what they may see: the one active unbilled account of Sowutuom, never Odorkor's routes
        $flags = collect($this->build('x11')['parts']['flags']['data']['flags'])->pluck('count', 'key');
        $this->assertSame(1, $flags['active_unbilled']);
        $this->assertSame(0, $flags['routes_no_cca'], 'the two routes no CCA reads are in the other district');
    }

    public function test_a_district_user_cannot_open_another_districts_route_and_the_alias_does_not_get_them_in(): void
    {
        $o2 = $this->crossRoute('O', '4202');
        $alias = CommercialRoute::query()->where('district_id', $this->sowutuom->id)->where('code', '4202')->firstOrFail();
        $this->assertSame($o2, (int) $alias->canonical_route_id, 'the list\'s Sowutuom 4202 is an alias of the billing\'s Odorkor 4202');

        $s = $this->crossAll('900079', true, $this->sowutuom);
        $this->actingAs($s);
        $report = app(CrossFileReport::class);

        $this->assertNotNull($report->route($this->crossRoute('S', '4101')));
        $this->assertNull($report->route($o2), 'another district\'s route');
        $this->assertNull($report->route($alias->id), 'an alias of another district\'s route is not a way in');
        $this->assertNull($report->route(999999), 'a route that does not exist looks the same');

        $this->get(route('commercial.cross.route', $this->crossRoute('S', '4101')))->assertOk();
        $this->get(route('commercial.cross.route', $o2))->assertNotFound();
        $this->get(route('commercial.cross.route', $alias->id))->assertNotFound();
        $this->get(route('commercial.export', ['report' => 'cross-route', 'format' => 'excel', 'route' => $o2]))->assertNotFound();
        Livewire::test(Route360::class, ['routeId' => $o2])->assertNotFound();
    }

    public function test_a_viewer_of_another_region_reaches_nothing_of_this_one(): void
    {
        $stranger = $this->userWith('900066', ['commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics', 'commercial.view_reader_performance'], $this->ashanti, $this->kumasi);
        $this->actingAs($stranger);

        $this->assertFalse($this->build('x7')['available']);
        $this->assertNull(app(CrossFileReport::class)->route($this->crossRoute('S', '4101')));
        $this->get(route('commercial.cross.route', $this->crossRoute('S', '4101')))->assertNotFound();
    }

    public function test_super_admin_opening_an_alias_is_shown_the_route_it_stands_for(): void
    {
        $this->actingAs($this->superAdmin());
        $alias = CommercialRoute::query()->where('district_id', $this->sowutuom->id)->where('code', '4202')->firstOrFail();

        $r = app(CrossFileReport::class)->route($alias->id);

        $this->assertSame($this->crossRoute('O', '4202'), $r['route']['id']);
        $this->assertTrue($r['route']['opened_as_alias']);
        $this->assertSame('Sowutuom', $r['route']['aliases'][0]['district']);
    }
}
