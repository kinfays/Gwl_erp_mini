<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Customers\Lists;
use App\Livewire\Commercial\Customers\Show;
use App\Models\AuditLog;
use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use App\Services\Commercial\Customers\CustomerExportService;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerSnapshots;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Commercial\ReportWorkbooks;

/** Phase 6b: customers with more than one mobile number are found, listed, exported and counted by every number they have. */
class CustomerSecondPhoneTest extends CustomerTestCase
{
    // Invented numbers. Three different digits or more, so none is a placeholder.
    private const A = '0241234567';

    private const B = '0207654321';

    private const C = '0501234567';

    private const D = '0551234567';

    protected function c(int $n, ?string $mobile): array
    {
        return ReportWorkbooks::customer($n, ['mobile' => $mobile]);
    }

    protected function spec(array $customers): array
    {
        return ['routes' => [['name' => '1001', 'customers' => $customers]]];
    }

    protected function lists(): CustomerListService
    {
        return app(CustomerListService::class);
    }

    /** @return list<int> customer numbers on a list, in order */
    protected function numbers(array $page): array
    {
        return collect($page['rows'])->pluck('account_no')->map(fn ($a) => (int) ltrim($a, '0') - 100000000000)->sort()->values()->all();
    }

    protected function filters(?int $restriction = null)
    {
        $snapshots = app(CustomerSnapshots::class);

        return $snapshots->filters($snapshots->current($restriction), $restriction);
    }

    public function test_the_second_number_is_stored_in_its_own_column_and_both_stay_in_the_list_of_numbers(): void
    {
        $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::B), $this->c(2, self::A)]));

        $one = DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId(1))->first();
        $this->assertSame(self::A, $one->phone_primary);
        $this->assertSame(self::B, $one->phone_secondary);
        $this->assertSame(self::A.','.self::B, $one->mobiles);
        $this->assertNull(DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId(2))->value('phone_secondary'));
    }

    public function test_search_finds_a_customer_by_either_number_and_never_by_a_placeholder(): void
    {
        $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::B), $this->c(2, self::C), $this->c(3, '0000000000')]));
        $search = fn (string $value) => $this->numbers($this->lists()->page(['details' => true, 'search' => ['type' => 'phone', 'value' => $value]]));

        $this->assertSame([1], $search(self::A), 'first number');
        $this->assertSame([1], $search(self::B), 'second number');
        $this->assertSame([1], $search('233 20 765 4321'), 'a pasted international form of the second number');
        $this->assertSame([2], $search(self::C));
        $this->assertSame([], $search('0000000000'), 'a placeholder matches nothing');
        $this->assertSame([], $search('0599999999'));
        $this->assertSame([], $search('not a number'));

        $this->expectException(\App\Services\Commercial\Customers\CustomerImportException::class);
        $this->lists()->page(['search' => ['type' => 'phone', 'value' => self::B]]);   // still needs the details permission
    }

    public function test_a_number_on_two_accounts_in_either_position_is_shared_and_one_listed_twice_is_not(): void
    {
        $batch = $this->loadCustomers($this->spec([
            $this->c(1, self::A.' / '.self::B),   // B is its SECOND number
            $this->c(2, self::B),                  // and the FIRST of this one
            $this->c(3, self::C.' / '.self::C),   // the same number twice on one account: not shared
            $this->c(4, self::D),
            $this->c(5, self::D.' / '.self::C),   // D shared with 4, C shared with 3
        ]));

        $accounts = fn (string $issue) => $this->numbers($this->lists()->page(['district_id' => $this->sowutuom->id, 'issue' => $issue, 'batch_id' => $batch->id, 'size' => 50, 'as_of' => '2026-10-05']));

        $this->assertSame([1, 2, 3, 4, 5], $accounts('shared_mobile'), '1+2 share B, 3+5 share C, 4+5 share D');

        $lone = $this->loadCustomers($this->spec([$this->c(11, self::C.' / '.self::C), $this->c(12, self::D)]), '2026-11-05');
        $this->assertSame([], $this->numbers($this->lists()->page(['district_id' => $this->sowutuom->id, 'issue' => 'shared_mobile', 'batch_id' => $lone->id, 'size' => 50, 'as_of' => '2026-11-05'])), 'one account listing a number twice is not sharing it');
    }

    public function test_placeholders_are_never_shared_and_set_the_invalid_flag(): void
    {
        $batch = $this->loadCustomers($this->spec([$this->c(1, '0000000000'), $this->c(2, '0000000000'), $this->c(3, self::A)]));

        $this->assertNull(DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId(1))->value('phone_primary'));
        $this->assertSame(0, (int) DB::table('commercial_customer_quality')->where('batch_id', $batch->id)->where('issue', 'shared_mobile')->value('issue_count'));
        $this->assertSame(2, (int) DB::table('commercial_customer_quality')->where('batch_id', $batch->id)->where('issue', 'invalid_phone')->value('issue_count'));
        $this->assertSame(2, (int) DB::table('commercial_customer_quality')->where('batch_id', $batch->id)->where('issue', 'missing_mobile')->value('issue_count'));
    }

    public function test_changing_only_the_second_number_rewrites_only_the_contact(): void
    {
        $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::B), $this->c(2, self::C)]));
        $customers = DB::table('commercial_customers')->orderBy('id')->get()->toJson();
        $changes = DB::table('commercial_customer_changes')->count();

        $second = $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::D), $this->c(2, self::C)]), '2026-11-05');

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $second->status, json_encode($second->errors));
        $this->assertSame(self::D, DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId(1))->value('phone_secondary'));
        $this->assertSame(0, $second->rows_changed, 'no customer row changed');
        $this->assertSame(2, $second->rows_unchanged);
        $this->assertSame($customers, DB::table('commercial_customers')->orderBy('id')->get()->toJson(), 'the customers table was not touched');
        $this->assertSame($changes, DB::table('commercial_customer_changes')->count(), 'the change log holds no personal data and wrote nothing');
        $this->assertSame(0, DB::table('commercial_customer_undo')->where('batch_id', $second->id)->count());

        // and an identical file again does zero writes of any kind
        $contacts = DB::table('commercial_customer_contacts')->orderBy('customer_id')->get()->toJson();
        $third = $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::D), $this->c(2, self::C)]), '2026-12-05');
        $this->assertSame(0, $third->rows_changed + $third->rows_new);
        $this->assertSame($contacts, DB::table('commercial_customer_contacts')->orderBy('customer_id')->get()->toJson(), 'not even a contact row was rewritten (updated_batch_id included)');
        $this->assertSame($customers, DB::table('commercial_customers')->orderBy('id')->get()->toJson());
    }

    public function test_lists_show_the_first_number_masked_with_a_plus_badge(): void
    {
        $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::B.' / '.self::C), $this->c(2, self::D)]));

        $rows = collect($this->lists()->page(['district_id' => $this->sowutuom->id, 'details' => true, 'size' => 10])['rows'])->keyBy(fn ($r) => (int) ltrim($r['account_no'], '0') - 100000000000);
        $this->assertSame('024****567', $rows[1]['mobile']);
        $this->assertSame(2, $rows[1]['more_phones']);
        $this->assertSame(0, $rows[2]['more_phones']);

        $page = Livewire::actingAs($this->detailer())->test(Lists::class)->set('district', (string) $this->sowutuom->id);
        $page->assertSee('024****567')->assertSee('+2')->assertDontSee(self::A)->assertDontSee(self::B);

        $plain = collect($this->lists()->page(['district_id' => $this->sowutuom->id, 'size' => 10])['rows']);
        $this->assertNull($plain[0]['mobile'], 'no numbers at all without the details permission');
        $this->assertSame(0, $plain[0]['more_phones']);
    }

    public function test_the_export_cell_has_every_number_masked_as_text(): void
    {
        $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::B), $this->c(2, self::C)]));

        $response = $this->actingAs($this->detailer())->get(route('commercial.customers.export', ['report' => 'list', 'format' => 'excel', 'district' => $this->sowutuom->id]))->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);

        $column = array_search('Mobile (masked)', $sheet->toArray(null, false, false, false)[0], true);
        $this->assertNotFalse($column);
        $cell = $sheet->getCell([$column + 1, 2]);

        $this->assertSame('024****567 / 020****321', (string) $cell->getValue());
        $this->assertContains($cell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE]);
        $this->assertSame('050****567', (string) $sheet->getCell([$column + 1, 3])->getValue());

        $text = json_encode($sheet->toArray());
        foreach ([self::A, self::B, self::C] as $number) {
            $this->assertStringNotContainsString($number, $text);
        }

        // the aggregate summary still carries no phone data
        $summary = $this->actingAs($this->analyst())->get(route('commercial.customers.export', ['report' => 'summary', 'format' => 'excel']))->assertOk();
        $this->assertStringNotContainsString('****', json_encode(IOFactory::load($summary->baseResponse->getFile()->getPathname())->getSheet(0)->toArray()));
    }

    public function test_the_two_numbers_and_reachable_counts_equal_their_lists_and_are_scoped_by_region(): void
    {
        $this->loadCustomers($this->spec([
            $this->c(1, self::A.' / '.self::B), $this->c(2, self::C.' / '.self::D), $this->c(3, '0591234567'), $this->c(4, null), $this->c(5, '12345'),
        ]));
        $this->loadCustomers($this->simpleSpec(101, 103, ['region' => 'ASHANTI', 'district' => 'KUMASI'], '2001'));

        $batch = CommercialCustomerBatch::query()->where('district_id', $this->sowutuom->id)->first();
        $counts = DB::table('commercial_customer_quality')->where('batch_id', $batch->id)->pluck('issue_count', 'issue');

        $two = $this->numbers($this->lists()->page(['district_id' => $this->sowutuom->id, 'issue' => 'multiple_phones', 'batch_id' => $batch->id, 'size' => 50, 'as_of' => '2026-10-05']));
        $noMobile = $this->numbers($this->lists()->page(['district_id' => $this->sowutuom->id, 'issue' => 'missing_mobile', 'batch_id' => $batch->id, 'size' => 50, 'as_of' => '2026-10-05']));

        $this->assertSame([1, 2], $two);
        $this->assertSame(count($two), (int) $counts['multiple_phones']);
        $this->assertSame(5 - count($noMobile), (int) $counts['reachable'], 'reachable = customers - those without a valid number');
        $this->assertSame(3, (int) $counts['reachable']);

        // the screen's figures: the whole company for an unrestricted user, only one's own region otherwise
        $quality = fn (?int $restriction) => collect(app(CustomerAnalyticsService::class)->quality($this->filters($restriction))['issues'])->pluck('count', 'issue');
        $this->assertSame(2, $quality(null)['multiple_phones']);
        $this->assertSame(3 + 3, $quality(null)['reachable'], 'Sowutuom 3 + Kumasi 3');
        $this->assertSame(3, $quality($this->accraWest->id)['reachable']);
        $this->assertSame(3, $quality($this->ashanti->id)['reachable']);
        $this->assertSame(0, $quality($this->ashanti->id)['multiple_phones'], 'another region\'s two-number customers are not counted');

        $page = Livewire::actingAs($this->analyst('900050', $this->accraWest, $this->sowutuom))->test(\App\Livewire\Commercial\Customers\Dashboard::class)->set('tab', 'quality');
        $page->assertSee('Two or more mobile numbers')->assertSee('Reachable');
    }

    public function test_purge_retention_removes_the_second_number_with_the_contact_and_a_void_does_not_roll_it_back(): void
    {
        $first = $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::B), $this->c(2, self::C)]));
        $second = $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::D), $this->c(2, self::C), $this->c(3, self::C.' / '.self::B)]), '2026-11-05');

        app(CustomerBatchLifecycle::class)->void($second, $this->superAdmin(), 'wrong file');
        $this->assertSame(self::D, DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId(1))->value('phone_secondary'), 'contact details are not rolled back');
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $first->fresh()->status);

        // retention: an account missing for long enough loses its contact row, second number included
        $third = $this->loadCustomers($this->spec([$this->c(2, self::C)]), '2026-12-05');
        $this->assertSame(1, $third->rows_missing);
        config(['gwl.commercial_customer_contact_retention_days' => 90]);
        $third->forceFill(['finished_at' => now()->subDays(120)])->save();
        Artisan::call('commercial:customers:purge');

        $this->assertSame(0, DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId(1))->count());
        $this->assertSame(0, DB::table('commercial_customer_contacts')->whereNotNull('phone_secondary')->where('customer_id', $this->customerId(1))->count());
    }

    public function test_the_import_reports_how_the_mobile_cells_looked_in_counts_only(): void
    {
        $batch = $this->loadCustomers($this->spec([
            $this->c(1, self::A.' / '.self::B), $this->c(2, '02412345670501234567'), $this->c(3, '0000000000'), $this->c(4, '2330551234567'), $this->c(5, self::C),
        ]));

        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $batch->status, json_encode($batch->errors));
        $message = collect($batch->warnings)->first(fn ($w) => ($w['row'] ?? '') === 'Mobile')['message'] ?? '';

        $this->assertStringContainsString('2 hold two or more numbers', $message);
        $this->assertStringContainsString('2 number(s) were separated', $message);
        $this->assertStringContainsString('1 had a stray 0', $message);
        $this->assertStringContainsString('1 placeholder', $message);
        $this->assertSame(0, preg_match('/\d{7,}/', $message), 'no phone number in the message');
    }

    public function test_no_audit_row_warning_or_error_holds_a_phone_number(): void
    {
        $batch = $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::B), $this->c(2, '0000000000'), $this->c(3, '12345 / '.self::C)]));
        Livewire::actingAs($this->detailer())->test(Show::class, ['customer' => $this->customerId(1)])->call('reveal');

        $audit = AuditLog::query()->get()->map(fn ($row) => json_encode($row->toArray()))->implode("\n");
        $batchText = json_encode([$batch->fresh()->warnings, $batch->fresh()->errors, $batch->fresh()->error_message]);

        foreach ([self::A, self::B, self::C, '241234567', '207654321', '501234567'] as $needle) {
            $this->assertStringNotContainsString($needle, $audit, 'an audit row holds a phone number');
            $this->assertStringNotContainsString($needle, $batchText, 'a batch message holds a phone number');
        }

        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.customer_contact_revealed')->count());
    }

    public function test_the_single_customer_view_still_shows_every_number_masked_until_revealed(): void
    {
        $this->loadCustomers($this->spec([$this->c(1, self::A.' / '.self::B)]));
        $page = Livewire::actingAs($this->detailer())->test(Show::class, ['customer' => $this->customerId(1)]);

        $page->assertSee('024****567')->assertSee('020****321')->assertDontSee(self::B);
        $page->call('reveal')->assertSee(self::A)->assertSee(self::B);
    }
}
