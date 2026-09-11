<?php

namespace Database\Factories;

use App\Models\CreditUnionLoan;
use App\Models\CreditUnionMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionLoan>
 */
class CreditUnionLoanFactory extends Factory
{
    protected $model = CreditUnionLoan::class;

    public function definition(): array
    {
        $principal = 2000.00;
        $termMonths = 12;
        $rate = (float) config('gwl.credit_union_loan_annual_interest_rate_percent', 15.0);
        $interest = round($principal * ($rate / 100) * ($termMonths / 12), 2);
        $totalRepayable = round($principal + $interest, 2);
        $savings = 5000.00;
        $limit = round($savings * (float) config('gwl.credit_union_loan_multiple_without_guarantor', 2), 2);

        return [
            'member_id' => CreditUnionMember::factory(),
            'loan_number' => 'CUL-'.now()->year.'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'principal_amount' => $principal,
            'interest_amount' => $interest,
            'total_repayable' => $totalRepayable,
            'interest_rate' => $rate,
            'term_months' => $termMonths,
            'monthly_installment_amount' => round($totalRepayable / $termMonths, 2),
            'savings_balance_at_application' => $savings,
            'no_guarantor_limit' => $limit,
            'guarantor_shortfall' => 0,
            'requires_guarantor' => false,
            'status' => CreditUnionLoan::STATUS_PENDING,
            'purpose' => 'School fees',
            'applied_at' => now(),
            'applied_by' => null,
            'outstanding_balance' => $totalRepayable,
        ];
    }

    public function forMember(CreditUnionMember $member): static
    {
        return $this->state(fn () => ['member_id' => $member->id]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function disbursed(): static
    {
        return $this->state(fn () => [
            'status' => CreditUnionLoan::STATUS_DISBURSED,
            'approved_at' => now(),
            'disbursed_at' => now(),
            'disbursement_reference' => 'CHQ-0001',
        ]);
    }

    /**
     * A loan needing guarantor cover for the portion above the no-guarantor limit.
     */
    public function awaitingGuarantor(float $shortfall = 1000.00): static
    {
        return $this->state(fn () => [
            'guarantor_shortfall' => $shortfall,
            'requires_guarantor' => true,
            'status' => CreditUnionLoan::STATUS_AWAITING_GUARANTOR,
        ]);
    }
}
