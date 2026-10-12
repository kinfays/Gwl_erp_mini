<?php

namespace Tests\Feature\GWater;

use App\Livewire\GWater\Overview;
use App\Models\AuditLog;
use App\Models\GwDamage;
use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwPrice;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwSetting;
use App\Models\GwStockMovement;
use App\Models\GwSupplierPayment;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\GWater\GwaterProfitService;
use App\Services\GWater\GwaterReminderService;
use App\Services\GWater\GwaterStockService;
use App\Services\GWater\GwaterSummaryService;
use Database\Seeders\Demo\GwaterDemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

class GwaterDemoSeederTest extends GwaterTestCase
{
    private function seedDemo(): void
    {
        $this->seed(GwaterDemoSeeder::class);
        Carbon::setTestNow('2026-10-11 09:00:00'); // the seeder moves the clock and puts it back to the real one
    }

    private function accraPlace(): array
    {
        $sowutuom = \App\Models\District::query()->where('district_name', 'Sowutuom')->firstOrFail();

        return ['region_id' => $sowutuom->region_id, 'district_id' => $sowutuom->id];
    }

    private function counts(): array
    {
        return [GwPurchase::count(), GwSale::count(), GwDamage::count(), GwExpense::count(), GwSupplierPayment::count(), GwStockMovement::count(), GwPrice::count(), GwOpeningBalance::count(), User::count(), AuditLog::count()];
    }

    public function test_the_seeder_runs_twice_without_duplicates(): void
    {
        $this->seedDemo();
        $first = $this->counts();

        $this->seedDemo();

        $this->assertSame($first, $this->counts());
        $this->assertGreaterThan(40, GwPurchase::count() + GwSale::count());
    }

    public function test_the_demo_data_has_the_agreed_shape(): void
    {
        $this->seedDemo();
        $place = $this->accraPlace();

        $this->assertSame(10, GwPurchase::query()->where('waybill_no', 'like', 'GW-%')->count(), 'a trip every week for ten weeks');
        $this->assertSame(1, GwOpeningBalance::count());
        $this->assertSame(1, GwSale::query()->where('status', 'voided')->count());
        $this->assertGreaterThanOrEqual(3, GwDamage::count());
        $this->assertSame(5, GwSupplierPayment::count());
        $this->assertSame(4, GwPrice::query()->whereNotNull('region_id')->count(), 'its own list, with a rise part-way');
        $this->assertSame(2, GwSetting::count());

        // Credit: customers who pay in full, in part, and not yet.
        $credit = GwSale::query()->posted()->where('payment_type', 'credit')->get();
        $this->assertGreaterThanOrEqual(5, $credit->count());
        $this->assertGreaterThanOrEqual(1, $credit->filter(fn ($sale) => $sale->receivedAmount() >= $sale->amount)->count(), 'a debt paid in full');
        $this->assertGreaterThanOrEqual(1, $credit->filter(fn ($sale) => $sale->receivedAmount() > 0 && $sale->balanceOwed() > 0)->count(), 'a part payment');
        $this->assertGreaterThanOrEqual(1, $credit->filter(fn ($sale) => $sale->receivedAmount() === 0)->count(), 'a debt nobody has paid');
        $this->assertGreaterThan(0, app(GwaterSummaryService::class)->owedByCustomers($place)['total']);
        $this->assertTrue($credit->every(fn ($sale) => filled($sale->customer_name)));

        // Fuel from all three sources, and some of it still to pay back and some paid back.
        $this->assertGreaterThanOrEqual(3, GwPurchase::query()->whereNotNull('vehicle_expense_id')->count());
        $this->assertGreaterThanOrEqual(3, GwExpense::query()->where('category', 'fuel')->count());
        $this->assertGreaterThan(0, GwExpense::query()->where('reimbursement_status', 'pending')->count() + GwPurchase::query()->where('fuel_reimbursement_status', 'pending')->count());
        $this->assertGreaterThan(0, GwExpense::query()->where('reimbursement_status', 'reimbursed')->count() + GwPurchase::query()->where('fuel_reimbursement_status', 'reimbursed')->count());

        // Payments: most paid, one overdue (older than the 30-day limit), the latest still waiting.
        $waiting = GwSupplierPayment::query()->where('status', 'requested')->orderBy('request_date')->get();
        $this->assertCount(2, $waiting);
        $this->assertTrue($waiting->first()->request_date->lt(now()->subDays(30)));
        $this->assertTrue($waiting->last()->request_date->gte(now()->subDays(14)));
        $this->assertSame(3, GwSupplierPayment::query()->where('status', 'paid')->count());

        // The books are real: stock is never negative, there is something owed, and a month has a profit figure.
        $stock = app(GwaterStockService::class);
        foreach ([$this->bottle, $this->sachet] as $product) {
            $this->assertGreaterThanOrEqual(0, $stock->balance($place, $product->id));
        }
        $this->assertGreaterThan(0, app(GwaterSummaryService::class)->owedToGwater($place));
        $month = app(GwaterProfitService::class)->report($place, '2026-09-01', '2026-09-30', User::query()->where('staff_id', 'GWA001')->firstOrFail())['combined'];
        $this->assertGreaterThan(0, $month['revenue']);
        $this->assertGreaterThan(0, $month['received_packs']);
    }

    public function test_everything_is_in_accra_west_and_nowhere_else(): void
    {
        $this->seedDemo();
        $accra = \App\Models\Region::query()->where('region_name', 'Accra West')->firstOrFail();

        foreach ([GwPurchase::class, GwSale::class, GwDamage::class, GwExpense::class, GwSupplierPayment::class, GwStockMovement::class, GwOpeningBalance::class] as $model) {
            $this->assertSame([$accra->id], $model::query()->pluck('region_id')->unique()->values()->all(), $model);
            $this->assertFalse($model::query()->whereIn('district_id', \App\Models\District::query()->where('district_name', 'like', '%Head Office%')->pluck('id'))->exists(), $model.' must not touch Head Office');
        }

        // Another region and Head Office see none of it.
        $kumasi = $this->gw('K1', $this->kumasi);
        $headOffice = $this->gw('H1', $this->headOffice);
        $profit = app(GwaterProfitService::class);

        $this->assertSame(0, $profit->report(['region_id' => $this->ashanti->id, 'district_id' => $this->kumasi->id], '2026-08-01', '2026-10-31', $kumasi)['combined']['revenue']);
        $this->assertSame(0, $profit->report(['region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id], '2026-08-01', '2026-10-31', $headOffice)['combined']['revenue']);
        Livewire::actingAs($kumasi)->test(Overview::class)->assertViewHas('recentTrips', fn ($trips) => $trips->isEmpty());
    }

    public function test_the_demo_logins_work_and_see_the_screens(): void
    {
        $this->seedDemo();
        $recorder = User::query()->where('staff_id', 'GWA001')->firstOrFail();
        $approver = User::query()->where('staff_id', 'GWA002')->firstOrFail();
        $plain = User::query()->where('staff_id', 'GWA102')->firstOrFail();

        foreach ($recorder->roles()->pluck('name')->all() as $role) {
            $this->assertContains($role, ['employee', 'g_water']);
        }

        foreach (['gwater.home', 'gwater.sales', 'gwater.purchases', 'gwater.damages', 'gwater.expenses', 'gwater.payments', 'gwater.stock', 'gwater.pnl', 'gwater.reimbursements', 'gwater.prices', 'gwater.settings'] as $route) {
            $this->actingAs($recorder)->get(route($route))->assertOk();
            $this->actingAs($approver)->get(route($route))->assertOk();
            $this->assertContains($this->actingAs($plain)->get(route($route))->getStatusCode(), [302, 403], $route);
        }

        Livewire::actingAs($recorder)->test(Overview::class)
            ->assertSee('Net profit this month')
            ->assertSee('Low stock: Sachet water')
            ->assertDontSee('Low stock: Bottled water')
            ->assertViewHas('owed', fn (int $owed) => $owed > 0)
            ->assertViewHas('pending', fn ($pending) => $pending->isNotEmpty());

        // Every demo login can sign in with the documented password.
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('12345', $recorder->password));
        $this->assertFalse((bool) $recorder->must_change_password);
    }

    public function test_the_pending_fuel_can_be_paid_back_by_the_other_g_water_user_but_not_the_recorder(): void
    {
        $this->seedDemo();
        $recorder = User::query()->where('staff_id', 'GWA001')->firstOrFail();
        $approver = User::query()->where('staff_id', 'GWA002')->firstOrFail();
        $place = $this->accraPlace();
        $item = app(GwaterSummaryService::class)->pendingReimbursements($place)->firstOrFail();

        $this->actingAs($recorder);
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            $this->workflow()->markReimbursed($recorder, $item['kind'], $item['id'], 'CHQ-DEMO');
        } finally {
            $this->actingAs($approver);
            $this->assertSame('reimbursed', $this->workflow()->markReimbursed($approver, $item['kind'], $item['id'], 'CHQ-DEMO', '2026-10-11')->{$item['kind'] === 'trip' ? 'fuel_reimbursement_status' : 'reimbursement_status'});
        }
    }

    public function test_the_reminders_have_something_to_say_about_the_demo_books(): void
    {
        $this->seedDemo();
        $recorder = User::query()->where('staff_id', 'GWA001')->firstOrFail();

        $summary = app(GwaterReminderService::class)->remind(now());

        $this->assertGreaterThanOrEqual(1, $summary['overdue'], 'the overdue payment request');
        $titles = Notification::sent($recorder, GeneralDatabaseNotification::class)->map(fn ($n) => $n->toArray($recorder)['title'])->all();
        $this->assertContains('G-Water: payment requests overdue', $titles);
    }

    public function test_the_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        (new GwaterDemoSeeder)->run();

        $this->assertSame(0, GwPurchase::count());
        $this->assertSame(0, User::query()->where('staff_id', 'GWA001')->count());
    }
}
