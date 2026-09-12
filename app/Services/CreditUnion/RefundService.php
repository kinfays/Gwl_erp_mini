<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\CreditUnionRefund;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrections for wrongly-deducted amounts, credited back to the member. There is no
 * approval step by design - `credit_union.manage_refunds` is the only permission in play,
 * and the audit log plus the offsetting ledger entry are the trail.
 */
class RefundService
{
    public function __construct(
        protected LedgerService $ledger
    ) {}

    public function record(CreditUnionMember $member, array $payload, ?int $actorId = null): CreditUnionRefund
    {
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $accountType = (string) ($payload['account_type'] ?? CreditUnionLedgerEntry::ACCOUNT_SAVINGS);
        $reason = trim((string) ($payload['reason'] ?? ''));

        if (! $member->isActive()) {
            throw ValidationException::withMessages([
                'form.member_id' => 'Refunds can only be recorded for active members.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'form.amount' => 'Refund amount must be greater than zero.',
            ]);
        }

        if (! in_array($accountType, CreditUnionLedgerEntry::ACCOUNT_TYPES, true)) {
            throw ValidationException::withMessages([
                'form.account_type' => 'Select a valid account (shares or savings).',
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([
                'form.reason' => 'A reason is required for every refund.',
            ]);
        }

        $actorId ??= auth()->id();
        $refundedAt = Carbon::parse($payload['refunded_at'] ?? today()->toDateString())->toDateString();

        return DB::transaction(function () use ($member, $amount, $accountType, $reason, $refundedAt, $actorId): CreditUnionRefund {
            $refund = CreditUnionRefund::query()->create([
                'member_id' => $member->id,
                'account_type' => $accountType,
                'amount' => $amount,
                'reason' => $reason,
                'refunded_at' => $refundedAt,
                'recorded_by' => $actorId,
            ]);

            $entry = $this->ledger->post($member, [
                'account_type' => $accountType,
                'entry_type' => CreditUnionLedgerEntry::ENTRY_REFUND,
                'amount' => $amount,
                'transaction_date' => $refundedAt,
                'source' => CreditUnionLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
                'refund_id' => $refund->id,
                'remarks' => 'Refund: '.$reason,
            ], $actorId);

            Audit::log(
                action: 'credit_union.refund_recorded',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionRefund::class,
                targetId: $refund->id,
                metadata: [
                    'member_id' => $member->id,
                    'member_number' => $member->member_number,
                    'account_type' => $accountType,
                    'amount' => $amount,
                    'reason' => $reason,
                    'ledger_entry_id' => $entry->id,
                    'balance_after' => (float) $entry->balance_after,
                    'recorded_by' => $actorId,
                ]
            );

            return $refund;
        });
    }
}
