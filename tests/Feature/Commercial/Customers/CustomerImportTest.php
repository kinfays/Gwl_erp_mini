<?php

namespace Tests\Feature\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\XlsxStreamReader;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commercial\ReportWorkbooks;

class CustomerImportTest extends CustomerTestCase
{
    public function test_a_file_is_parsed_staged_merged_and_rolled_up(): void
    {
        $batch = $this->loadCustomers($this->simpleSpec(1, 5));

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->status, json_encode([$batch->errors, $batch->error_message]));
        $this->assertSame(5, $batch->rows_read);
        $this->assertSame(5, $batch->rows_new);
        $this->assertSame($this->accraWest->id, $batch->region_id);
        $this->assertSame($this->sowutuom->id, $batch->district_id);
        $this->assertSame('2026-10', $batch->period_key);
        $this->assertSame(5, $this->customerCount());
        $this->assertSame(0, DB::table('commercial_customer_staging')->count(), 'staging (personal data) is always cleaned');

        $row = $this->customerRow(3);
        $this->assertSame(10300, (int) $row->balance, 'GH¢ 103.00 in pesewas');
        $this->assertSame('2026-09-25', $row->last_bill_date);
        $this->assertSame(8000, (int) $row->last_bill_amount);
        $this->assertSame('100000000003', $row->account_no, 'leading zeros and digits kept as a string');

        $contact = DB::table('commercial_customer_contacts')->where('customer_id', $row->id)->first();
        $this->assertSame('Customer 000003', $contact->account_name);
        $this->assertSame('0240000003', $contact->phone_primary);
    }

    public function test_the_stream_reader_reads_dates_numbers_and_strings_and_never_opens_the_document_map(): void
    {
        $path = ReportWorkbooks::customerList($this->simpleSpec(1, 2));
        $this->workbooks[] = $path;
        $reader = new XlsxStreamReader($path);

        $this->assertSame(['Document map', 'rptCustomerDetails'], $reader->sheetNames());

        $rows = iterator_to_array($reader->rows('rptCustomerDetails'));
        $first = collect($rows)->first(fn ($cells) => ($cells[1] ?? null) === '100000000001');

        $this->assertNotNull($first, 'the account number is a 12-digit STRING');
        $this->assertInstanceOf(\DateTimeImmutable::class, $first[17], 'a date-formatted cell comes back as a date');
        $this->assertSame('2026-09-20', $first[17]->format('Y-m-d'));
        $this->assertIsFloat($first[16]);
        $this->assertSame('Customer 000001', $first[3]);
    }

    public function test_the_same_file_twice_is_the_same_batch_and_writes_nothing(): void
    {
        $spec = $this->simpleSpec(1, 5);
        $path = ReportWorkbooks::customerList($spec);
        $this->workbooks[] = $path;
        $imports = app(\App\Services\Commercial\Customers\CustomerImportService::class);

        ['batch' => $one, 'duplicate' => $dup1] = $imports->register($path, 'a.xlsx', 'monthly', \Illuminate\Support\Carbon::parse('2026-10-05'), null);
        $imports->process($one);
        ['batch' => $two, 'duplicate' => $dup2] = $imports->register($path, 'b.xlsx', 'monthly', \Illuminate\Support\Carbon::parse('2026-10-12'), null);

        $this->assertFalse($dup1);
        $this->assertTrue($dup2);
        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, CommercialCustomerBatch::query()->count());
    }

    public function test_an_unchanged_file_uploaded_again_for_a_later_date_writes_no_customer_rows(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 20));
        $before = DB::table('commercial_customers')->orderBy('id')->get()->toJson();

        // A different batch (a later date) whose content is identical.
        $second = $this->loadCustomers($this->simpleSpec(1, 20) + ['region' => 'Accra West'], '2026-10-12', 'weekly');

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $second->status, json_encode($second->errors));
        $this->assertSame(0, $second->rows_new);
        $this->assertSame(0, $second->rows_changed);
        $this->assertSame(20, $second->rows_unchanged);
        $this->assertSame($before, DB::table('commercial_customers')->orderBy('id')->get()->toJson(), 'the customers table was not touched');
        $this->assertSame(0, DB::table('commercial_customer_undo')->where('batch_id', $second->id)->count());
        $this->assertSame(0, DB::table('commercial_customer_changes')->where('batch_id', $second->id)->count());
        $this->assertGreaterThan(0, DB::table('commercial_customer_rollups')->where('batch_id', $second->id)->count(), 'but its rollups exist');
    }

    public function test_a_changed_customer_is_updated_logged_and_its_pre_image_kept(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));

        $spec = $this->simpleSpec(1, 5);
        $spec['routes'][0]['customers'][1] = ReportWorkbooks::customer(2, ['status' => 'DISC', 'balance' => 500.0]);
        $batch = $this->loadCustomers($spec, '2026-11-05');

        $this->assertSame(1, $batch->rows_changed);
        $this->assertSame(4, $batch->rows_unchanged);
        $row = $this->customerRow(2);
        $this->assertSame(50000, (int) $row->balance);
        $this->assertSame($batch->id, (int) $row->updated_batch_id);

        $disc = DB::table('commercial_customer_statuses')->where('code', 'DISC')->value('id');
        $actb = DB::table('commercial_customer_statuses')->where('code', 'ACTB')->value('id');
        $change = DB::table('commercial_customer_changes')->where('batch_id', $batch->id)->where('field', 1)->first();

        $this->assertSame($row->id, $change->customer_id);
        $this->assertSame($actb, (int) $change->old_value);
        $this->assertSame($disc, (int) $change->new_value);
        $this->assertSame(10200, (int) DB::table('commercial_customer_undo')->where('batch_id', $batch->id)->where('customer_id', $row->id)->value('balance'));
    }

    public function test_a_customer_missing_from_the_next_file_is_flagged_not_deleted_and_returns_later(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        $second = $this->loadCustomers($this->simpleSpec(1, 4), '2026-11-05');

        $this->assertSame(1, $second->rows_missing);
        $this->assertSame(5, $this->customerCount(), 'never deleted');
        $this->assertSame($second->id, (int) $this->customerRow(5)->missing_since_batch_id);
        $this->assertNull($this->customerRow(1)->missing_since_batch_id);
        $this->assertSame(1, DB::table('commercial_customer_changes')->where('batch_id', $second->id)->where('field', 8)->count());

        $third = $this->loadCustomers($this->simpleSpec(1, 5), '2026-12-05');

        $this->assertNull($this->customerRow(5)->missing_since_batch_id);
        $this->assertSame(1, DB::table('commercial_customer_changes')->where('batch_id', $third->id)->where('field', 9)->count(), 'returned');
    }

    public function test_an_account_found_in_another_district_is_a_move(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 3));
        $odorkor = $this->loadCustomers($this->simpleSpec(3, 4, ['district' => 'ODORKOR']), '2026-11-05');

        $this->assertSame(1, $odorkor->rows_moved);
        $this->assertSame($this->odorkor->id, (int) $this->customerRow(3)->district_id);
        $change = DB::table('commercial_customer_changes')->where('batch_id', $odorkor->id)->where('field', 5)->first();
        $this->assertSame($this->sowutuom->id, (int) $change->old_value);
        $this->assertSame($this->odorkor->id, (int) $change->new_value);
    }

    public function test_a_route_that_does_not_add_up_to_its_own_totals_blocks_the_file(): void
    {
        $spec = $this->simpleSpec(1, 5);
        $spec['routes'][0]['count'] = 6;
        $batch = $this->loadCustomers($spec);

        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $batch->status);
        $this->assertStringContainsString('lists 5 customers but its totals row says 6', $batch->errors[0]['message']);
        $this->assertSame(0, $this->customerCount());
        $this->assertSame(0, DB::table('commercial_customer_staging')->count());

        $spec = $this->simpleSpec(1, 5);
        $spec['routes'][0]['balance'] = 9999.0;
        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $this->loadCustomers($spec)->status);
    }

    public function test_a_route_without_a_totals_row_only_warns(): void
    {
        $spec = $this->simpleSpec(1, 4);
        $spec['routes'][0]['totals'] = false;
        $batch = $this->loadCustomers($spec);

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->status, json_encode($batch->errors));
        $this->assertStringContainsString('No totals row was found for this route', collect($batch->warnings)->pluck('message')->implode(' '));
        $this->assertSame(4, $this->customerCount());
    }

    public function test_a_damaged_row_is_counted_and_reported_by_row_number_never_by_value(): void
    {
        $customers = [...$this->customers(1, 3), ReportWorkbooks::customer(4, ['account' => 'NOT-AN-ACCOUNT', 'name' => 'Secret Person'])];
        $batch = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $customers, 'count' => 3, 'balance' => 306.0]]]);

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->status, json_encode($batch->errors));
        $this->assertSame(1, $batch->rows_malformed);
        $this->assertSame(3, $this->customerCount());

        $warning = collect($batch->warnings)->first(fn ($w) => str_contains($w['message'], 'Account #'));
        $this->assertNotNull($warning);
        $this->assertIsInt($warning['row'], 'reported by row number');
        $this->assertStringNotContainsString('Secret Person', json_encode($batch->toArray()));
        $this->assertStringNotContainsString('NOT-AN-ACCOUNT', json_encode($batch->toArray()));
    }

    public function test_a_balance_total_within_a_cedi_of_the_file_passes_and_one_further_out_blocks(): void
    {
        $near = $this->simpleSpec(1, 4);
        $near['routes'][0]['balance'] = round(array_sum(array_column($near['routes'][0]['customers'], 'balance')) + 0.5, 2);
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $this->loadCustomers($near)->status);

        $far = $this->simpleSpec(11, 14);
        $far['routes'][0]['balance'] = round(array_sum(array_column($far['routes'][0]['customers'], 'balance')) + 5, 2);
        $blocked = $this->loadCustomers($far, '2026-11-05');
        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $blocked->status);
        $this->assertStringContainsString('balances add up to', $blocked->errors[0]['message']);
    }

    public function test_thousands_separators_in_the_customer_count_are_read(): void
    {
        $spec = $this->simpleSpec(1, 3);
        $spec['routes'][0]['count'] = 3;

        // "Customer Count: 1,234" style text is parsed by digits only.
        $parser = new \App\Services\Commercial\Customers\CustomerListParser;
        $events = iterator_to_array($parser->parse([1 => ['1055 TOTALS :', null, 'Customer Count: 1,234', null, 55.5]]));
        $this->assertSame('totals', $events[0]['kind']);
        $this->assertSame(1234, $events[0]['count']);
    }

    public function test_duplicate_accounts_in_one_file_block_it(): void
    {
        $customers = $this->customers(1, 4);
        $customers[3] = ReportWorkbooks::customer(2);
        $batch = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $customers]]]);

        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $batch->status);
        $this->assertSame(1, $batch->duplicate_accounts);
        $this->assertStringContainsString('appear more than once', $batch->errors[0]['message']);
    }

    public function test_unknown_categories_statuses_and_meter_statuses_become_pending_rows_with_a_warning(): void
    {
        $customers = [
            ReportWorkbooks::customer(1, ['category' => '999', 'status' => 'WXYZ', 'meter_status' => 'Q']),
            ReportWorkbooks::customer(2, ['category' => null]),
            ReportWorkbooks::customer(3, ['status' => 'TRFR']),
        ];
        $batch = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $customers]]]);

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->status);
        $this->assertTrue((bool) DB::table('commercial_customer_categories')->where('code', '999')->value('is_pending'));
        $this->assertTrue((bool) DB::table('commercial_customer_statuses')->where('code', 'WXYZ')->value('is_pending'));
        $this->assertTrue((bool) DB::table('commercial_meter_statuses')->where('code', 'Q')->value('is_pending'));

        $unknown = DB::table('commercial_customer_categories')->where('is_unknown', true)->value('id');
        $this->assertSame($unknown, (int) $this->customerRow(2)->category_id, 'a blank category is UNKNOWN');
        $this->assertFalse((bool) DB::table('commercial_customer_statuses')->where('code', 'TRFR')->value('meaning_confirmed'));

        $messages = collect($batch->warnings)->pluck('message')->implode(' | ');
        $this->assertStringContainsString('category code', $messages);
        $this->assertStringContainsString('WXYZ', $messages);
    }

    public function test_dates_in_odd_shapes_and_zero_balances_are_read_defensively(): void
    {
        $customers = [ReportWorkbooks::customer(1, ['last_read_date' => null, 'balance' => '1,234.50', 'last_bill_amount' => '0', 'mobile' => '0241111111 / +233 20 222 3456', 'connect_date' => null])];
        $batch = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $customers, 'balance' => 1234.5]]]);

        $row = $this->customerRow(1);
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->status, json_encode($batch->errors));
        $this->assertSame(123450, (int) $row->balance);
        $this->assertNull($row->last_read_date);
        $this->assertNull($row->connect_date);
        $this->assertSame('0241111111,0202223456', DB::table('commercial_customer_contacts')->where('customer_id', $row->id)->value('mobiles'));
    }

    public function test_an_unknown_district_parks_the_batch_before_any_customer_is_read(): void
    {
        $batch = $this->loadCustomers($this->simpleSpec(1, 3, ['district' => 'NOWHERE']));

        $this->assertSame(CommercialCustomerBatch::STATUS_NEEDS_MATCH, $batch->status);
        $this->assertSame(0, DB::table('commercial_customer_staging')->count(), 'no customer row was staged');
        $this->assertSame(0, $this->customerCount());

        // Matching it (the alias is remembered) lets the same batch carry on.
        app(\App\Services\Commercial\LocationMatcher::class)->saveDistrictAlias('NOWHERE', $this->sowutuom);
        app(\App\Services\Commercial\Customers\CustomerImportService::class)->resumeAfterMatch($batch);
        $done = app(\App\Services\Commercial\Customers\CustomerImportService::class)->process($batch->fresh());

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $done->status, json_encode($done->errors));
        $this->assertSame(3, $this->customerCount($this->sowutuom->id));
    }

    public function test_a_file_older_than_the_data_already_loaded_is_blocked(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 3), '2026-11-05');
        $other = $this->customers(1, 3);
        $other[0] = ReportWorkbooks::customer(1, ['balance' => 3.0]);
        $older = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $other]]], '2026-10-05');

        $this->assertSame(CommercialCustomerBatch::STATUS_BLOCKED, $older->status);
        $this->assertStringContainsString('newer file', $older->errors[0]['message']);
    }

    public function test_a_workbook_without_a_customer_sheet_is_refused_at_upload(): void
    {
        $path = ReportWorkbooks::customerList($this->simpleSpec(1, 2) + ['sheet' => 'Sheet9']);
        $this->workbooks[] = $path;
        $imports = app(\App\Services\Commercial\Customers\CustomerImportService::class);

        // A single non-"Document map" sheet is accepted whatever it is called; two unrelated ones are not.
        ['batch' => $batch] = $imports->register($path, 'x.xlsx', 'monthly', \Illuminate\Support\Carbon::parse('2026-10-05'), null);
        $this->assertSame(CommercialCustomerBatch::STATUS_QUEUED, $batch->status);

        $this->expectException(\App\Services\Commercial\Customers\CustomerImportException::class);
        $imports->register(__FILE__, 'x.xlsx', 'monthly', \Illuminate\Support\Carbon::parse('2026-10-05'), null);
    }
}
