<?php

namespace Tests\Feature\Commercial\Customers;

use App\Models\AuditLog;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerRecordBuilder;
use App\Services\Commercial\Customers\CustomerValues;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8b: about 5% of customers have TWO e-mail addresses in one cell. Handled like the mobile cell (6b): split, trim, validate each,
 * the first is the primary, the second is kept (lower-cased, indexed) and found by the search and the shared-address list; a piece that
 * holds an @ but is not an address flags INVALID_EMAIL and the good ones are kept. Invented addresses only (…@example.test).
 */
class CustomerEmailListTest extends CustomerTestCase
{
    protected function c(int $n, ?string $email): array
    {
        return ReportWorkbooks::customer($n, ['email' => $email]);
    }

    protected function spec(array $customers): array
    {
        return ['routes' => [['name' => '1001', 'customers' => $customers]]];
    }

    protected function lists(): CustomerListService
    {
        return app(CustomerListService::class);
    }

    /** @return list<int> */
    protected function numbers(array $page): array
    {
        return collect($page['rows'])->pluck('account_no')->map(fn ($a) => (int) ltrim($a, '0') - 100000000000)->sort()->values()->all();
    }

    // ---------------------------------------------------------------- the cell

    public function test_one_address_is_the_primary_and_there_is_no_second(): void
    {
        $read = CustomerValues::readEmails('Ama.Mensah@Example.test');

        $this->assertSame(['Ama.Mensah@Example.test'], $read['emails']);
        $this->assertFalse($read['invalid']);
        $this->assertSame(0, $read['extra']);
    }

    public function test_two_addresses_joined_by_a_slash_are_split_the_first_is_primary(): void
    {
        $read = CustomerValues::readEmails('first@example.test / second@example.test');

        $this->assertSame(['first@example.test', 'second@example.test'], $read['emails']);
        $this->assertFalse($read['invalid']);
    }

    public function test_every_separator_of_the_mobile_cell_splits_an_email_cell_too(): void
    {
        $cells = [
            'slash' => 'one@example.test/two@example.test', 'slash with spaces' => 'one@example.test / two@example.test', 'comma' => 'one@example.test, two@example.test',
            'semicolon' => 'one@example.test;two@example.test', 'bar' => 'one@example.test | two@example.test', 'ampersand' => 'one@example.test & two@example.test',
            'line break' => "one@example.test\ntwo@example.test", 'windows line break' => "one@example.test\r\ntwo@example.test",
        ];

        foreach ($cells as $name => $cell) {
            $this->assertSame(['one@example.test', 'two@example.test'], CustomerValues::readEmails($cell)['emails'], $name);
        }
    }

    public function test_an_invalid_piece_flags_the_cell_and_the_good_ones_are_kept(): void
    {
        $second = CustomerValues::readEmails('good@example.test / broken@@example');
        $this->assertSame(['good@example.test'], $second['emails'], 'the good address is kept');
        $this->assertTrue($second['invalid']);

        $first = CustomerValues::readEmails('broken@ / good@example.test');
        $this->assertSame(['good@example.test'], $first['emails'], 'an invalid FIRST piece does not stop the second becoming the primary');
        $this->assertTrue($first['invalid']);

        $none = CustomerValues::readEmails('only@broken');
        $this->assertSame([], $none['emails']);
        $this->assertTrue($none['invalid']);
    }

    public function test_a_piece_with_no_at_sign_is_not_an_address_and_is_ignored_as_before(): void
    {
        foreach (['N/A', 'none', '-', 'NIL', '', null, '   '] as $cell) {
            $read = CustomerValues::readEmails($cell);
            $this->assertSame([], $read['emails'], (string) $cell);
            $this->assertFalse($read['invalid'], 'not an attempt at an address: no INVALID_EMAIL for '.var_export($cell, true));
        }
    }

    public function test_the_same_address_twice_is_one_and_a_third_is_dropped_and_counted(): void
    {
        $this->assertSame(['a@example.test'], CustomerValues::readEmails('a@example.test / A@EXAMPLE.TEST')['emails']);

        $read = CustomerValues::readEmails('a@example.test / b@example.test / c@example.test / d@example.test');
        $this->assertSame(['a@example.test', 'b@example.test'], $read['emails']);
        $this->assertSame(2, $read['extra']);
    }

    // ---------------------------------------------------------------- stored, searched, shared

    public function test_the_second_address_is_stored_lower_cased_in_its_own_column_and_the_flags_follow(): void
    {
        $this->loadCustomers($this->spec([
            $this->c(1, 'First.One@example.test / Second.One@Example.Test'),
            $this->c(2, 'single@example.test'),
            $this->c(3, 'fine@example.test / broken@@x'),
            $this->c(4, 'N/A'),
            $this->c(5, 'broken@'),
        ]));
        $contact = fn (int $n) => DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId($n))->first();

        $one = $contact(1);
        $this->assertSame('First.One@example.test', $one->email, 'the first is what is shown and used');
        $this->assertSame('first.one@example.test', $one->email_lower);
        $this->assertSame('second.one@example.test', $one->email_secondary);
        $this->assertNull($contact(2)->email_secondary);

        $this->assertSame(0, $contact(1)->quality_flags & CustomerRecordBuilder::INVALID_EMAIL);
        $this->assertSame(CustomerRecordBuilder::INVALID_EMAIL, $contact(3)->quality_flags & CustomerRecordBuilder::INVALID_EMAIL, 'a bad piece flags the cell');
        $this->assertSame('fine@example.test', $contact(3)->email, 'and the good one is kept');
        $this->assertSame(0, $contact(4)->quality_flags & CustomerRecordBuilder::INVALID_EMAIL, 'N/A is simply no e-mail');
        $this->assertSame(CustomerRecordBuilder::MISSING_EMAIL, $contact(4)->quality_flags & CustomerRecordBuilder::MISSING_EMAIL);
        $this->assertSame(CustomerRecordBuilder::INVALID_EMAIL | CustomerRecordBuilder::MISSING_EMAIL, $contact(5)->quality_flags & (CustomerRecordBuilder::INVALID_EMAIL | CustomerRecordBuilder::MISSING_EMAIL), 'no usable address at all');
    }

    public function test_search_finds_a_customer_by_either_address_in_any_case(): void
    {
        $this->loadCustomers($this->spec([$this->c(1, 'first@example.test / Second@Example.test'), $this->c(2, 'other@example.test')]));
        $search = fn (string $value) => $this->numbers($this->lists()->page(['details' => true, 'search' => ['type' => 'email', 'value' => $value]]));

        $this->assertSame([1], $search('first@example.test'));
        $this->assertSame([1], $search('second@example.test'), 'the second address');
        $this->assertSame([1], $search('SECOND@EXAMPLE.TEST'));
        $this->assertSame([2], $search('other@example.test'));
        $this->assertSame([], $search('nobody@example.test'));
    }

    public function test_an_address_on_two_accounts_in_either_position_is_shared_and_one_account_listing_it_twice_is_not(): void
    {
        $batch = $this->loadCustomers($this->spec([
            $this->c(1, 'a@example.test / shared@example.test'),   // shared is its SECOND address
            $this->c(2, 'shared@example.test'),                      // and the FIRST of this one
            $this->c(3, 'twice@example.test / twice@example.test'), // the same address twice on one account: one address, not shared
            $this->c(4, 'lonely@example.test'),
        ]));

        $accounts = fn (string $issue) => $this->numbers($this->lists()->page(['district_id' => $this->sowutuom->id, 'issue' => $issue, 'batch_id' => $batch->id, 'size' => 50, 'as_of' => '2026-10-05']));

        $this->assertSame([1, 2], $accounts('shared_email'));
        $this->assertSame(2, (int) DB::table('commercial_customer_quality')->where('batch_id', $batch->id)->where('issue', 'shared_email')->value('issue_count'));
    }

    public function test_the_quality_tab_counts_and_lists_accounts_with_an_invalid_address(): void
    {
        $batch = $this->loadCustomers($this->spec([$this->c(1, 'ok@example.test'), $this->c(2, 'ok2@example.test / broken@@x'), $this->c(3, 'broken@')]));

        $this->assertSame(2, (int) DB::table('commercial_customer_quality')->where('batch_id', $batch->id)->where('issue', 'invalid_email')->value('issue_count'));
        $this->assertSame([2, 3], $this->numbers($this->lists()->page(['district_id' => $this->sowutuom->id, 'issue' => 'invalid_email', 'batch_id' => $batch->id, 'size' => 50, 'as_of' => '2026-10-05'])));
        $this->assertArrayHasKey('invalid_email', \App\Services\Commercial\Customers\CustomerAnalyticsService::QUALITY_LABELS);
    }

    public function test_a_customer_without_a_second_address_keeps_the_contact_hash_it_always_had(): void
    {
        $builder = new CustomerRecordBuilder(new \App\Services\Commercial\Customers\CustomerLookups, $this->accraWest->id, $this->sowutuom->id, \Illuminate\Support\Carbon::parse('2026-10-05'));
        $values = ['account' => '100000000001', 'name' => 'Customer 000001', 'address' => 'House 1', 'mobile' => '0241234567', 'email' => 'one@example.test', 'balance' => 10.0, 'category' => '611', 'status' => 'ACTB', 'meter_status' => 'W'];

        $row = $builder->build($values, '1001');
        $flags = (int) $row['quality_flags'] & CustomerRecordBuilder::CONTACT_BITS;

        // exactly the hash of the fields it always covered: name, address, mobiles, e-mail and the contact flags
        $this->assertSame(CustomerRecordBuilder::hash(['Customer 000001', 'House 1', '0241234567', 'one@example.test', $flags]), $row['contact_hash'], 'no mass rewrite of the contact rows of existing customers');

        $two = $builder->build(['email' => 'one@example.test / two@example.test'] + $values, '1001');
        $this->assertNotSame($row['contact_hash'], $two['contact_hash'], 'a customer who now has a second address is rewritten, and only that one');
    }

    public function test_no_address_reaches_a_log_audit_row_warning_or_error(): void
    {
        $batch = $this->loadCustomers($this->spec([$this->c(1, 'secret.person@example.test / second.secret@example.test'), $this->c(2, 'bad.piece@@example.test')]));

        $text = json_encode([$batch->toArray(), AuditLog::query()->get()->toArray()]);
        $this->assertStringNotContainsString('secret.person', $text);
        $this->assertStringNotContainsString('second.secret', $text);
        $this->assertStringNotContainsString('bad.piece', $text);
        $this->assertStringContainsString('E-mail cells: 1 hold two addresses', collect($batch->warnings)->pluck('message')->implode(' '), 'counts only');
    }

    public function test_the_single_customer_view_shows_both_addresses_masked_until_revealed(): void
    {
        $this->loadCustomers($this->spec([$this->c(1, 'first.person@example.test / second.person@example.test')]));
        $id = $this->customerId(1);

        $masked = $this->lists()->contact($id, false);
        $this->assertSame('f***@example.test, s***@example.test', $masked['email']);

        $revealed = $this->lists()->contact($id, true);
        $this->assertSame('first.person@example.test, second.person@example.test', $revealed['email']);
    }
}
