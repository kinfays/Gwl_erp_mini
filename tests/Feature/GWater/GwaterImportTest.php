<?php

namespace Tests\Feature\GWater;

use App\Livewire\GWater\Import;
use App\Models\AuditLog;
use App\Models\GwDamage;
use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwStockMovement;
use App\Models\GwSupplierPayment;
use App\Models\User;
use App\Services\GWater\GwaterImportService;
use App\Services\GWater\GwaterProfitService;
use App\Services\GWater\GwaterStockService;
use App\Services\GWater\GwaterSummaryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Past ledger lines in from a workbook: preview, confirm, never overwrite, never outside the importer's location. */
class GwaterImportTest extends GwaterTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * A tiny synthetic workbook: sheet title => rows (the first row is the headings).
     *
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    private function workbook(array $sheets): UploadedFile
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);

            foreach ($rows as $r => $row) {
                foreach ($row as $c => $value) {
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, is_int($value) || is_float($value) ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'gwi').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $this->files[] = $path;

        return new UploadedFile($path, 'past.xlsx', null, null, true);
    }

    private function importer(): GwaterImportService
    {
        return app(GwaterImportService::class);
    }

    /** The whole worked file, for Sowutuom. */
    private function goodSheets(): array
    {
        return [
            'Opening balance' => [['as_of_date', 'bottle_packs', 'bottle_outstanding_packs', 'owed_amount'], ['01/01/2026', 100, 20, '50.00']],
            'Purchases' => [['purchase_date', 'waybill_no', 'bottle_packs', 'bottle_unit_cost', 'loading_amount'], ['01/06/2026', 'WB-1', 50, '20.00', '10.00']],
            'Daily sales' => [['sale_date', 'product', 'packs'], ['02/06/2026', 'bottle', 30]],
            'Damages' => [['damage_date', 'product', 'packs', 'stage', 'reason', 'waybill_no'], ['03/06/2026', 'bottle', 2, 'transit', 'Burst', 'WB-1']],
            'Expenses' => [['expense_date', 'category', 'amount', 'receipt_ref'], ['03/06/2026', 'other', '5.00', 'R-1']],
            'Payments' => [['request_date', 'reference', 'amount', 'paid_on'], ['04/06/2026', 'PR-1', '100.00', '05/06/2026']],
        ];
    }

    private function counts(): array
    {
        return [GwOpeningBalance::count(), GwPurchase::count(), GwSale::count(), GwDamage::count(), GwExpense::count(), GwSupplierPayment::count(), GwStockMovement::count()];
    }

    // ---------------------------------------------------------------- preview and confirm

    public function test_the_preview_checks_every_row_and_writes_nothing(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        $preview = $this->importer()->preview($this->workbook($this->goodSheets()), $user);

        $this->assertSame([false, false, 6, 6, 0, 0], [$preview['blocked'], $preview['committed'], $preview['total_rows'], $preview['valid_count'], $preview['error_count'], $preview['imported']]);
        $this->assertSame(array_fill(0, 7, 0), $this->counts(), 'a preview leaves nothing behind');
        $this->assertSame(0, AuditLog::query()->where('module', 'gwater')->count());
        $this->assertSame(['Opening balance', 'Purchases', 'Daily sales', 'Damages', 'Expenses', 'Payments'], array_column($preview['sheets'], 'title'));
    }

    public function test_confirming_posts_every_row_through_the_workflow(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        $result = $this->importer()->import($this->workbook($this->goodSheets()), $user);

        $this->assertSame([true, 6, 0], [$result['committed'], $result['imported'], $result['error_count']]);
        // The trip's loading cost is an expense of its own, next to the 'other' one in the file.
        $this->assertSame([1, 1, 1, 1, 2, 1], array_slice($this->counts(), 0, 6));

        // Stock: 100 + 50 - 30 - 2; prices frozen from the list on each row's date (bottle 26.00 from 12 May); the trip link and the paid payment are kept.
        $place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];
        $this->assertSame(118, app(GwaterStockService::class)->balance($place, $this->bottle->id));
        $this->assertSame([2600, 2000, 2000], [GwSale::first()->unit_price, \App\Models\GwPurchaseLine::first()->unit_cost, GwDamage::first()->unit_cost]);
        $this->assertSame(GwPurchase::first()->id, GwDamage::first()->purchase_id);
        $this->assertSame('paid', GwSupplierPayment::first()->status);
        $this->assertSame(20, GwOpeningBalance::first()->lines->first()->outstanding_packs);

        // Each row was audited as if typed.
        $this->assertEqualsCanonicalizing(
            ['gwater.opening_balance_recorded', 'gwater.purchase_recorded', 'gwater.expense_recorded', 'gwater.sale_recorded', 'gwater.damage_recorded', 'gwater.payment_recorded'],
            AuditLog::query()->where('module', 'gwater')->pluck('action')->all()
        );
    }

    public function test_imported_entries_show_in_stock_profit_and_owed_exactly_as_typed_ones_do(): void
    {
        $importer = $this->gw('G1', $this->sowutuom);
        $typist = $this->gw('G2', $this->kumasi);   // another location, same prices (the placeholder list)

        $this->actingAs($importer);
        $this->importer()->import($this->workbook($this->goodSheets()), $importer);

        $this->actingAs($typist);
        $w = $this->workflow();
        $w->recordOpeningBalance($typist, ['as_of_date' => '2026-01-01', 'packs' => [$this->bottle->id => 100], 'outstanding' => [$this->bottle->id => 20], 'owed_amount' => 5000]);
        $trip = $w->recordPurchase($typist, ['purchase_date' => '2026-06-01', 'waybill_no' => 'WB-1', 'lines' => [['product_id' => $this->bottle->id, 'packs' => 50]], 'loading_amount' => 1000]);
        $w->recordSale($typist, $this->saleData(30, '2026-06-02'));
        $w->recordDamage($typist, ['damage_date' => '2026-06-03', 'product_id' => $this->bottle->id, 'packs' => 2, 'stage' => 'transit', 'reason' => 'Burst', 'purchase_id' => $trip->id]);
        $w->recordExpense($typist, ['expense_date' => '2026-06-03', 'category' => 'other', 'amount' => 500, 'receipt_ref' => 'R-1']);
        $w->recordPayment($typist, ['request_date' => '2026-06-04', 'reference' => 'PR-1', 'amount' => 10000, 'paid_on' => '2026-06-05']);

        $at = fn ($district) => ['region_id' => $district->region_id, 'district_id' => $district->id];
        $stock = app(GwaterStockService::class);
        $summary = app(GwaterSummaryService::class);
        $profit = app(GwaterProfitService::class);

        $this->assertSame($stock->balance($at($this->kumasi), $this->bottle->id), $stock->balance($at($this->sowutuom), $this->bottle->id));
        $this->assertSame($summary->owedToGwater($at($this->kumasi)), $summary->owedToGwater($at($this->sowutuom)));
        $this->assertSame($summary->waybillOutstanding($at($this->kumasi))->pluck('outstanding')->all(), $summary->waybillOutstanding($at($this->sowutuom))->pluck('outstanding')->all());

        $imported = $profit->report($at($this->sowutuom), '2026-06-01', '2026-06-30', $importer)['combined'];
        $typed = $profit->report($at($this->kumasi), '2026-06-01', '2026-06-30', $typist)['combined'];
        $this->assertSame($typed, $imported);
        $this->assertSame(78000 - 60000 - 4000 - 1000 - 500, $imported['net_profit']);   // 780 revenue (30 x 26.00), 600 cost, 40 damages, 10 loading, 5 other
    }

    // ---------------------------------------------------------------- what is refused, and why

    public function test_rows_that_break_the_rules_are_listed_with_the_reason(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $sheets = $this->goodSheets();
        $sheets['Purchases'][] = ['02/06/2026', 'WB-1', 5, '', ''];                       // the same waybill again
        $sheets['Purchases'][] = ['03/06/2026', 'WB-2', 5, '19.00', ''];                  // cost differs from the price list
        $sheets['Daily sales'][] = ['04/06/2026', 'bottle', 100000];                      // more than is in stock
        $sheets['Daily sales'][] = ['04/06/2026', 'bananas', 1];                          // unknown product
        $sheets['Daily sales'][] = ['01/01/2025', 'bottle', 1];                           // no price on the list that early
        $sheets['Daily sales'][] = ['not a date', 'bottle', 1];
        $sheets['Damages'][] = ['04/06/2026', 'bottle', 1, 'kitchen', 'Spilt', ''];       // unknown stage
        $sheets['Damages'][] = ['04/06/2026', 'bottle', 1, 'transit', 'Spilt', 'NO-SUCH']; // unknown waybill
        $sheets['Expenses'][] = ['04/06/2026', 'party', '5.00', ''];
        $sheets['Expenses'][] = ['04/06/2026', 'other', 'five', ''];
        $sheets['Payments'][] = ['04/06/2026', 'PR-1', '1.00', ''];                       // reference already used in the file

        $preview = $this->importer()->preview($this->workbook($sheets), $user);
        $messages = collect($preview['errors'])->mapWithKeys(fn ($e) => [$e['sheet'].'|'.$e['row'] => $e['message']]);

        $this->assertSame(11, $preview['error_count']);
        $this->assertSame(6, $preview['valid_count']);
        $this->assertStringContainsString('already recorded', $messages['Purchases|3']);
        $this->assertStringContainsString('does not match the price list', $messages['Purchases|4']);
        $this->assertStringContainsString('Not enough', $messages['Daily sales|3']);
        $this->assertStringContainsString('Unknown product', $messages['Daily sales|4']);
        $this->assertStringContainsString('No selling price', $messages['Daily sales|5']);
        $this->assertStringContainsString('date', $messages['Daily sales|6']);
        $this->assertStringContainsString('Choose where the damage happened', $messages['Damages|3']);
        $this->assertStringContainsString('No trip with waybill "NO-SUCH"', $messages['Damages|4']);
        $this->assertStringContainsString('Choose a category', $messages['Expenses|3']);
        $this->assertStringContainsString('not an amount', $messages['Expenses|4']);
        $this->assertStringContainsString('already used', $messages['Payments|3']);
        $this->assertSame(array_fill(0, 7, 0), $this->counts());
    }

    public function test_by_default_one_bad_row_keeps_the_whole_file_out_and_valid_only_keeps_the_good_ones(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $sheets = $this->goodSheets();
        $sheets['Daily sales'][] = ['04/06/2026', 'bottle', 100000];

        $all = $this->importer()->import($this->workbook($sheets), $user);
        $this->assertSame([false, 0, 1], [$all['committed'], $all['imported'], $all['error_count']]);
        $this->assertSame(array_fill(0, 7, 0), $this->counts(), 'nothing partial is kept');
        $this->assertSame(0, AuditLog::query()->where('module', 'gwater')->count());

        $some = $this->importer()->import($this->workbook($sheets), $user, null, validOnly: true);
        $this->assertSame([true, 6, 1], [$some['committed'], $some['imported'], $some['error_count']]);
        $this->assertSame(1, GwSale::count(), 'the good sale is in, the impossible one is not');
    }

    public function test_the_import_is_create_only_it_never_overwrites_what_is_already_there(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $this->importer()->import($this->workbook($this->goodSheets()), $user);
        $before = $this->counts();

        // The same file again: the opening balance, the waybill and the payment reference exist, so those rows are refused.
        $again = $this->importer()->import($this->workbook($this->goodSheets()), $user, null, validOnly: true);

        $messages = collect($again['errors'])->pluck('sheet')->all();
        $this->assertContains('Opening balance', $messages);
        $this->assertContains('Purchases', $messages);
        $this->assertContains('Payments', $messages);
        $this->assertSame(1, GwOpeningBalance::count());
        $this->assertSame(1, GwPurchase::count());
        $this->assertSame(1, GwSupplierPayment::count());
        $this->assertSame(['posted'], GwPurchase::pluck('status')->unique()->all());
        $this->assertGreaterThanOrEqual(0, $before[0]);
    }

    public function test_the_import_cannot_write_outside_the_importers_location(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $kumasi = $this->gw('G2', $this->kumasi);
        $this->actingAs($accra);
        $this->importer()->import($this->workbook($this->goodSheets()), $accra);

        // Kumasi imports a file that names Accra's waybill: it is not found there, and everything lands in Kumasi.
        $this->actingAs($kumasi);
        $sheets = [
            'Purchases' => [['purchase_date', 'waybill_no', 'bottle_packs'], ['01/06/2026', 'WB-K', 40]],
            'Damages' => [['damage_date', 'product', 'packs', 'stage', 'reason', 'waybill_no'], ['03/06/2026', 'bottle', 1, 'transit', 'Burst', 'WB-1']],
        ];
        $result = $this->importer()->import($this->workbook($sheets), $kumasi, null, validOnly: true);

        $this->assertSame([1, 1], [$result['valid_count'], $result['error_count']]);
        $this->assertStringContainsString('No trip with waybill "WB-1"', $result['errors'][0]['message']);
        $this->assertSame([$this->ashanti->id], GwPurchase::query()->where('waybill_no', 'WB-K')->pluck('region_id')->all());
        $this->assertSame(1, GwDamage::count(), 'Accra\'s damage only');

        // A location id in the request is ignored for anyone but super_admin: the file has no location column at all.
        $other = $this->importer()->preview($this->workbook(['Purchases' => [['purchase_date', 'waybill_no', 'bottle_packs'], ['01/06/2026', 'WB-1', 5]]]), $kumasi, $this->sowutuom->id);
        $this->assertSame(1, $other['valid_count'], 'WB-1 is free in Kumasi: the district argument did not move the import to Accra');
    }

    public function test_super_admin_imports_into_the_location_they_choose_and_must_choose(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super);
        $file = $this->workbook(['Purchases' => [['purchase_date', 'waybill_no', 'bottle_packs'], ['01/06/2026', 'WB-S', 12]]]);

        try {
            $this->importer()->preview($file, $super);
            $this->fail('super_admin must say which location.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('location', $exception->errors());
        }

        $this->importer()->import($file, $super, $this->headOffice->id);
        $this->assertSame([$this->headOffice->id], GwPurchase::pluck('district_id')->all());
    }

    public function test_a_file_that_cannot_be_used_is_blocked_with_a_reason(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        $cases = [
            'no known sheet' => [$this->workbook(['Stuff' => [['a'], ['b']]]), 'None of the sheets is named'],
            'missing column' => [$this->workbook(['Daily sales' => [['sale_date', 'packs'], ['01/06/2026', 1]]]), 'Missing required columns: product'],
            'empty sheets' => [$this->workbook(['Daily sales' => [['sale_date', 'product', 'packs']]]), 'no rows to import'],
        ];

        foreach ($cases as $name => [$file, $expected]) {
            $result = $this->importer()->preview($file, $user);
            $this->assertGreaterThan(0, $result['error_count'], $name);
            $this->assertStringContainsString($expected, $result['errors'][0]['message'], $name);
        }

        config(['gwl.gwater_import_max_rows' => 2]);
        $big = $this->importer()->preview($this->workbook(['Daily sales' => [['sale_date', 'product', 'packs'], ['01/06/2026', 'bottle', 1], ['01/06/2026', 'bottle', 1], ['01/06/2026', 'bottle', 1]]]), $user);
        $this->assertTrue($big['blocked']);
        $this->assertStringContainsString('at most 2', $big['errors'][0]['message']);

        // Not a workbook at all.
        $path = tempnam(sys_get_temp_dir(), 'gwi');
        file_put_contents($path, 'plain text');
        $this->files[] = $path;
        $this->assertTrue($this->importer()->preview(new UploadedFile($path, 'x.xlsx', null, null, true), $user)['blocked']);
    }

    public function test_sales_are_priced_from_the_list_on_the_rows_date_and_a_missing_price_blocks_only_that_row(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $this->trip($user, 'WB-0', 100, 0, '2026-03-01');

        $result = $this->importer()->import($this->workbook(['Daily sales' => [
            ['sale_date', 'product', 'packs'],
            ['11/05/2026', 'bottle', 4],
            ['12/05/2026', 'bottle', 4],
            ['01/01/2025', 'bottle', 4],
        ]]), $user, null, validOnly: true);

        $this->assertSame([2, 1], [$result['valid_count'], $result['error_count']]);
        $this->assertSame([2500, 2600], GwSale::query()->orderBy('sale_date')->pluck('unit_price')->all());
    }

    public function test_the_template_is_a_workbook_of_empty_sheets_that_imports_nothing(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        $response = $this->get(route('gwater.import.template'));
        $response->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'gwt').'.xlsx';
        file_put_contents($path, $response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? file_get_contents($response->baseResponse->getFile()->getPathname()) : $response->streamedContent());
        $this->files[] = $path;
        $book = IOFactory::load($path);

        $this->assertSame(['Read me', 'Opening balance', 'Purchases', 'Daily sales', 'Damages', 'Expenses', 'Payments'], $book->getSheetNames());
        $this->assertSame('waybill_no', $book->getSheetByName('Purchases')->getCell('B1')->getValue());
        $this->assertSame(1, $book->getSheetByName('Purchases')->getHighestRow(), 'headings only, no example rows to import by mistake');

        $result = $this->importer()->preview(new UploadedFile($path, 'gwater-import-template.xlsx', null, null, true), $user);
        $this->assertTrue($result['blocked'], 'an unfilled template has nothing to import');
    }

    public function test_the_import_needs_gwater_record(): void
    {
        $plain = $this->person('E1', $this->sowutuom);
        $this->actingAs($plain);

        $this->assertContains($this->get(route('gwater.import'))->getStatusCode(), [302, 403]);
        $this->assertContains($this->get(route('gwater.import.template'))->getStatusCode(), [302, 403]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->importer()->preview($this->workbook($this->goodSheets()), $plain);
    }

    // ---------------------------------------------------------------- the screen

    public function test_the_screen_previews_then_imports(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $file = fn () => UploadedFile::fake()->createWithContent('past.xlsx', file_get_contents($this->workbook($this->goodSheets())->getRealPath()));

        $page = Livewire::actingAs($user)->test(Import::class)
            ->set('file', $file())
            ->call('previewFile')->assertHasNoErrors()
            ->assertSee('Import all 6 rows');

        $this->assertSame(array_fill(0, 7, 0), $this->counts());

        $page->call('runImport')->assertHasNoErrors()->assertSee('Imported');
        $this->assertSame([1, 1, 1, 1, 2, 1], array_slice($this->counts(), 0, 6));

        // A second import of the same file is refused as a whole (everything already exists).
        Livewire::actingAs($user)->test(Import::class)->set('file', $file())->call('previewFile')->assertSee('Rows that cannot be imported')->assertDontSee('Import all');

        Storage::fake('local');
    }
}
