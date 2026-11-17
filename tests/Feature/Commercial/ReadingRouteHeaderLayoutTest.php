<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\CommercialImportService;
use App\Services\Commercial\ReadingRouteSampleFile;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * The reading report BY ROUTE: the header of a CCA sheet is TWO rows with merged cells in the real export ("Route" and "Verified" on the
 * first, "Strength" and the "#" / "%" cells on the second), and the importer must read that, the older one-row layouts, a header it cannot
 * read (ONE clear error, never a missing period or region on top of it) and the period in any of the ways the report prints it.
 * Invented CCAs, ids, names and routes only; the real files were never available.
 */
class ReadingRouteHeaderLayoutTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.commercial_customer_list_enabled' => false]);
    }

    /** @return array<string, mixed> */
    protected function spec(array $over = []): array
    {
        return $over + [
            'period' => '01-September-2026 - 30-September-2026',
            'ccas' => [
                ['id' => '80001', 'name' => 'AMA TEST', 'routes' => [
                    ['label' => 'SOWUTUOM 4602', 'strength' => 200, 'read' => 150, 'skipped' => 30, 'visited' => 180],
                    ['label' => 'SOWUTUOM 4603', 'strength' => 90, 'read' => 60, 'skipped' => 10, 'visited' => 70],
                ]],
                ['id' => '80002', 'name' => 'KWAME TEST', 'routes' => [
                    ['label' => 'ODORKOR 5101', 'strength' => 100, 'read' => 80, 'skipped' => 10, 'visited' => 90],
                ]],
                ['id' => '80003', 'name' => 'EFUA TEST', 'routes' => []],
            ],
        ];
    }

    protected function previewSpec(array $spec): array
    {
        $this->actingAs($this->officer());

        return app(CommercialImportService::class)->preview($this->asUpload(ReportWorkbooks::readingRoute($spec), 'rptReadingSumm.xlsx'));
    }

    protected function previewPath(string $path): array
    {
        $this->actingAs($this->officer());

        return app(CommercialImportService::class)->preview($this->asUpload($path, 'rptReadingSumm.xlsx'));
    }

    /** The figures a preview read, in a form two layouts can be compared by. */
    protected function figures(array $preview): array
    {
        return array_map(
            fn (array $r) => [$r['cca_staff_id'], $r['route_label_raw'], $r['verified_strength'], $r['read_count'], $r['skipped_count'], $r['visited_count']],
            $preview['parsed']['rows']
        );
    }

    protected function messages(array $preview, string $key): string
    {
        return collect($preview[$key])->pluck('message')->implode(' | ');
    }

    // ---------------------------------------------------------------- the layouts

    public function test_every_header_layout_imports_the_same_figures(): void
    {
        $reference = $this->previewSpec($this->spec(['header' => 'real']));

        $this->assertFalse($reference['blocked'], $this->messages($reference, 'errors'));
        $this->assertSame(3, $reference['total_rows'], 'three route rows (the CCA with no routes has none)');
        $this->assertContains(['80001', 'SOWUTUOM 4602', 200, 150, 30, 180], $this->figures($reference));
        $this->assertContains(['80001', 'SOWUTUOM 4603', 90, 60, 10, 70], $this->figures($reference));
        $this->assertContains(['80002', 'ODORKOR 5101', 100, 80, 10, 90], $this->figures($reference));

        // (a) the old one-row "Verified Strength", (c) "Strength" alone on the Route row, and "Read #" / "Read %"
        foreach (['one_row', 'strength_alone', 'explicit', 'groups'] as $layout) {
            $preview = $this->previewSpec($this->spec(['header' => $layout]));

            $this->assertFalse($preview['blocked'], $layout.': '.$this->messages($preview, 'errors'));
            $this->assertSame($this->figures($reference), $this->figures($preview), "layout {$layout} reads the same figures");
            $this->assertSame($reference['period'], $preview['period']);
            $this->assertSame($reference['route_report']['routes'], $preview['route_report']['routes']);
            $this->assertSame($reference['route_report']['flagged_rows'], $preview['route_report']['flagged_rows']);
        }
    }

    public function test_the_real_layout_has_the_merged_cells_the_importer_must_cope_with(): void
    {
        $path = ReportWorkbooks::readingRoute($this->spec(['header' => 'real']));
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('Sheet2');

        $this->assertNotNull($sheet, 'sheets are named Sheet2, Sheet3 ...');
        $this->assertNotNull($book->getSheetByName('Document map'));

        $merged = array_keys($sheet->getMergeCells());
        foreach (['B3:Q3', 'B5:E5', 'B7:D7', 'E7:G7', 'H7:I7', 'N7:Q7', 'B9:D9', 'E9:G9'] as $range) {
            $this->assertContains($range, $merged);
        }

        $this->assertSame('Verified', $sheet->getCell('E7')->getValue());
        $this->assertSame('Strength', $sheet->getCell('E8')->getValue(), 'Strength is on the SECOND header row');
        $this->assertNull($sheet->getCell('F7')->getValue(), 'a merged cell holds its value in the top-left cell only');
        @unlink($path);
    }

    public function test_a_percentage_column_is_never_read(): void
    {
        foreach (['real', 'one_row', 'strength_alone', 'explicit'] as $layout) {
            $preview = $this->previewSpec($this->spec(['header' => $layout]));

            // every % cell of the file holds 99.9, which would be read as 100 if a % column were ever mapped
            $this->assertSame(
                [['80001', 'SOWUTUOM 4602', 200, 150, 30, 180], ['80001', 'SOWUTUOM 4603', 90, 60, 10, 70], ['80002', 'ODORKOR 5101', 100, 80, 10, 90]],
                $this->figures($preview),
                "{$layout}: only the # columns are read"
            );
            $this->assertSame(0, $preview['route_report']['flagged_rows'], "{$layout}: read + skipped = visited on every row, so no % was mixed in");
        }
    }

    public function test_a_strength_heading_that_is_not_above_its_figures_falls_back_to_the_verified_column(): void
    {
        $shifted = $this->previewSpec($this->spec(['header' => 'real', 'strength_shift' => true]));

        $this->assertFalse($shifted['blocked'], $this->messages($shifted, 'errors'));
        $this->assertSame($this->figures($this->previewSpec($this->spec(['header' => 'real']))), $this->figures($shifted));
    }

    public function test_the_header_rows_are_never_read_as_data(): void
    {
        $preview = $this->previewSpec($this->spec(['header' => 'real']));
        $labels = array_column($preview['parsed']['rows'], 'route_label_raw');

        foreach ($labels as $label) {
            $this->assertNotNull($label);
            $this->assertDoesNotMatchRegularExpression('/^(strength|#|%|route|verified|read|skipped|visited|unvisited)$/i', (string) $label);
        }

        $this->assertCount(3, $labels, 'three route rows; nothing of the two header rows');
    }

    public function test_the_totals_row_is_still_checked_in_the_real_layout(): void
    {
        $spec = $this->spec(['header' => 'real']);
        $spec['ccas'][0]['totals'] = ['visited' => 251];   // the rows add up to 250
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 50]);

        $preview = $this->previewSpec($spec);

        $this->assertFalse($preview['blocked'], $this->messages($preview, 'errors'));
        $this->assertSame(1, $preview['route_report']['flagged_sheets']);
        $this->assertStringContainsString('TOTALS row disagrees', $this->messages($preview, 'warnings'));
    }

    // ---------------------------------------------------------------- a header nobody can read

    /** A workbook of CCA sheets whose header is none the importer knows, plus the Document map. @param list<array{id: string, name: string|null}> $sheets */
    protected function strangeWorkbook(array $sheets, array $map = []): string
    {
        $path = ReportWorkbooks::readingRoute(['ccas' => []]);   // just a Document map
        $book = IOFactory::load($path);
        $mapSheet = $book->getSheetByName('Document map');
        $row = 3;

        foreach ($map as $entry) {
            $mapSheet->setCellValueExplicit("A{$row}", $entry, DataType::TYPE_STRING);
            $row++;
        }

        foreach ($sheets as $i => $info) {
            $sheet = $book->createSheet();
            $sheet->setTitle('Sheet'.($i + 2));
            $sheet->setCellValue('A1', 'Customer Meter Reading Report - CCA Summary - By Route');
            $sheet->setCellValueExplicit('B3', "REPORT PERIOD: 01-September-2026 - 30-September-2026\nREGION: ACCRA WEST\n", DataType::TYPE_STRING);

            if ($info['name'] !== null) {
                $sheet->setCellValueExplicit('B5', $info['id'].' - '.$info['name'], DataType::TYPE_STRING);
            }

            foreach (['B7' => 'Area', 'C7' => 'Verified count', 'D7' => 'Done', 'E7' => 'Missed'] as $cell => $text) {
                $sheet->setCellValue($cell, $text);
            }

            $sheet->setCellValueExplicit('B8', 'SOWUTUOM 4602', DataType::TYPE_STRING);
            $sheet->setCellValue('C8', 200);
            $sheet->setCellValue('D8', 150);
        }

        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return $path;
    }

    public function test_a_header_that_cannot_be_read_is_one_error_with_the_words_found_and_never_a_missing_period_or_region(): void
    {
        $path = $this->strangeWorkbook(
            [['id' => '81001', 'name' => 'AMA TEST'], ['id' => '81002', 'name' => 'KWAME TEST'], ['id' => '81003', 'name' => 'EFUA TEST']],
            ['81001 - AMA TEST', '81002 - KWAME TEST', '81003 - EFUA TEST', '81004 - YAW TEST'],
        );
        $preview = $this->previewPath($path);
        $errors = $this->messages($preview, 'errors');

        $this->assertTrue($preview['blocked']);
        $layout = array_values(array_filter($preview['errors'], fn ($e) => $e['row'] === 'Layout'));
        $this->assertCount(1, $layout, 'one line for the whole file, not one per sheet');
        $this->assertStringContainsString('3 of 3 CCA sheets could not be read', $layout[0]['message']);
        $this->assertStringContainsString('Area | Verified count | Done | Missed', $layout[0]['message'], 'what was found at the top, so the next layout can be diagnosed');
        $this->assertDoesNotMatchRegularExpression('/\d{2,}/', explode('Found at the top', $layout[0]['message'])[1], 'no number, id or route is ever shown');

        // the filter cell is read whether or not the header parsed
        $this->assertStringNotContainsString('REPORT PERIOD', $errors);
        $this->assertStringNotContainsString('REGION line', $errors);
        $this->assertStringNotContainsString('does not match any region', $errors);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $preview['period']);
        $this->assertSame('Accra West', $preview['region']['name']);

        // and the map cannot say CCAs have "no sheet" when no sheet could be read
        $this->assertStringNotContainsString('have no sheet', $this->messages($preview, 'warnings'));
        @unlink($path);
    }

    public function test_unreadable_sheets_are_not_reported_again_as_ccas_with_no_sheet(): void
    {
        // two good sheets (the real layout), one sheet with a CCA line and a strange header, and the map lists those three and a fourth CCA
        $path = ReportWorkbooks::readingRoute($this->spec(['header' => 'real', 'ccas' => [
            ['id' => '80001', 'name' => 'AMA TEST', 'routes' => [['label' => 'SOWUTUOM 4602', 'strength' => 200, 'read' => 150, 'skipped' => 30, 'visited' => 180]]],
            ['id' => '80002', 'name' => 'KWAME TEST', 'routes' => [['label' => 'ODORKOR 5101', 'strength' => 100, 'read' => 80, 'skipped' => 10, 'visited' => 90]]],
        ], 'map_extra' => ['80003 - EFUA TEST', '80004 - YAW TEST']]));
        $book = IOFactory::load($path);
        $sheet = $book->createSheet();
        $sheet->setTitle('Sheet4');
        $sheet->setCellValueExplicit('B3', "REPORT PERIOD: 01-September-2026 - 30-September-2026\nREGION: ACCRA WEST\n", DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B5', '80003 - EFUA TEST', DataType::TYPE_STRING);

        foreach (['B7' => 'Area', 'C7' => 'Verified count'] as $cell => $text) {
            $sheet->setCellValue($cell, $text);
        }

        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $preview = $this->previewPath($path);
        $warnings = $this->messages($preview, 'warnings');

        $this->assertStringContainsString('1 of 3 CCA sheets could not be read', $this->messages($preview, 'errors'));
        $this->assertStringContainsString('1 CCA(s) are listed on the Document map but have no sheet', $warnings, 'only 80004: the unreadable sheet of 80003 is not "missing"');
        @unlink($path);

        // a sheet whose CCA line cannot be read either: the count of "no sheet" would be wrong, so it is not said at all
        $path = ReportWorkbooks::readingRoute($this->spec(['header' => 'real', 'map_extra' => ['80004 - YAW TEST']]));
        $book = IOFactory::load($path);
        $sheet = $book->createSheet();
        $sheet->setTitle('Sheet9');
        $sheet->setCellValueExplicit('B3', "REPORT PERIOD: 01-September-2026 - 30-September-2026\nREGION: ACCRA WEST\n", DataType::TYPE_STRING);

        // no CCA line, but a Route cell: it is taken for a CCA sheet that cannot be read
        foreach (['B7' => 'Route', 'C7' => 'Verified count'] as $cell => $text) {
            $sheet->setCellValue($cell, $text);
        }

        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $preview = $this->previewPath($path);

        $this->assertStringContainsString('1 of 4 CCA sheets could not be read', $this->messages($preview, 'errors'));
        $this->assertStringNotContainsString('have no sheet', $this->messages($preview, 'warnings'));
        @unlink($path);
    }

    public function test_the_excluded_account_may_be_on_the_map_and_have_a_sheet(): void
    {
        $preview = $this->previewSpec($this->spec(['header' => 'real', 'ccas' => [
            ['id' => '80001', 'name' => 'AMA TEST', 'routes' => [['label' => 'SOWUTUOM 4602', 'strength' => 200, 'read' => 150, 'skipped' => 30, 'visited' => 180]]],
            ['id' => '00000', 'name' => 'System Administrator', 'routes' => [['label' => 'SOWUTUOM 9999', 'strength' => 10, 'read' => 5, 'skipped' => 1, 'visited' => 6]]],
        ]]));

        $this->assertFalse($preview['blocked'], $this->messages($preview, 'errors'));
        $this->assertSame(1, $preview['route_report']['sheets']);
        $this->assertSame(1, $preview['system_count']);
        $this->assertStringNotContainsString('Document map', $this->messages($preview, 'warnings'));
    }

    // ---------------------------------------------------------------- the period

    public function test_the_report_period_is_read_in_every_way_the_report_prints_it(): void
    {
        $month = ['from' => '2026-09-01', 'to' => '2026-09-30'];

        foreach ([
            '01-September-2026 - 30-September-2026' => $month,
            '01-Sep-2026 - 30-Sep-2026' => $month,
            '01/09/2026 - 30/09/2026' => $month,
            '1 September 2026 to 30 September 2026' => $month,
            '2026-09-01 - 2026-09-30' => $month,
            '05-September-2026' => ['from' => '2026-09-05', 'to' => '2026-09-05'],
            '05/09/2026' => ['from' => '2026-09-05', 'to' => '2026-09-05'],
            '12/09/2026 - 11/10/2026' => ['from' => '2026-09-12', 'to' => '2026-10-11'],   // day first: the 12th of September, not December
        ] as $text => $expected) {
            $preview = $this->previewSpec($this->spec(['period' => $text]));

            $this->assertFalse($preview['blocked'], $text.': '.$this->messages($preview, 'errors'));
            $this->assertSame($expected, $preview['period'], $text);
        }

        foreach (['last fortnight', '30/09/2026 - 01/09/2026', '31/02/2026 - 28/02/2026'] as $bad) {
            $preview = $this->previewSpec($this->spec(['period' => $bad]));

            $this->assertTrue($preview['blocked'], $bad);
            $this->assertStringContainsString('could not be read as two dates', $this->messages($preview, 'errors'), $bad);
        }
    }

    public function test_the_detailed_billing_period_is_day_first_too(): void
    {
        $service = app(BillingDetailsImportService::class);

        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $service->readPeriod('01/09/2026 - 30/09/2026'), 'the first of September, never the ninth of January');
        $this->assertSame(['from' => '2026-09-05', 'to' => '2026-09-05'], $service->readPeriod('05/09/2026'));
        $this->assertNull($service->readPeriod('31/02/2026'));
    }

    // ---------------------------------------------------------------- the committed fixture

    public function test_the_committed_fixture_has_the_real_layout(): void
    {
        $copy = tempnam(sys_get_temp_dir(), 'rrs').'.xlsx';
        copy(base_path('tests/Fixtures/reading-route-sample.xlsx'), $copy);
        $book = IOFactory::load($copy);

        $this->assertNotNull($book->getSheetByName('Document map'));
        $sheet = $book->getSheetByName('Sheet2');
        $this->assertNotNull($sheet);
        $this->assertSame('Verified', $sheet->getCell('E7')->getValue());
        $this->assertSame('Strength', $sheet->getCell('E8')->getValue());
        $this->assertContains('B7:D7', array_keys($sheet->getMergeCells()));
        $this->assertContains('B3:Q3', array_keys($sheet->getMergeCells()));
        $book->disconnectWorksheets();
        @unlink($copy);
    }
}
