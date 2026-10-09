<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Customers\Lists;
use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerImportException;
use App\Services\Commercial\Customers\CustomerListService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/** The drill-down lists: keyset paging, filters, search and the data-quality account lists. */
class CustomerListTest extends CustomerTestCase
{
    protected function lists(): CustomerListService
    {
        return app(CustomerListService::class);
    }

    /** @return list<int> every id in the order a sort gives them, read page by page */
    protected function walk(array $q, int $size = 10): array
    {
        $ids = [];
        $after = null;
        $guard = 0;

        do {
            $page = $this->lists()->page($q + ['after' => $after, 'size' => $size]);
            array_push($ids, ...array_column($page['rows'], 'id'));
            $after = $page['next'];
        } while ($after !== null && ++$guard < 50);

        return $ids;
    }

    public function test_balance_pages_follow_the_index_order_with_no_duplicates_or_gaps_even_with_ties(): void
    {
        // 35 customers over two routes; many equal balances.
        $a = array_map(fn (int $n) => ReportWorkbooks::customer($n, ['balance' => 100 + ($n % 5)]), range(1, 20));
        $b = array_map(fn (int $n) => ReportWorkbooks::customer($n, ['balance' => 100 + ($n % 5)]), range(21, 35));
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => $a], ['name' => '1002', 'customers' => $b]]]);

        $expectedDesc = DB::table('commercial_customers')->orderByDesc('balance')->orderByDesc('id')->pluck('id')->all();
        $expectedAsc = DB::table('commercial_customers')->orderBy('balance')->orderBy('id')->pluck('id')->all();
        $expectedRoute = DB::table('commercial_customers')->orderBy('route_id')->orderBy('id')->pluck('id')->all();

        $this->assertSame($expectedDesc, $this->walk(['district_id' => $this->sowutuom->id, 'sort' => 'balance_desc']));
        $this->assertSame($expectedAsc, $this->walk(['district_id' => $this->sowutuom->id, 'sort' => 'balance_asc']));
        $this->assertSame($expectedRoute, $this->walk(['district_id' => $this->sowutuom->id, 'sort' => 'route']));
        $this->assertCount(35, array_unique($this->walk(['district_id' => $this->sowutuom->id, 'sort' => 'balance_desc'], 10)));
    }

    public function test_a_row_added_while_paging_neither_repeats_nor_skips_the_rest(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 35));
        $q = ['district_id' => $this->sowutuom->id, 'sort' => 'balance_desc'];

        $first = $this->lists()->page($q + ['size' => 10]);
        $remaining = array_slice(DB::table('commercial_customers')->orderByDesc('balance')->orderByDesc('id')->pluck('id')->all(), 10);

        // a customer that would sort BEFORE the cursor arrives between the pages
        DB::table('commercial_customers')->insert(array_merge((array) DB::table('commercial_customers')->where('id', $this->customerId(5))->first(), ['id' => 9999, 'account_no' => '999999999999', 'balance' => 99999999]));

        $rest = [];
        $after = $first['next'];

        do {
            $page = $this->lists()->page($q + ['size' => 10, 'after' => $after]);
            array_push($rest, ...array_column($page['rows'], 'id'));
            $after = $page['next'];
        } while ($after !== null);

        $this->assertSame($remaining, $rest, 'the pages after the cursor are exactly what they were');
        $this->assertNotContains(9999, $rest);
    }

    public function test_filters_narrow_the_list(): void
    {
        $c = fn (int $n, array $o = []) => ReportWorkbooks::customer($n, $o);
        $this->loadCustomers(['routes' => [
            ['name' => '1001', 'customers' => [$c(1), $c(2, ['status' => 'DISC']), $c(3, ['category' => '612']), $c(4, ['balance' => 0])]],
            ['name' => '1002', 'customers' => [$c(5, ['meter_status' => 'F']), $c(6, ['category' => '601'])]],
        ]]);
        $q = ['district_id' => $this->sowutuom->id, 'size' => 50];
        $accounts = fn (array $extra) => collect($this->lists()->page($q + $extra)['rows'])->pluck('account_no')->map(fn ($a) => (int) ltrim($a, '0') - 100000000000)->sort()->values()->all();

        $disc = DB::table('commercial_customer_statuses')->where('code', 'DISC')->value('id');
        $faulty = DB::table('commercial_meter_statuses')->where('code', 'F')->value('id');
        $route2 = DB::table('commercial_routes')->where('name', '1002')->value('id');

        $this->assertSame([1, 2, 3, 4, 5, 6], $accounts([]));
        $this->assertSame([2], $accounts(['status_id' => $disc]));
        $this->assertSame([5], $accounts(['meter_status_id' => $faulty]));
        $this->assertSame([5, 6], $accounts(['route_id' => $route2]));
        $this->assertSame([3], $accounts(['group' => 'commercial']));
        $this->assertSame([6], $accounts(['group' => 'bulk_tanker']));
        $this->assertSame([4], $accounts(['bucket' => 1]), 'a nil balance');
        $this->assertSame([], $accounts(['group' => 'standpipe']));
    }

    public function test_search_by_account_meter_and_for_a_detailer_phone_email_and_name(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 8));
        $number = fn (array $rows) => array_column($rows, 'account_no');

        $this->assertSame([$this->customerRow(3)->account_no], $number($this->lists()->page(['search' => ['type' => 'account', 'value' => '1000 0000 0003']])['rows']), 'spaces in a pasted account number are ignored');
        $this->assertSame([$this->customerRow(4)->account_no], $number($this->lists()->page(['search' => ['type' => 'meter', 'value' => 'M0000004']])['rows']));
        $this->assertSame([], $this->lists()->page(['search' => ['type' => 'account', 'value' => '100000000055']])['rows']);

        $d = ['details' => true];
        $this->assertSame([$this->customerRow(5)->account_no], $number($this->lists()->page($d + ['search' => ['type' => 'phone', 'value' => '024 000 0005']])['rows']));
        $this->assertSame([$this->customerRow(6)->account_no], $number($this->lists()->page($d + ['search' => ['type' => 'email', 'value' => 'C6@example.TEST']])['rows']));
        $this->assertCount(8, $this->lists()->page($d + ['search' => ['type' => 'name', 'value' => 'customer 0000']])['rows']);
        $this->assertSame([$this->customerRow(2)->account_no], $number($this->lists()->page($d + ['search' => ['type' => 'name', 'value' => 'Customer 000002']])['rows']));

        $this->expectException(CustomerImportException::class);
        $this->lists()->page(['search' => ['type' => 'phone', 'value' => '0240000005']]);   // no details permission
    }

    public function test_the_data_quality_lists_hold_exactly_the_accounts_behind_each_count(): void
    {
        $c = fn (int $n, array $o = []) => ReportWorkbooks::customer($n, $o);
        $batch = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [
            $c(1),
            $c(2, ['mobile' => null]),
            $c(3, ['mobile' => '12345']),
            $c(4, ['email' => null, 'address' => null]),
            $c(5, ['meter_no' => 'SHARED']),
            $c(6, ['meter_no' => 'SHARED']),
            $c(7, ['mobile' => '0249999999']),
            $c(8, ['mobile' => '0249999999', 'email' => null]),
            $c(9, ['last_bill_date' => '2027-01-01']),
            $c(10, ['category' => null]),
        ]]]]);

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->status, json_encode($batch->errors));
        $accounts = fn (string $issue) => collect($this->lists()->page(['district_id' => $this->sowutuom->id, 'issue' => $issue, 'batch_id' => $batch->id, 'size' => 50, 'as_of' => '2026-10-05'])['rows'])
            ->pluck('account_no')->map(fn ($a) => (int) ltrim($a, '0') - 100000000000)->sort()->values()->all();

        $this->assertSame([2, 3], $accounts('missing_mobile'), 'none, or only an invalid number');
        $this->assertSame([3], $accounts('invalid_phone'));
        $this->assertSame([4, 8], $accounts('missing_email'));
        $this->assertSame([4], $accounts('missing_address'));
        $this->assertSame([5, 6], $accounts('shared_meter'));
        $this->assertSame([7, 8], $accounts('shared_mobile'));
        $this->assertSame([9], $accounts('future_date'));
        $this->assertSame([10], $accounts('unknown_category'));
        $this->assertSame([], $accounts('shared_email'));
        $this->assertSame([], $accounts('not_a_real_issue'));

        // and the counts on the analysis page are those lists' sizes
        $counts = DB::table('commercial_customer_quality')->where('batch_id', $batch->id)->pluck('issue_count', 'issue');
        foreach (['missing_mobile' => 2, 'invalid_phone' => 1, 'missing_email' => 2, 'shared_meter' => 2, 'shared_mobile' => 2, 'future_date' => 1, 'unknown_category' => 1] as $issue => $count) {
            $this->assertSame($count, (int) $counts[$issue], $issue);
        }
    }

    public function test_the_issue_lists_of_older_uploads_are_released_but_the_previous_one_is_kept_for_a_void(): void
    {
        $one = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [ReportWorkbooks::customer(1, ['mobile' => null]), ReportWorkbooks::customer(2)]]]], '2026-08-05');
        $two = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [ReportWorkbooks::customer(1, ['mobile' => null]), ReportWorkbooks::customer(2, ['balance' => 5])]]]], '2026-09-05');
        $three = $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [ReportWorkbooks::customer(1, ['mobile' => null]), ReportWorkbooks::customer(2, ['balance' => 6])]]]], '2026-10-05');

        $lists = fn (CommercialCustomerBatch $b) => DB::table('commercial_customer_issue_accounts')->where('batch_id', $b->id)->count();

        $this->assertSame(0, $lists($one), 'two uploads back: released');
        $this->assertGreaterThan(0, $lists($two), 'the previous upload is kept');
        $this->assertGreaterThan(0, $lists($three));
    }

    public function test_customers_missing_from_the_latest_file_have_their_own_list(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        $this->loadCustomers($this->simpleSpec(1, 3), '2026-11-05');

        $gone = $this->lists()->page(['district_id' => $this->sowutuom->id, 'missing' => true, 'size' => 50]);
        $here = $this->lists()->page(['district_id' => $this->sowutuom->id, 'size' => 50]);

        $this->assertCount(2, $gone['rows']);
        $this->assertTrue($gone['rows'][0]['missing']);
        $this->assertCount(3, $here['rows']);
    }

    public function test_the_list_screen_pages_forward_and_back(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 25));
        config(['gwl.commercial_customer_page_size' => 10]);
        $user = $this->analyst();

        $page = Livewire::actingAs($user)->test(Lists::class)->set('district', (string) $this->sowutuom->id);
        $first = array_column($page->viewData('page')['rows'], 'id');
        $this->assertCount(10, $first);

        $next = $page->viewData('page')['next'];
        $page->call('next', $next);
        $second = array_column($page->viewData('page')['rows'], 'id');
        $this->assertCount(10, $second);
        $this->assertSame([], array_intersect($first, $second));

        $page->call('previous');
        $this->assertSame($first, array_column($page->viewData('page')['rows'], 'id'));
    }
}
