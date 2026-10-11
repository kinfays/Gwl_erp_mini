<?php

namespace Tests\Feature\GWater;

use App\Exports\Commercial\CommercialReportExport;
use App\Livewire\GWater\Overview;
use App\Livewire\GWater\ProfitAndLoss;
use App\Models\AuditLog;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\GWater\GwaterExportService;
use App\Services\GWater\GwaterProfitService;
use App\Services\GWater\GwaterSummaryService;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** The Profit and loss screen, the full Overview and the exports (Excel and PDF). */
class GwaterExportAndPnlScreenTest extends GwaterTestCase
{
    private function load(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'gwx').'.xlsx';
        file_put_contents($path, $bytes);

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    private function excel(User $user, string $report, array $query = []): Spreadsheet
    {
        $response = $this->actingAs($user)->get(route('gwater.export', ['report' => $report, 'format' => 'excel', 'from' => '2026-10-01', 'to' => '2026-10-31'] + $query));
        $response->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

        return $this->load($response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ? file_get_contents($response->baseResponse->getFile()->getPathname())
            : $response->streamedContent());
    }

    /** A month with a few entries at Sowutuom. */
    private function someMonth(User $user): void
    {
        $this->actingAs($user);
        $this->trip($user, 'WB-1', 100, 30, '2026-10-01', ['loading_amount' => 3000, 'fuel_amount' => 5000]);
        $this->workflow()->recordSale($user, $this->saleData(40, '2026-10-03'));
        $this->workflow()->recordDamage($user, ['damage_date' => '2026-10-04', 'product_id' => $this->bottle->id, 'packs' => 5, 'stage' => 'storage', 'reason' => 'Cracked']);
    }

    private function viewer(string $staffId): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'gw_viewer'], ['display_name' => 'G-Water viewer', 'description' => 'view only', 'is_system' => false]);
        $role->permissions()->syncWithoutDetaching([Permission::query()->where('name', 'gwater.view')->value('id')]);
        ModuleAccess::query()->updateOrCreate(['role_id' => $role->id, 'module' => Permission::MODULE_GWATER], ['can_access' => true]);
        $user = $this->person($staffId, $this->sowutuom, ['employee']);
        $user->roles()->syncWithoutDetaching($role);

        return $user->fresh();
    }

    // ---------------------------------------------------------------- exports

    public function test_the_excel_export_matches_the_profit_and_loss_the_screen_shows(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->someMonth($user);
        $place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];
        $report = app(GwaterProfitService::class)->report($place, '2026-10-01', '2026-10-31', $user)['combined'];

        $book = $this->excel($user, 'pnl');
        $sheet = $book->getSheetByName('Profit and loss');
        $rows = collect($sheet->toArray())->keyBy(0);

        $this->assertEquals($report['revenue'] / 100, $rows['Revenue (GH¢)'][1]);
        $this->assertEquals($report['net_profit'] / 100, $rows['Net profit (GH¢)'][1]);
        $this->assertEquals($report['damages'] / 100, $rows['Damages written off at cost (GH¢)'][1]);
        $this->assertSame('Notes', $book->getSheet(0)->getTitle());
        $this->assertContains('Trip costs', $book->getSheetNames());
    }

    public function test_the_pdf_export_downloads(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->someMonth($user);

        $response = $this->actingAs($user)->get(route('gwater.export', ['report' => 'pnl', 'format' => 'pdf', 'from' => '2026-10-01', 'to' => '2026-10-31']));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_every_report_builds_and_its_totals_agree_with_the_services_behind_the_screens(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->someMonth($user);
        $payer = $this->person('P1', $this->sowutuom);
        $this->workflow()->recordExpense($user, ['expense_date' => '2026-10-05', 'category' => 'fuel', 'amount' => 8000, 'paid_by_user_id' => $payer->id]);
        $this->workflow()->recordPayment($user, ['request_date' => '2026-10-06', 'reference' => 'PR-1', 'amount' => 40000, 'paid_on' => '2026-10-07']);
        $place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];
        $exports = app(GwaterExportService::class);

        foreach (array_keys(GwaterExportService::REPORTS) as $key) {
            $built = $exports->build($key, $place, '2026-10-01', '2026-10-31', $user, 'Accra West');
            $this->assertSame(GwaterExportService::REPORTS[$key]['title'], $built['title']);
            $this->assertGreaterThan(0, $built['row_count'], $key);
        }

        // Payments: the owed figure is the screen's.
        $payments = $exports->build('payments', $place, '2026-10-01', '2026-10-31', $user, 'Accra West');
        $owed = collect($payments['tables'])->last()['rows'][0][1];
        $this->assertEquals(app(GwaterSummaryService::class)->owedToGwater($place, \Illuminate\Support\Carbon::parse('2026-10-31')) / 100, $owed);

        // Expenses: the total of the rows is the total of the posted expenses.
        $expenses = $exports->build('expenses', $place, '2026-10-01', '2026-10-31', $user, 'Accra West');
        $this->assertEquals((5000 + 3000 + 8000) / 100, collect($expenses['tables'][0]['rows'])->sum(2));

        // Reimbursements: the pending fuel of the payer.
        $reimbursements = $exports->build('reimbursements', $place, '2026-10-01', '2026-10-31', $user, 'Accra West');
        $this->assertSame([[$payer->full_name, '05 Oct 2026', '', 80.0, 'G-Water expense', '', '']], $reimbursements['tables'][0]['rows']);
    }

    public function test_text_from_users_stays_text_in_the_workbook(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $this->workflow()->recordExpense($user, ['expense_date' => '2026-10-05', 'category' => 'other', 'amount' => 1000, 'receipt_ref' => '=1+1', 'payee' => '@SUM(A1:A2)']);

        $book = $this->excel($user, 'expenses');
        $sheet = $book->getSheetByName('Expenses');

        foreach (['G2' => '=1+1', 'F2' => '@SUM(A1:A2)'] as $address => $expected) {
            $cell = $sheet->getCell($address);
            $this->assertSame($expected, $cell->getValue());
            $this->assertSame('s', $cell->getDataType(), $address.' must be text, not a formula');
        }
    }

    public function test_an_export_needs_gwater_export_and_view_reports(): void
    {
        $viewer = $this->viewer('V1');
        $plain = $this->person('E1', $this->sowutuom);

        // View alone is not enough, nor is no G-Water access at all.
        $this->actingAs($viewer)->get(route('gwater.export', ['report' => 'pnl', 'format' => 'excel']))->assertForbidden();
        $this->assertContains($this->actingAs($plain)->get(route('gwater.export', ['report' => 'pnl', 'format' => 'excel']))->getStatusCode(), [302, 403]);
        $this->actingAs($this->gw('G1', $this->sowutuom))->get(route('gwater.export', ['report' => 'pnl', 'format' => 'excel']))->assertOk();

        // An unknown report or format is a 404 (the route pattern or the report list refuses it).
        $this->actingAs($this->gw('G2', $this->sowutuom))->get(route('gwater.export', ['report' => 'secrets', 'format' => 'excel']))->assertNotFound();
    }

    public function test_the_screen_shows_export_buttons_only_to_those_allowed_them(): void
    {
        $this->someMonth($user = $this->gw('G1', $this->sowutuom));
        $viewer = $this->viewer('V1');

        Livewire::actingAs($user)->test(\App\Livewire\GWater\Damages::class)->assertSee('/gwater/export/damages/excel', false);
        Livewire::actingAs($viewer)->test(\App\Livewire\GWater\Damages::class)->assertDontSee('/gwater/export/', false);
    }

    public function test_an_export_over_the_row_cap_is_refused_with_a_message(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->someMonth($user);
        config(['gwl.gwater_export_max_rows' => 2, 'gwl.gwater_export_pdf_max_rows' => 2]);

        foreach (['excel', 'pdf'] as $format) {
            $this->actingAs($user)->get(route('gwater.export', ['report' => 'stock', 'format' => $format, 'from' => '2026-10-01', 'to' => '2026-10-31']))
                ->assertRedirect()->assertSessionHas('error');
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'gwater.export_%')->count(), 'a refused export is not an export');
    }

    public function test_every_export_is_audited_without_personal_data(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->someMonth($user);
        $this->actingAs($user)->get(route('gwater.export', ['report' => 'damages', 'format' => 'excel', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();

        $row = AuditLog::query()->where('action', 'gwater.export_damages_excel')->firstOrFail();

        $this->assertSame($user->id, $row->user_id);
        $this->assertEqualsCanonicalizing(['report', 'format', 'from', 'to', 'rows', 'region_id', 'district_id'], array_keys($row->metadata));
        $this->assertSame([1, 'Damages', '2026-10-01', '2026-10-31'], [$row->metadata['rows'], $row->metadata['report'], $row->metadata['from'], $row->metadata['to']]);
        // The row says who took it (that is what the audit log is for); what it holds about the data is only ids and counts.
        $this->assertStringNotContainsString('Staff', json_encode($row->metadata));
        $this->assertNull($row->old_values);
        $this->assertNull($row->new_values);
    }

    public function test_an_export_only_ever_holds_the_users_own_location(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $kumasi = $this->gw('G2', $this->kumasi);
        $headOffice = $this->gw('G3', $this->headOffice);
        $this->actingAs($accra);
        $this->workflow()->recordExpense($accra, ['expense_date' => '2026-10-05', 'category' => 'other', 'amount' => 1000, 'receipt_ref' => 'ACCRA-ONLY']);
        $this->actingAs($kumasi);
        $this->workflow()->recordExpense($kumasi, ['expense_date' => '2026-10-05', 'category' => 'other', 'amount' => 2000, 'receipt_ref' => 'KUMASI-ONLY']);
        $this->actingAs($headOffice);
        $this->workflow()->recordExpense($headOffice, ['expense_date' => '2026-10-05', 'category' => 'other', 'amount' => 3000, 'receipt_ref' => 'HO-ONLY']);

        $receipts = fn (User $user) => collect($this->excel($user, 'expenses')->getSheetByName('Expenses')->toArray())->pluck(6)->filter()->slice(1)->values()->all();

        $this->assertSame(['ACCRA-ONLY'], $receipts($accra));
        $this->assertSame(['KUMASI-ONLY'], $receipts($kumasi));
        $this->assertSame(['HO-ONLY'], $receipts($headOffice));

        // A regional user cannot ask for another location's books with ?district=.
        $this->assertSame(['ACCRA-ONLY'], collect($this->excel($accra, 'expenses', ['district' => $this->kumasi->id])->getSheetByName('Expenses')->toArray())->pluck(6)->filter()->slice(1)->values()->all());
    }

    public function test_super_admin_must_name_the_location_to_export(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->actingAs($accra);
        $this->workflow()->recordExpense($accra, ['expense_date' => '2026-10-05', 'category' => 'other', 'amount' => 1000, 'receipt_ref' => 'ACCRA-ONLY']);
        $super = $this->superAdmin();

        $this->actingAs($super)->get(route('gwater.export', ['report' => 'expenses', 'format' => 'excel']))->assertRedirect()->assertSessionHas('error');

        $book = $this->excel($super, 'expenses', ['district' => $this->odorkor->id]);
        $this->assertContains('ACCRA-ONLY', collect($book->getSheetByName('Expenses')->toArray())->pluck(6)->all());
    }

    public function test_the_workbook_class_keeps_leading_symbols_as_text_for_any_report(): void
    {
        $report = ['key' => 'x', 'file' => ['region' => 'r', 'period' => 'p'], 'title' => 'T', 'meta' => ['Location' => '-L'], 'notes' => ['=note'], 'row_count' => 1, 'tables' => [['title' => 'Sheet', 'headings' => ['A'], 'rows' => [['+1', '-2', '@x', '=3']]]]];
        $book = $this->load(Excel::raw(new CommercialReportExport($report, now()), ExcelWriter::XLSX));

        foreach (['A2', 'B2', 'C2', 'D2'] as $address) {
            $this->assertSame('s', $book->getSheetByName('Sheet')->getCell($address)->getDataType(), $address);
        }
    }

    // ---------------------------------------------------------------- screens

    public function test_the_profit_and_loss_screen_shows_the_services_figures_and_needs_view_reports(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->someMonth($user);
        $viewer = $this->viewer('V1');

        $this->actingAs($user)->get(route('gwater.pnl'))->assertOk()->assertSee('Profit and loss');
        $this->assertSame(403, $this->actingAs($viewer)->get(route('gwater.pnl'))->getStatusCode());
        Livewire::actingAs($viewer)->test(ProfitAndLoss::class)->assertForbidden();

        $place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];
        $net = app(GwaterProfitService::class)->report($place, today()->startOfMonth(), today()->endOfMonth(), $user)['combined']['net_profit'];

        Livewire::actingAs($user)->test(ProfitAndLoss::class)
            ->assertSee('In progress')
            ->assertSee(\App\Services\GWater\Money::format($net))
            ->assertSee('Revenue, cost of water sold and costs by month')
            ->assertViewHas('report', fn (array $report) => $report['combined']['net_profit'] === $net)
            ->set('preset', 'last_month')
            ->assertDispatched('gwater-pnl-updated')
            ->assertSet('from', '2026-09-01')
            ->assertSee('Complete')
            ->set('from', '2026-10-01')
            ->assertSet('preset', 'custom');
    }

    public function test_the_profit_and_loss_screen_is_scoped_to_the_users_location(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->someMonth($accra);
        $kumasi = $this->gw('G2', $this->kumasi);

        Livewire::actingAs($kumasi)->test(ProfitAndLoss::class)->assertViewHas('report', fn (array $report) => $report['combined']['revenue'] === 0 && $report['trips'] === []);
        Livewire::actingAs($accra)->test(ProfitAndLoss::class)->assertViewHas('report', fn (array $report) => $report['combined']['revenue'] > 0);
        Livewire::actingAs($this->superAdmin())->test(ProfitAndLoss::class)->assertSee('Choose a location above')->set('locationId', $this->sowutuom->id)->assertViewHas('report', fn (array $report) => $report['combined']['revenue'] > 0);
    }

    public function test_the_overview_shows_net_profit_owed_and_the_flags(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $this->trip($user, 'WB-1', 40, 0, '2026-10-01');
        $this->workflow()->recordDamage($user, ['damage_date' => '2026-10-02', 'product_id' => $this->bottle->id, 'packs' => 10, 'stage' => 'transit', 'reason' => 'Burst']);
        $viewer = $this->viewer('V1');

        // 30 packs on hand is below the default flag of 50; 10 of 40 received is 25%, above 5%.
        Livewire::actingAs($user)->test(Overview::class)
            ->assertSee('Net profit this month')
            ->assertSee('Low stock: Bottled water')
            ->assertSee('High damage rate this month: Bottled water')
            ->assertSee('Owed to G Water')
            ->assertViewHas('owed', 40 * 2000);

        // Without view_reports the profit figure is not shown at all.
        Livewire::actingAs($this->gw('G9', $this->kumasi))->test(Overview::class)->assertSee('Net profit this month');
        Livewire::actingAs($viewer)->test(Overview::class)->assertDontSee('Net profit this month');
    }

    public function test_the_profit_and_loss_is_in_the_sidebar_only_for_report_viewers(): void
    {
        $navigation = app(\App\Support\ErpNavigation::class);
        $routes = fn (User $user) => collect($navigation->build($user, 'gwater')['sidebar'])->pluck('route')->all();

        $this->assertContains('gwater.pnl', $routes($this->gw('G1', $this->sowutuom)));
        $this->assertNotContains('gwater.pnl', $routes($this->viewer('V1')));
    }
}
