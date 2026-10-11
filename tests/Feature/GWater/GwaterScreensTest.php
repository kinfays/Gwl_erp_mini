<?php

namespace Tests\Feature\GWater;

use App\Livewire\GWater\DailySales;
use App\Livewire\GWater\OpeningBalances;
use App\Livewire\GWater\Overview;
use App\Livewire\GWater\Prices;
use App\Livewire\GWater\Purchases;
use App\Livewire\GWater\Reimbursements;
use App\Livewire\GWater\StockLedger;
use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwPrice;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwStockMovement;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Uac\RoleGrantPolicy;
use App\Support\ErpNavigation;
use Livewire\Livewire;

class GwaterScreensTest extends GwaterTestCase
{
    // ---------------------------------------------------------------- the role, access and the flag

    public function test_the_g_water_role_is_a_normal_role_any_location_can_hold_with_every_permission(): void
    {
        $role = Role::query()->where('name', 'g_water')->firstOrFail();

        $this->assertSame(['G-Water', true, false, false], [$role->display_name, (bool) $role->ict_assignable, (bool) $role->is_protected, (bool) $role->is_system]);
        $this->assertEqualsCanonicalizing(
            ['gwater.view', 'gwater.record', 'gwater.manage_prices', 'gwater.view_reports', 'gwater.void', 'gwater.reimburse', 'gwater.export'],
            $role->permissions()->pluck('name')->all()
        );
        $this->assertTrue(ModuleAccess::query()->where('role_id', $role->id)->where('module', Permission::MODULE_GWATER)->where('can_access', true)->exists());

        // No location restriction on the role itself: Head Office, regional and district staff can all hold it.
        $policy = app(RoleGrantPolicy::class);
        foreach ([$this->headOffice, $this->sowutuom, $this->kumasi] as $district) {
            $this->assertNull($policy->locationDenial($role, $this->gw('S'.$district->id, $district)->employee), $district->district_name);
        }

        // Nobody else holds a gwater permission by default.
        $employee = Role::query()->where('name', 'employee')->firstOrFail();
        $this->assertSame(0, $employee->permissions()->where('module', Permission::MODULE_GWATER)->count());
    }

    public function test_the_routes_need_the_module_and_the_permission(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $headOffice = $this->gw('G3', $this->headOffice);
        $plain = $this->person('E1', $this->sowutuom);

        foreach (['gwater.home', 'gwater.sales', 'gwater.purchases', 'gwater.stock', 'gwater.prices', 'gwater.opening', 'gwater.reimbursements'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
            $this->actingAs($headOffice)->get(route($route))->assertOk();
            // Without the module the middleware turns the user away (a redirect or a 403), never a page.
            $this->assertContains($this->actingAs($plain)->get(route($route))->getStatusCode(), [302, 403], $route);
        }
    }

    public function test_the_module_is_hidden_when_its_flag_is_off(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom);
        $navigation = app(ErpNavigation::class);
        $slugs = fn ($who) => collect($navigation->build($who, 'gwater')['modules'])->pluck('slug')->all();

        $this->assertContains('gwater', $slugs($user));
        $this->assertNotContains('gwater', $slugs($plain));
        $this->assertContains('gwater.sales', collect($navigation->build($user, 'gwater')['sidebar'])->pluck('route')->all());

        config(['gwl.gwater_module_enabled' => false]);
        $this->assertNotContains('gwater', $slugs($user));

        // And a component refuses to mount even if someone reached it another way.
        Livewire::actingAs($user)->test(Overview::class)->assertNotFound();
    }

    // ---------------------------------------------------------------- screens

    public function test_a_sale_is_recorded_priced_and_voided_from_the_screen(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 100, 0, '2026-05-01');

        $page = Livewire::actingAs($user)->test(DailySales::class)
            ->set('from', '2026-01-01')
            ->set('form.sale_date', '2026-05-12')
            ->set('form.product_id', (string) $this->bottle->id)
            ->set('form.packs', '8')
            ->assertSee('26.00')
            ->call('save')
            ->assertHasNoErrors();

        $sale = GwSale::query()->firstOrFail();
        $this->assertSame([8, 2600, 20800], [$sale->packs, $sale->unit_price, $sale->amount]);
        $page->assertSee('208.00');

        $page->call('openVoid', $sale->id)->set('voidReason', 'Typed on the wrong day')->call('confirmVoid')->assertHasNoErrors();
        $this->assertSame('voided', $sale->fresh()->status);
    }

    public function test_the_screen_refuses_a_sale_with_no_stock_and_shows_why(): void
    {
        $user = $this->gw('G1', $this->sowutuom);

        Livewire::actingAs($user)->test(DailySales::class)
            ->set('form.packs', '5')
            ->call('save')
            ->assertHasErrors(['form.packs']);

        $this->assertSame(0, GwSale::query()->count());
    }

    public function test_the_lists_show_only_the_users_own_books_and_a_foreign_id_is_not_found(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $kumasi = $this->gw('G2', $this->kumasi);
        $this->trip($accra, 'WB-ACC', 100);
        $sale = $this->workflow()->recordSale($accra, $this->saleData(7));
        $this->trip($kumasi, 'WB-KUM', 50);

        Livewire::actingAs($accra)->test(DailySales::class)->set('from', '2026-01-01')->assertViewHas('sales', fn ($sales) => $sales->total() === 1);
        Livewire::actingAs($kumasi)->test(DailySales::class)->set('from', '2026-01-01')->assertViewHas('sales', fn ($sales) => $sales->total() === 0);
        Livewire::actingAs($accra)->test(Purchases::class)->set('from', '2026-01-01')->assertSee('WB-ACC')->assertDontSee('WB-KUM');
        Livewire::actingAs($kumasi)->test(Purchases::class)->set('from', '2026-01-01')->assertSee('WB-KUM')->assertDontSee('WB-ACC');

        Livewire::actingAs($kumasi)->test(DailySales::class)->call('openVoid', $sale->id)->assertNotFound();
        $this->assertSame('posted', $sale->fresh()->status);

        // Head Office, same region id, sees neither.
        Livewire::actingAs($this->gw('G3', $this->headOffice))->test(Purchases::class)->set('from', '2026-01-01')->assertDontSee('WB-ACC')->assertDontSee('WB-KUM');
    }

    public function test_a_trip_is_recorded_from_the_form_with_fuel_and_labour(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $other = $this->vehicleAt($this->kumasi, 'KUM-1');

        Livewire::actingAs($user)->test(Purchases::class)
            ->call('openForm')
            ->assertSee('ACC-1')->assertDontSee('KUM-1')
            ->set('form.purchase_date', '2026-06-01')
            ->set('form.waybill_no', 'WB-LW')
            ->set("packs.{$this->bottle->id}", '40')
            ->set("packs.{$this->sachet->id}", '25')
            ->set('form.vehicle_id', (string) $vehicle->id)
            ->set('form.fuel_amount', '120.50')
            ->set('form.loading_amount', '30')
            ->set('form.offloading_amount', '20.00')
            ->call('save')
            ->assertHasNoErrors();

        $trip = GwPurchase::query()->with('lines')->firstOrFail();
        $this->assertSame([65, 40 * 2000 + 25 * 800], [$trip->totalPacks(), $trip->totalCost()]);
        $this->assertSame($vehicle->id, $trip->vehicle_id);
        $this->assertSame(['fuel' => 12050, 'loading' => 3000, 'offloading' => 2000], GwExpense::query()->pluck('amount', 'category')->all());

        // A vehicle id the form never offered is refused.
        Livewire::actingAs($user)->test(Purchases::class)
            ->call('openForm')
            ->set('form.waybill_no', 'WB-X')->set("packs.{$this->bottle->id}", '1')
            ->set('form.vehicle_id', (string) $other->id)
            ->call('save')
            ->assertNotFound();

        // And a bad amount is an error on the field, not a crash.
        Livewire::actingAs($user)->test(Purchases::class)
            ->call('openForm')
            ->set('form.waybill_no', 'WB-Y')->set("packs.{$this->bottle->id}", '1')->set('form.fuel_amount', 'twelve')
            ->call('save')
            ->assertHasErrors(['form.fuel_amount']);
    }

    public function test_prices_are_added_in_cedis_and_shown_with_their_source(): void
    {
        $user = $this->gw('G1', $this->sowutuom);

        Livewire::actingAs($user)->test(Prices::class)
            ->assertSee('placeholder', false)
            ->set('form.product_id', (string) $this->bottle->id)
            ->set('form.effective_from', '2026-10-01')
            ->set('form.selling_price', '27.50')
            ->set('form.cost_price', '21')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Your own price');

        $row = GwPrice::query()->whereNotNull('region_id')->firstOrFail();
        $this->assertSame([2750, 2100, $this->accraWest->id], [$row->selling_price, $row->cost_price, $row->region_id]);

        Livewire::actingAs($user)->test(Prices::class)
            ->set('form.selling_price', '27.555')->set('form.cost_price', '21')
            ->call('save')
            ->assertHasErrors(['form.selling_price']);

        // The plain employee has no price screen; the viewer-only user could not add one either.
        $this->assertSame(1, GwPrice::query()->whereNotNull('region_id')->count());
    }

    public function test_the_opening_balance_screen_posts_once_and_can_be_voided(): void
    {
        $user = $this->gw('G1', $this->sowutuom);

        $page = Livewire::actingAs($user)->test(OpeningBalances::class)
            ->set('form.as_of_date', '2026-01-01')
            ->set("packs.{$this->bottle->id}", '960')
            ->set("packs.{$this->sachet->id}", '1365')
            ->set('form.owed_amount', '14300')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Recorded');

        $opening = GwOpeningBalance::query()->firstOrFail();
        $this->assertSame(1430000, $opening->owed_amount);
        $this->assertSame([960, 1365], GwStockMovement::query()->orderBy('product_id')->pluck('packs')->all());

        $page->call('openVoid')->set('voidReason', 'Counted again')->call('confirmVoid')->assertHasNoErrors();
        $this->assertSame('voided', $opening->fresh()->status);
        $this->assertSame(0, (int) GwStockMovement::query()->sum('packs'));
    }

    public function test_the_stock_and_overview_screens_show_the_balance_and_take_a_correction(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 100, 30);
        $this->workflow()->recordSale($user, $this->saleData(25, '2026-06-02'));

        Livewire::actingAs($user)->test(Overview::class)
            ->assertViewHas('stock', fn ($stock) => $stock->pluck('packs')->all() === [75, 30])
            ->assertViewHas('owed', 100 * 2000 + 30 * 800);

        Livewire::actingAs($user)->test(StockLedger::class)
            ->set('from', '2026-01-01')
            ->assertSee('Sale')->assertSee('Purchase')
            ->set('form.packs', '-5')->set('form.reason', 'Counted short')
            ->call('correct')
            ->assertHasNoErrors();

        $this->assertSame(70, app(\App\Services\GWater\GwaterStockService::class)->balance(['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id], $this->bottle->id));
    }

    public function test_reimbursements_list_pending_fuel_per_person_and_the_recorder_cannot_pay_it(): void
    {
        $recorder = $this->gw('G1', $this->sowutuom);
        $approver = $this->gw('G2', $this->odorkor);
        $payer = $this->person('P1', $this->sowutuom);
        $this->trip($recorder, 'WB-1', 10, 0, '2026-06-01', ['fuel_amount' => 12000, 'fuel_paid_by_user_id' => $payer->id, 'loading_amount' => 3000]);
        $expense = GwExpense::query()->where('category', 'fuel')->firstOrFail();

        Livewire::actingAs($recorder)->test(Reimbursements::class)
            ->assertSee($payer->full_name)->assertSee('120.00')
            ->assertSee('Someone else must mark this')
            ->assertDontSee('30.00');

        Livewire::actingAs($recorder)->test(Reimbursements::class)
            ->call('openPay', 'expense', $expense->id)->set('form.reference', 'CHQ-1')->call('pay')
            ->assertHasErrors(['form.reference']);

        Livewire::actingAs($approver)->test(Reimbursements::class)
            ->call('openPay', 'expense', $expense->id)->set('form.reference', 'CHQ-77')->call('pay')
            ->assertHasNoErrors()
            ->assertSee('CHQ-77');

        $this->assertSame('reimbursed', $expense->fresh()->reimbursement_status);
    }

    public function test_super_admin_picks_a_location_before_anything_is_shown(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->trip($accra, 'WB-ACC', 100);
        $this->trip($this->gw('G2', $this->kumasi), 'WB-KUM', 50);

        Livewire::actingAs($this->superAdmin())->test(Purchases::class)
            ->set('from', '2026-01-01')
            ->assertSee('Choose a location above')
            ->assertDontSee('WB-ACC')
            ->set('locationId', $this->sowutuom->id)
            ->assertSee('WB-ACC')->assertDontSee('WB-KUM')
            ->set('locationId', $this->kumasi->id)
            ->assertSee('WB-KUM')->assertDontSee('WB-ACC');
    }

    public function test_a_user_without_a_location_sees_why_and_nothing_else(): void
    {
        $homeless = $this->homeless('H1', ['g_water']);

        Livewire::actingAs($homeless)->test(Overview::class)->assertSee('no location');
        Livewire::actingAs($homeless)->test(DailySales::class)->set('form.packs', '1')->call('save')->assertNotFound();
    }
}
