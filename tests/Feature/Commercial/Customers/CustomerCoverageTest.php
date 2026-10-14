<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Customers\Dashboard;
use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\BillingAnalyticsService;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerSnapshots;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Billing coverage, meter-reading coverage, the estimated-reading rate and arrears by time since the last payment (the customer
 * list), and the active-account trend. One small district whose every figure is worked out by hand in the comments.
 *
 * File date 2026-10-05, so "the last 30 days" starts 2026-09-05. Customers:
 *   1 ACTB, working meter, valid bill (25 Sep, 80), read 20 Sep, paid 28 Sep, owes 100
 *   2 ACTB, working meter, old bill, old read (1 Jun), paid 1 May (157 days ago), billed on an estimate, owes 200
 *   3 ACTB, FAULTY meter, bill of 0 (not valid), read 30 Sep, never paid, in CREDIT of 20 (not arrears)
 *   4 ACTB, NO meter, valid bill, never paid, owes 50
 *   5 ACTN (active, not billing), paid 1 Sep (34 days ago), owes 300
 *   6 DISC (not active), paid 1 Jan 2025, owes 400
 */
class CustomerCoverageTest extends CustomerTestCase
{
    protected function fixture(): void
    {
        $c = fn (int $n, array $over) => ReportWorkbooks::customer($n, $over);

        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [
            $c(1, ['status' => 'ACTB', 'meter_status' => 'W', 'last_bill_date' => '2026-09-25', 'last_bill_amount' => 80.0, 'last_read_date' => '2026-09-20', 'last_paid_date' => '2026-09-28', 'balance' => 100.0, 'estimated_consume' => 0]),
            $c(2, ['status' => 'ACTB', 'meter_status' => 'W', 'last_bill_date' => '2026-08-01', 'last_bill_amount' => 80.0, 'last_read_date' => '2026-06-01', 'last_paid_date' => '2026-05-01', 'balance' => 200.0, 'estimated_consume' => 8]),
            $c(3, ['status' => 'ACTB', 'meter_status' => 'F', 'last_bill_date' => '2026-09-30', 'last_bill_amount' => 0.0, 'last_read_date' => '2026-09-30', 'last_paid_date' => null, 'balance' => -20.0, 'estimated_consume' => 0]),
            $c(4, ['status' => 'ACTB', 'meter_status' => 'N', 'last_bill_date' => '2026-09-30', 'last_bill_amount' => 80.0, 'last_read_date' => null, 'last_paid_date' => null, 'balance' => 50.0, 'estimated_consume' => 0]),
            $c(5, ['status' => 'ACTN', 'meter_status' => 'W', 'last_bill_date' => '2026-09-30', 'last_bill_amount' => 80.0, 'last_read_date' => '2026-09-30', 'last_paid_date' => '2026-09-01', 'balance' => 300.0, 'estimated_consume' => 0]),
            $c(6, ['status' => 'DISC', 'meter_status' => 'W', 'last_bill_date' => '2025-01-01', 'last_bill_amount' => 80.0, 'last_read_date' => '2025-01-01', 'last_paid_date' => '2025-01-01', 'balance' => 400.0, 'estimated_consume' => 0]),
        ]]]]);
    }

    protected function filters(?int $restriction = null)
    {
        $snapshots = app(CustomerSnapshots::class);

        return $snapshots->filters($snapshots->current($restriction), $restriction);
    }

    protected function coverage(?int $restriction = null): array
    {
        return app(CustomerAnalyticsService::class)->coverage($this->filters($restriction));
    }

    public function test_billing_coverage_counts_eligible_accounts_with_a_valid_recent_bill(): void
    {
        $this->fixture();

        $t = $this->coverage()['total'];

        $this->assertSame(4, $t['eligible'], 'the four billing accounts (ACTB)');
        $this->assertSame(2, $t['billed_valid'], 'customers 1 and 4: customer 3 was billed 0, customer 2 not in the last 30 days');
        $this->assertSame(50.0, $t['billing_coverage']);
    }

    public function test_reading_coverage_is_over_metered_eligible_accounts_and_a_faulty_meter_counts_as_metered(): void
    {
        $this->fixture();

        $t = $this->coverage()['total'];

        $this->assertSame(3, $t['metered'], 'customers 1, 2 (working) and 3 (faulty); customer 4 has no meter');
        $this->assertSame(2, $t['read'], 'customers 1 and 3 have a read in the last 30 days');
        $this->assertSame(66.7, $t['reading_coverage']);
    }

    public function test_the_estimated_reading_rate_is_over_eligible_accounts(): void
    {
        $this->fixture();

        $t = $this->coverage()['total'];

        $this->assertSame(1, $t['estimated']);
        $this->assertSame(25.0, $t['estimated_rate']);
        $this->assertSame(5, $t['active'], 'ACTB and ACTN are active; the disconnected account is not');
    }

    public function test_arrears_are_what_is_owed_grouped_by_time_since_the_last_payment_and_credits_are_left_out(): void
    {
        $this->fixture();

        $cv = $this->coverage();

        $this->assertSame(1050.0, $cv['total']['owed'], '100 + 200 + 50 + 300 + 400: the GH¢ 20 credit is not arrears and is not netted off');
        $this->assertSame(
            ['Paid in the last 30 days' => 100.0, 'Last paid 30 to 60 days ago' => 300.0, 'Last paid 60 to 90 days ago' => 0.0, 'Last paid 90 to 180 days ago' => 200.0, 'Last paid over 180 days ago, or never' => 450.0],
            collect($cv['arrears_ageing'])->pluck('amount', 'label')->all()
        );
        $this->assertSame(1050.0, array_sum(array_column($cv['arrears_ageing'], 'amount')), 'the slices add up to what is owed');
        $this->assertSame([9.5, 28.6, 0.0, 19.0, 42.9], array_column($cv['arrears_ageing'], 'share'));
        $this->assertSame(650.0, $cv['total']['owed_no_pay_90'], 'customers 2, 4 and 6 have not paid for 90 days');
    }

    public function test_the_figures_are_per_district_and_a_batch_built_before_the_measures_is_left_out_not_shown_as_zero(): void
    {
        $this->fixture();
        $this->loadCustomers($this->simpleSpec(101, 103, ['region' => 'ASHANTI', 'district' => 'KUMASI'], '2001'));

        $cv = $this->coverage();
        $this->assertSame(['Kumasi', 'Sowutuom'], array_column($cv['districts'], 'label'));
        $this->assertSame(2, $cv['ready'], 'two districts, both built with the measures');

        // an older rollup (version 1): left out, and said so
        $old = CommercialCustomerBatch::query()->where('district_id', $this->sowutuom->id)->firstOrFail();
        DB::table('commercial_customer_batches')->where('id', $old->id)->update(['rollup_version' => 1]);

        $partial = $this->coverage();
        $this->assertTrue($partial['available']);
        $this->assertSame([2, 1], [$partial['batches'], $partial['ready']]);
        $this->assertSame(['Kumasi'], array_column($partial['districts'], 'label'));

        DB::table('commercial_customer_batches')->update(['rollup_version' => 1]);
        $none = $this->coverage();
        $this->assertFalse($none['available']);
        $this->assertSame(0, $none['ready']);
    }

    public function test_a_viewer_only_sees_their_own_region(): void
    {
        $this->fixture();
        $this->loadCustomers($this->simpleSpec(101, 103, ['region' => 'ASHANTI', 'district' => 'KUMASI'], '2001'));

        $west = $this->coverage($this->accraWest->id);

        $this->assertSame(['Sowutuom'], array_column($west['districts'], 'label'));
        $this->assertSame(4, $west['total']['eligible']);
    }

    public function test_new_batches_are_built_with_the_measures(): void
    {
        $this->fixture();

        $this->assertSame([2], CommercialCustomerBatch::query()->pluck('rollup_version')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame(3, (int) DB::table('commercial_customer_rollups_district')->sum('billed_valid_30'), 'customers 1, 4 and 5 (every status is stored; the analysis keeps the billing accounts)');
    }

    public function test_the_active_account_trend_follows_the_statuses_and_the_change_between_uploads(): void
    {
        $this->fixture();

        // the next month: customer 5 is disconnected (no longer active), customer 6 is reconnected (active again), a new customer 7 arrives
        $customers = [
            ReportWorkbooks::customer(1, ['status' => 'ACTB']), ReportWorkbooks::customer(2, ['status' => 'ACTB']), ReportWorkbooks::customer(3, ['status' => 'ACTB']), ReportWorkbooks::customer(4, ['status' => 'ACTB']),
            ReportWorkbooks::customer(5, ['status' => 'DISC']), ReportWorkbooks::customer(6, ['status' => 'ACTB']), ReportWorkbooks::customer(7, ['status' => 'ACTB']),
        ];
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $customers]]], '2026-11-05');

        $series = app(CustomerAnalyticsService::class)->trend(null, null, null, 12)['series'];

        $this->assertSame([5, 6], array_column($series, 'active'), 'ACTB + ACTN each month');
        $this->assertSame([6, 7], array_column($series, 'customers'));
        $this->assertSame([null, 1], array_map(fn ($p) => $p['change']['active'] ?? null, $series));

        $byMonth = app(CustomerAnalyticsService::class)->trend(null, null, null, 12, true)['series'];
        $this->assertSame([5, 6], array_column($byMonth, 'active'));
    }

    public function test_the_screen_shows_the_coverage_tab_with_its_definitions_and_the_summary_export_carries_it(): void
    {
        $this->fixture();
        $analyst = $this->analyst('910601', $this->accraWest, $this->sowutuom);

        Livewire::actingAs($analyst)->test(Dashboard::class)->set('tab', 'coverage')
            ->assertSee('Billing coverage')->assertSee('50.0%')->assertSee('66.7%')->assertSee('25.0%')
            ->assertSee('Arrears by time since the last payment')->assertSee('Last paid 90 to 180 days ago')->assertSee('1,050.00')
            ->assertSee('does not say whether it was accepted')->assertSee('true ageing needs an aged-debtors report');

        $response = $this->actingAs($analyst)->get(route('commercial.customers.export', ['report' => 'summary', 'format' => 'excel']))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $titles = array_map(fn ($sheet) => $sheet->getTitle(), $book->getAllSheets());

        $this->assertContains('Coverage', $titles);
        $this->assertContains('Arrears by time since payment', $titles);
    }

    public function test_the_coverage_tab_explains_itself_when_no_batch_has_the_measures_yet(): void
    {
        $this->fixture();
        DB::table('commercial_customer_batches')->update(['rollup_version' => 1]);

        Livewire::actingAs($this->analyst('910602', $this->accraWest, $this->sowutuom))->test(Dashboard::class)->set('tab', 'coverage')
            ->assertSee('appear from the next customer-list upload')->assertDontSee('0.0%');
    }

    public function test_collection_against_the_amount_due_is_payments_over_opening_plus_billing_plus_adjustments(): void
    {
        $batch = $this->seedBilling([
            ['district' => 'SOWUTUOM', 'code' => 'S1', 'billing_for_period' => 1000, 'opening_balance' => 500, 'revenue_adjustment' => 100, 'total_receivable' => 1600, 'payment_for_month' => 600, 'prev_month_payment' => 200, 'total_payments' => 800, 'closing_balance' => 800],
            ['district' => 'SOWUTUOM', 'code' => 'S2', 'billing_for_period' => 0, 'opening_balance' => 400, 'revenue_adjustment' => 0, 'total_receivable' => 400, 'payment_for_month' => 0, 'prev_month_payment' => 0, 'total_payments' => 0, 'closing_balance' => 400],
        ], '2026-09-01');

        $routes = $batch->routes()->get();
        $collections = app(BillingAnalyticsService::class)->collections($routes);

        $this->assertSame(2000.0, $collections['total']['receivable']);
        $this->assertSame(40.0, $collections['total']['due_ratio'], '800 / 2,000');
        $this->assertSame(80.0, $collections['total']['cash_ratio'], 'against this month\'s billing alone: 800 / 1,000');
        $this->assertNull(app(BillingAnalyticsService::class)->collections($routes->take(0))['total']['due_ratio'], 'no amount due: no ratio');

        $this->actingAs($this->officer())->get(route('commercial.billing'))->assertOk();
        \Livewire\Livewire::actingAs($this->officer())->test(\App\Livewire\Commercial\Billing::class)->set('tab', 'collections')->assertSee('Collected of the amount due')->assertSee('40.0%');
    }
}
