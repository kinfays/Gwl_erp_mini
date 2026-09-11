<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts ledger entries and maintains the running balance the passbook workbooks used to
 * keep as per-row Excel formulas.
 */
class LedgerService
{
    public function post(CreditUnionMember $member, array $payload, ?int $recordedBy = null): CreditUnionLedgerEntry
    {
        $accountType = (string) ($payload['account_type'] ?? '');
        $entryType = (string) ($payload['entry_type'] ?? CreditUnionLedgerEntry::ENTRY_CONTRIBUTION);
        $source = (string) ($payload['source'] ?? CreditUnionLedgerEntry::SOURCE_CASH);
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $transactionDate = $payload['transaction_date'] ?? today()->toDateString();

        if (! in_array($accountType, CreditUnionLedgerEntry::ACCOUNT_TYPES, true)) {
            throw ValidationException::withMessages([
                'form.account_type' => 'Select a valid account (shares or savings).',
            ]);
        }

        if (! in_array($entryType, CreditUnionLedgerEntry::ENTRY_TYPES, true)) {
            throw ValidationException::withMessages([
                'form.entry_type' => 'Select a valid entry type.',
            ]);
        }

        if (! in_array($source, CreditUnionLedgerEntry::SOURCES, true)) {
            throw ValidationException::withMessages([
                'form.source' => 'Select a valid payment source.',
            ]);
        }

        if (! in_array($source, $member->allowedLedgerSources(), true)) {
            throw ValidationException::withMessages([
                'form.source' => 'Associate members are not on GWL payroll - use cash or cheque instead.',
            ]);
        }

        if ($entryType === CreditUnionLedgerEntry::ENTRY_ADJUSTMENT) {
            if ($amount === 0.0) {
                throw ValidationException::withMessages([
                    'form.amount' => 'An adjustment amount cannot be zero.',
                ]);
            }
        } elseif ($amount <= 0) {
            throw ValidationException::withMessages([
                'form.amount' => 'Amount must be greater than zero.',
            ]);
        }

        if (! $member->isActive()) {
            throw ValidationException::withMessages([
                'form.amount' => 'Ledger entries can only be posted for active members.',
            ]);
        }

        return DB::transaction(function () use ($member, $payload, $accountType, $entryType, $source, $amount, $transactionDate, $recordedBy): CreditUnionLedgerEntry {
            $currentBalance = $this->lockedBalanceFor($member, $accountType);
            $signedAmount = CreditUnionLedgerEntry::signedAmountFor($entryType, $amount);
            $balanceAfter = round($currentBalance + $signedAmount, 2);

            if ($balanceAfter < 0) {
                throw ValidationException::withMessages([
                    'form.amount' => 'This would take the '.$accountType.' balance below zero (current balance '.number_format($currentBalance, 2).').',
                ]);
            }

            $entry = CreditUnionLedgerEntry::query()->create([
                'member_id' => $member->id,
                'account_type' => $accountType,
                'entry_type' => $entryType,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'transaction_date' => Carbon::parse($transactionDate)->toDateString(),
                'source' => $source,
                'deduction_batch_id' => $payload['deduction_batch_id'] ?? null,
                'reference_no' => $payload['reference_no'] ?? null,
                'remarks' => $payload['remarks'] ?? null,
                'recorded_by' => $recordedBy ?? auth()->id(),
            ]);

            Audit::log(
                action: 'credit_union.ledger_entry_posted',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionLedgerEntry::class,
                targetId: $entry->id,
                metadata: [
                    'member_id' => $member->id,
                    'member_number' => $member->member_number,
                    'account_type' => $accountType,
                    'entry_type' => $entryType,
                    'amount' => $amount,
                    'balance_after' => $balanceAfter,
                    'source' => $source,
                ]
            );

            return $entry;
        });
    }

    public function balances(CreditUnionMember $member): array
    {
        $shares = $member->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SHARES);
        $savings = $member->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS);

        return [
            CreditUnionLedgerEntry::ACCOUNT_SHARES => $shares,
            CreditUnionLedgerEntry::ACCOUNT_SAVINGS => $savings,
            'total' => round($shares + $savings, 2),
        ];
    }

    protected function lockedBalanceFor(CreditUnionMember $member, string $accountType): float
    {
        // lockForUpdate compiles away on SQLite and guards concurrent posts everywhere else.
        $balance = CreditUnionLedgerEntry::query()
            ->where('member_id', $member->id)
            ->where('account_type', $accountType)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('balance_after');

        return (float) ($balance ?? 0);
    }
}
