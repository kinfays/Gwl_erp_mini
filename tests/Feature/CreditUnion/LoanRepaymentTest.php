<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\LoanShow;
use App\Models\AuditLog;
use App\Models\CreditUnionDeductionBatch;
use App\Models\CreditUnionDeductionBatchLine;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionLoanRepayment;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\LoanService;
use Livewire\Livewire;

class LoanRepaymentTest extends CreditUnionTestCase
{
    public function test_a_cash_repayment_reduces_the_outstanding_balance(): void
    {
        $loan = $this->disbursedLoan('900101');
        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('repaymentForm.amount', '500')
            ->set('repaymentForm.source', CreditUnionLoanRepayment::SOURCE_CASH)
            ->call('recordRepayment')
            ->assertHasNoErrors();

        $loan->refresh();
        $repayment = $loan->repayments()->latest('id')->firstOrFail();

        // 2000 principal + 300 interest = 2300 repayable, less 500.
        $this->assertSame('500.00', $repayment->amount);
        $this->assertSame('1800.00', $repayment->balance_after);
        $this->assertSame('1800.00', $loan->outstanding_balance);
        $this->assertSame(CreditUnionLoan::STATUS_ACTIVE, $loan->status);
    }

    public function test_repaying_the_full_balance_completes_the_loan(): void
    {
        $loan = $this->disbursedLoan('900201');
        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('repaymentForm.amount', '2300')
            ->set('repaymentForm.source', CreditUnionLoanRepayment::SOURCE_CASH)
            ->call('recordRepayment')
            ->assertHasNoErrors();

        $loan->refresh();

        $this->assertSame('0.00', $loan->outstanding_balance);
        $this->assertSame(CreditUnionLoan::STATUS_COMPLETED, $loan->status);
    }

    public function test_a_repayment_cannot_exceed_the_outstanding_balance(): void
    {
        $loan = $this->disbursedLoan('900301');
        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('repaymentForm.amount', '5000')
            ->set('repaymentForm.source', CreditUnionLoanRepayment::SOURCE_CASH)
            ->call('recordRepayment')
            ->assertHasErrors('repaymentForm.amount');

        $this->assertSame(0, $loan->repayments()->count());
        $this->assertSame('2300.00', $loan->refresh()->outstanding_balance);
    }

    public function test_an_associate_member_loan_rejects_a_payroll_deduction_repayment(): void
    {
        $associate = $this->associateMemberWithBalance('P0021', savings: 5000);
        $loan = $this->disburse(app(LoanService::class)->apply($associate, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer('OFFP0021')->id));

        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('repaymentForm.amount', '500')
            ->set('repaymentForm.source', CreditUnionLoanRepayment::SOURCE_PAYROLL_DEDUCTION)
            ->call('recordRepayment')
            ->assertHasErrors('repaymentForm.source');

        $this->assertSame(0, $loan->repayments()->count());
        $this->assertSame('2300.00', $loan->refresh()->outstanding_balance);
    }

    public function test_an_associate_member_loan_accepts_a_cash_repayment(): void
    {
        $associate = $this->associateMemberWithBalance('P0022', savings: 5000);
        $loan = $this->disburse(app(LoanService::class)->apply($associate, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer('OFFP0022')->id));

        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('repaymentForm.amount', '500')
            ->set('repaymentForm.source', CreditUnionLoanRepayment::SOURCE_CASH)
            ->call('recordRepayment')
            ->assertHasNoErrors();

        $this->assertSame('1800.00', $loan->refresh()->outstanding_balance);
    }

    public function test_the_repayment_source_list_for_an_associate_excludes_payroll(): void
    {
        $associate = $this->associateMemberWithBalance('P0023', savings: 5000);
        $associateLoan = $this->disburse(app(LoanService::class)->apply($associate, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer('OFFP0023')->id));

        $staffLoan = $this->disbursedLoan('900401');

        $this->actingAs($this->officer());

        $associateSources = Livewire::test(LoanShow::class, ['loan' => $associateLoan])->viewData('repaymentSources');
        $staffSources = Livewire::test(LoanShow::class, ['loan' => $staffLoan])->viewData('repaymentSources');

        $this->assertNotContains(CreditUnionLoanRepayment::SOURCE_PAYROLL_DEDUCTION, $associateSources);
        $this->assertContains(CreditUnionLoanRepayment::SOURCE_PAYROLL_DEDUCTION, $staffSources);
    }

    public function test_repayments_cannot_be_recorded_before_disbursement(): void
    {
        $member = $this->memberWithBalance('900501', savings: 5000);
        $loan = app(LoanService::class)->apply($member, ['principal_amount' => 2000, 'term_months' => 12]);

        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('repaymentForm.amount', '500')
            ->set('repaymentForm.source', CreditUnionLoanRepayment::SOURCE_CASH)
            ->call('recordRepayment')
            ->assertHasErrors('repaymentForm.amount');

        $this->assertSame(0, $loan->repayments()->count());
    }

    public function test_every_repayment_is_audit_logged(): void
    {
        $loan = $this->disbursedLoan('900601');
        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('repaymentForm.amount', '250')
            ->set('repaymentForm.source', CreditUnionLoanRepayment::SOURCE_CHEQUE)
            ->set('repaymentForm.reference_no', 'CHQ-7788')
            ->call('recordRepayment')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.loan_repayment_recorded')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CreditUnionLoanRepayment::class, $log->target_type);
        $this->assertEquals(250.0, $log->metadata['amount'] ?? null);
        $this->assertSame(CreditUnionLoanRepayment::SOURCE_CHEQUE, $log->metadata['source'] ?? null);
    }

    public function test_creating_a_loan_posts_payroll_repayments_phase_two_had_to_defer(): void
    {
        $member = $this->memberWithBalance('900701', savings: 5000);

        // Phase 2 captured these loan repayments but had no loan to post them against.
        [$firstLine, $secondLine] = $this->deferredDeductionLines($member, [120.00, 80.00]);

        $loan = app(LoanService::class)->apply($member, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer('OFF900701')->id);

        $loan->refresh();
        $repayments = $loan->repayments()->orderBy('id')->get();

        $this->assertCount(2, $repayments);
        $this->assertSame('120.00', $repayments[0]->amount);
        $this->assertSame('80.00', $repayments[1]->amount);

        foreach ($repayments as $repayment) {
            $this->assertSame(CreditUnionLoanRepayment::SOURCE_PAYROLL_DEDUCTION, $repayment->source);
            $this->assertNotNull($repayment->deduction_batch_id);
        }

        // 2300 repayable less the 200 already deducted.
        $this->assertSame('2100.00', $loan->outstanding_balance);

        $this->assertTrue($firstLine->refresh()->loan_repayment_posted);
        $this->assertTrue($secondLine->refresh()->loan_repayment_posted);
        $this->assertFalse($firstLine->hasUnpostedLoanRepayment());
    }

    public function test_the_backfill_leaves_an_undisbursed_loan_in_its_own_status(): void
    {
        $member = $this->memberWithBalance('900801', savings: 5000);
        $this->deferredDeductionLines($member, [120.00]);

        $loan = app(LoanService::class)->apply($member, ['principal_amount' => 2000, 'term_months' => 12]);

        // Backfilling history must not look like a disbursement.
        $this->assertSame(CreditUnionLoan::STATUS_PENDING, $loan->refresh()->status);
        $this->assertSame(1, $loan->repayments()->count());
    }

    public function test_the_console_command_posts_deferred_repayments_for_existing_loans(): void
    {
        $member = $this->memberWithBalance('900901', savings: 5000);
        $loan = $this->disburse(app(LoanService::class)->apply($member, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer('OFF900901')->id));

        // Lines that arrived after the loan already existed.
        $lines = $this->deferredDeductionLines($member, [150.00]);

        $this->artisan('credit-union:post-deferred-loan-repayments')->assertSuccessful();

        $this->assertTrue($lines[0]->refresh()->loan_repayment_posted);
        $this->assertSame('2150.00', $loan->refresh()->outstanding_balance);
    }

    public function test_unposted_lines_on_an_unposted_batch_are_left_alone(): void
    {
        $member = $this->memberWithBalance('901001', savings: 5000);

        $batch = CreditUnionDeductionBatch::factory()->received(200)->create([
            'status' => CreditUnionDeductionBatch::STATUS_IMPORTED,
            'posted_at' => null,
        ]);
        $line = CreditUnionDeductionBatchLine::factory()->forMember($member)->amounts(50, 120, 90)->create([
            'deduction_batch_id' => $batch->id,
        ]);

        $loan = app(LoanService::class)->apply($member, ['principal_amount' => 2000, 'term_months' => 12]);

        $this->assertSame(0, $loan->repayments()->count());
        $this->assertFalse($line->refresh()->loan_repayment_posted);
    }

    /**
     * @return array<int, CreditUnionDeductionBatchLine>
     */
    protected function deferredDeductionLines(CreditUnionMember $member, array $amounts): array
    {
        $lines = [];

        foreach ($amounts as $index => $amount) {
            $batch = CreditUnionDeductionBatch::factory()->received(170)->create([
                'period_month' => today()->subMonths(count($amounts) - $index)->startOfMonth()->toDateString(),
                'status' => CreditUnionDeductionBatch::STATUS_RECONCILED,
                'posted_at' => now(),
            ]);

            $lines[] = CreditUnionDeductionBatchLine::factory()
                ->forMember($member)
                ->amounts(50, 120, $amount)
                ->create(['deduction_batch_id' => $batch->id]);
        }

        return $lines;
    }

    /**
     * A disbursed 2000/12-month loan: 2300 repayable at the confirmed 15% rate.
     */
    protected function disbursedLoan(string $staffId): CreditUnionLoan
    {
        $member = $this->memberWithBalance($staffId, savings: 5000);

        return $this->disburse(app(LoanService::class)->apply($member, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer('OFF'.$staffId)->id));
    }

    protected function disburse(CreditUnionLoan $loan): CreditUnionLoan
    {
        $loans = app(LoanService::class);
        $loans->approve($loan, $this->committeeMember('CUC'.$loan->id));

        return $loans->disburse($loan->refresh());
    }
}
