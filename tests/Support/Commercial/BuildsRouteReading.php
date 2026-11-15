<?php

namespace Tests\Support\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Models\Region;
use App\Services\Commercial\ReadingRouteOwner;

/**
 * Builds reading-by-route uploads straight in the database for the analytics, screen and export tests (Phase 8c). Invented CCAs, ids
 * and routes only. Needs the host test to be a CommercialTestCase (regions and districts).
 *
 * A row is [cca staff id, cca name, route code or null (a CCA listed with no route), district, strength, read, skipped, visited].
 * The owner flag is set exactly as the importer does (ReadingRouteOwner, per route in the upload).
 */
trait BuildsRouteReading
{
    /**
     * @param  list<array{0: string, 1: string, 2: string|null, 3: District|null, 4: int, 5: int, 6: int, 7: int}>  $rows
     * @param  array{region?: Region, from?: string, to?: string, imported_by?: int|null, status?: string}  $options
     */
    protected function seedRouteReading(array $rows, array $options = []): CommercialImportBatch
    {
        $region = $options['region'] ?? $this->accraWest;
        $from = $options['from'] ?? '2026-10-01';
        $to = $options['to'] ?? '2026-10-31';

        $batch = CommercialImportBatch::query()->create([
            'report_type' => CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY,
            'region_id' => $region->id,
            'period_from' => $from,
            'period_to' => $to,
            'granularity' => CommercialImportBatch::GRANULARITY_MULTI_MONTH,
            'source_filename' => 'route-reading-'.uniqid().'.xlsx',
            'file_hash' => hash('sha256', uniqid('route-reading', true)),
            'status' => $options['status'] ?? CommercialImportBatch::STATUS_IMPORTED,
            'imported_by' => $options['imported_by'] ?? null,
            'imported_at' => now(),
        ]);

        $routeIds = [];
        $made = [];

        foreach ($rows as $index => [$cca, $name, $code, $district, $strength, $read, $skipped, $visited]) {
            $routeId = null;

            if ($code !== null && $district !== null) {
                $routeIds[$district->id.'|'.$code] ??= CommercialRoute::query()->firstOrCreate(
                    ['district_id' => $district->id, 'code' => $code],
                    ['region_id' => $district->region_id, 'name' => $code, 'source' => CommercialRoute::SOURCE_READING, 'active' => true, 'is_pending' => false]
                )->id;
                $routeId = $routeIds[$district->id.'|'.$code];
            }

            $made[$index] = CommercialReadingRouteStat::query()->create([
                'batch_id' => $batch->id, 'route_id' => $routeId, 'district_id' => $district?->id, 'cca_staff_id' => $cca, 'cca_name_raw' => $name,
                'route_label_raw' => $code === null ? null : ($district ? strtoupper($district->district_name).' '.$code : $code),
                'verified_strength' => $code === null ? 0 : $strength, 'read_count' => $read, 'skipped_count' => $skipped, 'visited_count' => $visited,
                'is_route_owner' => false, 'match_status' => CommercialReadingRouteStat::MATCH_MATCHED,
            ]);
        }

        // the owner of each route in the upload (the importer's own rule)
        foreach (collect($made)->filter(fn ($r) => $r->route_id !== null)->groupBy('route_id') as $group) {
            $list = $group->values();
            $owner = ReadingRouteOwner::ownerIndex($list->map(fn ($r) => ['cca' => $r->cca_staff_id, 'strength' => $r->verified_strength, 'visited' => $r->visited_count])->all());
            $list[$owner]->update(['is_route_owner' => true]);
        }

        return $batch;
    }

    /**
     * The small hand-computed fixture (October 2026, Accra West). The arithmetic is done by hand in ReadingRouteAnalyticsTest.
     *
     *   80001 AMA TEST    S1 (100 strength, 70 read, 10 skipped, 80 visited), S2 (60, 40, 20, 60)
     *   80002 KWAME TEST  S3 (120, 90, 30, 120) and the STALE row of S1 (strength 95, no visits)
     *   80003 EFUA TEST   O1 (200, 150, 50, 200)
     *   80004 YAW TEST    O2 (80, 0, 0, 0): routes, ZERO visits
     *   80005 KOFI TEST   listed with no route at all
     *
     * @return list<array<int, mixed>>
     */
    protected function routeFixtureRows(): array
    {
        return [
            ['80001', 'AMA TEST', 'S1', $this->sowutuom, 100, 70, 10, 80],
            ['80001', 'AMA TEST', 'S2', $this->sowutuom, 60, 40, 20, 60],
            ['80002', 'KWAME TEST', 'S3', $this->sowutuom, 120, 90, 30, 120],
            ['80002', 'KWAME TEST', 'S1', $this->sowutuom, 95, 0, 0, 0],
            ['80003', 'EFUA TEST', 'O1', $this->odorkor, 200, 150, 50, 200],
            ['80004', 'YAW TEST', 'O2', $this->odorkor, 80, 0, 0, 0],
            ['80005', 'KOFI TEST', null, $this->odorkor, 0, 0, 0, 0],
        ];
    }
}
