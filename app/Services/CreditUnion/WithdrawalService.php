<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\CreditUnionWithdrawalRequest;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Request, approve and pay out savings/shares withdrawals. Validating the request against
 * the member's real ledger balance is the control the WITHDRAWALS spreadsheet could never
 * enforce - there, a payout was just a number typed into a cell.
 */
class WithdrawalService
{
    public function __construct(
        protected LedgerService $ledger
    ) {}

    public function request(CreditUnionMember $member, array $payload, ?int $actorId = null): CreditUnionWithdrawalRequest
    {
        $savings = round((float) ($payload['savings_amount'] ?? 0), 2);
        $shares = round((float) ($payload['shares_amount'] ?? 0), 2);

        if (! $member->isActive()) {
            throw ValidationException::withMessages([
                'form.member_id' => 'Withdrawals can only be raised for active members.',
            ]);
        }

        if ($savings < 0 || $shares < 0) {
            throw ValidationException::withMessages([
                'form.savings_amount' => 'Withdrawal amounts cannot be negative.',
            ]);
        }

        if ($savings + $shares <= 0) {
            throw ValidationException::withMessages([
                'form.savings_amount' => 'Enter a savings or shares amount to withdraw.',
            ]);
        }

        $this->guardAgainstBalance($member, $savings, $shares);

        $actorId ??= auth()->id();

        $withdrawal = CreditUnionWithdrawalRequest::query()->create([
            'member_id' => $member->id,
            'savings_amount' => $savings,
            'shares_amount' => $shares,
            'reason' => $payload['reason'] ?? null,
            'status' => CreditUnionWithdrawalRequest::STATUS_PENDING,
            'requested_by' => $actorId,
            'requested_at' => now(),
        ]);

        Audit::log(
            action: 'credit_union.withdrawal_requested',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionWithdrawalRequest::class,
            targetId: $withdrawal->id,
            metadata: [
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'savings_amount' => $savings,
                'shares_amount' => $shares,
                'total_amount' => round($savings + $shares, 2),
                'requested_by' => $actorId,
            ]
        );

        return $withdrawal;
    }

    public function approve(CreditUnionWithdrawalRequest $withdrawal, User $approver): CreditUnionWithdrawalRequest
    {
        $this->guardPending($withdrawal);
        $this->guardNotSelfApproval($withdrawal, $approver);

        // The balance can have moved since the request was raised.
        $this->guardAgainstBalance(
            $withdrawal->member,
            round((float) $withdrawal->savings_amount, 2),
            round((float) $withdrawal->shares_amount, 2)
        );

        $withdrawal->forceFill([
            'status' => CreditUnionWithdrawalRequest::STATUS_APPROVED,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'rejection_reason' => null,
        ])->save();

        Audit::log(
            action: 'credit_union.withdrawal_approved',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionWithdrawalRequest::class,
            targetId: $withdrawal->id,
            metadata: [
                'member_id' => $withdrawal->member_id,
                'approved_by' => $approver->id,
                'requested_by' => $withdrawal->requested_by,
                'total_amount' => $withdrawal->totalAmount(),
            ]
        );

        return $withdrawal->refresh();
    }

    public function reject(CreditUnionWithdrawalRequest $withdrawal, User $approver, string $reason): CreditUnionWithdrawalRequest
    {
        $this->guardPending($withdrawal);
        $this->guardNotSelfApproval($withdrawal, $approver);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejectionReason' => 'A reason is required when rejecting a withdrawal.',
            ]);
        }

        $withdrawal->forceFill([
            'status' => CreditUnionWithdrawalRequest::STATUS_REJECTED,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        Audit::log(
            action: 'credit_union.withdrawal_rejected',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionWithdrawalRequest::class,
            targetId: $withdrawal->id,
            metadata: [
                'member_id' => $withdrawal->member_id,
                'rejected_by' => $approver->id,
                'reason' => $reason,
            ]
        );

        return $withdrawal->refresh();
    }

    /**
     * Pays an approved withdrawal out and debits the member's ledger, one entry per
     * account leg, each tagged with this withdrawal.
     */
    public function markPaid(CreditUnionWithdrawalRequest $withdrawal, array $payload, ?int $actorId = null): CreditUnionWithdrawalRequest
    {
        if (! $withdrawal->isApproved()) {
            throw ValidationException::withMessages([
                'paymentForm.payment_method' => 'Only an approved withdrawal can be paid out.',
            ]);
        }

        $member = $withdrawal->member;
        $method = (string) ($payload['payment_method'] ?? '');

        if (! in_array($method, CreditUnionWithdrawalRequest::PAYMENT_METHODS, true)) {
            throw ValidationException::withMessages([
                'paymentForm.payment_method' => 'Select a valid payment method.',
            ]);
        }

        if (! in_array($method, CreditUnionWithdrawalRequest::paymentMethodsFor($member), true)) {
            throw ValidationException::withMessages([
                'paymentForm.payment_method' => 'Associate members are paid in cash or by cheque.',
            ]);
        }

        $actorId ??= auth()->id();

        DB::transaction(function () use ($withdrawal, $member, $method, $payload, $actorId): void {
            foreach ($withdrawal->payableAccounts() as $accountType => $amount) {
                $this->ledger->post($member, [
                    'account_type' => $accountType,
                    'entry_type' => CreditUnionLedgerEntry::ENTRY_WITHDRAWAL,
                    'amount' => $amount,
                    'transaction_date' => $payload['paid_at'] ?? today()->toDateString(),
                    'source' => $method,
                    'withdrawal_id' => $withdrawal->id,
                    'reference_no' => $payload['payment_reference'] ?? null,
                    'remarks' => 'Withdrawal payout',
                ], $actorId);
            }

            $withdrawal->forceFill([
                'status' => CreditUnionWithdrawalRequest::STATUS_PAID,
                'payment_method' => $method,
                'payment_reference' => $payload['payment_reference'] ?? null,
                'paid_at' => now(),
            ])->save();

            Audit::log(
                action: 'credit_union.withdrawal_paid',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionWithdrawalRequest::class,
                targetId: $withdrawal->id,
                metadata: [
                    'member_id' => $member->id,
                    'member_number' => $member->member_number,
                    'savings_amount' => (float) $withdrawal->savings_amount,
                    'shares_amount' => (float) $withdrawal->shares_amount,
                    'total_amount' => $withdrawal->totalAmount(),
                    'payment_method' => $method,
                    'payment_reference' => $withdrawal->payment_reference,
                    'paid_by' => $actorId,
                ]
            );
        });

        return $withdrawal->refresh();
    }

    /**
     * Whoever raised the request, or whose own membership it is against, is the requester.
     */
    public function isSelfApproval(CreditUnionWithdrawalRequest $withdrawal, User $approver): bool
    {
        if ($withdrawal->requested_by !== null && (int) $withdrawal->requested_by === (int) $approver->id) {
            return true;
        }

        $member = $withdrawal->member;

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
     * Nothing may be withdrawn that the member's ledger does not actually hold.
     */
    protected function guardAgainstBalance(CreditUnionMember $member, float $savings, float $shares): void
    {
        $balances = $this->ledger->balances($member);

        if ($savings > $balances[CreditUnionLedgerEntry::ACCOUNT_SAVINGS] + 0.005) {
            throw ValidationException::withMessages([
                'form.savings_amount' => 'Savings withdrawal exceeds the member\'s savings balance of '
                    .number_format($balances[CreditUnionLedgerEntry::ACCOUNT_SAVINGS], 2).'.',
            ]);
        }

        if ($shares > $balances[CreditUnionLedgerEntry::ACCOUNT_SHARES] + 0.005) {
            throw ValidationException::withMessages([
                'form.shares_amount' => 'Shares withdrawal exceeds the member\'s shares balance of '
                    .number_format($balances[CreditUnionLedgerEntry::ACCOUNT_SHARES], 2).'.',
            ]);
        }
    }

    protected function guardPending(CreditUnionWithdrawalRequest $withdrawal): void
    {
        if (! $withdrawal->isPending()) {
            throw ValidationException::withMessages([
                'withdrawal' => 'Only a pending withdrawal request can be decided on.',
            ]);
        }
    }

    /**
     * Hard rule, matching loans: whoever raised the request can never be the one to
     * decide it, even if they hold the committee permission.
     */
    protected function guardNotSelfApproval(CreditUnionWithdrawalRequest $withdrawal, User $approver): void
    {
        if ($this->isSelfApproval($withdrawal, $approver)) {
            abort(403, 'You cannot decide on your own withdrawal request.');
        }
    }
}
