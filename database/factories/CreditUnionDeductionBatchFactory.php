<?php

namespace Database\Factories;

use App\Models\CreditUnionDeductionBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionDeductionBatch>
 */
class CreditUnionDeductionBatchFactory extends Factory
{
    protected $model = CreditUnionDeductionBatch::class;

    public function definition(): array
    {
        return [
            'period_month' => today()->startOfMonth()->toDateString(),
            'bank_reference' => strtoupper($this->faker->bothify('GCB ######')),
            'banked_date' => today()->toDateString(),
            'amount_received' => 0,
            'amount_posted' => 0,
            'status' => CreditUnionDeductionBatch::STATUS_IMPORTED,
            'import_file_path' => null,
            'imported_by' => null,
            'imported_at' => now(),
            'posted_by' => null,
            'posted_at' => null,
            'notes' => null,
        ];
    }

    public function received(float $amount): static
    {
        return $this->state(fn () => ['amount_received' => $amount]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => CreditUnionDeductionBatch::STATUS_DRAFT]);
    }
}
