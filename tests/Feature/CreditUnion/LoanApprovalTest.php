<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\LoanShow;
use App\Models\AuditLog;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionLoanGuarantor;
use App\Models\Permission;
use App\Models\Role;
use App\Services\CreditUnion\LoanService;
use Livewire\Livewire;

class LoanApprovalTest extends CreditUnionTestCase
{
    public function test_a_committee_member_approves_a_loan_that_needs_no_guarantor(): void
    {
        $loan = $this->simpleLoan('800101');
        $this->actingAs($this->committeeMember());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->call('approve')
            ->assertHasNoErrors();

        $loan->refresh();

        $this->assertSame(CreditUnionLoan::STATUS_APPROVED, $loan->status);
        $this->assertNotNull($loan->approved_at);
        $this->assertNotNull($loan->approved_by);

        $this->assertTrue(AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.loan_approved')
            ->exists());
    }

    public function test_an_officer_without_the_approve_permission_cannot_approve(): void
    {
        $loan = $this->simpleLoan('800201');
        $officer = $this->officer();

        $this->assertFalse($officer->hasPermission('credit_union.approve_loans'));
        $this->actingAs($officer);

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(CreditUnionLoan::STATUS_PENDING, $loan->refresh()->status);
    }

    public function test_a_committee_member_cannot_approve_their_own_loan(): void
    {
        $applicant = $this->committeeMember('800301');
        $member = $this->memberWithBalance('800302', savings: 5000);

        // The committee member raised this application themselves.
        $loan = app(LoanService::class)->apply($member, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $applicant->id);

        $this->actingAs($applicant);

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->call('approve')
            ->assertForbidden();

        $loan->refresh();
        $this->assertSame(CreditUnionLoan::STATUS_PENDING, $loan->status);
        $this->assertNull($loan->approved_at);
    }

    public function test_a_committee_member_cannot_approve_a_loan_taken_out_in_their_own_name(): void
    {
        $employee = $this->createEmployee('800401', 'Committee Borrower');
        $user = $this->employeeUser($employee);
        $user->roles()->syncWithoutDetaching(Role::query()->where('name', 'credit_union_committee')->firstOrFail());

        // The loan is against their own membership even though an officer keyed it in.
        $member = $this->memberWithBalance('800401', savings: 5000, overrides: ['employee_id' => $employee->id]);
        $loan = app(LoanService::class)->apply($member, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer()->id);

        $this->actingAs($user->fresh());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(CreditUnionLoan::STATUS_PENDING, $loan->refresh()->status);
    }

    public function test_a_loan_awaiting_guarantors_cannot_be_approved_until_the_shortfall_is_covered(): void
    {
        $borrower = $this->memberWithBalance('800501', savings: 2000);
        $loan = app(LoanService::class)->apply($borrower, ['principal_amount' => 6000, 'term_months' => 12]);

        $guarantor = $this->memberWithBalance('800502', savings: 5000);
        $loans = app(LoanService::class);

        // Only 1200 of the 2000 shortfall is covered so far.
        $partial = $loans->addGuarantor($loan, $guarantor, 1200);
        $loans->acceptGuarantor($partial);

        $this->actingAs($this->committeeMember());

        Livewire::test(LoanShow::class, ['loan' => $loan->refresh()])
            ->call('approve')
            ->assertHasErrors('loan');

        $this->assertSame(CreditUnionLoan::STATUS_AWAITING_GUARANTOR, $loan->refresh()->status);
    }

    public function test_a_loan_is_approvable_once_split_guarantors_cover_the_full_shortfall(): void
    {
        $borrower = $this->memberWithBalance('800601', savings: 2000);
        $loans = app(LoanService::class);
        $loan = $loans->apply($borrower, ['principal_amount' => 6000, 'term_months' => 12]);

        $first = $this->memberWithBalance('800602', savings: 1500);
        $second = $this->memberWithBalance('800603', savings: 1500);

        $loans->acceptGuarantor($loans->addGuarantor($loan, $first, 1200));
        $loans->acceptGuarantor($loans->addGuarantor($loan, $second, 800));

        $this->actingAs($this->committeeMember());

        Livewire::test(LoanShow::class, ['loan' => $loan->refresh()])
            ->call('approve')
            ->assertHasNoErrors();

        $this->assertSame(CreditUnionLoan::STATUS_APPROVED, $loan->refresh()->status);
    }

    public function test_a_pending_guarantor_does_not_count_toward_the_shortfall_at_approval(): void
    {
        $borrower = $this->memberWithBalance('800701', savings: 2000);
        $loans = app(LoanService::class);
        $loan = $loans->apply($borrower, ['principal_amount' => 6000, 'term_months' => 12]);

        $guarantor = $this->memberWithBalance('800702', savings: 5000);
        $loans->addGuarantor($loan, $guarantor, 2000);

        $this->actingAs($this->committeeMember());

        // The guarantor has been asked but has not accepted yet.
        Livewire::test(LoanShow::class, ['loan' => $loan->refresh()])
            ->call('approve')
            ->assertHasErrors('loan');

        $this->assertSame(CreditUnionLoanGuarantor::STATUS_PENDING, $loan->guarantors()->firstOrFail()->status);
    }

    public function test_a_loan_can_be_rejected_with_a_reason(): void
    {
        $loan = $this->simpleLoan('800801');
        $this->actingAs($this->committeeMember());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->call('startReject')
            ->set('rejectionReason', 'Member already over-committed elsewhere.')
            ->call('reject')
            ->assertHasNoErrors();

        $loan->refresh();

        $this->assertSame(CreditUnionLoan::STATUS_REJECTED, $loan->status);
        $this->assertSame('Member already over-committed elsewhere.', $loan->rejection_reason);
        $this->assertSame('0.00', $loan->outstanding_balance);

        $this->assertTrue(AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.loan_rejected')
            ->exists());
    }

    public function test_an_applicant_cannot_reject_their_own_loan_either(): void
    {
        $applicant = $this->committeeMember('800901');
        $member = $this->memberWithBalance('800902', savings: 5000);
        $loan = app(LoanService::class)->apply($member, ['principal_amount' => 2000, 'term_months' => 12], $applicant->id);

        $this->actingAs($applicant);

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->call('startReject')
            ->assertForbidden();

        $this->assertSame(CreditUnionLoan::STATUS_PENDING, $loan->refresh()->status);
    }

    public function test_an_already_decided_loan_cannot_be_approved_again(): void
    {
        $loan = $this->simpleLoan('801001');
        $this->actingAs($this->committeeMember());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->call('approve')
            ->assertHasNoErrors();

        Livewire::test(LoanShow::class, ['loan' => $loan->refresh()])
            ->call('approve')
            ->assertHasErrors('loan');
    }

    public function test_an_approved_loan_can_be_disbursed(): void
    {
        $loan = $this->simpleLoan('801101');

        $this->actingAs($this->committeeMember());
        Livewire::test(LoanShow::class, ['loan' => $loan])->call('approve')->assertHasNoErrors();

        $this->actingAs($this->officer());
        Livewire::test(LoanShow::class, ['loan' => $loan->refresh()])
            ->set('disbursementForm.disbursement_reference', 'CHQ-4411')
            ->call('disburse')
            ->assertHasNoErrors();

        $loan->refresh();

        $this->assertSame(CreditUnionLoan::STATUS_DISBURSED, $loan->status);
        $this->assertSame('CHQ-4411', $loan->disbursement_reference);
        $this->assertNotNull($loan->disbursed_at);

        $this->assertTrue(AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.loan_disbursed')
            ->exists());
    }

    public function test_an_undecided_loan_cannot_be_disbursed(): void
    {
        $loan = $this->simpleLoan('801201');
        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->call('disburse')
            ->assertHasErrors('loan');

        $this->assertSame(CreditUnionLoan::STATUS_PENDING, $loan->refresh()->status);
    }

    /**
     * A loan comfortably inside the no-guarantor limit, applied for by an officer.
     */
    protected function simpleLoan(string $staffId): CreditUnionLoan
    {
        $member = $this->memberWithBalance($staffId, savings: 5000);

        return app(LoanService::class)->apply($member, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer('OFF'.$staffId)->id);
    }
}
