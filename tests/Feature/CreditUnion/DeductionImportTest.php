<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\DeductionBatchShow;
use App\Livewire\CreditUnion\Deductions;
use App\Models\AuditLog;
use App\Models\CreditUnionDeductionBatch;
use App\Models\CreditUnionDeductionBatchLine;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

class DeductionImportTest extends CreditUnionTestCase
{
    public function test_a_clean_file_imports_every_row_as_a_matched_line(): void
    {
        $this->staffMember('400101', 'Akosua Mensah');
        $this->staffMember('400102', 'Kwame Boateng');

        $this->actingAs($this->deductionOfficer());

        Livewire::test(Deductions::class)
            ->call('openUpload')
            ->set('file', $this->deductionFile([
                ['400101', 'Akosua Mensah', '50', '120', '0'],
                ['400102', 'Kwame Boateng', '50', '200', '0'],
            ]))
            ->call('previewFile')
            ->set('form.period_month', '2026-07-01')
            ->set('form.bank_reference', 'GCB 659863')
            ->set('form.amount_received', '420')
            ->call('runImport')
            ->assertHasNoErrors();

        $batch = CreditUnionDeductionBatch::query()->latest('id')->firstOrFail();

        $this->assertSame(CreditUnionDeductionBatch::STATUS_IMPORTED, $batch->status);
        $this->assertSame('2026-07-01', $batch->period_month->toDateString());
        $this->assertSame('GCB 659863', $batch->bank_reference);
        $this->assertSame('420.00', $batch->amount_received);
        $this->assertSame('0.00', $batch->amount_posted);
        $this->assertCount(2, $batch->lines);
        $this->assertSame(2, $batch->lines()->matched()->count());
        $this->assertNotNull($batch->lines->first()->member_id);
    }

    public function test_an_unknown_staff_id_is_recorded_as_an_unmatched_line(): void
    {
        $this->actingAs($this->deductionOfficer());

        $this->importFile([
            ...$this->matchedRows('4002'),
            ['999999', 'Ghost Employee', '50', '120', '0'],
        ], amountReceived: '1020');

        $batch = CreditUnionDeductionBatch::query()->latest('id')->firstOrFail();
        $unmatched = $batch->lines()->where('staff_id_raw', '999999')->firstOrFail();

        $this->assertSame(CreditUnionDeductionBatchLine::MATCH_UNMATCHED, $unmatched->match_status);
        $this->assertNull($unmatched->member_id);
        $this->assertStringContainsString('does not match any credit union member', (string) $unmatched->resolution_notes);
        $this->assertSame(5, $batch->lines()->matched()->count());
    }

    public function test_a_row_matching_an_associate_member_is_rejected_as_invalid_associate_member(): void
    {
        $associate = CreditUnionMember::factory()->associate()->shareIssued()->create([
            'member_number' => 'P0007',
            'full_name' => 'Yaw Nkrumah',
        ]);

        $this->actingAs($this->deductionOfficer());

        $this->importFile([
            ...$this->matchedRows('4003'),
            ['P0007', 'Yaw Nkrumah', '50', '120', '0'],
        ], amountReceived: '1020');

        $batch = CreditUnionDeductionBatch::query()->latest('id')->firstOrFail();
        $line = $batch->lines()->where('staff_id_raw', 'P0007')->firstOrFail();

        // Associates are never on GWL payroll, so the row is surfaced, not posted.
        $this->assertSame(CreditUnionDeductionBatchLine::MATCH_INVALID_ASSOCIATE, $line->match_status);
        $this->assertSame($associate->id, $line->member_id);
        $this->assertStringContainsString('never on GWL payroll', (string) $line->resolution_notes);
        $this->assertSame(5, $batch->lines()->matched()->count());
    }

    public function test_a_file_whose_rows_mostly_cannot_be_posted_is_blocked_by_the_failure_threshold(): void
    {
        $this->staffMember('400401', 'Rita Appiah');
        $this->actingAs($this->deductionOfficer());

        $component = Livewire::test(Deductions::class)
            ->call('openUpload')
            ->set('file', $this->deductionFile([
                ['400401', 'Rita Appiah', '50', '120', '0'],
                ['888881', 'Unknown One', '50', '120', '0'],
                ['888882', 'Unknown Two', '50', '120', '0'],
                ['888883', 'Unknown Three', '50', '120', '0'],
            ]))
            ->call('previewFile');

        $preview = $component->get('preview');

        $this->assertSame(4, $preview['total_rows']);
        $this->assertSame(3, $preview['warning_count']);
        $this->assertSame(75.0, $preview['failure_percent']);
        $this->assertSame(20, $preview['max_failure_percent']);
        $this->assertTrue($preview['blocked']);

        $component
            ->set('form.period_month', '2026-07-01')
            ->set('form.amount_received', '680')
            ->call('runImport')
            ->assertHasErrors('file');

        $this->assertSame(0, CreditUnionDeductionBatch::query()->count());
    }

    public function test_structurally_invalid_rows_are_reported_and_dropped(): void
    {
        $this->staffMember('400501', 'Kojo Danso');
        $this->actingAs($this->deductionOfficer());

        $preview = Livewire::test(Deductions::class)
            ->call('openUpload')
            ->set('file', $this->deductionFile([
                ['400501', 'Kojo Danso', '50', '120', '0'],
                ['', 'Missing Staff Id', '50', '120', '0'],
                ['400503', 'Zero Row', '0', '0', '0'],
            ]))
            ->call('previewFile')
            ->get('preview');

        $this->assertSame(3, $preview['total_rows']);
        $this->assertSame(2, $preview['error_count']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame(1, $preview['postable_count']);
    }

    public function test_an_unmatched_line_can_be_rematched_once_the_member_exists(): void
    {
        $this->actingAs($this->deductionOfficer());

        $this->importFile([
            ...$this->matchedRows('4006'),
            ['400699', 'Late Registration', '50', '120', '0'],
        ], amountReceived: '1020');

        $batch = CreditUnionDeductionBatch::query()->latest('id')->firstOrFail();
        $line = $batch->lines()->where('staff_id_raw', '400699')->firstOrFail();
        $this->assertSame(CreditUnionDeductionBatchLine::MATCH_UNMATCHED, $line->match_status);

        $member = $this->staffMember('400699', 'Late Registration');

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('rematchLine', $line->id)
            ->assertHasNoErrors();

        $line->refresh();
        $this->assertSame(CreditUnionDeductionBatchLine::MATCH_MATCHED, $line->match_status);
        $this->assertSame($member->id, $line->member_id);
    }

    public function test_an_officer_can_record_a_resolution_note_on_an_unmatched_line(): void
    {
        $this->actingAs($this->deductionOfficer());

        $this->importFile([
            ...$this->matchedRows('4007'),
            ['400799', 'Typo Staff Id', '50', '120', '0'],
        ], amountReceived: '1020');

        $batch = CreditUnionDeductionBatch::query()->latest('id')->firstOrFail();
        $line = $batch->lines()->where('staff_id_raw', '400799')->firstOrFail();

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('startResolving', $line->id)
            ->set('resolutionNotes', 'Payroll typo - confirmed with HR, correct staff ID is 400701.')
            ->call('saveResolution')
            ->assertHasNoErrors();

        $this->assertSame(
            'Payroll typo - confirmed with HR, correct staff ID is 400701.',
            $line->refresh()->resolution_notes
        );
    }

    public function test_the_import_is_audit_logged(): void
    {
        $this->staffMember('400801', 'Mawuli Tetteh');
        $this->actingAs($this->deductionOfficer());

        $this->importFile([
            ['400801', 'Mawuli Tetteh', '50', '120', '0'],
        ], amountReceived: '170');

        $log = AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.deduction_batch_imported')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CreditUnionDeductionBatch::class, $log->target_type);
        $this->assertSame(1, $log->metadata['line_count'] ?? null);
        $this->assertSame(1, $log->metadata['matched'] ?? null);
    }

    public function test_importing_creates_no_ledger_entries_until_the_batch_is_posted(): void
    {
        $this->staffMember('400901', 'Abena Darko');
        $this->actingAs($this->deductionOfficer());

        $ledgerCountBefore = CreditUnionLedgerEntry::query()->count();

        $this->importFile([
            ['400901', 'Abena Darko', '50', '120', '80'],
        ], amountReceived: '170');

        $this->assertSame($ledgerCountBefore, CreditUnionLedgerEntry::query()->count());
    }

    public function test_a_user_without_the_deductions_permission_cannot_reach_the_screen(): void
    {
        $this->actingAs($this->committeeMember());

        $this->get(route('credit-union.deductions'))->assertForbidden();
    }

    public function test_an_officer_can_load_the_deduction_screens(): void
    {
        $officer = $this->deductionOfficer();
        $this->actingAs($officer);

        $this->get(route('credit-union.deductions'))->assertOk()->assertSee('Deduction Batches');

        $this->staffMember('401001', 'Nana Yaw');
        $this->importFile([
            ['401001', 'Nana Yaw', '50', '120', '40'],
        ], amountReceived: '170');

        $batch = CreditUnionDeductionBatch::query()->latest('id')->firstOrFail();

        $this->get(route('credit-union.deductions.show', $batch))
            ->assertOk()
            ->assertSee('Reconciliation')
            ->assertSee('401001');
    }

    /**
     * Runs the full upload -> preview -> create-batch flow through the Livewire component.
     */
    protected function importFile(array $rows, string $amountReceived, string $periodMonth = '2026-07-01'): void
    {
        Livewire::test(Deductions::class)
            ->call('openUpload')
            ->set('file', $this->deductionFile($rows))
            ->call('previewFile')
            ->set('form.period_month', $periodMonth)
            ->set('form.amount_received', $amountReceived)
            ->call('runImport')
            ->assertHasNoErrors();
    }

    /**
     * Registers $count members and returns matching file rows, so a fixture carrying one
     * problem row still lands under the configured failure threshold.
     */
    protected function matchedRows(string $prefix, int $count = 5): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $staffId = $prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $name = 'Member '.$staffId;

            $this->staffMember($staffId, $name);
            $rows[] = [$staffId, $name, '50', '120', '0'];
        }

        return $rows;
    }

    protected function deductionFile(array $rows): UploadedFile
    {
        $lines = ['staff_id,name,shares_amount,savings_amount,loan_repayment_amount'];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent('deductions.csv', implode("\n", $lines)."\n");
    }

    protected function staffMember(string $staffId, string $name): CreditUnionMember
    {
        return CreditUnionMember::factory()->shareIssued()->create([
            'member_number' => $staffId,
            'staff_id' => $staffId,
            'full_name' => $name,
        ]);
    }

    protected function deductionOfficer(): User
    {
        return $this->officer('CUD001');
    }
}
