<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\SupplierImport;
use App\Models\AuditLog;
use App\Models\FinSupplier;
use App\Services\Finance\SupplierImportService;
use App\Services\Finance\SupplierService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The Excel import: create-only, previewed and rolled back, then confirmed from the file again, every row through SupplierService. */
class SupplierImportTest extends FinanceTestCase
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
     * A tiny synthetic workbook: sheet title => rows (the first row is the headings). Numbers stay numbers, everything else text.
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
                    if ($value === null) {
                        continue;
                    }

                    $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'fsi').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $this->files[] = $path;

        return new UploadedFile($path, 'suppliers.xlsx', null, null, true);
    }

    private function sheet(array ...$rows): UploadedFile
    {
        return $this->workbook(['Suppliers' => [['name', 'code', 'tin', 'phone'], ...$rows]]);
    }

    private function importer(): SupplierImportService
    {
        return app(SupplierImportService::class);
    }

    public function test_the_preview_checks_everything_and_saves_nothing(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        app(SupplierService::class)->create($fin, ['name' => 'Already Here Ltd']);

        $result = $this->importer()->preview($this->sheet(
            ['Fresh One', null, null, null],
            ['Fresh Two', 'F2', 'C0000000002', '0201234567'],
            ['already   here LTD', null, null, null],
        ), $fin);

        $this->assertFalse($result['blocked']);
        $this->assertFalse($result['committed']);
        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(2, $result['valid_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(0, $result['error_count']);
        $this->assertSame('Already on the list as "Already Here Ltd".', $result['skipped'][0]['message']);
        $this->assertSame(1, FinSupplier::query()->count(), 'a preview is rolled back');
        $this->assertSame(0, AuditLog::query()->where('action', 'finance.suppliers_imported')->count());
    }

    public function test_confirming_reads_the_file_again_creates_the_new_ones_and_writes_one_summary_audit_row(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        app(SupplierService::class)->create($fin, ['name' => 'Already Here Ltd', 'phone' => '0200000000']);

        $result = $this->importer()->import($this->sheet(
            ['Fresh One', null, null, null],
            ['Fresh Two', 'F2', 'c0000000002', '0201234567'],
            ['Already Here Ltd', 'NEWCODE', 'C0000000099', '0209999999'], // listed: skipped, NEVER updated
            ['FRESH ONE', null, null, null],                                 // a repeat inside the file: skipped
        ), $fin);

        $this->assertTrue($result['committed']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['skipped_count']);

        $one = FinSupplier::query()->where('name', 'Fresh One')->firstOrFail();
        $this->assertNull($one->code);
        $this->assertNull($one->tin);
        $this->assertNull($one->phone);
        $two = FinSupplier::query()->where('name', 'Fresh Two')->firstOrFail();
        $this->assertSame('C0000000002', $two->tin, 'cleaned like on the screen');
        $this->assertSame($fin->id, $two->created_by);

        $existing = FinSupplier::query()->where('name', 'Already Here Ltd')->firstOrFail();
        $this->assertNull($existing->tin, 'create-only: a listed supplier is never updated');
        $this->assertSame('0200000000', $existing->phone);

        $rows = AuditLog::query()->where('target_type', 'fin_suppliers')->orderBy('id')->get();
        $this->assertSame(1, $rows->where('action', 'finance.suppliers_imported')->count());
        $this->assertSame(1, $rows->where('action', 'finance.supplier_created')->count(), 'only the earlier manual add has its own row; the import writes one summary row');
        $this->assertSame(['created' => 2, 'skipped' => 2, 'errors' => 0, 'warnings' => 0, 'valid_rows_only' => false], $rows->firstWhere('action', 'finance.suppliers_imported')->metadata);
        $this->assertStringNotContainsString('Fresh', $rows->toJson());
    }

    public function test_one_bad_row_keeps_the_whole_file_out_unless_valid_rows_only_is_chosen(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        app(SupplierService::class)->create($fin, ['name' => 'Owner Of The TIN', 'tin' => 'C0000000001']);

        $file = fn () => $this->sheet(
            ['Good One', null, null, null],
            ['Bad Phone', null, null, 'call me'],
            [null, 'CODE-ONLY', null, null],
            ['Tin Thief', null, 'C0000000001', null],
            ['Bad Tin', null, '12', null],
        );

        $all = $this->importer()->import($file(), $fin);
        $this->assertFalse($all['committed']);
        $this->assertSame(1, $all['valid_count']);
        $this->assertSame(4, $all['error_count']);
        $this->assertSame(1, FinSupplier::query()->count(), 'all or nothing: the good row is not kept either');

        $byRow = collect($all['errors'])->keyBy('row');
        $this->assertEqualsCanonicalizing([3, 4, 5, 6], $byRow->keys()->all());
        $this->assertStringContainsString('phone number', $byRow[3]['message']);
        $this->assertStringContainsString('supplier\'s name', $byRow[4]['message']);
        $this->assertStringContainsString('Owner Of The TIN', $byRow[5]['message'], 'a TIN clash names the supplier that holds it');
        $this->assertStringContainsString('TIN', $byRow[6]['message']);

        $some = $this->importer()->import($file(), $fin, validOnly: true);
        $this->assertTrue($some['committed']);
        $this->assertSame(1, $some['imported']);
        $this->assertSame(['Good One', 'Owner Of The TIN'], FinSupplier::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(true, AuditLog::query()->where('action', 'finance.suppliers_imported')->firstOrFail()->metadata['valid_rows_only']);
    }

    public function test_a_look_alike_is_imported_but_listed_as_a_warning(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        app(SupplierService::class)->create($fin, ['name' => 'ABC Trading Ltd.']);

        $result = $this->importer()->preview($this->sheet(['ABC Trading Limited', null, null, null], ['Quite Different', null, null, null]), $fin);

        $this->assertSame(2, $result['valid_count']);
        $this->assertSame(1, $result['warning_count']);
        $this->assertSame(2, $result['warnings'][0]['row']);
        $this->assertStringContainsString('ABC Trading Ltd.', $result['warnings'][0]['message']);

        // Two look-alikes inside the same file are caught too.
        $twice = $this->importer()->preview($this->sheet(['Mensah Brothers Ltd', null, null, null], ['Mensah Brothers Limited', null, null, null]), $fin);
        $this->assertSame(1, $twice['warning_count']);
    }

    public function test_cells_stay_text_and_a_lost_leading_zero_is_restored(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);

        $this->importer()->import($this->sheet(
            ['=1+1', null, null, '0244123456'],       // text that looks like a formula stays text
            ['Number Phone', null, null, 244123456],   // Excel kept it as a number and dropped the zero
            ['Spaced Phone', null, null, '024 412 3457'],
        ), $fin);

        $this->assertSame('=1+1', FinSupplier::query()->where('name_key', '=1+1')->value('name'));
        $this->assertSame('0244123456', FinSupplier::query()->where('name', 'Number Phone')->value('phone'));
        $this->assertSame('0244123457', FinSupplier::query()->where('name', 'Spaced Phone')->value('phone'));
    }

    public function test_the_template_has_the_headings_only_formatted_as_text_and_imports_nothing(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);

        $response = $this->actingAs($fin)->get(route('finance.suppliers.import.template'));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'fst').'.xlsx';
        file_put_contents($path, $response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? file_get_contents($response->baseResponse->getFile()->getPathname()) : $response->streamedContent());
        $this->files[] = $path;
        $book = IOFactory::load($path);

        $this->assertSame(['Read me', 'Suppliers'], $book->getSheetNames());
        $sheet = $book->getSheetByName('Suppliers');
        $this->assertSame(['name', 'code', 'tin', 'phone'], [$sheet->getCell('A1')->getValue(), $sheet->getCell('B1')->getValue(), $sheet->getCell('C1')->getValue(), $sheet->getCell('D1')->getValue()]);
        $this->assertSame(1, $sheet->getHighestDataRow(), 'headings only, no example rows to import by mistake');
        foreach (['A', 'B', 'C', 'D'] as $column) {
            $format = $book->getCellXfByIndex($sheet->getColumnDimension($column)->getXfIndex())->getNumberFormat()->getFormatCode();
            $this->assertSame('@', $format, "column {$column} is text so a phone keeps its zero");
        }

        $this->assertTrue($this->importer()->preview(new UploadedFile($path, 'template.xlsx', null, null, true), $fin)['blocked']);
    }

    public function test_a_file_that_cannot_be_used_is_blocked_with_a_reason(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);

        $cases = [
            'no name column' => [$this->workbook(['Suppliers' => [['code', 'tin'], ['X', 'C1234567890']]]), 'no "name" column'],
            'wrong sheet' => [$this->workbook(['Other' => [['name'], ['A']], 'More' => [['name'], ['B']]]), 'no sheet named "Suppliers"'],
            'no rows' => [$this->sheet(), 'no rows to import'],
        ];

        foreach ($cases as $label => [$file, $expected]) {
            $result = $this->importer()->preview($file, $fin);
            $this->assertTrue($result['blocked'], $label);
            $this->assertStringContainsString($expected, $result['errors'][0]['message'], $label);
        }

        config(['gwl.fin_supplier_import_max_rows' => 2]);
        $big = $this->importer()->preview($this->sheet(['A', null, null, null], ['B', null, null, null], ['C', null, null, null]), $fin);
        $this->assertTrue($big['blocked']);
        $this->assertStringContainsString('at most 2', $big['errors'][0]['message']);

        $path = tempnam(sys_get_temp_dir(), 'fsi');
        file_put_contents($path, 'plain text, not a workbook');
        $this->files[] = $path;
        $this->assertTrue($this->importer()->preview(new UploadedFile($path, 'x.xlsx', null, null, true), $fin)['blocked']);

        $this->assertSame(0, FinSupplier::query()->count());
    }

    public function test_a_single_sheet_workbook_and_the_common_heading_spellings_are_understood(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);

        $file = $this->workbook(['Sheet1' => [['Supplier Name', 'TIN No', 'Phone Number'], ['Spelled Differently', 'C0000000005', '0201112222']]]);
        $this->assertTrue($this->importer()->import($file, $fin)['committed']);

        $supplier = FinSupplier::query()->where('name', 'Spelled Differently')->firstOrFail();
        $this->assertSame('C0000000005', $supplier->tin);
        $this->assertSame('0201112222', $supplier->phone);
    }

    public function test_the_import_needs_the_import_permission_and_audit_has_no_door(): void
    {
        $aud = $this->audit('AU1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom);
        $fin = $this->finance('F1', $this->sowutuom);

        foreach ([$aud, $plain] as $user) {
            try {
                $this->importer()->preview($this->sheet(['X', null, null, null]), $user);
                $this->fail('Expected a 403.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->actingAs($aud)->get(route('finance.suppliers.import'))->assertRedirect();
        $this->actingAs($aud)->get(route('finance.suppliers.import.template'))->assertRedirect();
        $this->actingAs($fin)->get(route('finance.suppliers.import'))->assertOk()->assertSee('Import suppliers');
    }

    public function test_the_screen_previews_then_imports_and_audit_cannot_open_it(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $file = fn () => UploadedFile::fake()->createWithContent('suppliers.xlsx', file_get_contents($this->sheet(['Screen One', null, null, null], ['Screen Two', null, null, null])->getRealPath()));

        $page = Livewire::actingAs($fin)->test(SupplierImport::class)
            ->set('file', $file())
            ->call('previewFile')->assertHasNoErrors()
            ->assertSee('Import 2 new suppliers');

        $this->assertSame(0, FinSupplier::query()->count());

        $page->call('runImport')->assertHasNoErrors()->assertSee('Imported');
        $this->assertSame(2, FinSupplier::query()->count());

        // Again: both are on the list now, so nothing is new.
        Livewire::actingAs($fin)->test(SupplierImport::class)->set('file', $file())->call('previewFile')->assertSee('Skipped: already on the list')->assertDontSee('Import 2 new suppliers');

        Livewire::actingAs($this->audit('AU1', $this->sowutuom))->test(SupplierImport::class)->assertForbidden();
    }
}
