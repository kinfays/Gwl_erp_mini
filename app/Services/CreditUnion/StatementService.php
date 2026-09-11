<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;

/**
 * Builds the digital replacement for the hand-maintained per-member passbook workbook.
 * Shares + savings only for now; the loans section arrives with the loans phase.
 */
class StatementService
{
    public function __construct(
        protected LedgerService $ledger
    ) {}

    public function payload(CreditUnionMember $member): array
    {
        $entries = $member->ledgerEntries()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $balances = $this->ledger->balances($member);

        return [
            'member' => $member,
            'shares' => $entries->where('account_type', CreditUnionLedgerEntry::ACCOUNT_SHARES)->values(),
            'savings' => $entries->where('account_type', CreditUnionLedgerEntry::ACCOUNT_SAVINGS)->values(),
            'balances' => $balances,
            'generatedAt' => now(),
        ];
    }

    public function fileName(CreditUnionMember $member): string
    {
        return 'credit-union-statement-'.strtolower((string) $member->member_number).'-'.now()->format('Ymd-His').'.pdf';
    }
}
