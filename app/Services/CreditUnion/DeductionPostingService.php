<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionDeductionBatch;
use App\Models\CreditUnionDeductionBatchLine;
use App\Models\CreditUnionLedgerEntry;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fans a validated batch out into the member ledgers in one transaction — the step that
 * replaces manually re-typing the same monthly figure into LEDGER 2026, the loan sheet
 * and every member's personal passbook file.
 */
class DeductionPostingService
{
    public function __construct(
        protected LedgerService $ledger
    ) {}

    public function post(CreditUnionDeductionBatch $batch, ?int $actorId = null): CreditUnionDeductionBatch
    {
        if (! $batch->isPostable()) {
            throw ValidationException::withMessages([
                'batch' => 'This batch has already been posted.',
            ]);
        }

        $lines = $batch->lines()->matched()->with('member')->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'batch' => 'This batch has no matched lines to post. Resolve the unmatched rows first.',
            ]);
        }

        $actorId ??= auth()->id();

        DB::transaction(function () use ($batch, $lines, $actorId): void {
            $amountPosted = 0.0;
            $entriesCreated = 0;

            foreach ($lines as $line) {
                /** @var CreditUnionDeductionBatchLine $line */
                $member = $line->member;

                // Belt and braces: the import already refuses associate members, and
                // LedgerService refuses payroll_deduction for them independently.
                if (! $member || $member->isAssociate() || ! $member->isActive()) {
                    throw ValidationException::withMessages([
                        'batch' => 'Line for staff ID '.$line->staff_id_raw.' is no longer postable. Re-run matching on this batch.',
                    ]);
                }

                foreach ($this->postableAccounts($line) as $accountType => $amount) {
                    $this->ledger->post($member, [
                        'account_type' => $accountType,
                        'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
                        'amount' => $amount,
                        'transaction_date' => $batch->period_month->toDateString(),
                        'source' => CreditUnionLedgerEntry::SOURCE_PAYROLL_DEDUCTION,
                        'deduction_batch_id' => $batch->id,
                        'reference_no' => $batch->bank_reference,
                        'remarks' => 'Payroll deduction for '.$batch->period_month->format('F Y'),
                    ], $actorId);

                    $amountPosted += $amount;
                    $entriesCreated++;
                }
            }

            $amountPosted = round($amountPosted, 2);
            $amountReceived = round((float) $batch->amount_received, 2);

            $batch->forceFill([
                'amount_posted' => $amountPosted,
                // loan_repayment_amount is excluded from amount_posted on purpose: nothing
                // has been posted for it until Phase 3's loan tables exist.
                'status' => $this->resolveStatus($amountPosted, $amountReceived),
                'posted_by' => $actorId,
                'posted_at' => now(),
            ])->save();

            Audit::log(
                action: 'credit_union.deduction_batch_posted',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionDeductionBatch::class,
                targetId: $batch->id,
                metadata: [
                    'period_month' => $batch->period_month->toDateString(),
                    'lines_posted' => $lines->count(),
                    'ledger_entries_created' => $entriesCreated,
                    'amount_received' => $amountReceived,
                    'amount_posted' => $amountPosted,
                    'variance' => round($amountPosted - $amountReceived, 2),
                    'status' => $batch->status,
                    'unposted_loan_repayment_total' => $this->unpostedLoanRepaymentTotal($batch),
                ]
            );
        });

        return $batch->refresh();
    }

    /**
     * What a posted batch looks like against the bulk remittance it arrived with.
     */
    public function reconciliation(CreditUnionDeductionBatch $batch): array
    {
        $lines = $batch->lines()->get();

        return [
            'amount_received' => round((float) $batch->amount_received, 2),
            'amount_posted' => round((float) $batch->amount_posted, 2),
            'variance' => $batch->variance(),
            'is_reconciled' => $batch->status === CreditUnionDeductionBatch::STATUS_RECONCILED,
            'line_count' => $lines->count(),
            'matched_count' => $lines->where('match_status', CreditUnionDeductionBatchLine::MATCH_MATCHED)->count(),
            'unmatched_count' => $lines->where('match_status', CreditUnionDeductionBatchLine::MATCH_UNMATCHED)->count(),
            'skipped_count' => $lines->where('match_status', CreditUnionDeductionBatchLine::MATCH_SKIPPED)->count(),
            'invalid_associate_count' => $lines->where('match_status', CreditUnionDeductionBatchLine::MATCH_INVALID_ASSOCIATE)->count(),
            'shares_total' => round($lines->sum(fn (CreditUnionDeductionBatchLine $line) => (float) $line->shares_amount), 2),
            'savings_total' => round($lines->sum(fn (CreditUnionDeductionBatchLine $line) => (float) $line->savings_amount), 2),
            'loan_repayment_total' => round($lines->sum(fn (CreditUnionDeductionBatchLine $line) => (float) $line->loan_repayment_amount), 2),
            'unposted_loan_repayment_total' => round(
                $lines->filter(fn (CreditUnionDeductionBatchLine $line) => $line->hasUnpostedLoanRepayment())
                    ->sum(fn (CreditUnionDeductionBatchLine $line) => (float) $line->loan_repayment_amount),
                2
            ),
            'unresolved_total' => round(
                $lines->filter(fn (CreditUnionDeductionBatchLine $line) => ! $line->isMatched())
                    ->sum(fn (CreditUnionDeductionBatchLine $line) => $line->totalAmount()),
                2
            ),
        ];
    }

    /**
     * @return array<string, float>
     */
    protected function postableAccounts(CreditUnionDeductionBatchLine $line): array
    {
        return collect([
            CreditUnionLedgerEntry::ACCOUNT_SHARES => round((float) $line->shares_amount, 2),
            CreditUnionLedgerEntry::ACCOUNT_SAVINGS => round((float) $line->savings_amount, 2),
        ])->filter(fn (float $amount) => $amount > 0)->all();
    }

    protected function resolveStatus(float $amountPosted, float $amountReceived): string
    {
        return abs($amountPosted - $amountReceived) < 0.005
            ? CreditUnionDeductionBatch::STATUS_RECONCILED
            : CreditUnionDeductionBatch::STATUS_VARIANCE;
    }

    protected function unpostedLoanRepaymentTotal(CreditUnionDeductionBatch $batch): float
    {
        return round(
            (float) $batch->lines()
                ->where('loan_repayment_posted', false)
                ->sum('loan_repayment_amount'),
            2
        );
    }
}
