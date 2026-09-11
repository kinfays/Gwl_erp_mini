<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionDeductionBatchLine;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionLoanGuarantor;
use App\Models\CreditUnionLoanRepayment;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loan application, guarantor cover, committee approval, disbursement and repayment.
 * The confirmed policy rules live here: a 2x-savings cap before a guarantor is needed,
 * straight-line 15% annual interest, and no self-approval.
 */
class LoanService
{
    public function __construct(
        protected MemberEligibilityService $eligibility,
        protected LedgerService $ledger
    ) {}

    public function apply(CreditUnionMember $member, array $payload, ?int $actorId = null): CreditUnionLoan
    {
        $principal = round((float) ($payload['principal_amount'] ?? 0), 2);
        $termMonths = (int) ($payload['term_months'] ?? 0);

        if (! $member->isActive()) {
            throw ValidationException::withMessages([
                'form.principal_amount' => 'Loans can only be raised for active members.',
            ]);
        }

        if ($principal <= 0) {
            throw ValidationException::withMessages([
                'form.principal_amount' => 'Principal amount must be greater than zero.',
            ]);
        }

        if ($termMonths <= 0) {
            throw ValidationException::withMessages([
                'form.term_months' => 'Term must be at least one month.',
            ]);
        }

        if ($this->eligibility->hasActiveLoan($member)) {
            throw ValidationException::withMessages([
                'form.member_id' => 'This member already has an outstanding loan.',
            ]);
        }

        $terms = $this->computeTerms($member, $principal, $termMonths);
        $actorId ??= auth()->id();

        $loan = DB::transaction(function () use ($member, $payload, $terms, $termMonths, $principal, $actorId): CreditUnionLoan {
            $loan = CreditUnionLoan::query()->create([
                'member_id' => $member->id,
                'loan_number' => $this->nextLoanNumber(),
                'principal_amount' => $principal,
                'interest_amount' => $terms['interest_amount'],
                'total_repayable' => $terms['total_repayable'],
                'interest_rate' => $terms['interest_rate'],
                'term_months' => $termMonths,
                'monthly_installment_amount' => $terms['monthly_installment_amount'],
                'savings_balance_at_application' => $terms['savings_balance_at_application'],
                'no_guarantor_limit' => $terms['no_guarantor_limit'],
                'guarantor_shortfall' => $terms['guarantor_shortfall'],
                'requires_guarantor' => $terms['requires_guarantor'],
                'status' => $terms['requires_guarantor']
                    ? CreditUnionLoan::STATUS_AWAITING_GUARANTOR
                    : CreditUnionLoan::STATUS_PENDING,
                'purpose' => $payload['purpose'] ?? null,
                'applied_at' => now(),
                'applied_by' => $actorId,
                'outstanding_balance' => $terms['total_repayable'],
            ]);

            Audit::log(
                action: 'credit_union.loan_applied',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionLoan::class,
                targetId: $loan->id,
                metadata: [
                    'loan_number' => $loan->loan_number,
                    'member_number' => $member->member_number,
                    'principal_amount' => $principal,
                    'interest_rate' => $terms['interest_rate'],
                    'interest_amount' => $terms['interest_amount'],
                    'total_repayable' => $terms['total_repayable'],
                    'term_months' => $termMonths,
                    'no_guarantor_limit' => $terms['no_guarantor_limit'],
                    'guarantor_shortfall' => $terms['guarantor_shortfall'],
                    'requires_guarantor' => $terms['requires_guarantor'],
                ]
            );

            return $loan;
        });

        // Catch up on any payroll loan repayments Phase 2 captured before this loan existed.
        $this->postDeferredDeductionRepayments($loan, $actorId);

        return $loan->refresh();
    }

    /**
     * The confirmed policy maths, kept in one place so the apply screen can preview the
     * same numbers the loan will be written with.
     *
     * @return array<string, float|bool>
     */
    public function computeTerms(CreditUnionMember $member, float $principal, int $termMonths): array
    {
        $rate = (float) config('gwl.credit_union_loan_annual_interest_rate_percent', 15.0);
        $multiple = (float) config('gwl.credit_union_loan_multiple_without_guarantor', 2);

        $savingsBalance = round($member->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS), 2);
        $noGuarantorLimit = round($savingsBalance * $multiple, 2);
        $guarantorShortfall = round(max(0, $principal - $noGuarantorLimit), 2);

        // Straight-line: the full term's interest is fixed at application time.
        $interestAmount = round($principal * ($rate / 100) * ($termMonths / 12), 2);
        $totalRepayable = round($principal + $interestAmount, 2);

        return [
            'interest_rate' => $rate,
            'interest_amount' => $interestAmount,
            'total_repayable' => $totalRepayable,
            'monthly_installment_amount' => $termMonths > 0 ? round($totalRepayable / $termMonths, 2) : 0.0,
            'savings_balance_at_application' => $savingsBalance,
            'no_guarantor_limit' => $noGuarantorLimit,
            'guarantor_shortfall' => $guarantorShortfall,
            'requires_guarantor' => $guarantorShortfall > 0,
        ];
    }

    /**
     * Records a proposed guarantor. A candidate who fails the eligibility check is still
     * written down — as `declined` with the reason — so the attempt stays on the record.
     */
    public function addGuarantor(
        CreditUnionLoan $loan,
        CreditUnionMember $candidate,
        float $guaranteedAmount,
        ?int $actorId = null
    ): CreditUnionLoanGuarantor {
        if (! $loan->requires_guarantor) {
            throw ValidationException::withMessages([
                'guarantorForm.member_id' => 'This loan is within the no-guarantor limit and needs no guarantor.',
            ]);
        }

        if (! $loan->isDecidable()) {
            throw ValidationException::withMessages([
                'guarantorForm.member_id' => 'Guarantors can only be added while the loan is still awaiting a decision.',
            ]);
        }

        if ($candidate->id === $loan->member_id) {
            throw ValidationException::withMessages([
                'guarantorForm.member_id' => 'A member cannot guarantee their own loan.',
            ]);
        }

        $guaranteedAmount = round($guaranteedAmount, 2);

        if ($guaranteedAmount <= 0) {
            throw ValidationException::withMessages([
                'guarantorForm.guaranteed_amount' => 'Guaranteed amount must be greater than zero.',
            ]);
        }

        if ($loan->guarantors()->where('guarantor_member_id', $candidate->id)->exists()) {
            throw ValidationException::withMessages([
                'guarantorForm.member_id' => 'This member has already been proposed as a guarantor on this loan.',
            ]);
        }

        $check = $this->eligibility->isEligibleGuarantor($candidate, $guaranteedAmount);

        $guarantor = $loan->guarantors()->create([
            'guarantor_member_id' => $candidate->id,
            'guaranteed_amount' => $guaranteedAmount,
            'guarantor_asset_balance_at_guarantee' => $check['asset_balance'],
            'status' => $check['eligible']
                ? CreditUnionLoanGuarantor::STATUS_PENDING
                : CreditUnionLoanGuarantor::STATUS_DECLINED,
            'disqualified_reason' => $check['reason'],
            'eligibility_checked_at' => now(),
            'was_in_good_standing' => $check['eligible'],
            'responded_at' => $check['eligible'] ? null : now(),
        ]);

        Audit::log(
            action: 'credit_union.loan_guarantor_proposed',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionLoanGuarantor::class,
            targetId: $guarantor->id,
            metadata: [
                'loan_number' => $loan->loan_number,
                'guarantor_member_number' => $candidate->member_number,
                'guaranteed_amount' => $guaranteedAmount,
                'eligible' => $check['eligible'],
                'disqualified_reason' => $check['reason'],
                'asset_balance' => $check['asset_balance'],
                'recorded_by' => $actorId ?? auth()->id(),
            ]
        );

        return $guarantor;
    }

    public function acceptGuarantor(CreditUnionLoanGuarantor $guarantor, ?int $actorId = null): CreditUnionLoanGuarantor
    {
        if (! $guarantor->isPending()) {
            throw ValidationException::withMessages([
                'guarantors' => 'Only a pending guarantor request can be accepted.',
            ]);
        }

        $guarantor->forceFill([
            'status' => CreditUnionLoanGuarantor::STATUS_ACCEPTED,
            'responded_at' => now(),
        ])->save();

        $this->auditGuarantorResponse($guarantor, 'credit_union.loan_guarantor_accepted', $actorId);

        return $guarantor->refresh();
    }

    public function declineGuarantor(CreditUnionLoanGuarantor $guarantor, ?int $actorId = null): CreditUnionLoanGuarantor
    {
        if (! $guarantor->isPending()) {
            throw ValidationException::withMessages([
                'guarantors' => 'Only a pending guarantor request can be declined.',
            ]);
        }

        $guarantor->forceFill([
            'status' => CreditUnionLoanGuarantor::STATUS_DECLINED,
            'responded_at' => now(),
        ])->save();

        $this->auditGuarantorResponse($guarantor, 'credit_union.loan_guarantor_declined', $actorId);

        return $guarantor->refresh();
    }

    public function approve(CreditUnionLoan $loan, User $approver): CreditUnionLoan
    {
        $this->guardDecidable($loan);
        $this->guardNotSelfApproval($loan, $approver);

        $loan->load('guarantors');

        if ($loan->requires_guarantor && ! $loan->isFullyGuaranteed()) {
            throw ValidationException::withMessages([
                'loan' => sprintf(
                    'Accepted guarantors cover %s of the %s shortfall. %s still needs covering before approval.',
                    number_format($loan->acceptedGuaranteeTotal(), 2),
                    number_format((float) $loan->guarantor_shortfall, 2),
                    number_format($loan->guaranteeShortfallRemaining(), 2)
                ),
            ]);
        }

        $loan->forceFill([
            'status' => CreditUnionLoan::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();

        Audit::log(
            action: 'credit_union.loan_approved',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionLoan::class,
            targetId: $loan->id,
            metadata: [
                'loan_number' => $loan->loan_number,
                'approved_by' => $approver->id,
                'applied_by' => $loan->applied_by,
                'principal_amount' => (float) $loan->principal_amount,
                'guarantee_total' => $loan->acceptedGuaranteeTotal(),
            ]
        );

        return $loan->refresh();
    }

    public function reject(CreditUnionLoan $loan, User $approver, string $reason): CreditUnionLoan
    {
        $this->guardDecidable($loan);
        $this->guardNotSelfApproval($loan, $approver);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejectionReason' => 'A reason is required when rejecting a loan.',
            ]);
        }

        $loan->forceFill([
            'status' => CreditUnionLoan::STATUS_REJECTED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
            'outstanding_balance' => 0,
        ])->save();

        Audit::log(
            action: 'credit_union.loan_rejected',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionLoan::class,
            targetId: $loan->id,
            metadata: [
                'loan_number' => $loan->loan_number,
                'rejected_by' => $approver->id,
                'reason' => $reason,
            ]
        );

        return $loan->refresh();
    }

    public function disburse(CreditUnionLoan $loan, array $payload = [], ?int $actorId = null): CreditUnionLoan
    {
        if ($loan->status !== CreditUnionLoan::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'loan' => 'Only an approved loan can be disbursed.',
            ]);
        }

        $loan->forceFill([
            'status' => CreditUnionLoan::STATUS_DISBURSED,
            'disbursed_at' => isset($payload['disbursed_at']) && $payload['disbursed_at']
                ? Carbon::parse($payload['disbursed_at'])
                : now(),
            'disbursement_reference' => $payload['disbursement_reference'] ?? null,
        ])->save();

        Audit::log(
            action: 'credit_union.loan_disbursed',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionLoan::class,
            targetId: $loan->id,
            metadata: [
                'loan_number' => $loan->loan_number,
                'disbursement_reference' => $loan->disbursement_reference,
                'total_repayable' => (float) $loan->total_repayable,
                'disbursed_by' => $actorId ?? auth()->id(),
            ]
        );

        return $loan->refresh();
    }

    public function recordRepayment(CreditUnionLoan $loan, array $payload, ?int $actorId = null): CreditUnionLoanRepayment
    {
        if (! $loan->isRepayable()) {
            throw ValidationException::withMessages([
                'repaymentForm.amount' => 'Repayments can only be recorded against a disbursed loan.',
            ]);
        }

        return $this->writeRepayment($loan, $payload, $actorId, enforceSource: true);
    }

    /**
     * Phase 2 captured loan_repayment_amount on deduction batch lines but had no loan to
     * post it against. Once a loan exists for that member, those historical amounts are
     * written as repayments and the lines are marked posted.
     */
    public function postDeferredDeductionRepayments(CreditUnionLoan $loan, ?int $actorId = null): int
    {
        $member = $loan->member;

        // Associates are never on payroll, so they can never have deduction lines.
        if (! $member || $member->isAssociate()) {
            return 0;
        }

        $lines = CreditUnionDeductionBatchLine::query()
            ->where('member_id', $member->id)
            ->where('loan_repayment_posted', false)
            ->where('loan_repayment_amount', '>', 0)
            ->whereHas('batch', fn ($query) => $query->whereNotNull('posted_at'))
            ->with('batch')
            ->orderBy('id')
            ->get();

        $posted = 0;

        foreach ($lines as $line) {
            if ((float) $loan->outstanding_balance <= 0) {
                break;
            }

            $amount = min(round((float) $line->loan_repayment_amount, 2), round((float) $loan->outstanding_balance, 2));

            $this->writeRepayment($loan, [
                'amount' => $amount,
                'repayment_date' => $line->batch?->period_month?->toDateString() ?? today()->toDateString(),
                'source' => CreditUnionLoanRepayment::SOURCE_PAYROLL_DEDUCTION,
                'deduction_batch_id' => $line->deduction_batch_id,
                'reference_no' => $line->batch?->bank_reference,
            ], $actorId, enforceSource: false, deferredBackfill: true);

            $line->forceFill(['loan_repayment_posted' => true])->save();
            $loan->refresh();
            $posted++;
        }

        return $posted;
    }

    protected function writeRepayment(
        CreditUnionLoan $loan,
        array $payload,
        ?int $actorId,
        bool $enforceSource,
        bool $deferredBackfill = false
    ): CreditUnionLoanRepayment {
        $member = $loan->member;
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $source = (string) ($payload['source'] ?? CreditUnionLoanRepayment::SOURCE_CASH);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'repaymentForm.amount' => 'Repayment amount must be greater than zero.',
            ]);
        }

        if (! in_array($source, CreditUnionLoanRepayment::SOURCES, true)) {
            throw ValidationException::withMessages([
                'repaymentForm.source' => 'Select a valid repayment source.',
            ]);
        }

        if ($enforceSource && $member && ! in_array($source, CreditUnionLoanRepayment::sourcesFor($member), true)) {
            throw ValidationException::withMessages([
                'repaymentForm.source' => 'Associate members are not on GWL payroll - record the repayment as cash or cheque.',
            ]);
        }

        if ($amount > round((float) $loan->outstanding_balance, 2) + 0.005) {
            throw ValidationException::withMessages([
                'repaymentForm.amount' => 'Repayment exceeds the outstanding balance of '.number_format((float) $loan->outstanding_balance, 2).'.',
            ]);
        }

        $actorId ??= auth()->id();

        return DB::transaction(function () use ($loan, $payload, $amount, $source, $actorId, $deferredBackfill): CreditUnionLoanRepayment {
            $balanceAfter = round((float) $loan->outstanding_balance - $amount, 2);

            $repayment = $loan->repayments()->create([
                'amount' => $amount,
                'repayment_date' => Carbon::parse($payload['repayment_date'] ?? today()->toDateString())->toDateString(),
                'source' => $source,
                'deduction_batch_id' => $payload['deduction_batch_id'] ?? null,
                'reference_no' => $payload['reference_no'] ?? null,
                'balance_after' => $balanceAfter,
                'recorded_by' => $actorId,
            ]);

            $loan->forceFill([
                'outstanding_balance' => $balanceAfter,
                'status' => $this->statusAfterRepayment($loan, $balanceAfter),
            ])->save();

            Audit::log(
                action: 'credit_union.loan_repayment_recorded',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionLoanRepayment::class,
                targetId: $repayment->id,
                metadata: [
                    'loan_number' => $loan->loan_number,
                    'amount' => $amount,
                    'source' => $source,
                    'balance_after' => $balanceAfter,
                    'deduction_batch_id' => $repayment->deduction_batch_id,
                    'deferred_backfill' => $deferredBackfill,
                ]
            );

            return $repayment;
        });
    }

    protected function statusAfterRepayment(CreditUnionLoan $loan, float $balanceAfter): string
    {
        if ($balanceAfter <= 0) {
            return CreditUnionLoan::STATUS_COMPLETED;
        }

        // A backfilled repayment against a not-yet-disbursed loan must not fake a disbursement.
        return $loan->isRepayable() ? CreditUnionLoan::STATUS_ACTIVE : $loan->status;
    }

    /**
     * Whoever raised the loan, or whose own membership it is against, is the applicant.
     */
    public function isSelfApproval(CreditUnionLoan $loan, User $approver): bool
    {
        if ($loan->applied_by !== null && (int) $loan->applied_by === (int) $approver->id) {
            return true;
        }

        $member = $loan->member;

        if (! $member) {
            return false;
        }

        $employee = $approver->employee ?? $approver->employeeByStaffId;

        if ($employee && $member->employee_id !== null && (int) $member->employee_id === (int) $employee->id) {
            return true;
        }

        return $member->staff_id !== null
            && $approver->staff_id !== null
            && (string) $member->staff_id === (string) $approver->staff_id;
    }

    /**
     * Hard rule: whoever applied for the loan can never be the one to decide it, even if
     * they hold the committee permission.
     */
    protected function guardNotSelfApproval(CreditUnionLoan $loan, User $approver): void
    {
        if ($this->isSelfApproval($loan, $approver)) {
            abort(403, 'You cannot decide on your own loan application.');
        }
    }

    protected function guardDecidable(CreditUnionLoan $loan): void
    {
        if (! $loan->isDecidable()) {
            throw ValidationException::withMessages([
                'loan' => 'Only a pending loan can be approved or rejected.',
            ]);
        }
    }

    protected function auditGuarantorResponse(CreditUnionLoanGuarantor $guarantor, string $action, ?int $actorId): void
    {
        Audit::log(
            action: $action,
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionLoanGuarantor::class,
            targetId: $guarantor->id,
            metadata: [
                'loan_id' => $guarantor->loan_id,
                'guarantor_member_id' => $guarantor->guarantor_member_id,
                'guaranteed_amount' => (float) $guarantor->guaranteed_amount,
                'recorded_by' => $actorId ?? auth()->id(),
            ]
        );
    }

    public function nextLoanNumber(): string
    {
        $prefix = 'CUL-'.now()->year.'-';

        $highest = CreditUnionLoan::query()
            ->where('loan_number', 'like', $prefix.'%')
            ->pluck('loan_number')
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }
}
