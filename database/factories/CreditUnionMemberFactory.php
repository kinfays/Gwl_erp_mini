<?php

namespace Database\Factories;

use App\Models\CreditUnionMember;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditUnionMember>
 */
class CreditUnionMemberFactory extends Factory
{
    protected $model = CreditUnionMember::class;

    public function definition(): array
    {
        $staffId = (string) $this->faker->unique()->numberBetween(100000, 999999);

        return [
            'member_type' => CreditUnionMember::TYPE_STAFF,
            'member_number' => $staffId,
            'employee_id' => null,
            'staff_id' => $staffId,
            'full_name' => $this->faker->name(),
            'phone' => $this->faker->numerify('02########'),
            'address' => $this->faker->streetAddress(),
            'application_source' => CreditUnionMember::SOURCE_HR_ADDED,
            'status' => CreditUnionMember::STATUS_ACTIVE,
            'registered_at' => today()->toDateString(),
            'membership_form_fee_amount' => config('gwl.credit_union_membership_form_fee'),
            'membership_form_fee_paid_at' => today()->toDateString(),
            'initial_share_amount' => config('gwl.credit_union_initial_share_amount'),
            'initial_share_paid_at' => null,
            'created_by' => null,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn () => [
            'member_type' => CreditUnionMember::TYPE_STAFF,
            'member_number' => $employee->staff_id,
            'employee_id' => $employee->id,
            'staff_id' => $employee->staff_id,
            'full_name' => $employee->full_name,
        ]);
    }

    public function associate(): static
    {
        return $this->state(fn () => [
            'member_type' => CreditUnionMember::TYPE_ASSOCIATE,
            'member_number' => CreditUnionMember::ASSOCIATE_NUMBER_PREFIX
                .str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'employee_id' => null,
            'staff_id' => null,
            'application_source' => CreditUnionMember::SOURCE_ASSOCIATE_MANUAL,
        ]);
    }

    public function pending(?User $applicant = null): static
    {
        return $this->state(fn () => [
            'status' => CreditUnionMember::STATUS_PENDING,
            'application_source' => CreditUnionMember::SOURCE_SELF_APPLIED,
            'applied_by' => $applicant?->id,
        ]);
    }

    /**
     * A member whose one-time initial share has already been posted to the shares ledger.
     */
    public function shareIssued(): static
    {
        return $this->state(fn () => [
            'initial_share_paid_at' => today()->toDateString(),
        ]);
    }
}
