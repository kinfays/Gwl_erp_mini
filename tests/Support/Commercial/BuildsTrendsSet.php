<?php

namespace Tests\Support\Commercial;

use App\Models\User;
use App\Services\Commercial\Trends\TrendReport;
use App\Services\Commercial\Trends\TrendsSampleLoader;

/**
 * Loads the invented trends series (TrendsSampleSet) through the real import paths for the Phase 8f tests. Needs the host test to be a
 * Tests\Feature\Commercial\Customers\CustomerTestCase. The two districts are Sowutuom (routes 4101 to 4103, CCAs 80001 and 80002) and Odorkor
 * (4201 to 4203, CCAs 80003 and 80004), both in Accra West. The arithmetic of every figure is in TrendsSampleSet's schedule table.
 */
trait BuildsTrendsSet
{
    /** @var array<string, mixed> */
    protected array $series = [];

    protected function loadTrendsSeries(): void
    {
        config([
            'gwl.commercial_customer_list_enabled' => true, 'gwl.commercial_min_visits_for_ranking' => 10,
            'gwl.commercial_reconcile_max_route_failures_pct' => 10.0, 'gwl.commercial_reconcile_grand_total_tolerance_pct' => 1.0,
        ]);

        $this->series = app(TrendsSampleLoader::class)->load(sys_get_temp_dir().'/trends-set-'.uniqid(), ['SOWUTUOM', 'ODORKOR'], 'ACCRA WEST');

        foreach (array_merge(...array_values(array_map(fn ($set) => is_array($set) ? $set : [], $this->series['files']))) as $path) {
            $this->workbooks[] = $path;
        }
    }

    /** Everything the trends need (reading, billing, customers, names, export). */
    protected function trendViewer(string $staffId = '900060', bool $districtLimited = false, $district = null): User
    {
        return $this->userWith($staffId, ['commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics', 'commercial.view_reader_performance', 'commercial.export_reports'], $this->accraWest, $district ?? $this->sowutuom, $districtLimited);
    }

    /** @param  list<string>  $permissions */
    protected function trendViewerWith(array $permissions, string $staffId, bool $districtLimited = false): User
    {
        return $this->userWith($staffId, $permissions, $this->accraWest, $this->sowutuom, $districtLimited);
    }

    /** @return array<string, mixed> */
    protected function trend(string $key, array $q = []): array
    {
        return app(TrendReport::class)->build($key, $q + ['region' => $this->accraWest->id]);
    }

    /** @return array<string, array<string, mixed>> the measures of a part keyed by measure key */
    protected function measuresOf(array $part): array
    {
        return collect($part['data']['measures'])->keyBy('key')->all();
    }
}
