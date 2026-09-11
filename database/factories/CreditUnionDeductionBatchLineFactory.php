<?php

namespace Database\Factories;

use App\Models\CreditUnionDeductionBatch;
use App\Models\CreditUnionDeductionBatchLine;
use App\Models\CreditUnionMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionDeductionBatchLine>
 */
class CreditUnionDeductionBatchLineFactory extends Factory
{
    protected $model = CreditUnionDeductionBatchLine::class;

    public function definition(): array
    {
        return [
            'deduction_batch_id' => CreditUnionDeductionBatch::factory(),
            'member_id' => null,
            'staff_id_raw' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'name_raw' => $this->faker->name(),
            'shares_amount' => 50,
            'savings_amount' => 150,
            'loan_repayment_amount' => 0,
            'loan_repayment_posted' => false,
            'match_status' => CreditUnionDeductionBatchLine::MATCH_UNMATCHED,
            'resolution_notes' => null,
        ];
    }

    public function forMember(CreditUnionMember $member): static
    {
        return $this->state(fn () => [
            'member_id' => $member->id,
            'staff_id_raw' => (string) ($member->staff_id ?? $member->member_number),
            'name_raw' => $member->full_name,
            'match_status' => $member->isAssociate()
                ? CreditUnionDeductionBatchLine::MATCH_INVALID_ASSOCIATE
                : CreditUnionDeductionBatchLine::MATCH_MATCHED,
        ]);
    }

    public function amounts(float $shares, float $savings, float $loanRepayment = 0): static
    {
        return $this->state(fn () => [
            'shares_amount' => $shares,
            'savings_amount' => $savings,
            'loan_repayment_amount' => $loanRepayment,
        ]);
    }
}
