<?php

namespace Database\Factories;

use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionLedgerEntry>
 */
class CreditUnionLedgerEntryFactory extends Factory
{
    protected $model = CreditUnionLedgerEntry::class;

    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, 20, 800);

        return [
            'member_id' => CreditUnionMember::factory(),
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
            'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
            'amount' => $amount,
            'balance_after' => $amount,
            'transaction_date' => today()->toDateString(),
            'source' => CreditUnionLedgerEntry::SOURCE_CASH,
            'reference_no' => $this->faker->bothify('RCP-####'),
            'remarks' => null,
            'recorded_by' => null,
        ];
    }

    public function shares(): static
    {
        return $this->state(fn () => [
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SHARES,
        ]);
    }

    public function withdrawal(): static
    {
        return $this->state(fn () => [
            'entry_type' => CreditUnionLedgerEntry::ENTRY_WITHDRAWAL,
        ]);
    }

    public function payrollDeduction(): static
    {
        return $this->state(fn () => [
            'source' => CreditUnionLedgerEntry::SOURCE_PAYROLL_DEDUCTION,
        ]);
    }
}
