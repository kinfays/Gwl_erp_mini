<?php

namespace Tests\Feature\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use App\Services\Commercial\Customers\CustomerImportException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commercial\ReportWorkbooks;

/** Voiding the newest upload of a district restores the customers exactly, through the change log and the undo table. */
class CustomerRollbackTest extends CustomerTestCase
{
    /** @return array<string, mixed> everything in the customers table keyed by account, minus the columns the void may legitimately change */
    protected function state(): array
    {
        return DB::table('commercial_customers')->orderBy('account_no')->get()->mapWithKeys(fn ($row) => [$row->account_no => (array) $row])->all();
    }

    public function test_voiding_the_newest_batch_restores_every_row_changed_missing_and_new(): void
    {
        $first = $this->loadCustomers($this->simpleSpec(1, 6));
        $before = $this->state();

        // Second file: 2 changes, 1 customer gone (6), one brand new (7).
        $customers = $this->customers(1, 5);
        $customers[1] = ReportWorkbooks::customer(2, ['status' => 'DISC', 'balance' => 777.0, 'category' => '612']);
        $customers[3] = ReportWorkbooks::customer(4, ['meter_status' => 'F', 'last_paid_date' => '2026-10-20']);
        $customers[] = ReportWorkbooks::customer(7);
        $second = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $customers]]], '2026-11-05');

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $second->status, json_encode($second->errors));
        $this->assertSame(1, $second->rows_new);
        $this->assertSame(3, $second->rows_changed + $second->rows_missing, '2 changed + 1 missing');
        $this->assertNotSame($before, $this->state());

        app(CustomerBatchLifecycle::class)->void($second, $this->superAdmin(), 'wrong file');

        $this->assertSame($before, $this->state(), 'the customers table is exactly as it was after the first upload');
        $this->assertSame(CommercialCustomerBatch::STATUS_VOIDED, $second->fresh()->status);
        $this->assertSame(0, DB::table('commercial_customer_rollups')->where('batch_id', $second->id)->count());
        $this->assertSame(0, DB::table('commercial_customer_changes')->where('batch_id', $second->id)->count());
        $this->assertSame(0, DB::table('commercial_customer_undo')->where('batch_id', $second->id)->count());
        $this->assertSame(0, DB::table('commercial_customer_contacts')->where('customer_id', 'not-a-customer')->count());
        $this->assertSame(6, DB::table('commercial_customer_contacts')->count(), 'the new customer\'s contact went with it');
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $first->fresh()->status);
    }

    public function test_only_the_newest_batch_of_a_district_can_be_voided(): void
    {
        $first = $this->loadCustomers($this->simpleSpec(1, 3), '2026-10-05');
        $this->loadCustomers($this->simpleSpec(1, 4), '2026-11-05');

        $blocker = app(CustomerBatchLifecycle::class)->voidBlocker($first->fresh());

        $this->assertNotNull($blocker);
        $this->assertStringContainsString('newest upload', $blocker);

        $this->expectException(CustomerImportException::class);
        app(CustomerBatchLifecycle::class)->void($first->fresh(), $this->superAdmin(), 'no');
    }

    public function test_rollback_data_is_kept_only_for_the_configured_number_of_newest_batches(): void
    {
        $first = $this->loadCustomers($this->simpleSpec(1, 3, ['district' => 'SOWUTUOM']), '2026-10-05');
        $changed = $this->customers(1, 3);
        $changed[0] = ReportWorkbooks::customer(1, ['balance' => 999.0]);
        $second = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $changed]]], '2026-11-05');

        $this->assertSame(0, DB::table('commercial_customer_undo')->where('batch_id', $first->id)->count());
        $this->assertSame(1, DB::table('commercial_customer_undo')->where('batch_id', $second->id)->count());

        config(['gwl.commercial_customer_undo_keep_batches' => 2]);
        $changed[1] = ReportWorkbooks::customer(2, ['balance' => 5.0]);
        $third = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $changed]]], '2026-12-05');

        $this->assertSame(1, DB::table('commercial_customer_undo')->where('batch_id', $second->id)->count(), 'kept: it is one of the 2 newest');
        $this->assertSame(1, DB::table('commercial_customer_undo')->where('batch_id', $third->id)->count());
    }

    public function test_a_voided_batch_brings_back_the_period_it_had_superseded(): void
    {
        $a = $this->loadCustomers($this->simpleSpec(1, 3), '2026-10-05', 'monthly');
        $changed = $this->customers(1, 3);
        $changed[0] = ReportWorkbooks::customer(1, ['balance' => 5.0]);
        $b = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $changed]]], '2026-10-20', 'monthly');

        $this->assertSame(CommercialCustomerBatch::STATUS_SUPERSEDED, $a->fresh()->status);
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $b->fresh()->status);

        app(CustomerBatchLifecycle::class)->void($b->fresh(), $this->superAdmin(), 'duplicate');

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $a->fresh()->status);
    }
}
