<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creating an employee fires EmployeeObserver, which creates the linked user
 * and sends an invite — call Notification::fake() in tests.
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public const MALE_FIRST_NAMES = ['Kwame', 'Kofi', 'Kwesi', 'Yaw', 'Kwabena', 'Kwaku', 'Kojo', 'Nii', 'Ebo', 'Fiifi', 'Selasi', 'Emmanuel', 'Samuel', 'Isaac'];

    public const FEMALE_FIRST_NAMES = ['Ama', 'Akosua', 'Abena', 'Afua', 'Yaa', 'Adwoa', 'Akua', 'Efua', 'Esi', 'Naa', 'Dzifa', 'Gifty', 'Comfort', 'Mercy'];

    public const SURNAMES = ['Mensah', 'Owusu', 'Boateng', 'Asante', 'Osei', 'Appiah', 'Addo', 'Tetteh', 'Quaye', 'Lamptey', 'Ankrah', 'Adjei', 'Darko', 'Ofori', 'Sarpong', 'Gyamfi'];

    protected $model = Employee::class;

    public function definition(): array
    {
        $gender = $this->faker->randomElement(['Male', 'Female']);
        $firstNames = $gender === 'Male' ? self::MALE_FIRST_NAMES : self::FEMALE_FIRST_NAMES;

        return [
            'staff_id' => $this->faker->unique()->numerify('######'),
            'full_name' => $this->faker->randomElement($firstNames).' '.$this->faker->randomElement(self::SURNAMES),
            'gender' => $gender,
            'category' => $this->faker->randomElement(['Senior Staff', 'Junior Staff', 'Management']),
            'email' => $this->faker->unique()->safeEmail(),
            'job_title_id' => JobTitle::factory(),
            'department_id' => Department::factory(),
            'district_id' => District::factory(),
            'region_id' => fn (array $attributes) => District::query()->whereKey($attributes['district_id'])->value('region_id'),
            // Re-derived from the district name by Employee::boot().
            'location_type' => 'District',
            'date_of_birth' => $this->faker->dateTimeBetween('-58 years', '-22 years')->format('Y-m-d'),
            'date_joined' => $this->faker->dateTimeBetween('-15 years', '-1 month')->format('Y-m-d'),
            'present_appointment' => null,
            'unit' => null,
            'is_active' => true,
        ];
    }

    public function inactive(string $reason = 'left'): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'deactivation_reason' => $reason,
        ]);
    }

    public function inDistrict(District $district): static
    {
        return $this->state(fn () => [
            'district_id' => $district->id,
            'region_id' => $district->region_id,
        ]);
    }
}
