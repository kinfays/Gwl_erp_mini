<?php

namespace Tests\Feature\Commercial;

use App\Models\AuditLog;
use App\Models\CommercialImportBatch;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Every export, as a user downloads it. "Today" is 15 Oct 2026. */
class CommercialExportsTest extends CommercialTestCase
{
    private const COMBOS = [
        ['summary', 'excel'], ['summary', 'pdf'],
        ['reading-trend', 'excel'],
        ['readers', 'excel'], ['readers', 'pdf'],
        ['billing', 'excel'], ['billing', 'pdf'],
        ['scorecard', 'excel'], ['scorecard', 'pdf'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function seedBoth(array $readingOptions = [], array $billingOptions = []): void
    {
        $this->seedReading([
            'R1' => ['2026-08-01' => [400, 100], '2026-09-01' => [450, 50]],
            'R2' => ['2026-08-01' => [200, 100], '2026-09-01' => [220, 80]],
        ], options: $readingOptions + ['names' => ['R1' => 'ADWOA BOATENG', 'R2' => 'KWESI PAINTSIL'], 'districts' => ['R1' => $this->sowutuom->id, 'R2' => $this->odorkor->id]]);

        $this->seedBilling($this->billingFixture(), '2026-09-01', $billingOptions + ['districts' => ['SOWUTUOM' => $this->sowutuom->id]]);
    }

    /** A user holding exactly the given commercial permissions (and nothing else). */
    protected function userWith(string $staffId, array $permissions, ?\App\Models\Region $region = null): User
    {
        $role = Role::query()->create(['name' => 'custom_'.$staffId, 'display_name' => 'Custom '.$staffId, 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);

        $user = $this->userWithRoles($staffId, [], $region);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    protected function export(User $user, string $report, string $format, array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('commercial.export', ['report' => $report, 'format' => $format, ...$query]));
    }

    protected function book(TestResponse $response): Spreadsheet
    {
        return IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    /** @return array<string, list<list<mixed>>> sheet title => rows */
    protected function sheets(TestResponse $response): array
    {
        $out = [];

        foreach ($this->book($response)->getAllSheets() as $sheet) {
            $out[$sheet->getTitle()] = $sheet->toArray(null, false, false, false);
        }

        return $out;
    }

    protected function everyCell(TestResponse $response): string
    {
        return json_encode(array_map(fn ($rows) => $rows, $this->sheets($response)));
    }

    // ---------------------------------------------------------------- downloads

    public function test_every_export_downloads_for_a_permitted_user_with_a_sensible_filename(): void
    {
        $this->seedBoth();
        $officer = $this->officer();

        foreach (self::COMBOS as [$report, $format]) {
            $response = $this->export($officer, $report, $format)->assertOk();

            $extension = $format === 'excel' ? 'xlsx' : 'pdf';
            $disposition = (string) $response->headers->get('content-disposition');
            $this->assertMatchesRegularExpression('/commercial_'.str_replace('-', '_', $report).'_accra-west_[a-z0-9-]+_20261015\.'.$extension.'/', $disposition, "{$report} {$format}: {$disposition}");

            if ($format === 'pdf') {
                $this->assertStringStartsWith('%PDF', $response->getContent(), "{$report} PDF must be a PDF");
                $this->assertSame('application/pdf', $response->headers->get('content-type'));
            } else {
                $this->assertArrayHasKey('Notes', $this->sheets($response), "{$report} workbook has a Notes sheet");
            }
        }

        $filename = (string) $this->export($officer, 'billing', 'excel')->headers->get('content-disposition');
        $this->assertStringContainsString('commercial_billing_accra-west_sep-2026_20261015.xlsx', $filename);
    }

    public function test_an_unknown_report_or_format_is_a_404(): void
    {
        $officer = $this->officer();

        $this->export($officer, 'nope', 'excel')->assertNotFound();
        $this->export($officer, 'reading-trend', 'pdf')->assertNotFound();   // that one is Excel only
        $this->actingAs($officer)->get('/commercial/export/billing/csv')->assertNotFound();
    }

    public function test_each_export_is_audited_with_its_filters_and_row_count(): void
    {
        $this->seedBoth();
        $officer = $this->officer();

        $response = $this->export($officer, 'billing', 'excel')->assertOk();
        $rows = collect($this->sheets($response))->except('Notes')->sum(fn ($sheet) => max(0, count($sheet) - 1));

        $log = AuditLog::query()->where('action', 'commercial.export_billing_excel')->sole();
        $this->assertSame(Permission::MODULE_COMMERCIAL, $log->module);
        $this->assertSame($rows, $log->metadata['rows']);
        $this->assertSame('excel', $log->metadata['format']);
        $this->assertArrayHasKey('snapshot', $log->metadata['filters']);

        $this->export($officer, 'readers', 'pdf')->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.export_readers_pdf')->count());

        $this->export($officer, 'reading-trend', 'excel', ['from' => '2026-08', 'to' => '2026-09'])->assertOk();
        $this->assertSame('2026-08', AuditLog::query()->where('action', 'commercial.export_reading_trend_excel')->sole()->metadata['filters']['from']);
    }

    // ---------------------------------------------------------------- permissions

    public function test_without_export_reports_every_export_is_403(): void
    {
        $this->seedBoth();
        $viewer = $this->userWithRoles('900060', ['district_manager']);

        $this->assertTrue($viewer->hasPermission('commercial.view_billing'));
        $this->assertFalse($viewer->hasPermission('commercial.export_reports'));

        foreach (self::COMBOS as [$report, $format]) {
            $this->export($viewer, $report, $format)->assertForbidden();
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'commercial.export_%')->count());
    }

    public function test_a_reader_level_export_needs_view_reader_performance_too(): void
    {
        $this->seedBoth();
        $user = $this->userWith('900061', ['commercial.export_reports', 'commercial.view_reading', 'commercial.view_billing']);

        $this->export($user, 'readers', 'excel')->assertForbidden();
        $this->export($user, 'readers', 'pdf')->assertForbidden();

        // The aggregate exports are fine.
        $this->export($user, 'reading-trend', 'excel')->assertOk();
        $this->export($user, 'billing', 'excel')->assertOk();
        $this->export($user, 'scorecard', 'excel')->assertOk();
        $this->export($user, 'summary', 'excel')->assertOk();

        $withReaders = $this->userWith('900062', ['commercial.export_reports', 'commercial.view_reader_performance']);
        $this->export($withReaders, 'readers', 'excel')->assertOk();
        $this->export($withReaders, 'reading-trend', 'excel')->assertForbidden();   // that needs view_reading
    }

    public function test_the_billing_and_scorecard_exports_need_their_view_permissions(): void
    {
        $this->seedBoth();
        $readingOnly = $this->userWith('900063', ['commercial.export_reports', 'commercial.view_reading']);
        $billingOnly = $this->userWith('900064', ['commercial.export_reports', 'commercial.view_billing']);

        $this->export($readingOnly, 'billing', 'excel')->assertForbidden();
        $this->export($readingOnly, 'scorecard', 'excel')->assertForbidden();
        $this->export($billingOnly, 'scorecard', 'excel')->assertForbidden();   // needs both
        $this->export($billingOnly, 'billing', 'pdf')->assertOk();
        $this->export($readingOnly, 'reading-trend', 'excel')->assertOk();
    }

    public function test_the_summary_export_needs_at_least_one_view_permission(): void
    {
        $this->seedBoth();
        $nothing = $this->userWith('900065', ['commercial.export_reports']);

        $this->export($nothing, 'summary', 'excel')->assertForbidden();
    }

    // ---------------------------------------------------------------- scope

    public function test_an_export_is_region_scoped_like_the_screens(): void
    {
        $this->seedBoth();
        $this->seedReading(['A1' => ['2026-09-01' => [999, 1]]], $this->ashanti, ['names' => ['A1' => 'ASHANTI READER'], 'strength' => 5000]);
        $foreign = $this->seedBilling([['district' => 'KUMASI', 'code' => 'KUMASI 1', 'billing_for_period' => 99999]], '2026-09-01', ['region' => $this->ashanti]);
        $officer = $this->officer();

        $readers = $this->export($officer, 'readers', 'excel')->assertOk();
        $text = $this->everyCell($readers);
        $this->assertStringContainsString('ADWOA BOATENG', $text);
        $this->assertStringNotContainsString('ASHANTI READER', $text);

        $billing = $this->export($officer, 'billing', 'excel')->assertOk();
        $text = $this->everyCell($billing);
        $this->assertStringContainsString('SOWUTUOM 1', $text);
        $this->assertStringNotContainsString('KUMASI', $text);

        // Asking for another region's snapshot (or region) by hand does not widen the file.
        $handEdited = $this->export($officer, 'billing', 'excel', ['snapshot' => $foreign->id])->assertOk();
        $this->assertStringNotContainsString('KUMASI', $this->everyCell($handEdited));
        $this->assertStringNotContainsString('ASHANTI READER', $this->everyCell($this->export($officer, 'readers', 'excel', ['region' => $this->ashanti->id])));
        $this->assertStringNotContainsString('ASHANTI READER', $this->everyCell($this->export($officer, 'reading-trend', 'excel', ['region' => $this->ashanti->id])));

        // Head office may pick the other region.
        $headOffice = $this->officer('900010', $this->accraWest, $this->headOffice);
        $this->assertStringContainsString('ASHANTI READER', $this->everyCell($this->export($headOffice, 'readers', 'excel', ['region' => $this->ashanti->id])));
        $this->assertStringContainsString('KUMASI', $this->everyCell($this->export($headOffice, 'billing', 'excel', ['snapshot' => $foreign->id])));
    }

    // ---------------------------------------------------------------- spreadsheet formulas

    public function test_text_that_looks_like_a_formula_is_stored_as_text(): void
    {
        $evil = '=1+1';
        $this->seedReading(['R1' => ['2026-09-01' => [100, 0]]], options: ['names' => ['R1' => $evil]]);
        $this->seedBilling([['district' => '@SUM(1,1)', 'code' => $evil, 'billing_for_period' => 10]], '2026-09-01');
        $officer = $this->officer();

        foreach ([['readers', 'League table'], ['billing', 'Routes']] as [$report, $sheetTitle]) {
            $book = $this->book($this->export($officer, $report, 'excel')->assertOk());
            $found = false;

            foreach ($book->getAllSheets() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $this->assertFalse($cell->isFormula(), "{$report} {$sheet->getTitle()}!{$cell->getCoordinate()} must not be a formula");

                        if ($cell->getValue() === $evil) {
                            $found = true;
                            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), 'the text is kept exactly, as a string');
                        }
                    }
                }
            }

            $this->assertTrue($found, "{$report}: the text '=1+1' should appear as text in the workbook");
        }

        // A district label starting with @ is text too.
        $routes = $this->book($this->export($officer, 'billing', 'excel'))->getSheetByName('Routes');
        $this->assertSame('@SUM(1,1)', $routes->getCell('C2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $routes->getCell('C2')->getDataType());
    }

    // ---------------------------------------------------------------- row cap

    public function test_over_the_row_cap_the_user_gets_a_message_not_a_file(): void
    {
        $this->seedBoth();
        config(['gwl.commercial_export_max_rows' => 3]);

        $response = $this->actingAs($this->officer())->from(route('commercial.billing'))->get(route('commercial.export', ['report' => 'billing', 'format' => 'excel']));

        $response->assertRedirect(route('commercial.billing'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Narrow the filters', session('error'));
        $this->assertStringContainsString('over the Excel limit of 3', session('error'));
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'commercial.export_%')->count(), 'no file, no audit row');
    }

    public function test_a_pdf_has_its_own_lower_row_cap_than_excel(): void
    {
        $this->seedBoth();
        config(['gwl.commercial_export_max_rows' => 5000, 'gwl.commercial_export_pdf_max_rows' => 3]);
        $officer = $this->officer();

        $pdf = $this->actingAs($officer)->from(route('commercial.billing'))->get(route('commercial.export', ['report' => 'billing', 'format' => 'pdf']));

        $pdf->assertRedirect(route('commercial.billing'));
        $this->assertStringContainsString('over the PDF limit of 3', session('error'));
        $this->assertStringContainsString('download the Excel file instead', session('error'));
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'commercial.export_%')->count(), 'no file, no audit row');

        // The same rows are fine as a spreadsheet, because only the PDF cap is 3.
        $this->export($officer, 'billing', 'excel')->assertOk();
        $this->assertGreaterThan(0, AuditLog::query()->where('action', 'like', 'commercial.export_%')->count());
    }

    public function test_a_pdf_under_its_cap_downloads(): void
    {
        $this->seedBoth();
        config(['gwl.commercial_export_pdf_max_rows' => 1500]);

        $this->export($this->officer(), 'billing', 'pdf')->assertOk();
    }

    // ---------------------------------------------------------------- numbers stay numbers

    public function test_numbers_stay_numbers_and_text_stays_text_on_the_billing_routes_sheet(): void
    {
        $this->seedBilling([
            ['district' => 'SOWUTUOM', 'code' => 'SOWUTUOM 1', 'customers_count' => 120, 'billing_for_period' => 1234.5, 'billed_total' => 10],
            ['district' => 'SOWUTUOM', 'code' => '=HYPERLINK("x")', 'customers_count' => 80, 'billing_for_period' => 99, 'billed_total' => 2],
        ], '2026-09-01');

        $sheet = $this->book($this->export($this->officer(), 'billing', 'excel')->assertOk())->getSheetByName('Routes');
        $headers = $sheet->toArray(null, false, false, false)[0];
        $column = fn (string $name) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search($name, $headers, true) + 1);

        $this->assertNotFalse(array_search('Billing (GH¢)', $headers, true), 'the billing column is there: '.implode('|', $headers));
        $billing = $column('Billing (GH¢)');
        $route = $column('Route');

        foreach ([2, 3] as $row) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($billing.$row)->getDataType(), "{$billing}{$row} (billing) is a number, not text");
            $this->assertIsNumeric($sheet->getCell($billing.$row)->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($route.$row)->getDataType(), "{$route}{$row} (route code) is text");
        }

        $this->assertEquals(1234.5, $sheet->getCell($billing.'2')->getValue());

        $formulaCell = collect([2, 3])->map(fn ($row) => $sheet->getCell($route.$row))->first(fn ($cell) => str_starts_with((string) $cell->getValue(), '='));
        $this->assertNotNull($formulaCell, 'the route code that starts with = is kept');
        $this->assertFalse($formulaCell->isFormula());
        $this->assertSame('=HYPERLINK("x")', $formulaCell->getValue());
    }

    // ---------------------------------------------------------------- contents

    public function test_the_executive_summary_export_has_no_reader_names(): void
    {
        $this->seedBoth();
        $officer = $this->officer();

        $excel = $this->everyCell($this->export($officer, 'summary', 'excel')->assertOk());
        $this->assertStringNotContainsString('ADWOA', $excel);
        $this->assertStringNotContainsString('PAINTSIL', $excel);
        $this->assertStringContainsString('Cash collection ratio', $excel);
        $this->assertStringContainsString('Data freshness', $excel);

        $this->assertStringNotContainsString('ADWOA', $this->export($officer, 'summary', 'pdf')->getContent());
    }

    public function test_the_not_like_for_like_note_is_in_the_scorecard_and_summary_exports_only_for_a_segment(): void
    {
        $this->seedBoth();   // new_service
        $officer = $this->officer();

        $notes = fn (string $report) => json_encode($this->sheets($this->export($officer, $report, 'excel'))['Notes']);

        $this->assertStringContainsString('not like for like', $notes('scorecard'));
        $this->assertStringContainsString('not like for like', $notes('summary'));
        $this->assertStringContainsString("reader's HOME district", str_replace("\\u0027", "'", $notes('scorecard')));

        CommercialImportBatch::query()->where('report_type', 'billing_summary')->update(['customer_segment' => 'all']);

        $this->assertStringNotContainsString('not like for like', $notes('scorecard'));
        $this->assertStringNotContainsString('not like for like', $notes('summary'));
    }

    public function test_the_billing_workbook_has_one_sheet_per_tab(): void
    {
        $this->seedBoth(billingOptions: ['bands' => [['<=5', 80, 400, 3000], ['>5', 20, 600, 7000]]]);

        $sheets = $this->sheets($this->export($this->officer(), 'billing', 'excel')->assertOk());

        $this->assertSame(['Notes', 'Overview', 'Routes', 'Balance roll-forward', 'Collections', 'Balances (credits)', 'Estimation and unbilled', 'Consumption bands', 'Exceptions'], array_keys($sheets));
        $this->assertCount(4 + 1, $sheets['Routes'], 'a heading row and the four routes');
        $this->assertSame(1600, (int) $sheets['Overview'][array_key_last($sheets['Overview'])][2], 'the All shown row carries the 1,600 total');
        $this->assertSame(2 + 1, count($sheets['Consumption bands']));
        $this->assertStringContainsString('customer credits (to be confirmed)', json_encode($sheets['Notes']));
        $this->assertStringNotContainsString('7777', json_encode($sheets), 'customers_count is in no export');
    }
}
