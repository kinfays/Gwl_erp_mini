<?php

namespace Database\Factories;

use App\Models\CreditUnionMember;
use App\Models\CreditUnionWithdrawalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionWithdrawalRequest>
 */
class CreditUnionWithdrawalRequestFactory extends Factory
{
    protected $model = CreditUnionWithdrawalRequest::class;

    public function definition(): array
    {
        return [
            'member_id' => CreditUnionMember::factory(),
            'savings_amount' => 300.00,
            'shares_amount' => 0,
            'reason' => 'School fees',
            'status' => CreditUnionWithdrawalRequest::STATUS_PENDING,
            'requested_by' => null,
            'requested_at' => now(),
            'decided_by' => null,
            'decided_at' => null,
            'payment_method' => null,
            'payment_reference' => null,
            'paid_at' => null,
        ];
    }

    public function forMember(CreditUnionMember $member): static
    {
        return $this->state(fn () => ['member_id' => $member->id]);
    }

    public function amounts(float $savings, float $shares = 0): static
    {
        return $this->state(fn () => [
            'savings_amount' => $savings,
            'shares_amount' => $shares,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => CreditUnionWithdrawalRequest::STATUS_APPROVED,
            'decided_at' => now(),
        ]);
    }
}
