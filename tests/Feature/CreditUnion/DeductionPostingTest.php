<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\DeductionBatchShow;
use App\Models\AuditLog;
use App\Models\CreditUnionDeductionBatch;
use App\Models\CreditUnionDeductionBatchLine;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class DeductionPostingTest extends CreditUnionTestCase
{
    public function test_posting_a_matched_batch_writes_shares_and_savings_ledger_entries(): void
    {
        $member = $this->staffMember('500101', 'Akosua Mensah');
        $batch = CreditUnionDeductionBatch::factory()->received(170)->create([
            'period_month' => '2026-07-01',
            'bank_reference' => 'GCB 659863',
        ]);
        CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120)->create([
            'deduction_batch_id' => $batch->id,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasNoErrors();

        $entries = $member->ledgerEntries()->where('source', CreditUnionLedgerEntry::SOURCE_PAYROLL_DEDUCTION)->get();

        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertSame(CreditUnionLedgerEntry::ENTRY_CONTRIBUTION, $entry->entry_type);
            $this->assertSame($batch->id, $entry->deduction_batch_id);
            $this->assertSame('2026-07-01', $entry->transaction_date->toDateString());
            $this->assertSame('GCB 659863', $entry->reference_no);
        }

        $this->assertSame('50.00', $entries->firstWhere('account_type', CreditUnionLedgerEntry::ACCOUNT_SHARES)->amount);
        $this->assertSame('120.00', $entries->firstWhere('account_type', CreditUnionLedgerEntry::ACCOUNT_SAVINGS)->amount);
    }

    public function test_a_fully_posted_batch_is_marked_reconciled(): void
    {
        $member = $this->staffMember('500201', 'Kwame Boateng');
        $batch = CreditUnionDeductionBatch::factory()->received(170)->create();
        CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120)->create([
            'deduction_batch_id' => $batch->id,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasNoErrors();

        $batch->refresh();

        $this->assertSame(CreditUnionDeductionBatch::STATUS_RECONCILED, $batch->status);
        $this->assertSame('170.00', $batch->amount_posted);
        $this->assertSame(0.0, $batch->variance());
        $this->assertNotNull($batch->posted_at);
        $this->assertNotNull($batch->posted_by);
    }

    public function test_a_batch_posting_less_than_it_received_is_flagged_as_variance(): void
    {
        $member = $this->staffMember('500301', 'Adwoa Owusu');
        // The union banked 500 but only 170 of it could be matched to a member.
        $batch = CreditUnionDeductionBatch::factory()->received(500)->create();
        CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120)->create([
            'deduction_batch_id' => $batch->id,
        ]);
        CreditUnionDeductionBatchLine::factory()->amounts(60, 270)->create([
            'deduction_batch_id' => $batch->id,
            'staff_id_raw' => '999999',
            'match_status' => CreditUnionDeductionBatchLine::MATCH_UNMATCHED,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasNoErrors();

        $batch->refresh();

        $this->assertSame(CreditUnionDeductionBatch::STATUS_VARIANCE, $batch->status);
        $this->assertSame('170.00', $batch->amount_posted);
        $this->assertSame(-330.0, $batch->variance());
    }

    public function test_loan_repayment_amounts_are_captured_but_never_posted(): void
    {
        $member = $this->staffMember('500401', 'Efua Bonsu');
        $batch = CreditUnionDeductionBatch::factory()->received(170)->create();
        $line = CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120, 85)->create([
            'deduction_batch_id' => $batch->id,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasNoErrors();

        $batch->refresh();
        $line->refresh();

        // The 85 is held on the line: no ledger entry, no loan record, and it is excluded
        // from amount_posted so reconciliation stays honest.
        $this->assertSame('85.00', $line->loan_repayment_amount);
        $this->assertFalse($line->loan_repayment_posted);
        $this->assertTrue($line->hasUnpostedLoanRepayment());

        $this->assertSame('170.00', $batch->amount_posted);
        $this->assertSame(CreditUnionDeductionBatch::STATUS_RECONCILED, $batch->status);

        $this->assertCount(2, $member->ledgerEntries()->where('source', CreditUnionLedgerEntry::SOURCE_PAYROLL_DEDUCTION)->get());
        $this->assertSame(0, CreditUnionLedgerEntry::query()->where('amount', 85)->count());
        $this->assertSame(170.0, (float) CreditUnionLedgerEntry::query()->where('deduction_batch_id', $batch->id)->sum('amount'));

        // Loans are a later phase; nothing in this one may depend on their tables.
        $this->assertFalse(Schema::hasTable('credit_union_loans'));
        $this->assertFalse(Schema::hasTable('credit_union_loan_repayments'));
    }

    public function test_unmatched_and_associate_lines_are_never_posted(): void
    {
        $member = $this->staffMember('500501', 'Kojo Danso');
        $associate = CreditUnionMember::factory()->associate()->shareIssued()->create([
            'member_number' => 'P0012',
            'full_name' => 'Yaw Nkrumah',
        ]);

        $batch = CreditUnionDeductionBatch::factory()->received(170)->create();
        CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120)->create([
            'deduction_batch_id' => $batch->id,
        ]);
        CreditUnionDeductionBatchLine::factory()->forMember($associate)->amounts(40, 90)->create([
            'deduction_batch_id' => $batch->id,
        ]);
        CreditUnionDeductionBatchLine::factory()->amounts(30, 70)->create([
            'deduction_batch_id' => $batch->id,
            'staff_id_raw' => '999999',
            'match_status' => CreditUnionDeductionBatchLine::MATCH_UNMATCHED,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasNoErrors();

        $this->assertSame(2, CreditUnionLedgerEntry::query()->where('deduction_batch_id', $batch->id)->count());
        $this->assertSame(0, $associate->ledgerEntries()->where('source', CreditUnionLedgerEntry::SOURCE_PAYROLL_DEDUCTION)->count());
        $this->assertSame('170.00', $batch->refresh()->amount_posted);
    }

    public function test_a_batch_with_no_matched_lines_cannot_be_posted(): void
    {
        $batch = CreditUnionDeductionBatch::factory()->received(100)->create();
        CreditUnionDeductionBatchLine::factory()->amounts(30, 70)->create([
            'deduction_batch_id' => $batch->id,
            'match_status' => CreditUnionDeductionBatchLine::MATCH_UNMATCHED,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasErrors('batch');

        $this->assertSame(CreditUnionDeductionBatch::STATUS_IMPORTED, $batch->refresh()->status);
    }

    public function test_a_batch_cannot_be_posted_twice(): void
    {
        $member = $this->staffMember('500601', 'Rita Appiah');
        $batch = CreditUnionDeductionBatch::factory()->received(170)->create();
        CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120)->create([
            'deduction_batch_id' => $batch->id,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasNoErrors();

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch->refresh()])
            ->call('postBatch')
            ->assertHasErrors('batch');

        $this->assertSame(2, CreditUnionLedgerEntry::query()->where('deduction_batch_id', $batch->id)->count());
    }

    public function test_posting_maintains_the_member_running_balance(): void
    {
        $member = $this->staffMember('500701', 'Ama Serwaa');
        $member->ledgerEntries()->create([
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
            'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
            'amount' => 300,
            'balance_after' => 300,
            'transaction_date' => '2026-06-01',
            'source' => CreditUnionLedgerEntry::SOURCE_CASH,
        ]);

        $batch = CreditUnionDeductionBatch::factory()->received(170)->create(['period_month' => '2026-07-01']);
        CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120)->create([
            'deduction_batch_id' => $batch->id,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasNoErrors();

        $this->assertSame(420.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
        $this->assertSame(50.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SHARES));
    }

    public function test_posting_is_audit_logged(): void
    {
        $member = $this->staffMember('500801', 'Mawuli Tetteh');
        $batch = CreditUnionDeductionBatch::factory()->received(200)->create();
        CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120, 85)->create([
            'deduction_batch_id' => $batch->id,
        ]);

        $this->actingAs($this->deductionOfficer());

        Livewire::test(DeductionBatchShow::class, ['batch' => $batch])
            ->call('postBatch')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.deduction_batch_posted')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CreditUnionDeductionBatch::class, $log->target_type);
        $this->assertEquals(170.0, $log->metadata['amount_posted'] ?? null);
        $this->assertEquals(200.0, $log->metadata['amount_received'] ?? null);
        $this->assertEquals(-30.0, $log->metadata['variance'] ?? null);
        $this->assertEquals(85.0, $log->metadata['unposted_loan_repayment_total'] ?? null);
        $this->assertSame(CreditUnionDeductionBatch::STATUS_VARIANCE, $log->metadata['status'] ?? null);
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
        return $this->officer('CUD002');
    }
}
