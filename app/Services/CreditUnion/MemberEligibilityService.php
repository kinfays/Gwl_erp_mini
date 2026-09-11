<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionLoan;
use App\Models\CreditUnionMember;

/**
 * The "good standing" check behind the guarantor rule: a guarantor must be free of their
 * own outstanding loan and must be able to stand in trust for the amount they are covering.
 */
class MemberEligibilityService
{
    public const REASON_HAS_ACTIVE_LOAN = 'has_active_loan';
    public const REASON_INSUFFICIENT_BALANCE = 'insufficient_balance';

    public function __construct(
        protected LedgerService $ledger
    ) {}

    /**
     * @return array{eligible: bool, reason: string|null, asset_balance: float}
     */
    public function isEligibleGuarantor(CreditUnionMember $candidate, float $guaranteedAmount): array
    {
        $assetBalance = $this->assetBalance($candidate);

        if ($this->hasActiveLoan($candidate)) {
            return [
                'eligible' => false,
                'reason' => self::REASON_HAS_ACTIVE_LOAN,
                'asset_balance' => $assetBalance,
            ];
        }

        if ($assetBalance < round($guaranteedAmount, 2)) {
            return [
                'eligible' => false,
                'reason' => self::REASON_INSUFFICIENT_BALANCE,
                'asset_balance' => $assetBalance,
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'asset_balance' => $assetBalance,
        ];
    }

    /**
     * A loan of the member's own that is still out: disbursed or actively being repaid.
     */
    public function hasActiveLoan(CreditUnionMember $member): bool
    {
        return CreditUnionLoan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', CreditUnionLoan::OUTSTANDING_STATUSES)
            ->exists();
    }

    /**
     * The member's own combined shares + savings holdings, taken as the latest
     * balance_after on each account.
     */
    public function assetBalance(CreditUnionMember $member): float
    {
        return $this->ledger->balances($member)['total'];
    }

    public function describeReason(?string $reason): string
    {
        return match ($reason) {
            self::REASON_HAS_ACTIVE_LOAN => 'This member already has an outstanding loan of their own.',
            self::REASON_INSUFFICIENT_BALANCE => 'This member\'s own shares and savings do not cover the amount they would guarantee.',
            default => 'Eligible.',
        };
    }
}
