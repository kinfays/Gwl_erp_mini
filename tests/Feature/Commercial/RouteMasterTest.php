<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Routes;
use App\Livewire\Commercial\Settings;
use App\Models\AuditLog;
use App\Models\CommercialRoute;
use App\Services\Commercial\CommercialSettings;
use App\Services\Commercial\LocationMatcher;
use App\Services\Commercial\RouteResolver;
use App\Support\ErpNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8a, the route master (design 8.30): reading a label in either form, creating unseen routes pending, aliases and the canonical
 * route under each authority setting, "0000", the manager's pin and alias, the X10 conflicts screen and its permission. Invented
 * codes only.
 */
class RouteMasterTest extends Customers\CustomerTestCase
{
    protected RouteResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(RouteResolver::class);
    }

    // ---------------------------------------------------------------- reading a label

    public function test_both_label_forms_are_read(): void
    {
        $this->assertSame(['code' => '4601', 'district' => 'SOWUTUOM', 'region' => null], $this->resolver->parse('SOWUTUOM 4601'), 'billing / reading: DISTRICT code');
        $this->assertSame(['code' => '4601', 'district' => 'SOWUTUOM', 'region' => 'ACCRA WEST'], $this->resolver->parse('4601, SOWUTUOM, ACCRA WEST'), 'customer list: code, DISTRICT, REGION');
        $this->assertSame(['code' => '0000', 'district' => 'SOWUTUOM', 'region' => null], $this->resolver->parse('SOWUTUOM 0000'), '"0000" is a code, kept as printed');
        $this->assertSame(['code' => '0000', 'district' => null, 'region' => null], $this->resolver->parse('0000'));
        $this->assertSame(['code' => '12, 3', 'district' => 'ODORKOR', 'region' => 'ACCRA WEST'], $this->resolver->parse('12, 3, ODORKOR, ACCRA WEST'), 'a route that itself contains a comma');
        $this->assertSame(['code' => '4601', 'district' => 'GREATER ACCRA SOUTH', 'region' => null], $this->resolver->parse('  GREATER   ACCRA SOUTH 4601 '), 'spaces are tidied, the code is the last token');
        $this->assertSame(['code' => null, 'district' => null, 'region' => null], $this->resolver->parse('  '));
    }

    // ---------------------------------------------------------------- resolving

    public function test_an_unseen_label_creates_a_pending_route_once_and_returns_the_same_route_afterwards(): void
    {
        $route = $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);

        $this->assertSame([$this->sowutuom->id, $this->accraWest->id, '4601', 'billing', true, null], [$route->district_id, $route->region_id, $route->code, $route->source, $route->is_pending, $route->canonical_route_id]);

        $again = $this->resolver->resolve('4601, SOWUTUOM, ACCRA WEST', null, CommercialRoute::SOURCE_CUSTOMER_LIST);
        $this->assertSame($route->id, $again->id, 'the customer list label finds the same route (the region came from the label)');
        $this->assertSame(1, CommercialRoute::query()->count());

        // a district nobody can place creates nothing: it is reported, not guessed
        $this->assertNull($this->resolver->resolve('NOWHERE 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING));
        $this->assertSame(1, CommercialRoute::query()->count());
    }

    public function test_a_district_alias_is_used_to_find_the_district(): void
    {
        app(LocationMatcher::class)->saveDistrictAlias('SOWUT.', $this->sowutuom);

        $route = $this->resolver->resolve('SOWUT. 4602', $this->accraWest->id, CommercialRoute::SOURCE_READING);

        $this->assertSame($this->sowutuom->id, $route->district_id);
        $this->assertSame('4602', $route->code);
    }

    public function test_the_unassigned_route_keeps_its_zeros_and_is_counted(): void
    {
        $route = $this->resolver->resolve('SOWUTUOM 0000', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('ODORKOR 0000', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);

        $this->assertSame('0000', $route->code);
        $this->assertSame('0000', DB::table('commercial_routes')->where('id', $route->id)->value('name'));
        $this->assertSame(2, $this->resolver->conflicts([$this->accraWest->id])['unassigned']);
        $this->assertSame(0, $this->resolver->conflicts([$this->ashanti->id])['unassigned'], 'another region\'s');
    }

    public function test_the_canonical_route_follows_the_authority_setting(): void
    {
        // billing prints 4601 under Sowutuom; the customer list prints the same code under Odorkor
        $billing = $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $list = $this->resolver->resolve('4601, ODORKOR, ACCRA WEST', null, CommercialRoute::SOURCE_CUSTOMER_LIST);

        // default authority: billing / reading. The customer list's label resolves to the billing route.
        $this->assertSame('billing', $this->resolver->authority());
        $this->assertSame($billing->id, $list->id, 'resolve() returns the CANONICAL route');
        $odorkorRoute = CommercialRoute::query()->where('district_id', $this->odorkor->id)->firstOrFail();
        $this->assertSame($billing->id, $odorkorRoute->canonical_route_id, 'the customer list\'s route is an alias');
        $this->assertNull($billing->fresh()->canonical_route_id);

        // the other authority: the customer list owns the district
        config(['gwl.commercial_route_district_authority' => 'customer_list']);
        $this->assertSame(1, $this->resolver->rebuild());

        $this->assertNull($odorkorRoute->fresh()->canonical_route_id);
        $this->assertSame($odorkorRoute->id, $billing->fresh()->canonical_route_id);
        $this->assertSame($odorkorRoute->id, $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING)->id, 'now billing\'s label resolves to the customer list\'s route');

        // a system that shows the SAME (district, code) pair as the authority makes the route its own
        config(['gwl.commercial_route_district_authority' => 'billing']);
        $this->resolver->resolve('ODORKOR 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->assertSame('billing', $odorkorRoute->fresh()->source);
    }

    public function test_the_same_code_in_another_region_is_not_a_conflict(): void
    {
        $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $kumasi = $this->resolver->resolve('KUMASI 4601', $this->ashanti->id, CommercialRoute::SOURCE_BILLING);

        $this->assertNull($kumasi->canonical_route_id);
        $this->assertSame([], $this->resolver->conflicts()['conflicts']);
    }

    // ---------------------------------------------------------------- what a manager does

    public function test_a_manager_pins_a_route_and_a_pin_is_not_overridden_by_the_setting(): void
    {
        $billing = $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('4601, ODORKOR, ACCRA WEST', null, CommercialRoute::SOURCE_CUSTOMER_LIST);
        $odorkorRoute = CommercialRoute::query()->where('district_id', $this->odorkor->id)->firstOrFail();

        $manager = $this->userWithRoles('910950', ['commercial_manager'], $this->accraWest, $this->sowutuom);
        $this->actingAs($manager);

        // pin the Odorkor route: it is the route now, whatever the setting says
        $this->resolver->pin($odorkorRoute->id, $this->odorkor->id);
        $this->assertNull($odorkorRoute->fresh()->canonical_route_id);
        $this->assertTrue($odorkorRoute->fresh()->pinned);
        $this->assertSame('customer_list', $odorkorRoute->fresh()->source, 'the route keeps the system it was first seen in');
        $this->assertSame($odorkorRoute->id, $billing->fresh()->canonical_route_id);

        $this->resolver->rebuild();
        $this->assertNull($odorkorRoute->fresh()->canonical_route_id, 'authority billing, but a manager pinned the other one');

        // a pin to a district of another region is refused
        $this->expectException(ValidationException::class);
        $this->resolver->pin($odorkorRoute->id, $this->kumasi->id);
    }

    public function test_marking_an_alias_needs_the_same_code_and_region_and_a_release_hands_the_decision_back(): void
    {
        $a = $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('4601, ODORKOR, ACCRA WEST', null, CommercialRoute::SOURCE_CUSTOMER_LIST);
        $b = CommercialRoute::query()->where('district_id', $this->odorkor->id)->firstOrFail();
        $other = $this->resolver->resolve('SOWUTUOM 9999', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);

        $this->resolver->pin($b->id, $this->odorkor->id);
        $this->resolver->markAlias($a->id, $b->id);
        $this->assertSame($b->id, $a->fresh()->canonical_route_id);
        $this->assertTrue($a->fresh()->pinned);

        $this->resolver->rebuild();
        $this->assertSame($b->id, $a->fresh()->canonical_route_id, 'a manager\'s alias stays when the codes are settled again');

        try {
            $this->resolver->markAlias($a->id, $other->id);
            $this->fail('a different code cannot be an alias');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('canonical', $e->errors());
        }

        // released: the authority (billing) decides again, and billing printed route A
        $this->resolver->release($b->id);
        $this->resolver->release($a->id);
        $this->assertNull($a->fresh()->canonical_route_id);
        $this->assertSame($a->id, $b->fresh()->canonical_route_id);
        $this->assertFalse($a->fresh()->pinned);
    }

    // ---------------------------------------------------------------- the imports feed it

    public function test_a_billing_import_and_a_customer_list_register_their_routes_with_region_code_and_source(): void
    {
        $this->actingAs($this->officer());
        $this->importFile($this->billingFile(['districts' => ReportWorkbooks::defaultDistricts()]));

        // the billing routes in a district the file matched: SOWUTUOM 4601 / 4602 (the codes are the last token)
        $billing = CommercialRoute::query()->where('source', 'billing')->orderBy('code')->get();
        $this->assertSame(['4601', '4602', '4701', '4702'], $billing->pluck('code')->all());
        $this->assertTrue($billing->every(fn ($r) => $r->region_id === $this->accraWest->id && $r->is_pending === true));

        // the customer list files its own routes with the same fields
        $this->loadCustomers($this->simpleSpec(1, 3, [], '4601'));
        $this->assertSame(1, CommercialRoute::query()->where('district_id', $this->sowutuom->id)->where('code', '4601')->count(), 'the same (district, code) pair is one route, whoever prints it');

        $this->loadCustomers($this->simpleSpec(11, 13, [], '4999'), '2026-10-06');
        $list = CommercialRoute::query()->where('source', 'customer_list')->firstOrFail();
        $this->assertSame(['4999', $this->accraWest->id, $this->sowutuom->id], [$list->code, $list->region_id, $list->district_id]);
    }

    // ---------------------------------------------------------------- the screen

    public function test_only_a_holder_of_manage_routes_reaches_the_screen_and_the_menu_entry_follows(): void
    {
        $manager = $this->userWithRoles('910951', ['commercial_manager'], $this->accraWest, $this->sowutuom);

        $this->actingAs($manager)->get(route('commercial.routes'))->assertOk()->assertSee('Route master');
        $this->actingAs($this->officer())->get(route('commercial.routes'))->assertForbidden();
        $this->actingAs($this->userWithRoles('910952', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom))->get(route('commercial.routes'))->assertForbidden();
        Livewire::actingAs($this->officer())->test(Routes::class)->assertForbidden();

        $labels = fn ($user) => collect(app(ErpNavigation::class)->build($user, 'commercial')['sidebar'])->pluck('label')->all();
        $this->assertContains('Route master', $labels($manager));
        $this->assertContains('Route master', $labels($this->superAdmin()));
        $this->assertNotContains('Route master', $labels($this->officer()));
    }

    public function test_the_screen_lists_routes_filters_the_conflicts_and_pins_with_an_audit_row(): void
    {
        $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('4601, ODORKOR, ACCRA WEST', null, CommercialRoute::SOURCE_CUSTOMER_LIST);
        $this->resolver->resolve('SOWUTUOM 5000', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('SOWUTUOM 0000', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('KUMASI 4601', $this->ashanti->id, CommercialRoute::SOURCE_BILLING);
        $manager = $this->userWithRoles('910953', ['commercial_manager'], $this->accraWest, $this->sowutuom);

        // a customer filed under the Sowutuom 4601 route is counted
        $this->loadCustomers($this->simpleSpec(1, 3, [], '4601'));

        $page = Livewire::actingAs($manager)->test(Routes::class)->assertSee('4601')->assertSee('5000')->assertDontSee('Kumasi', false);
        $page->assertSee('Alias of Sowutuom');

        $conflicts = $page->set('filter', 'conflicts');
        $this->assertSame(['4601', '4601'], $conflicts->viewData('routes')->pluck('code')->all(), 'only the code printed under two districts');

        $this->assertSame(['0000'], Livewire::actingAs($manager)->test(Routes::class)->set('filter', 'unassigned')->viewData('routes')->pluck('code')->all());
        $this->assertSame(['5000'], Livewire::actingAs($manager)->test(Routes::class)->set('search', '500')->viewData('routes')->pluck('code')->all());

        // pin the Odorkor alias to Odorkor: audited, ids and codes only
        $alias = CommercialRoute::query()->where('district_id', $this->odorkor->id)->firstOrFail();
        Livewire::actingAs($manager)->test(Routes::class)->call('startPin', $alias->id)->set('pinDistrictId', (string) $this->odorkor->id)->call('pin')->assertHasNoErrors();

        $audit = AuditLog::query()->where('action', 'commercial.route_pinned')->firstOrFail();
        $this->assertSame($alias->id, (int) $audit->target_id);
        $this->assertSame('4601', $audit->metadata['code']);
        $this->assertNull($alias->fresh()->canonical_route_id);

        // another region's route is a 404, whatever the id
        $kumasiRoute = CommercialRoute::query()->where('region_id', $this->ashanti->id)->firstOrFail();
        Livewire::actingAs($manager)->test(Routes::class)->call('startPin', $kumasiRoute->id)->assertNotFound();
        Livewire::actingAs($manager)->test(Routes::class)->call('confirm', $kumasiRoute->id)->assertNotFound();

        // a super admin sees every region
        $this->assertSame(5, Livewire::actingAs($this->superAdmin())->test(Routes::class)->viewData('routes')->total());
    }

    // ---------------------------------------------------------------- Phase 8d, step 0.2: confirm all pending

    public function test_confirm_all_pending_confirms_every_pending_route_of_the_managers_region_only_and_audits_counts(): void
    {
        $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('SOWUTUOM 4602', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('ODORKOR 4603', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $kumasi = $this->resolver->resolve('KUMASI 5001', $this->ashanti->id, CommercialRoute::SOURCE_BILLING);
        $this->assertSame(4, CommercialRoute::query()->where('is_pending', true)->count());

        $manager = $this->userWithRoles('910954', ['commercial_manager'], $this->accraWest, $this->sowutuom);

        Livewire::actingAs($manager)->test(Routes::class)->assertSee('Confirm all pending (3)')->call('confirmAllPending')->assertHasNoErrors();

        $this->assertSame(0, CommercialRoute::query()->where('region_id', $this->accraWest->id)->where('is_pending', true)->count(), 'every pending route of the manager\'s region is confirmed');
        $this->assertTrue($kumasi->fresh()->is_pending, 'another region\'s pending route is untouched');
        $this->assertSame(4, CommercialRoute::query()->count(), 'nothing is created or deleted, no route changes district');

        $audit = AuditLog::query()->where('action', 'commercial.routes_confirmed_all')->firstOrFail();
        $this->assertSame(3, $audit->metadata['confirmed']);
        $this->assertSame([$this->accraWest->id], $audit->metadata['region_ids']);
        $this->assertStringNotContainsString('4601', json_encode($audit->metadata), 'counts and region ids only: never a route code');

        // nothing pending: no button, no second audit row, and the action says so
        Livewire::actingAs($manager)->test(Routes::class)->assertDontSee('Confirm all pending')->call('confirmAllPending');
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.routes_confirmed_all')->count());
    }

    public function test_confirm_all_pending_needs_manage_routes_and_a_super_admin_can_choose_a_region(): void
    {
        $this->resolver->resolve('SOWUTUOM 4601', $this->accraWest->id, CommercialRoute::SOURCE_BILLING);
        $this->resolver->resolve('KUMASI 5001', $this->ashanti->id, CommercialRoute::SOURCE_BILLING);

        Livewire::actingAs($this->officer())->test(Routes::class)->assertForbidden();
        Livewire::actingAs($this->userWithRoles('910955', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom))->test(Routes::class)->assertForbidden();

        // the region filter narrows what "all" means: Ashanti only
        Livewire::actingAs($this->superAdmin())->test(Routes::class)->set('region', (string) $this->ashanti->id)->call('confirmAllPending');
        $this->assertSame(1, CommercialRoute::query()->where('is_pending', true)->count());
        $this->assertTrue(CommercialRoute::query()->where('region_id', $this->accraWest->id)->firstOrFail()->is_pending);

        // a manager cannot reach another region by typing its id into the filter
        $manager = $this->userWithRoles('910956', ['commercial_manager'], $this->ashanti, $this->kumasi);
        $this->resolver->resolve('KUMASI 5002', $this->ashanti->id, CommercialRoute::SOURCE_BILLING);
        Livewire::actingAs($manager)->test(Routes::class)->set('region', (string) $this->accraWest->id)->call('confirmAllPending');
        $this->assertTrue(CommercialRoute::query()->where('region_id', $this->accraWest->id)->firstOrFail()->is_pending, 'Accra West is not the manager\'s region');
        $this->assertTrue(CommercialRoute::query()->where('code', '5002')->firstOrFail()->is_pending);
    }

    // ---------------------------------------------------------------- the settings

    public function test_the_route_authority_and_the_reconciliation_limits_are_editable_settings(): void
    {
        $settings = app(CommercialSettings::class);

        $settings->save([
            'commercial_route_district_authority' => 'customer_list',
            'commercial_reconcile_max_route_failures_pct' => 5,
            'commercial_reconcile_grand_total_tolerance_pct' => 1.25,
        ], $this->superAdmin()->id);

        $this->assertSame('customer_list', config('gwl.commercial_route_district_authority'));
        $this->assertSame(5.0, config('gwl.commercial_reconcile_max_route_failures_pct'));
        $this->assertSame(1.25, config('gwl.commercial_reconcile_grand_total_tolerance_pct'));
        $this->assertSame('customer_list', $this->resolver->authority());

        // saved back to the default, they are no override at all
        $settings->save(['commercial_route_district_authority' => 'billing', 'commercial_reconcile_max_route_failures_pct' => 2.0, 'commercial_reconcile_grand_total_tolerance_pct' => 0.5], $this->superAdmin()->id);
        $this->assertFalse($settings->isOverridden('commercial_route_district_authority'));
        $this->assertFalse($settings->isOverridden('commercial_reconcile_max_route_failures_pct'));

        foreach ([['commercial_route_district_authority' => 'nonsense'], ['commercial_reconcile_max_route_failures_pct' => 101], ['commercial_reconcile_grand_total_tolerance_pct' => -1]] as $bad) {
            try {
                $settings->save($bad, $this->superAdmin()->id);
                $this->fail('refused: '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        // on the Settings screen, by someone who may edit them
        Livewire::actingAs($this->superAdmin())->test(Settings::class)
            ->assertSee('District of a route is decided by')->assertSee('Staff IDs never imported')->assertSee('Block a file when more routes than this fail their totals')
            ->set('values.commercial_route_district_authority', 'customer_list')->call('save');
        $this->assertSame('customer_list', config('gwl.commercial_route_district_authority'));
    }
}
