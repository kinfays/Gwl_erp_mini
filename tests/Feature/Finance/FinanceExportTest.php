<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Finance\FinanceExportService;
use App\Services\Finance\FinanceScope;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Excel and PDF downloads: the same scoped data as the screens, one location per file, capped, audited with counts only. */
class FinanceExportTest extends FinanceTestCase
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

    private function exports(): FinanceExportService
    {
        return app(FinanceExportService::class);
    }

    private function west(): array
    {
        return ['region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id];
    }

    private function download($response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'fex').'.xlsx';
        file_put_contents($path, $response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? file_get_contents($response->baseResponse->getFile()->getPathname()) : $response->streamedContent());
        $this->files[] = $path;

        return IOFactory::load($path);
    }

    private function period(array $extra = []): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-11-07'] + $extra;
    }

    private function books(): array
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);

        $one = $this->sent($fin, ['pv_no' => 'EX-1', 'pv_date' => '2026-10-05', 'amount' => '1250.50', 'details' => '=1+1']);
        $this->actingAs($aud);
        $this->workflow()->auditAcknowledge($aud, $one);
        $this->sent($fin, ['pv_no' => 'EX-2', 'pv_date' => '2026-10-20', 'amount' => '50.00']);
        $this->draft($fin, ['pv_no' => 'EX-DRAFT', 'pv_date' => '2026-10-21']);
        $this->sent($ash, ['pv_no' => 'KUMASI-ONLY', 'pv_date' => '2026-10-10']);

        return compact('fin', 'aud', 'ash');
    }

    public function test_the_register_lists_the_locations_vouchers_with_marks_and_amounts(): void
    {
        ['fin' => $fin, 'aud' => $aud] = $this->books();

        $report = $this->exports()->build('register', $fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07', 'Accra West');
        $rows = collect($report['tables'][0]['rows']);

        $this->assertSame(['EX-1', 'EX-2', 'EX-DRAFT'], $rows->pluck(1)->all(), 'oldest first; Kumasi\'s voucher is not here');
        $this->assertSame(1250.5, $rows[0][7]);
        $this->assertSame(['Audit acknowledged', 'Acknowledged', 'Waiting'], [$rows[0][8], $rows[0][10], $rows[0][11]]);
        $this->assertSame(3, $report['row_count']);
        $this->assertSame('Accra West', $report['meta']['Location']);

        $audit = $this->exports()->build('register', $aud, FinanceScope::AS_AUDIT, $this->west(), '2026-10-01', '2026-11-07', 'Accra West');
        $this->assertSame(['EX-1', 'EX-2'], collect($audit['tables'][0]['rows'])->pluck(1)->all(), 'Audit never sees a draft');
    }

    public function test_a_register_over_the_cap_builds_nothing_and_reports_its_size(): void
    {
        ['fin' => $fin] = $this->books();

        $report = $this->exports()->build('register', $fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07', 'Accra West', cap: 2);

        $this->assertSame(3, $report['row_count']);
        $this->assertSame([], $report['tables']);
    }

    public function test_the_summary_and_the_waiting_list_come_from_the_same_numbers_as_the_screens(): void
    {
        ['fin' => $fin] = $this->books();

        $summary = $this->exports()->build('summary', $fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07', 'Accra West');
        $titles = array_column($summary['tables'], 'title');
        $this->assertSame(['Headline', 'Spend by category', 'Spend by type', 'Spend by payee type', 'By month', 'Top suppliers', 'Why Audit queried', 'Turnaround', 'Waiting right now'], $titles);

        $headline = collect($summary['tables'][0]['rows'])->pluck(1, 0);
        $this->assertSame(2, $headline['Vouchers that went ahead']);
        $this->assertSame(1300.5, $headline['Total amount (GH¢)']);
        $this->assertSame(1, $headline['Drafts (not sent to Audit)']);

        $waiting = $this->exports()->build('waiting', $fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07', 'Accra West');
        $this->assertSame(['EX-1', 'EX-2'], collect($waiting['tables'][0]['rows'])->pluck(0)->sort()->values()->all(), 'one is With Audit, the other ticked and waiting for Finance');
    }

    public function test_the_excel_download_keeps_text_as_text_and_is_audited_with_counts_only(): void
    {
        ['fin' => $fin] = $this->books();

        $response = $this->actingAs($fin)->get(route('finance.export', ['report' => 'register', 'format' => 'excel'] + $this->period()));
        $response->assertOk();

        $book = $this->download($response);
        $this->assertSame(['Notes', 'Vouchers'], $book->getSheetNames());
        $sheet = $book->getSheetByName('Vouchers');
        $this->assertSame('EX-1', $sheet->getCell('B2')->getValue());
        $this->assertSame('=1+1', $sheet->getCell('G2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('G2')->getDataType(), 'text that looks like a formula stays text');
        $this->assertStringNotContainsString('KUMASI-ONLY', json_encode($sheet->toArray()));

        $row = AuditLog::query()->where('action', 'finance.export_register_excel')->firstOrFail();
        $this->assertSame('finance', $row->module);
        $this->assertSame(['report' => 'Payment voucher register', 'format' => 'excel', 'from' => '2026-10-01', 'to' => '2026-11-07', 'rows' => 3, 'region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id], $row->metadata);
        $this->assertStringNotContainsString('EX-1', $row->toJson());
        $this->assertStringNotContainsString('1250', $row->toJson());
    }

    public function test_the_pdf_download_works_and_audit_has_its_own_door(): void
    {
        ['fin' => $fin, 'aud' => $aud] = $this->books();

        $pdf = $this->actingAs($fin)->get(route('finance.export', ['report' => 'summary', 'format' => 'pdf'] + $this->period()));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($aud)->get(route('audit.export', ['report' => 'register', 'format' => 'excel'] + $this->period()))->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'audit.export_register_excel')->where('module', 'audit')->count());

        // Neither role can use the other's door.
        $this->actingAs($aud)->get(route('finance.export', ['report' => 'register', 'format' => 'excel'] + $this->period()))->assertRedirect();
        $this->actingAs($fin)->get(route('audit.export', ['report' => 'register', 'format' => 'excel'] + $this->period()))->assertRedirect();
    }

    public function test_an_export_needs_both_the_export_and_the_view_reports_permission(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $export = Permission::query()->where('name', 'finance.export')->firstOrFail();
        $reports = Permission::query()->where('name', 'finance.view_reports')->firstOrFail();
        $role = Role::query()->where('name', 'finance_officer')->firstOrFail();
        $url = route('finance.export', ['report' => 'summary', 'format' => 'excel'] + $this->period());

        $this->actingAs($fin)->get($url)->assertOk();

        $role->permissions()->detach($export->id);
        $this->actingAs($this->finance('F2', $this->sowutuom)->fresh())->get($url)->assertStatus(403);

        $role->permissions()->attach($export->id);
        $role->permissions()->detach($reports->id);
        $this->actingAs($this->finance('F3', $this->sowutuom)->fresh())->get($url)->assertStatus(403);
        $this->assertSame(1, AuditLog::query()->where('action', 'like', 'finance.export_%')->count(), 'refused downloads leave no audit row');
    }

    public function test_super_admin_names_the_location_and_an_unknown_report_or_format_is_a_404(): void
    {
        $this->books();
        $super = $this->superAdmin();
        $url = fn (array $extra = []) => route('finance.export', ['report' => 'register', 'format' => 'excel'] + $this->period($extra));

        $this->actingAs($super)->from(route('finance.reports'))->get($url())->assertRedirect(route('finance.reports'))->assertSessionHas('error');
        $this->actingAs($super)->get($url(['district' => $this->sowutuom->id]))->assertOk();

        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin)->get(route('finance.export', ['report' => 'nonsense', 'format' => 'excel']))->assertNotFound();
        $this->actingAs($fin)->get(route('finance.export', ['report' => 'register', 'format' => 'csv']))->assertNotFound();

        // A normal user's ?district= is ignored: they only ever get their own location.
        $book = $this->download($this->actingAs($fin)->get(route('finance.export', ['report' => 'register', 'format' => 'excel'] + $this->period(['district' => $this->kumasi->id]))));
        $this->assertStringNotContainsString('KUMASI-ONLY', json_encode($book->getSheetByName('Vouchers')->toArray()));
    }

    public function test_a_file_over_the_row_cap_is_refused_with_a_message_and_no_audit_row(): void
    {
        ['fin' => $fin] = $this->books();
        config(['gwl.fin_export_max_rows' => 2]);

        $this->actingAs($fin)->from(route('finance.reports'))
            ->get(route('finance.export', ['report' => 'register', 'format' => 'excel'] + $this->period()))
            ->assertRedirect(route('finance.reports'))->assertSessionHas('error');

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'finance.export_%')->count());
    }

    public function test_the_reports_screens_are_gated_and_show_the_export_buttons_only_with_export(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom);

        $this->actingAs($fin)->get(route('finance.reports'))->assertOk()->assertSee('Summary (Excel)');
        $this->actingAs($aud)->get(route('audit.reports'))->assertOk()->assertSee('Register (Excel)');
        $this->actingAs($plain)->get(route('finance.reports'))->assertRedirect();
        $this->actingAs($aud)->get(route('finance.reports'))->assertRedirect();
        $this->actingAs($fin)->get(route('audit.reports'))->assertRedirect();
    }
}
