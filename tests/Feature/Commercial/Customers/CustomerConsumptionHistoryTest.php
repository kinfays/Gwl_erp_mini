<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Customers\Dashboard;
use App\Livewire\Commercial\Customers\Lists;
use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use App\Services\Commercial\Customers\CustomerConsumptionHistory;
use App\Services\Commercial\Customers\CustomerSnapshots;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * The consumption history (one row per billing account and month) and the comparison of each account with its own earlier months.
 *
 * Average consumption by monthly upload (accounts 1 to 6, all billing accounts):
 *
 *           Jun   Jul   Aug   Sep (current)   what Sep means
 *   1       10    10    10    25              above 2 x its mean of 10          -> spike
 *   2       10    10    10    4               below 0.5 x its mean, not zero    -> drop
 *   3       10    10    10    0               zero against a positive history    -> zero
 *   4       10    10    10    12              normal
 *   5       -     -     10    100             only one earlier month            -> no baseline, never flagged
 *   6       0     0     0     50              baseline is 0                      -> no baseline, never flagged
 */
class CustomerConsumptionHistoryTest extends CustomerTestCase
{
    /** @param  array<int, float|int>  $averages  by account number */
    protected function upload(string $asOf, array $averages, string $period = 'monthly')
    {
        $customers = [];

        foreach ($averages as $n => $average) {
            // the balance differs by month, so two uploads are never the same file (an identical file is recognised as a duplicate)
            $customers[] = ReportWorkbooks::customer($n, ['average_consume' => $average, 'balance' => 100.0 + $n + (int) substr($asOf, 5, 2) + (int) substr($asOf, 8, 2) / 100]);
        }

        return $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $customers]]], $asOf, $period);
    }

    protected function history(int $n): array
    {
        return DB::table('commercial_customer_consumption_history')->where('customer_id', $this->customerId($n))->orderBy('period_month')->pluck('average_consume', 'period_month')
            ->map(fn ($v) => (float) $v)->all();
    }

    protected function months(): void
    {
        $this->upload('2026-06-05', [1 => 10, 2 => 10, 3 => 10, 4 => 10, 6 => 0]);
        $this->upload('2026-07-05', [1 => 10, 2 => 10, 3 => 10, 4 => 10, 6 => 0]);
        $this->upload('2026-08-05', [1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 10, 6 => 0]);
    }

    protected function consumption(): array
    {
        $snapshots = app(CustomerSnapshots::class);

        return app(CustomerAnalyticsService::class)->consumption($snapshots->filters($snapshots->current(null), null));
    }

    public function test_every_upload_files_each_billing_account_under_its_month_and_stores_nothing_personal(): void
    {
        $this->months();

        $this->assertSame(['2026-06-01' => 10.0, '2026-07-01' => 10.0, '2026-08-01' => 10.0], $this->history(1));
        $this->assertSame(['2026-08-01' => 10.0], $this->history(5), 'a new account starts its history when it first appears');
    }

    public function test_the_latest_upload_of_a_month_replaces_that_months_row_so_weekly_files_do_not_multiply_the_storage(): void
    {
        $this->upload('2026-07-05', [1 => 10, 2 => 10], 'weekly');
        $this->upload('2026-07-19', [1 => 14, 2 => 10], 'weekly');

        $this->assertSame(['2026-07-01' => 14.0], $this->history(1));
        $this->assertSame(2, DB::table('commercial_customer_consumption_history')->count(), 'two accounts, one month');
    }

    public function test_an_account_that_is_not_a_billing_account_or_has_no_average_is_not_stored(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [
            ReportWorkbooks::customer(1, ['average_consume' => 9]),
            ReportWorkbooks::customer(2, ['status' => 'DISC', 'average_consume' => 9]),
            ReportWorkbooks::customer(3, ['average_consume' => null]),
        ]]]], '2026-07-05');

        $this->assertSame([$this->customerId(1)], DB::table('commercial_customer_consumption_history')->pluck('customer_id')->map(fn ($id) => (int) $id)->all(), 'only the billing account with an average');
    }

    public function test_an_older_file_uploaded_late_is_not_filed_over_the_newest_state(): void
    {
        $this->upload('2026-08-05', [1 => 10]);
        $this->upload('2026-06-05', [1 => 99]);

        $this->assertSame(['2026-08-01' => 10.0], $this->history(1), 'the customers table holds the newest state; it is not filed under June');
    }

    public function test_there_is_no_baseline_until_enough_earlier_months_are_stored_and_the_screen_says_so(): void
    {
        $this->upload('2026-06-05', [1 => 10, 2 => 10]);
        $this->upload('2026-07-05', [1 => 30, 2 => 10]);

        $c = $this->consumption();

        $this->assertFalse($c['history']['ready']);
        $this->assertSame(0, $c['history']['spike'], 'nothing to compare with is not "no anomalies"');
        $this->assertSame(1, $c['history']['months'], 'June is the one earlier month stored');

        Livewire::actingAs($this->analyst('910701', $this->accraWest, $this->sowutuom))->test(Dashboard::class)->set('tab', 'consumption')
            ->assertSee('No account has a history baseline yet')->assertSee('1 of the 3 earlier months');
    }

    public function test_accounts_far_from_their_own_history_are_counted_and_listed(): void
    {
        $this->months();
        $this->upload('2026-09-05', [1 => 25, 2 => 4, 3 => 0, 4 => 12, 5 => 100, 6 => 50]);

        $h = $this->consumption()['history'];

        $this->assertTrue($h['ready']);
        $this->assertSame(4, $h['baseline'], 'accounts 1 to 4: three earlier months at a mean above zero (5 has one month, 6 a mean of 0)');
        $this->assertSame([1, 1, 1], [$h['spike'], $h['drop'], $h['zero']]);
        $this->assertSame(3, $h['months']);

        $batch = CommercialCustomerBatch::query()->orderByDesc('id')->firstOrFail();
        $lists = fn (string $issue) => DB::table('commercial_customer_issue_accounts')->where('batch_id', $batch->id)->where('issue', $issue)->pluck('customer_id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$this->customerId(1)], $lists('consumption_spike'));
        $this->assertSame([$this->customerId(2)], $lists('consumption_drop'));
        $this->assertSame([$this->customerId(3)], $lists('consumption_zero'));
    }

    public function test_the_baseline_is_the_mean_of_the_window_only_and_the_current_month_is_not_part_of_it(): void
    {
        config(['gwl.commercial_customer_history_window_months' => 3]);

        // an old month (Jan) outside the 3-month window, three in it
        $this->upload('2026-01-05', [1 => 1000]);
        $this->upload('2026-06-05', [1 => 10]);
        $this->upload('2026-07-05', [1 => 10]);
        $this->upload('2026-08-05', [1 => 10]);
        $this->upload('2026-09-05', [1 => 25]);

        $this->assertSame(1, $this->consumption()['history']['spike'], 'against a mean of 10, not (1000 + 30) / 4');
    }

    public function test_the_thresholds_are_the_configured_multiples(): void
    {
        $this->months();
        config(['gwl.commercial_customer_history_high_multiple' => 3.0, 'gwl.commercial_customer_history_low_multiple' => 0.3]);
        $this->upload('2026-09-05', [1 => 25, 2 => 4, 3 => 0, 4 => 12]);

        $h = $this->consumption()['history'];

        $this->assertSame([0, 0, 1], [$h['spike'], $h['drop'], $h['zero']], '25 is under 3 x 10 and 4 is above 0.3 x 10; zero is still zero');
    }

    public function test_the_district_screen_lists_the_accounts_behind_the_counts(): void
    {
        $this->months();
        $this->upload('2026-09-05', [1 => 25, 2 => 4, 3 => 0, 4 => 12]);
        $analyst = $this->analyst('910702', $this->accraWest, $this->sowutuom);

        Livewire::actingAs($analyst)->test(Dashboard::class)->set('tab', 'consumption')
            ->assertSee("Against each account's own history")->assertSee('Far above their own history')->assertSee('Accounts with a baseline');

        Livewire::actingAs($analyst)->test(Lists::class, ['district' => (string) $this->sowutuom->id])->set('issue', 'consumption_spike')
            ->assertSee('100000000001')->assertDontSee('100000000002');
    }

    public function test_voiding_the_newest_upload_takes_its_history_rows_with_it(): void
    {
        $this->months();
        $batch = $this->upload('2026-09-05', [1 => 25, 2 => 4, 3 => 0, 4 => 12]);

        $this->assertArrayHasKey('2026-09-01', $this->history(1));

        app(CustomerBatchLifecycle::class)->void($batch->fresh(), $this->superAdmin(), 'wrong file');

        $this->assertSame(['2026-06-01', '2026-07-01', '2026-08-01'], array_keys($this->history(1)));
    }

    public function test_months_past_the_retention_are_pruned_and_a_dry_run_changes_nothing(): void
    {
        $this->months();
        config(['gwl.commercial_customer_history_keep_months' => 24]);
        $old = now()->startOfMonth()->subMonths(30)->format('Y-m-d');
        DB::table('commercial_customer_consumption_history')->insert(['customer_id' => $this->customerId(1), 'period_month' => $old, 'district_id' => $this->sowutuom->id, 'batch_id' => 1, 'average_consume' => 5]);
        $before = DB::table('commercial_customer_consumption_history')->count();

        $this->assertSame(1, app(CustomerConsumptionHistory::class)->prune(true));
        $this->assertSame($before, DB::table('commercial_customer_consumption_history')->count());

        $this->assertSame(1, app(CustomerConsumptionHistory::class)->prune());
        $this->assertSame($before - 1, DB::table('commercial_customer_consumption_history')->count());
    }

    public function test_the_record_history_command_files_the_current_state_and_is_safe_to_repeat(): void
    {
        $this->upload('2026-07-05', [1 => 10, 2 => 12]);
        DB::table('commercial_customer_consumption_history')->delete();

        $this->artisan('commercial:customers:record-history')->assertSuccessful();
        $this->artisan('commercial:customers:record-history')->assertSuccessful();

        $this->assertSame(['2026-07-01' => 10.0], $this->history(1));
        $this->assertSame(2, DB::table('commercial_customer_consumption_history')->count());
    }

    public function test_the_history_table_holds_no_personal_data_columns(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('commercial_customer_consumption_history');

        $this->assertSame(['customer_id', 'period_month', 'district_id', 'batch_id', 'average_consume'], $columns);
    }
}
