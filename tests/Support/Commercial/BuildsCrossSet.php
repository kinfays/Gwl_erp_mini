<?php

namespace Tests\Support\Commercial;

use App\Services\Commercial\Cross\CrossSampleLoader;
use App\Services\Commercial\Cross\CrossSampleSet;

/**
 * Loads the invented cross-file set (CrossSampleSet) through the real import paths for the Phase 8e tests. Needs the host test to be a
 * Tests\Feature\Commercial\Customers\CustomerTestCase (regions, districts and the customer-list helpers). The two districts are Sowutuom (the
 * set's "first" district, routes 41xx) and Odorkor (42xx), both in Accra West.
 */
trait BuildsCrossSet
{
    /** @return array<string, mixed> */
    protected function loadCrossSet(): array
    {
        config(['gwl.commercial_customer_list_enabled' => true]);

        $directory = sys_get_temp_dir().'/cross-set-'.uniqid();
        $loaded = app(CrossSampleLoader::class)->load($directory, ['SOWUTUOM', 'ODORKOR'], 'ACCRA WEST');

        foreach ($loaded['files'] as $path) {
            $this->workbooks[] = $path;
        }

        return $loaded;
    }

    /** The route master's id of a route of the set, by its district and code. */
    protected function crossRoute(string $district, string $code): int
    {
        $districtId = $district === 'S' ? $this->sowutuom->id : $this->odorkor->id;
        $route = \App\Models\CommercialRoute::query()->where('district_id', $districtId)->where('code', $code)->firstOrFail();

        return (int) ($route->canonical_route_id ?? $route->id);
    }

    protected function crossFilters(array $over = []): array
    {
        return $over + ['region' => $this->accraWest->id];
    }

    /** A viewer with exactly these commercial permissions and no district limit (everything of the region). */
    protected function crossViewer(array $permissions, string $staffId = '900060', bool $districtLimited = false, $district = null): \App\Models\User
    {
        return $this->userWith($staffId, $permissions, $this->accraWest, $district ?? $this->sowutuom, $districtLimited);
    }

    /** Everything the cross screens need (reading, billing, customers, names). */
    protected function crossAll(string $staffId = '900060', bool $districtLimited = false, $district = null): \App\Models\User
    {
        return $this->crossViewer(['commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics', 'commercial.view_reader_performance', 'commercial.export_reports'], $staffId, $districtLimited, $district);
    }

    protected function crossPeriod(): string
    {
        return CrossSampleSet::BILL_PERIOD;
    }
}
