<?php

namespace Database\Factories;

use App\Models\CreditUnionManualReceipt;
use App\Models\CreditUnionMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionManualReceipt>
 */
class CreditUnionManualReceiptFactory extends Factory
{
    protected $model = CreditUnionManualReceipt::class;

    public function definition(): array
    {
        return [
            'member_id' => CreditUnionMember::factory(),
            'method' => CreditUnionManualReceipt::METHOD_CASH,
            'purpose' => CreditUnionManualReceipt::PURPOSE_SAVINGS,
            'cheque_no' => null,
            'payer_name' => $this->faker->name(),
            'amount' => 250.00,
            'received_date' => today()->toDateString(),
            'banked_date' => null,
            'banked' => false,
            'recorded_by' => null,
            'remarks' => null,
        ];
    }

    public function forMember(CreditUnionMember $member): static
    {
        return $this->state(fn () => [
            'member_id' => $member->id,
            'payer_name' => $member->full_name,
        ]);
    }

    public function purpose(string $purpose): static
    {
        return $this->state(fn () => ['purpose' => $purpose]);
    }

    public function cheque(string $chequeNo = 'CHQ-0001'): static
    {
        return $this->state(fn () => [
            'method' => CreditUnionManualReceipt::METHOD_CHEQUE,
            'cheque_no' => $chequeNo,
        ]);
    }

    public function banked(): static
    {
        return $this->state(fn () => [
            'banked' => true,
            'banked_date' => today()->toDateString(),
        ]);
    }
}
