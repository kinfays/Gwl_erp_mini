<?php

namespace Database\Factories;

use App\Models\IctAssetIssueReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IctAssetIssueReport>
 */
class IctAssetIssueReportFactory extends Factory
{
    protected $model = IctAssetIssueReport::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Internet keeps dropping', 'Password reset request', 'Printer paper jam', 'Desktop not booting']),
            'issue_type' => $this->faker->randomElement(IctAssetIssueReport::ISSUE_TYPES),
            'reason' => $this->faker->sentence(),
            'status' => 'Open',
            'date_solved' => null,
            'linked_asset_id' => null,
            'reporting_region_id' => null,
            'reporting_district_id' => null,
            'reported_by_user_id' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'Resolved',
            'date_solved' => now()->subDays($this->faker->numberBetween(0, 30))->toDateString(),
        ]);
    }
}
