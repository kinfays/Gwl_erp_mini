<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\PpeImport;
use App\Models\AuditLog;
use App\Models\HsPpeIssue;
use App\Models\HsPpeStockMovement;
use App\Models\User;
use App\Services\HealthSafety\PpeImportService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PpeImportTest extends HealthSafetyTestCase
{
    private const HEADINGS = ['staff_id', 'ppe_type', 'size', 'quantity', 'issued_on', 'expires_on'];

    private function service(): PpeImportService
    {
        return app(PpeImportService::class);
    }

    private function officer0(): User
    {
        return $this->officer('200001', $this->accraWest, $this->headOffice);
    }

    private function row(array $overrides = []): array
    {
        return ['staff_id' => '300001', 'ppe_type' => 'Hard hat', 'quantity' => 1, 'issued_on' => '15/03/2025', ...$overrides];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ppeType(['name' => 'Hard hat', 'replacement_months' => 36]);
        $this->bootsType(['replacement_months' => 12]);
        $this->userWithRoles('300001', ['employee'], $this->accraWest, $this->sowutuom);
        $this->userWithRoles('300002', ['employee'], $this->accraWest, $this->odorkor);
    }

    // ---------------------------------------------------------------- the happy path

    public function test_a_valid_file_is_read_and_recorded_as_already_held_issues_with_no_stock_movement(): void
    {
        $officer = $this->officer0();
        $file = $this->xlsx(self::HEADINGS, [
            $this->row(),
            $this->row(['staff_id' => '300002', 'ppe_type' => 'safety boots', 'size' => '41', 'issued_on' => new \DateTimeImmutable('2025-01-10')]),
            $this->row(['staff_id' => '300002', 'ppe_type' => 'Hard hat', 'quantity' => 2, 'issued_on' => '2024-06-01']),
        ]);

        $preview = $this->service()->preview($file, $officer);

        $this->assertSame(3, $preview['total_rows']);
        $this->assertSame(3, $preview['valid_count']);
        $this->assertFalse($preview['blocked']);
        $this->assertSame(0, HsPpeIssue::query()->count(), 'a preview writes nothing');

        $result = $this->service()->import($file, $officer);

        $this->assertSame(3, $result['created']);
        $this->assertSame(3, HsPpeIssue::query()->where('is_historic', true)->count());
        $this->assertSame(0, HsPpeStockMovement::query()->count(), 'nothing came out of any store');

        $boots = HsPpeIssue::query()->whereHas('type', fn ($q) => $q->where('name', 'Safety boots'))->firstOrFail();
        $this->assertSame('41', $boots->size);
        $this->assertSame('2025-01-10', $boots->issued_on->toDateString());
        $this->assertSame('2026-01-10', $boots->replace_due_on->toDateString(), 'the replacement date follows from the type');
        $this->assertSame('issued', $boots->status);

        $audit = AuditLog::query()->where('action', 'health_safety.ppe_issues_imported')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(3, $audit->first()->metadata['created']);
        $this->assertSame(0, AuditLog::query()->where('action', 'health_safety.ppe_issued')->count(), 'one audit row, no per-row noise');
    }

    public function test_real_excel_dates_and_ddmmyyyy_text_both_read_and_an_ambiguous_one_is_rejected(): void
    {
        $officer = $this->officer0();

        $good = $this->service()->preview($this->xlsx(self::HEADINGS, [
            $this->row(['issued_on' => new \DateTimeImmutable('2025-03-15')]),
            $this->row(['staff_id' => '300002', 'issued_on' => '15/03/2025']),
            $this->row(['staff_id' => '300002', 'quantity' => 2, 'issued_on' => '2025-03-15']),
        ]), $officer);

        $this->assertSame([], $good['errors']);

        $bad = ['13/13/2025', '03/04/25', 'March 2025', '31/02/2025', 'last year', 7];
        $rows = collect($bad)->map(fn ($value) => $this->row(['issued_on' => $value]))->all();
        $preview = $this->service()->preview($this->xlsx(self::HEADINGS, $rows), $officer);

        $this->assertSame(count($bad), $preview['error_rows']);
        $this->assertStringContainsString('issued on', $preview['errors'][0]['message']);
    }

    // ---------------------------------------------------------------- the row errors

    public function test_each_kind_of_row_error_is_reported_with_its_row_number(): void
    {
        config(['gwl.max_import_failure_percent' => 100]);
        $officer = $this->officer('200010', $this->accraWest, $this->odorkor);   // a regional officer: Ashanti is out of reach
        $this->ppeType(['name' => 'Filter', 'has_expiry' => true]);
        $outsider = $this->userWithRoles('300003', ['employee'], $this->ashanti, $this->kumasi);
        $left = $this->userWithRoles('300004', ['employee'], $this->accraWest, $this->sowutuom);
        $left->employee->update(['is_active' => false]);

        $preview = $this->service()->preview($this->xlsx(self::HEADINGS, [
            $this->row(['staff_id' => '999999']),                       // row 2: unknown staff
            $this->row(['ppe_type' => 'Jetpack']),                       // row 3: unknown type
            $this->row(['ppe_type' => 'Safety boots']),                  // row 4: size missing
            $this->row(['ppe_type' => 'Safety boots', 'size' => '50']),  // row 5: size not listed
            $this->row(['size' => 'L']),                                 // row 6: size on a type without sizes
            $this->row(['quantity' => 0]),                               // row 7
            $this->row(['quantity' => 'two']),                           // row 8
            $this->row(['ppe_type' => 'Filter']),                        // row 9: expiry needed
            $this->row(['issued_on' => today()->addDay()->format('d/m/Y')]),   // row 10: future
            $this->row(['staff_id' => '300003']),                        // row 11: staff in another region
            $this->row(['staff_id' => '300004']),                        // row 12: staff who left
            $this->row(['issued_on' => null]),                           // row 13: no date
            $this->row(['staff_id' => null]),                            // row 14: no staff ID
            $this->row(['ppe_type' => 'Filter', 'expires_on' => '01/01/2020', 'issued_on' => '01/01/2025']),   // row 15: expires before issue
        ]), $officer);

        $byRow = collect($preview['errors'])->groupBy('row')->map(fn ($errors) => $errors->pluck('message')->implode(' '));

        $this->assertStringContainsString('Staff ID 999999 was not found', $byRow[2]);
        $this->assertStringContainsString('Unknown PPE type "Jetpack"', $byRow[3]);
        $this->assertStringContainsString('Size is missing', $byRow[4]);
        $this->assertStringContainsString('"50" is not one of the sizes', $byRow[5]);
        $this->assertStringContainsString('does not come in sizes', $byRow[6]);
        $this->assertStringContainsString('Quantity must be a whole number', $byRow[7]);
        $this->assertStringContainsString('Quantity must be a whole number', $byRow[8]);
        $this->assertStringContainsString('has an expiry date', $byRow[9]);
        $this->assertStringContainsString('cannot be in the future', $byRow[10]);
        $this->assertStringContainsString('was not found', $byRow[11], 'staff outside the importer\'s region look like staff who do not exist');
        $this->assertStringContainsString('was not found', $byRow[12], 'staff who have left are not active staff');
        $this->assertStringContainsString('enter the date', $byRow[13]);
        $this->assertStringContainsString('No staff ID', $byRow[14]);
        $this->assertStringContainsString('before it was issued', $byRow[15]);

        $this->assertSame(['Jetpack'], $preview['unknown_types']);
        $this->assertSame(0, $preview['valid_count']);
        $this->assertNotNull($outsider);
    }

    public function test_the_run_is_blocked_above_the_failure_threshold_and_nothing_is_written(): void
    {
        config(['gwl.max_import_failure_percent' => 20]);
        $officer = $this->officer0();
        $file = $this->xlsx(self::HEADINGS, [$this->row(), $this->row(['staff_id' => '300002']), $this->row(['ppe_type' => 'Jetpack']), $this->row(['staff_id' => '999999'])]);

        $preview = $this->service()->preview($file, $officer);

        $this->assertSame(50.0, $preview['failure_percent']);
        $this->assertTrue($preview['blocked']);

        try {
            $this->service()->import($file, $officer);
            $this->fail('A blocked import must not run.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('50', $exception->errors()['file'][0]);
        }

        $this->assertSame(0, HsPpeIssue::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'health_safety.ppe_issues_imported')->count());
    }

    public function test_below_the_threshold_the_usable_rows_are_recorded_and_the_bad_ones_left_out(): void
    {
        config(['gwl.max_import_failure_percent' => 20]);
        $officer = $this->officer0();
        $rows = [];

        foreach (range(1, 9) as $day) {
            $rows[] = $this->row(['issued_on' => sprintf('%02d/01/2025', $day)]);
        }

        $rows[] = $this->row(['ppe_type' => 'Jetpack']);

        $result = $this->service()->import($this->xlsx(self::HEADINGS, $rows), $officer);

        $this->assertSame(9, $result['created']);
        $this->assertSame(1, $result['error_rows']);
        $this->assertSame(9, HsPpeIssue::query()->count());
    }

    // ---------------------------------------------------------------- create-only

    public function test_a_holding_already_recorded_is_skipped_with_a_warning_so_a_second_upload_does_not_double_it(): void
    {
        $officer = $this->officer0();
        $file = $this->xlsx(self::HEADINGS, [$this->row(), $this->row(['staff_id' => '300002'])]);

        $this->assertSame(2, $this->service()->import($file, $officer)['created']);

        $preview = $this->service()->preview($file, $officer);
        $this->assertSame(2, $preview['skipped_count']);
        $this->assertSame(0, $preview['error_rows'], 'a skip is not a failure');
        $this->assertSame(0, $preview['valid_count']);
        $this->assertStringContainsString('Already recorded', $preview['warnings'][0]['message']);
        $this->assertFalse($preview['blocked']);

        try {
            $this->service()->import($file, $officer);
            $this->fail('There is nothing left to import.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertSame(2, HsPpeIssue::query()->count());
    }

    public function test_it_is_create_only_it_never_changes_or_closes_what_is_there(): void
    {
        $officer = $this->officer0();
        $kofi = User::query()->where('staff_id', '300001')->firstOrFail();
        $hat = \App\Models\HsPpeType::query()->where('name', 'Hard hat')->firstOrFail();
        $existing = app(\App\Services\HealthSafety\PpeIssueService::class)->issue($officer, ['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'quantity' => 1, 'is_historic' => true, 'issued_on' => '2020-01-01']);

        $this->service()->import($this->xlsx(self::HEADINGS, [$this->row(['issued_on' => '01/06/2025'])]), $officer);

        $this->assertSame('issued', $existing->fresh()->status);
        $this->assertSame('2020-01-01', $existing->fresh()->issued_on->toDateString());
        $this->assertSame(2, HsPpeIssue::query()->count());
    }

    // ---------------------------------------------------------------- headings, size and scope

    public function test_headings_are_matched_tolerantly_and_a_missing_required_column_blocks_the_file(): void
    {
        $officer = $this->officer0();

        $tolerant = $this->service()->preview($this->xlsx(['Staff ID', 'PPE Type', 'Quantity', 'Issued-On'], [
            ['Staff ID' => '300001', 'PPE Type' => 'hard hat', 'Quantity' => 1, 'Issued-On' => '01/02/2025'],
        ]), $officer);
        $this->assertSame([], $tolerant['errors']);
        $this->assertSame(1, $tolerant['valid_count']);

        $missing = $this->service()->preview($this->xlsx(['staff_id', 'ppe_type', 'quantity'], [['staff_id' => '300001', 'ppe_type' => 'Hard hat', 'quantity' => 1]]), $officer);
        $this->assertTrue($missing['blocked']);
        $this->assertStringContainsString('Missing required columns: issued_on', $missing['errors'][0]['message']);

        $empty = $this->service()->preview($this->xlsx([], []), $officer);
        $this->assertTrue($empty['blocked']);
    }

    public function test_an_officer_can_only_import_for_staff_in_their_own_region_but_the_manager_can_for_any(): void
    {
        config(['gwl.max_import_failure_percent' => 100]);
        $this->userWithRoles('300003', ['employee'], $this->ashanti, $this->kumasi);
        $regional = $this->officer('200010', $this->accraWest, $this->odorkor);
        $file = $this->xlsx(self::HEADINGS, [$this->row(['staff_id' => '300003']), $this->row(['staff_id' => '300002'])]);

        $this->assertSame(1, $this->service()->preview($file, $regional)['valid_count']);
        $this->assertSame(2, $this->service()->preview($file, $this->hsManager())['valid_count']);
    }

    public function test_the_row_limit_protects_against_an_enormous_file(): void
    {
        $rows = array_fill(0, PpeImportService::MAX_ROWS + 1, $this->row());
        $preview = $this->service()->preview($this->xlsx(self::HEADINGS, $rows), $this->officer0());

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('more than '.PpeImportService::MAX_ROWS.' rows', $preview['errors'][0]['message']);
    }

    // ---------------------------------------------------------------- permissions and screen

    public function test_only_manage_ppe_can_import_or_download_the_template(): void
    {
        $file = $this->xlsx(self::HEADINGS, [$this->row()]);
        $manager = $this->districtManager('200003', $this->sowutuom);

        try {
            $this->service()->preview($file, $manager);
            $this->fail('No manage_ppe.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        foreach ([$manager, $this->reporter('100050')] as $user) {
            $this->actingAs($user)->get(route('health_safety.ppe.import'))->assertForbidden();
            $this->actingAs($user)->get(route('health_safety.ppe.import.template'))->assertForbidden();
            Livewire::actingAs($user)->test(PpeImport::class)->assertForbidden();
        }

        $this->actingAs($this->officer0())->get(route('health_safety.ppe.import'))->assertOk();
        $this->actingAs($this->officer0())->get(route('health_safety.ppe.import.template'))->assertOk();
        $this->assertSame(self::HEADINGS, $this->service()->templateExport()->headings());
    }

    public function test_the_screen_previews_then_imports_and_the_preview_the_browser_holds_is_locked(): void
    {
        $officer = $this->officer0();
        $file = $this->xlsx(self::HEADINGS, [$this->row(), $this->row(['ppe_type' => 'Jetpack'])]);
        config(['gwl.max_import_failure_percent' => 100]);

        $component = Livewire::actingAs($officer)->test(PpeImport::class)
            ->set('file', $file)
            ->call('previewFile')
            ->assertHasNoErrors()
            ->assertSee('Jetpack')
            ->assertSee('Ready to record');

        $this->assertSame(1, $component->get('preview')['valid_count']);
        $this->assertArrayNotHasKey('valid_rows', $component->get('preview'), 'the rows stay on the server');

        try {
            $component->set('preview', ['valid_count' => 1, 'blocked' => false]);
            $this->fail('The preview must be locked.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsStringIgnoringCase('locked', $exception->getMessage());
        }

        $component->call('runImport')->assertRedirect(route('health_safety.ppe.issues'));
        $this->assertSame(1, HsPpeIssue::query()->count());
        $this->assertSame(0, HsPpeStockMovement::query()->count());
    }
}
