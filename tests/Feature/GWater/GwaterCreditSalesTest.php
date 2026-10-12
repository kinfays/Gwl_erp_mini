<?php

namespace Tests\Feature\GWater;

use App\Livewire\GWater\DailySales;
use App\Livewire\GWater\Overview;
use App\Models\AuditLog;
use App\Models\GwSale;
use App\Models\GwSaleReceipt;
use App\Models\GwStockMovement;
use App\Models\User;
use App\Services\GWater\GwaterExportService;
use App\Services\GWater\GwaterProfitService;
use App\Services\GWater\GwaterStockService;
use App\Services\GWater\GwaterSummaryService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Credit sales: a customer who pays later, the receipts that clear the debt, and where the debt shows. */
class GwaterCreditSalesTest extends GwaterTestCase
{
    private User $user;

    private array $place;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->gw('G1', $this->sowutuom);
        $this->place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];
        $this->trip($this->user, 'WB-1', 200, 0, '2026-10-01');   // the placeholder prices: bottle sells at 26.00
    }

    private function credit(string $customer, int $packs, string $date, array $extra = []): GwSale
    {
        return $this->workflow()->recordSale($this->user, $this->saleData($packs, $date) + ['payment_type' => 'credit', 'customer_name' => $customer] + $extra);
    }

    private function receive(GwSale $sale, int $amount, string $date, array $extra = []): GwSaleReceipt
    {
        return $this->workflow()->recordSaleReceipt($this->user, $sale, ['amount' => $amount, 'received_on' => $date] + $extra);
    }

    // ---------------------------------------------------------------- recording

    public function test_a_sale_is_cash_unless_it_is_put_on_credit_with_a_customer(): void
    {
        $cash = $this->workflow()->recordSale($this->user, $this->saleData(10, '2026-10-02') + ['customer_name' => 'Ignored Name']);
        $this->assertSame(['cash', null, null, 0], [$cash->payment_type, $cash->customer_name, $cash->customer_phone, $cash->balanceOwed()]);

        $credit = $this->credit('Mama Efua', 10, '2026-10-02', ['customer_phone' => '0244000000']);
        $this->assertSame(['credit', 'Mama Efua', '0244000000', 26000, 26000], [$credit->payment_type, $credit->customer_name, $credit->customer_phone, $credit->amount, $credit->balanceOwed()]);

        foreach ([['payment_type' => 'credit'], ['payment_type' => 'credit', 'customer_name' => ' '], ['payment_type' => 'credit', 'customer_name' => 'X'], ['payment_type' => 'later']] as $bad) {
            try {
                $this->workflow()->recordSale($this->user, $this->saleData(1, '2026-10-02') + $bad);
                $this->fail('Expected a refusal for '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame(2, GwSale::count());
            }
        }
    }

    public function test_a_credit_sale_moves_stock_and_counts_as_revenue_like_a_cash_sale(): void
    {
        $this->workflow()->recordSale($this->user, $this->saleData(10, '2026-10-02'));
        $this->credit('Mama Efua', 10, '2026-10-02');

        $this->assertSame(180, app(GwaterStockService::class)->balance($this->place, $this->bottle->id));
        $this->assertSame(2, GwStockMovement::query()->where('type', 'sale')->count());

        $combined = app(GwaterProfitService::class)->report($this->place, '2026-10-01', '2026-10-31', $this->user)['combined'];
        $this->assertSame([52000, 26000, 26000, 40000], [$combined['revenue'], $combined['credit_sales'], $combined['cash_collected'], $combined['cost_of_sales']]);
    }

    public function test_the_audit_row_names_the_kind_of_sale_but_never_the_customer(): void
    {
        $sale = $this->credit('Mama Efua', 10, '2026-10-02', ['customer_phone' => '0244000000']);
        $this->receive($sale, 5000, '2026-10-03');

        $rows = AuditLog::query()->whereIn('action', ['gwater.sale_recorded', 'gwater.sale_payment_received'])->get();
        $this->assertCount(2, $rows);
        $this->assertSame('credit', $rows->firstWhere('action', 'gwater.sale_recorded')->metadata['payment_type']);

        foreach ($rows as $row) {
            $text = json_encode($row->metadata).json_encode($row->old_values).json_encode($row->new_values);
            $this->assertStringNotContainsString('Mama', $text);
            $this->assertStringNotContainsString('0244', $text);
        }
    }

    // ---------------------------------------------------------------- receipts

    public function test_a_customer_pays_in_parts_until_the_sale_is_cleared(): void
    {
        $sale = $this->credit('Mama Efua', 10, '2026-10-02');   // GH¢ 260.00

        $first = $this->receive($sale, 10000, '2026-10-04', ['reference' => 'MM-1']);
        $this->assertSame([16000, 10000, 'MM-1'], [$sale->fresh()->balanceOwed(), $first->amount, $first->reference]);
        $this->assertSame([$this->accraWest->id, $this->sowutuom->id], [$first->region_id, $first->district_id]);

        $this->receive($sale, 6000, '2026-10-05');
        $this->assertSame(10000, $sale->fresh()->balanceOwed());

        $this->receive($sale, 10000, '2026-10-06');
        $this->assertSame([0, 26000], [$sale->fresh()->balanceOwed(), $sale->fresh()->receivedAmount()]);
        $this->assertSame(3, GwSaleReceipt::count());

        // Nothing left to collect.
        try {
            $this->receive($sale, 100, '2026-10-07');
            $this->fail('A cleared sale takes no more money.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('paid in full', $exception->errors()['amount'][0]);
        }
    }

    public function test_a_receipt_is_refused_when_it_is_more_than_owed_or_dated_wrongly_or_not_for_a_credit_sale(): void
    {
        $credit = $this->credit('Mama Efua', 10, '2026-10-05');
        $cash = $this->workflow()->recordSale($this->user, $this->saleData(5, '2026-10-05'));
        $this->receive($credit, 20000, '2026-10-06');

        $cases = [
            'more than is owed' => [$credit, 6001, '2026-10-07', 'amount', 'Only GH¢ 60.00 is still owed'],
            'nothing' => [$credit, 0, '2026-10-07', 'amount', 'Enter the amount'],
            'before the sale' => [$credit, 100, '2026-10-04', 'received_on', 'before the sale'],
            'in the future' => [$credit, 100, '2026-10-12', 'received_on', 'future'],
            'a cash sale' => [$cash, 100, '2026-10-07', 'amount', 'paid at once'],
        ];

        foreach ($cases as $name => [$sale, $amount, $date, $field, $message]) {
            try {
                $this->receive($sale, $amount, $date);
                $this->fail("{$name} should have been refused.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, $exception->errors()[$field][0], $name);
            }
        }

        $this->assertSame(1, GwSaleReceipt::count());
        $this->assertSame(6000, $credit->fresh()->balanceOwed());
    }

    public function test_a_wrong_receipt_is_voided_and_the_customer_owes_it_again(): void
    {
        $sale = $this->credit('Mama Efua', 10, '2026-10-02');
        $receipt = $this->receive($sale, 26000, '2026-10-03');
        $this->assertSame(0, $sale->fresh()->balanceOwed());

        try {
            $this->workflow()->voidSaleReceipt($receipt, $this->user, '  ');
            $this->fail('A reason is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->workflow()->voidSaleReceipt($receipt, $this->user, 'Entered against the wrong customer');
        $this->assertSame([26000, 'voided'], [$sale->fresh()->balanceOwed(), $receipt->fresh()->status]);
        $this->assertSame(1, GwSaleReceipt::count(), 'nothing is deleted');

        $this->expectException(ValidationException::class);
        $this->workflow()->voidSaleReceipt($receipt, $this->user, 'Again');
    }

    public function test_a_credit_sale_with_payments_on_it_is_voided_only_after_those_payments(): void
    {
        $sale = $this->credit('Mama Efua', 10, '2026-10-02');
        $receipt = $this->receive($sale, 5000, '2026-10-03');

        try {
            $this->workflow()->voidSale($sale, $this->user, 'Wrong customer');
            $this->fail('Money has been received on it.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Void those payments first', $exception->errors()['reason'][0]);
        }

        $this->assertSame(190, app(GwaterStockService::class)->balance($this->place, $this->bottle->id));

        $this->workflow()->voidSaleReceipt($receipt, $this->user, 'Wrong customer');
        $this->workflow()->voidSale($sale, $this->user, 'Wrong customer');

        $this->assertSame([200, 0], [app(GwaterStockService::class)->balance($this->place, $this->bottle->id), $sale->fresh()->balanceOwed()]);

        // A voided sale takes no payment.
        $this->expectException(ValidationException::class);
        $this->receive($sale, 100, '2026-10-04');
    }

    public function test_receipts_follow_the_location_and_the_permissions(): void
    {
        $sale = $this->credit('Mama Efua', 10, '2026-10-02');
        $receipt = $this->receive($sale, 5000, '2026-10-03');
        $kumasi = $this->gw('G2', $this->kumasi);
        $headOffice = $this->gw('G3', $this->headOffice);
        $colleague = $this->gw('G4', $this->odorkor);

        foreach ([$kumasi, $headOffice] as $stranger) {
            $this->actingAs($stranger);

            foreach ([
                fn () => $this->workflow()->recordSaleReceipt($stranger, $sale, ['amount' => 100, 'received_on' => '2026-10-04']),
                fn () => $this->workflow()->voidSaleReceipt($receipt, $stranger, 'Not mine'),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Another location\'s credit sale must not be found.');
                } catch (NotFoundHttpException) {
                    $this->assertSame(5000, $sale->fresh()->receivedAmount());
                }
            }
        }

        // A colleague of the same region collects too.
        $this->actingAs($colleague);
        $this->assertSame(1000, $this->workflow()->recordSaleReceipt($colleague, $sale, ['amount' => 1000, 'received_on' => '2026-10-04'])->amount);

        $plain = $this->person('E1', $this->sowutuom);
        $this->actingAs($plain);
        $this->expectException(HttpException::class);
        $this->workflow()->recordSaleReceipt($plain, $sale, ['amount' => 100, 'received_on' => '2026-10-04']);
    }

    // ---------------------------------------------------------------- what is owed

    /** Hand-worked: Mama owes 260.00 (paid 100.00) and 104.00; Kojo's 130.00 is paid in full on 6 Oct. */
    private function worked(): void
    {
        $mama = $this->credit('Mama Efua', 10, '2026-10-02');
        $kojo = $this->credit('Kojo Boateng', 5, '2026-10-03');
        $this->credit(' mama efua ', 4, '2026-10-05');
        $this->receive($mama, 10000, '2026-10-04');
        $this->receive($kojo, 13000, '2026-10-06');
        $this->workflow()->recordSale($this->user, $this->saleData(20, '2026-10-02'));   // 520.00 in cash
    }

    public function test_what_customers_owe_matches_a_hand_worked_account_now_and_on_an_earlier_day(): void
    {
        $this->worked();
        $summary = app(GwaterSummaryService::class);

        // Now: Mama 160.00 + 104.00 (one customer, however the name was typed); Kojo is cleared.
        $now = $summary->owedByCustomers($this->place);
        $this->assertSame([26400, 2, 1, 9], [$now['total'], $now['count'], $now['customers'], $now['oldest_days']]);

        $byCustomer = $summary->owingByCustomer($this->place);
        $this->assertCount(1, $byCustomer);
        $this->assertSame(['Mama Efua', 26400, 2], [$byCustomer[0]['customer'], $byCustomer[0]['owed'], $byCustomer[0]['sales']]);

        // On 5 October: 100.00 had come in from Mama, Kojo had not paid yet.
        $then = $summary->owedByCustomers($this->place, Carbon::parse('2026-10-05'));
        $this->assertSame([16000 + 10400 + 13000, 3, 2], [$then['total'], $then['count'], $then['customers']]);
    }

    public function test_the_profit_and_loss_shows_credit_sales_cash_collected_and_what_is_owed_without_changing_revenue(): void
    {
        $this->worked();
        $profit = app(GwaterProfitService::class);
        $combined = $profit->report($this->place, '2026-10-01', '2026-10-31', $this->user)['combined'];

        // Revenue counts every sale: 520 cash + 260 + 130 + 104 on credit.
        $this->assertSame([101400, 49400, 75000, 26400], [$combined['revenue'], $combined['credit_sales'], $combined['cash_collected'], $combined['owed_by_customers']]);

        // Money received outside the period is not collected in it; what is owed is as at the end of the period.
        $early = $profit->report($this->place, '2026-10-01', '2026-10-03', $this->user)['combined'];
        $this->assertSame([52000 + 26000 + 13000, 39000, 52000, 26000 + 13000], [$early['revenue'], $early['credit_sales'], $early['cash_collected'], $early['owed_by_customers']]);

        // The same sales all in cash give the same revenue, cost and net profit.
        $net = $combined['net_profit'];
        $this->assertSame($combined['gross_profit'], $combined['revenue'] - $combined['cost_of_sales']);
        $this->assertIsInt($net);
    }

    public function test_a_payment_shows_in_the_cached_profit_and_loss_at_once(): void
    {
        $this->worked();
        $profit = app(GwaterProfitService::class);
        $before = $profit->report($this->place, '2026-10-01', '2026-10-31', $this->user)['combined'];
        $this->assertSame($before, $profit->report($this->place, '2026-10-01', '2026-10-31', $this->user)['combined']);

        $mama = GwSale::query()->where('customer_name', 'Mama Efua')->orderBy('id')->first();
        $this->receive($mama, 16000, '2026-10-08');

        $after = $profit->report($this->place, '2026-10-01', '2026-10-31', $this->user)['combined'];
        $this->assertSame([$before['cash_collected'] + 16000, $before['owed_by_customers'] - 16000, $before['revenue']], [$after['cash_collected'], $after['owed_by_customers'], $after['revenue']]);
    }

    public function test_head_office_and_its_region_do_not_see_each_others_debts(): void
    {
        $this->worked();
        $headOffice = $this->gw('G3', $this->headOffice);
        $hoPlace = ['region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id];
        $this->trip($headOffice, 'HO-1', 50, 0, '2026-10-01');
        $this->workflow()->recordSale($headOffice, $this->saleData(2, '2026-10-02') + ['payment_type' => 'credit', 'customer_name' => 'Head Office Canteen']);
        $summary = app(GwaterSummaryService::class);

        $this->assertSame(26400, $summary->owedByCustomers($this->place)['total']);
        $this->assertSame(5200, $summary->owedByCustomers($hoPlace)['total']);
        $this->assertSame(['Head Office Canteen'], $summary->owingByCustomer($hoPlace)->pluck('customer')->all());
    }

    // ---------------------------------------------------------------- screens

    public function test_the_sales_screen_records_a_credit_sale_shows_the_credit_column_and_takes_payments(): void
    {
        $page = Livewire::actingAs($this->user)->test(DailySales::class)
            ->set('form.sale_date', '2026-10-05')
            ->set('form.packs', '10')
            ->set('form.payment_type', 'credit')
            ->call('save')->assertHasErrors(['form.customer_name'])
            ->set('form.customer_name', 'Mama Efua')
            ->set('form.customer_phone', '0244000000')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Credit (owed)')->assertSee('Mama Efua')->assertSee('Unpaid')->assertSee('260.00')->assertSee('Who owes');

        $sale = GwSale::query()->where('customer_name', 'Mama Efua')->firstOrFail();

        $page->call('openReceive', $sale->id)
            ->assertSet('receipt.amount', '260.00')
            ->set('receipt.amount', '100')->set('receipt.received_on', '2026-10-06')->set('receipt.reference', 'MM-9')
            ->call('receive')->assertHasNoErrors()
            ->assertSee('Part paid')->assertSee('160.00')->assertSee('MM-9');

        $page->call('openReceive', $sale->id)->set('receipt.amount', '999')->call('receive')->assertHasErrors(['receipt.amount']);
        $page->set('receipt.amount', '160')->call('receive')->assertHasNoErrors()->assertSee('Paid in full');
        $this->assertSame(0, $sale->fresh()->balanceOwed());

        $receipt = GwSaleReceipt::query()->firstOrFail();
        $page->call('openVoidReceipt', $receipt->id)->set('receiptVoidReason', 'Wrong customer')->call('confirmVoidReceipt')->assertHasNoErrors();
        $this->assertSame(10000, $sale->fresh()->balanceOwed());
    }

    public function test_the_sales_screen_can_show_only_credit_sales_and_only_those_still_owing(): void
    {
        $this->workflow()->recordSale($this->user, $this->saleData(1, '2026-10-05'));
        $paid = $this->credit('Paid Pat', 2, '2026-10-05');
        $this->receive($paid, $paid->amount, '2026-10-06');
        $this->credit('Owing Olu', 3, '2026-10-05');

        Livewire::actingAs($this->user)->test(DailySales::class)
            ->assertViewHas('sales', fn ($sales) => $sales->total() === 3)
            ->set('show', 'credit')->assertViewHas('sales', fn ($sales) => $sales->total() === 2)
            ->set('show', 'owing')->assertViewHas('sales', fn ($sales) => $sales->pluck('customer_name')->all() === ['Owing Olu']);
    }

    public function test_the_screen_never_offers_another_locations_credit_sale(): void
    {
        $sale = $this->credit('Mama Efua', 10, '2026-10-05');
        $kumasi = $this->gw('G2', $this->kumasi);

        Livewire::actingAs($kumasi)->test(DailySales::class)->assertDontSee('Mama Efua');
        Livewire::actingAs($kumasi)->test(DailySales::class)->call('openReceive', $sale->id)->assertNotFound();
    }

    public function test_the_overview_shows_what_customers_owe(): void
    {
        $this->credit('Mama Efua', 10, '2026-10-05');

        Livewire::actingAs($this->user)->test(Overview::class)
            ->assertSee('Owed by customers')
            ->assertViewHas('customersOwe', fn (array $owe) => $owe['total'] === 26000 && $owe['customers'] === 1);
    }

    // ---------------------------------------------------------------- exports and import

    public function test_the_receivables_export_lists_who_owes_and_the_profit_and_loss_export_carries_the_credit_rows(): void
    {
        $this->worked();
        $exports = app(GwaterExportService::class);

        $report = $exports->build('receivables', $this->place, '2026-10-01', '2026-10-31', $this->user, 'Accra West');
        [$byCustomer, $bySale] = $report['tables'];

        $this->assertSame([['Mama Efua', '', 2, 264.0, '02 Oct 2026', 29]], $byCustomer['rows']);
        $this->assertSame([[10, 4], [160.0, 104.0]], [array_column($bySale['rows'], 3), array_column($bySale['rows'], 6)]);
        $this->assertEquals(['As at' => '31 Oct 2026', 'Location' => 'Accra West'], array_intersect_key($report['meta'], ['As at' => 1, 'Location' => 1]));

        $pnl = collect($exports->build('pnl', $this->place, '2026-10-01', '2026-10-31', $this->user, 'Accra West')['tables'][1]['rows'])->keyBy(0);
        $this->assertEquals(494.0, $pnl['Credit sales made in the period (GH¢)'][1]);
        $this->assertEquals(750.0, $pnl['Cash collected: cash sales + money received on credit (GH¢)'][1]);
        $this->assertEquals(264.0, $pnl['Owed by customers at the end of the period (GH¢)'][1]);

        $this->actingAs($this->user)->get(route('gwater.export', ['report' => 'receivables', 'format' => 'excel', 'to' => '2026-10-31']))->assertOk();
        $this->actingAs($this->user)->get(route('gwater.export', ['report' => 'receivables', 'format' => 'pdf', 'to' => '2026-10-31']))->assertOk();
    }

    public function test_the_import_takes_credit_sales_with_their_customer(): void
    {
        $file = $this->workbook([
            'Daily sales' => [
                ['sale_date', 'product', 'packs', 'on_credit', 'customer_name', 'customer_phone'],
                ['02/10/2026', 'bottle', 4, 'yes', 'Mama Efua', '0244000000'],
                ['02/10/2026', 'bottle', 3, 'no', '', ''],
                ['03/10/2026', 'bottle', 2, 'yes', '', ''],       // credit with no customer
                ['03/10/2026', 'bottle', 2, 'maybe', 'X Y', ''],   // not yes or no
            ],
        ]);

        $result = app(\App\Services\GWater\GwaterImportService::class)->import($file, $this->user, null, validOnly: true);

        $this->assertSame([2, 2], [$result['valid_count'], $result['error_count']]);
        $this->assertSame(['credit', 'cash'], GwSale::query()->orderBy('id')->where('sale_date', '>=', '2026-10-02')->pluck('payment_type')->all());
        $this->assertSame(10400, app(GwaterSummaryService::class)->owedByCustomers($this->place)['total']);
        $this->assertStringContainsString('customer', $result['errors'][0]['message']);
        $this->assertStringContainsString('not yes or no', $result['errors'][1]['message']);
    }

    private function workbook(array $sheets): \Illuminate\Http\UploadedFile
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);

            foreach ($rows as $r => $row) {
                foreach ($row as $c => $value) {
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, is_int($value) ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'gwc').'.xlsx';
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'credit.xlsx', null, null, true);
    }
}
