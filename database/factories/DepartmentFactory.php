<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'department_name' => $this->faker->randomElement([
                'Administration', 'HRAS', 'Finance', 'Commercial', 'Operations', 'Distribution', 'ICT', 'Internal Audit', 'Materials', 'GIS',
            ]),
        ];
    }
}
