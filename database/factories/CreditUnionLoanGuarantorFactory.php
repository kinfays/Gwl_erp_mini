<?php

namespace Database\Factories;

use App\Models\CreditUnionLoan;
use App\Models\CreditUnionLoanGuarantor;
use App\Models\CreditUnionMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionLoanGuarantor>
 */
class CreditUnionLoanGuarantorFactory extends Factory
{
    protected $model = CreditUnionLoanGuarantor::class;

    public function definition(): array
    {
        return [
            'loan_id' => CreditUnionLoan::factory(),
            'guarantor_member_id' => CreditUnionMember::factory(),
            'guaranteed_amount' => 1000.00,
            'guarantor_asset_balance_at_guarantee' => 2000.00,
            'status' => CreditUnionLoanGuarantor::STATUS_PENDING,
            'disqualified_reason' => null,
            'eligibility_checked_at' => now(),
            'was_in_good_standing' => true,
            'responded_at' => null,
        ];
    }

    public function forLoan(CreditUnionLoan $loan): static
    {
        return $this->state(fn () => ['loan_id' => $loan->id]);
    }

    public function by(CreditUnionMember $member): static
    {
        return $this->state(fn () => ['guarantor_member_id' => $member->id]);
    }

    public function covering(float $amount): static
    {
        return $this->state(fn () => ['guaranteed_amount' => $amount]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => CreditUnionLoanGuarantor::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    public function declined(?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => CreditUnionLoanGuarantor::STATUS_DECLINED,
            'disqualified_reason' => $reason,
            'was_in_good_standing' => $reason === null,
            'responded_at' => now(),
        ]);
    }
}
