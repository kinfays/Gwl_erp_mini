<?php

namespace Database\Factories;

use App\Models\JobTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobTitle>
 */
class JobTitleFactory extends Factory
{
    protected $model = JobTitle::class;

    public function definition(): array
    {
        return [
            'job_title_name' => $this->faker->randomElement([
                'Manager', 'District Manager', 'Accountant', 'ICT Officer', 'Commercial Officer', 'Customer Service Officer',
                'Engineer', 'Meter Reader', 'Plumber', 'Secretary', 'Assistant Human Resource Officer',
            ]),
        ];
    }
}
