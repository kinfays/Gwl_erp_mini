<?php

namespace Database\Factories;

use App\Models\CreditUnionInterestDistribution;
use App\Models\CreditUnionLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionInterestDistribution>
 */
class CreditUnionInterestDistributionFactory extends Factory
{
    protected $model = CreditUnionInterestDistribution::class;

    public function definition(): array
    {
        $endDate = today()->endOfYear();

        return [
            'period_label' => ($endDate->year - 1).'/'.$endDate->year,
            'period_start_date' => $endDate->copy()->subYear()->addDay()->toDateString(),
            'period_end_date' => $endDate->toDateString(),
            'total_interest_pool' => 1000.00,
            'credit_account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
            'status' => CreditUnionInterestDistribution::STATUS_DRAFT,
            'computed_by' => null,
            'computed_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'posted_by' => null,
            'posted_at' => null,
            'notes' => null,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function computed(): static
    {
        return $this->state(fn () => [
            'status' => CreditUnionInterestDistribution::STATUS_COMPUTED,
            'computed_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => CreditUnionInterestDistribution::STATUS_APPROVED,
            'computed_at' => now(),
            'approved_at' => now(),
        ]);
    }
}
