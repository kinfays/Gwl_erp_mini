<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionInterestDistribution;
use App\Models\CreditUnionInterestDistributionLine;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The annual run that shares loan interest income back out to members, pro-rata by their
 * combined shares + savings holdings as at the fiscal year end.
 */
class InterestDistributionService
{
    public function __construct(
        protected LedgerService $ledger
    ) {}

    public function compute(
        string $periodLabel,
        string $periodEndDate,
        ?string $periodStartDate = null,
        ?int $actorId = null
    ): CreditUnionInterestDistribution {
        $periodLabel = trim($periodLabel);

        if ($periodLabel === '') {
            throw ValidationException::withMessages([
                'form.period_label' => 'A period label is required (for example 2025/2026).',
            ]);
        }

        $endDate = Carbon::parse($periodEndDate)->toDateString();
        $startDate = Carbon::parse($periodStartDate ?? Carbon::parse($endDate)->subYear()->addDay())->toDateString();

        if ($startDate > $endDate) {
            throw ValidationException::withMessages([
                'form.period_start_date' => 'The period start date must fall on or before the end date.',
            ]);
        }

        $actorId ??= auth()->id();
        $pool = $this->interestPoolFor($startDate, $endDate);

        if ($pool <= 0) {
            throw ValidationException::withMessages([
                'form.period_end_date' => 'No loan interest was earned in this period, so there is nothing to distribute.',
            ]);
        }

        return DB::transaction(function () use ($periodLabel, $startDate, $endDate, $pool, $actorId): CreditUnionInterestDistribution {
            $distribution = CreditUnionInterestDistribution::query()->create([
                'period_label' => $periodLabel,
                'period_start_date' => $startDate,
                'period_end_date' => $endDate,
                'total_interest_pool' => $pool,
                'credit_account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
                'status' => CreditUnionInterestDistribution::STATUS_DRAFT,
                'computed_by' => $actorId,
                'computed_at' => now(),
            ]);

            $lineCount = $this->writeLines($distribution, $pool, $endDate);

            $distribution->forceFill([
                'status' => CreditUnionInterestDistribution::STATUS_COMPUTED,
            ])->save();

            Audit::log(
                action: 'credit_union.interest_distribution_computed',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionInterestDistribution::class,
                targetId: $distribution->id,
                metadata: [
                    'period_label' => $periodLabel,
                    'period_start_date' => $startDate,
                    'period_end_date' => $endDate,
                    'total_interest_pool' => $pool,
                    'line_count' => $lineCount,
                    'line_total' => $distribution->lineTotal(),
                    'computed_by' => $actorId,
                ]
            );

            return $distribution->refresh();
        });
    }

    public function approve(CreditUnionInterestDistribution $distribution, User $approver): CreditUnionInterestDistribution
    {
        // A distribution is a union-wide batch, not one member's request, so there is no
        // applicant to guard against here - any committee holder may approve it.
        if (! $distribution->isComputed()) {
            throw ValidationException::withMessages([
                'distribution' => 'Only a computed distribution can be approved.',
            ]);
        }

        $distribution->forceFill([
            'status' => CreditUnionInterestDistribution::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        Audit::log(
            action: 'credit_union.interest_distribution_approved',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionInterestDistribution::class,
            targetId: $distribution->id,
            metadata: [
                'period_label' => $distribution->period_label,
                'total_interest_pool' => (float) $distribution->total_interest_pool,
                'line_count' => $distribution->lines()->count(),
                'approved_by' => $approver->id,
            ]
        );

        return $distribution->refresh();
    }

    public function post(CreditUnionInterestDistribution $distribution, ?int $actorId = null): CreditUnionInterestDistribution
    {
        if (! $distribution->isApproved()) {
            throw ValidationException::withMessages([
                'distribution' => 'Only an approved distribution can be posted.',
            ]);
        }

        $actorId ??= auth()->id();

        DB::transaction(function () use ($distribution, $actorId): void {
            $lines = $distribution->lines()->payable()->with('member')->orderBy('id')->get();
            $postedTotal = 0.0;

            foreach ($lines as $line) {
                if (! $line->member || ! $line->member->isActive()) {
                    throw ValidationException::withMessages([
                        'distribution' => 'Member '.($line->member?->member_number ?? $line->member_id)
                            .' is no longer active. Recompute the distribution before posting.',
                    ]);
                }

                $entry = $this->ledger->post($line->member, [
                    'account_type' => $distribution->credit_account_type,
                    'entry_type' => CreditUnionLedgerEntry::ENTRY_INTEREST,
                    'source' => CreditUnionLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
                    'amount' => (float) $line->amount,
                    'transaction_date' => $distribution->period_end_date->toDateString(),
                    'remarks' => 'Interest distribution '.$distribution->period_label,
                ], $actorId);

                $line->forceFill(['ledger_entry_id' => $entry->id])->save();
                $postedTotal += (float) $line->amount;
            }

            $distribution->forceFill([
                'status' => CreditUnionInterestDistribution::STATUS_POSTED,
                'posted_by' => $actorId,
                'posted_at' => now(),
            ])->save();

            Audit::log(
                action: 'credit_union.interest_distribution_posted',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionInterestDistribution::class,
                targetId: $distribution->id,
                metadata: [
                    'period_label' => $distribution->period_label,
                    'credit_account_type' => $distribution->credit_account_type,
                    'total_interest_pool' => (float) $distribution->total_interest_pool,
                    'lines_posted' => $lines->count(),
                    'amount_posted' => round($postedTotal, 2),
                    'posted_by' => $actorId,
                ]
            );
        });

        return $distribution->refresh();
    }

    /**
     * Interest ACCRUED on loans disbursed in the period - the confirmed basis, rather
     * than interest collected so far through repayments.
     */
    public function interestPoolFor(string $startDate, string $endDate): float
    {
        return round((float) CreditUnionLoan::query()
            ->whereNotNull('disbursed_at')
            ->whereDate('disbursed_at', '>=', $startDate)
            ->whereDate('disbursed_at', '<=', $endDate)
            ->sum('interest_amount'), 2);
    }

    /**
     * Writes one line per active member holding assets, then absorbs the rounding
     * remainder so the lines sum to the pool exactly.
     */
    protected function writeLines(CreditUnionInterestDistribution $distribution, float $pool, string $endDate): int
    {
        $snapshots = CreditUnionMember::query()
            ->where('status', CreditUnionMember::STATUS_ACTIVE)
            ->orderBy('id')
            ->get()
            ->map(fn (CreditUnionMember $member) => [
                'member' => $member,
                'balance' => $member->assetBalanceAsOf($endDate),
            ])
            // A member holding nothing gets no line at all, rather than a zero-balance
            // row or a divide-by-zero.
            ->filter(fn (array $row) => $row['balance'] > 0)
            ->values();

        $totalBalance = round($snapshots->sum(fn (array $row) => $row['balance']), 2);

        if ($totalBalance <= 0) {
            throw ValidationException::withMessages([
                'form.period_end_date' => 'No active member held any shares or savings at '.$endDate.', so there is nothing to distribute against.',
            ]);
        }

        $lines = [];
        $allocated = 0.0;

        foreach ($snapshots as $row) {
            // Multiply before dividing: pool * (balance / total) loses precision earlier.
            $amount = round(($pool * $row['balance']) / $totalBalance, 2);
            $proportion = $row['balance'] / $totalBalance;
            $allocated += $amount;

            $lines[] = $distribution->lines()->create([
                'member_id' => $row['member']->id,
                'asset_balance_at_computation' => $row['balance'],
                'share_of_pool_percent' => round($proportion * 100, 4),
                'amount' => $amount,
            ]);
        }

        $this->absorbRoundingRemainder($lines, $pool, round($allocated, 2));

        return count($lines);
    }

    /**
     * Per-line rounding drifts a cent or two away from the pool. Rather than leave the
     * union's books out by that, the largest line takes the difference.
     *
     * @param  array<int, CreditUnionInterestDistributionLine>  $lines
     */
    protected function absorbRoundingRemainder(array $lines, float $pool, float $allocated): void
    {
        $remainder = round($pool - $allocated, 2);

        if ($remainder === 0.0 || $lines === []) {
            return;
        }

        $largest = collect($lines)
            ->sortByDesc(fn (CreditUnionInterestDistributionLine $line) => (float) $line->amount)
            ->first();

        $largest->forceFill([
            'amount' => round((float) $largest->amount + $remainder, 2),
        ])->save();
    }
}
