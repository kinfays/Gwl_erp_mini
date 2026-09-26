<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * The checkout code here is arbitrary; use VisitorService::checkIn() when a
 * test depends on codes being unique among today's visitors.
 *
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    public function definition(): array
    {
        $firstNames = [...EmployeeFactory::MALE_FIRST_NAMES, ...EmployeeFactory::FEMALE_FIRST_NAMES];

        return [
            'visitor_name' => $this->faker->randomElement($firstNames).' '.$this->faker->randomElement(EmployeeFactory::SURNAMES),
            'phone' => $this->faker->randomElement(['024', '054', '055', '020', '050', '027']).$this->faker->numerify('#######'),
            'staff_id' => Employee::factory(),
            'purpose' => $this->faker->randomElement(['Bill payment enquiry', 'Official meeting', 'Job interview', 'Invoice submission']),
            'signature' => 'data:image/png;base64,'.base64_encode('signature'),
            'checkout_code' => (string) $this->faker->numberBetween(1, 999),
            'check_in_at' => now(),
            'check_out_at' => null,
            'checked_out_by' => null,
        ];
    }

    public function checkedOut(string $by = 'self'): static
    {
        return $this->state(fn (array $attributes) => [
            'check_out_at' => Carbon::parse($attributes['check_in_at'])->addHour(),
            'checked_out_by' => $by,
        ]);
    }
}
