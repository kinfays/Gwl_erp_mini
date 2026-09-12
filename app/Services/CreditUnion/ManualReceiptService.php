<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionLoanRepayment;
use App\Models\CreditUnionManualReceipt;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash and cheque received over the counter - the CASH SHEET and the per-member rows of
 * CHEQUE REGISTER. For associate members this is their only contribution route.
 */
class ManualReceiptService
{
    public function __construct(
        protected LedgerService $ledger,
        protected LoanService $loans
    ) {}

    public function record(?CreditUnionMember $member, array $payload, ?int $actorId = null): CreditUnionManualReceipt
    {
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $method = (string) ($payload['method'] ?? CreditUnionManualReceipt::METHOD_CASH);
        $purpose = (string) ($payload['purpose'] ?? '');
        $banked = (bool) ($payload['banked'] ?? false);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'form.amount' => 'Receipt amount must be greater than zero.',
            ]);
        }

        if (! in_array($method, CreditUnionManualReceipt::METHODS, true)) {
            throw ValidationException::withMessages([
                'form.method' => 'Select a valid method (cash or cheque).',
            ]);
        }

        if (! in_array($purpose, CreditUnionManualReceipt::PURPOSES, true)) {
            throw ValidationException::withMessages([
                'form.purpose' => 'Select a valid purpose.',
            ]);
        }

        if (! $member && $purpose !== CreditUnionManualReceipt::PURPOSE_MEMBERSHIP_FORM_FEE) {
            throw ValidationException::withMessages([
                'form.member_id' => 'A member is required for anything other than a membership form fee.',
            ]);
        }

        if ($member && ! $member->isActive() && $purpose !== CreditUnionManualReceipt::PURPOSE_MEMBERSHIP_FORM_FEE) {
            throw ValidationException::withMessages([
                'form.member_id' => 'Receipts can only be posted for active members.',
            ]);
        }

        $loan = null;

        if ($purpose === CreditUnionManualReceipt::PURPOSE_LOAN_REPAYMENT) {
            $loan = $this->resolveLoan($member);
        }

        $actorId ??= auth()->id();
        $receivedDate = Carbon::parse($payload['received_date'] ?? today()->toDateString())->toDateString();

        return DB::transaction(function () use ($member, $payload, $amount, $method, $purpose, $banked, $receivedDate, $loan, $actorId): CreditUnionManualReceipt {
            $receipt = CreditUnionManualReceipt::query()->create([
                'member_id' => $member?->id,
                'method' => $method,
                'purpose' => $purpose,
                'cheque_no' => $payload['cheque_no'] ?? null,
                'payer_name' => $payload['payer_name'] ?? $member?->full_name,
                'amount' => $amount,
                'received_date' => $receivedDate,
                'banked_date' => $banked ? ($payload['banked_date'] ?? $receivedDate) : ($payload['banked_date'] ?? null),
                'banked' => $banked,
                'recorded_by' => $actorId,
                'remarks' => $payload['remarks'] ?? null,
            ]);

            $ledgerEntryId = null;
            $loanRepaymentId = null;

            if ($receipt->isLedgerPurpose() && $banked) {
                // Shares/savings only reach the ledger once the money is actually banked.
                $ledgerEntryId = $this->ledger->post($member, [
                    'account_type' => $purpose,
                    'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
                    'amount' => $amount,
                    'transaction_date' => $receivedDate,
                    'source' => $receipt->ledgerSource(),
                    'reference_no' => $receipt->cheque_no,
                    'remarks' => 'Manual '.$method.' receipt',
                ], $actorId)->id;
            }

            if ($purpose === CreditUnionManualReceipt::PURPOSE_LOAN_REPAYMENT && $loan) {
                $loanRepaymentId = $this->loans->recordRepayment($loan, [
                    'amount' => $amount,
                    'repayment_date' => $receivedDate,
                    'source' => $method === CreditUnionManualReceipt::METHOD_CHEQUE
                        ? CreditUnionLoanRepayment::SOURCE_CHEQUE
                        : CreditUnionLoanRepayment::SOURCE_CASH,
                    'reference_no' => $receipt->cheque_no,
                ], $actorId)->id;
            }

            Audit::log(
                action: 'credit_union.manual_receipt_recorded',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionManualReceipt::class,
                targetId: $receipt->id,
                metadata: [
                    'member_id' => $member?->id,
                    'member_number' => $member?->member_number,
                    'method' => $method,
                    'purpose' => $purpose,
                    'amount' => $amount,
                    'banked' => $banked,
                    'ledger_entry_id' => $ledgerEntryId,
                    'loan_repayment_id' => $loanRepaymentId,
                    // The membership form fee is an admin charge, never a ledger posting.
                    'ledgered' => $ledgerEntryId !== null,
                    'recorded_by' => $actorId,
                ]
            );

            return $receipt->refresh();
        });
    }

    /**
     * Banking a receipt that was recorded as un-banked is what releases it to the ledger.
     */
    public function markBanked(CreditUnionManualReceipt $receipt, ?string $bankedDate = null, ?int $actorId = null): CreditUnionManualReceipt
    {
        if ($receipt->banked) {
            throw ValidationException::withMessages([
                'receipts' => 'This receipt is already marked as banked.',
            ]);
        }

        $actorId ??= auth()->id();
        $bankedDate = Carbon::parse($bankedDate ?? today()->toDateString())->toDateString();

        return DB::transaction(function () use ($receipt, $bankedDate, $actorId): CreditUnionManualReceipt {
            $receipt->forceFill([
                'banked' => true,
                'banked_date' => $bankedDate,
            ])->save();

            $ledgerEntryId = null;

            if ($receipt->isLedgerPurpose() && $receipt->member) {
                $ledgerEntryId = $this->ledger->post($receipt->member, [
                    'account_type' => $receipt->purpose,
                    'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
                    'amount' => round((float) $receipt->amount, 2),
                    'transaction_date' => $receipt->received_date->toDateString(),
                    'source' => $receipt->ledgerSource(),
                    'reference_no' => $receipt->cheque_no,
                    'remarks' => 'Manual '.$receipt->method.' receipt',
                ], $actorId)->id;
            }

            Audit::log(
                action: 'credit_union.manual_receipt_banked',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionManualReceipt::class,
                targetId: $receipt->id,
                metadata: [
                    'member_id' => $receipt->member_id,
                    'purpose' => $receipt->purpose,
                    'amount' => (float) $receipt->amount,
                    'banked_date' => $bankedDate,
                    'ledger_entry_id' => $ledgerEntryId,
                    'banked_by' => $actorId,
                ]
            );

            return $receipt->refresh();
        });
    }

    protected function resolveLoan(?CreditUnionMember $member): CreditUnionLoan
    {
        $loan = CreditUnionLoan::query()
            ->where('member_id', $member?->id)
            ->outstanding()
            ->orderBy('id')
            ->first();

        if (! $loan) {
            throw ValidationException::withMessages([
                'form.purpose' => 'This member has no outstanding loan to repay.',
            ]);
        }

        return $loan;
    }
}
