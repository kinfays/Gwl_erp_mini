<?php

namespace App\Services\Commercial;

use App\Models\CommercialReadingRouteStat;
use Illuminate\Support\Collection;

/**
 * The analyses of the reading report BY ROUTE (design 8.36), as pure passes over rows that are ALREADY scoped to the viewer and to the
 * uploads the period rules chose (CommercialReportData does the scoping and the choosing; nothing here reads the database except through
 * what it is handed). The Reading screen, the trends and the exports all call it, so they cannot disagree.
 *
 * THE DEFINITIONS (written only here):
 *   route         a CANONICAL route (or, for a row whose district could not be placed yet, what was printed). Its figures over a range:
 *                 read, skipped, visited are SUMMED over every row and upload in the range; its VERIFIED STRENGTH is a stock, so it is the
 *                 OWNER row's strength in the LATEST upload of the range that has the route, counted once.
 *   owner         ReadingRouteOwner: the CCA row with the most visits (ties: the larger strength, then the lower staff id). The other
 *                 rows of a route are "assigned, no visits".
 *   CCA           owned routes = the routes whose owner (latest upload) is this CCA; owned strength = their strengths. read / skipped /
 *                 visited = the CCA's own rows (all routes, all uploads). unvisited = owned strength - the CCA's visits on the routes it
 *                 owns; coverage = those visits / owned strength.
 *   unvisited     strength - visited, always recomputed (the source's Unvisited column is never stored). Over a SUMMED range revisits can
 *                 push visited above strength: coverage is NOT capped at 100% and unvisited can be negative; backlog lists only positive.
 *   skip rate     skipped / visited, in percent; null on zero visits. Coverage = visited / strength, in percent.
 *   eligible      a CCA with at least commercial_min_visits_for_ranking visits in the range (ranked in R1, judged in R7).
 */
class ReadingRouteAnalyticsService
{
    /** A district needs this many eligible CCAs before an outlier is called (as in the by-month analysis). */
    public const MIN_PEERS = 5;

    /** A route in more than this many rows of one upload is listed with its first rows only. */
    public const LIST_CAP = 500;

    // ---------------------------------------------------------------- the one pass

    /**
     * @param  Collection<int, CommercialReadingRouteStat|array<string, mixed>>  $rows  the scoped rows of the chosen uploads
     * @param  array<int, array{from: string, to: string}>  $batches  batch id => its period (the chosen uploads)
     * @param  array<int, string>  $districtNames  district id => name
     * @return array<string, mixed>
     */
    public function analyse(Collection $rows, array $batches, array $districtNames = [], ?int $minVisits = null): array
    {
        $minVisits ??= max(1, (int) config('gwl.commercial_min_visits_for_ranking', 300));
        $rows = $this->normalise($rows);
        $routes = $this->routes($rows, $batches, $districtNames);
        $ccas = $this->ccas($rows, $routes, $districtNames, $minVisits);
        $districts = $this->districts($routes, $rows, $districtNames);

        return [
            'rows' => $rows->count(),
            'routes' => $routes,
            'ccas' => $ccas,
            'districts' => $districts,
            'totals' => $this->totals($routes, $ccas),
            'workload' => $this->workload($ccas),
            'backlog' => $this->backlog($routes, $ccas),
            'coverage' => $this->roleFindings($rows, $routes, $ccas),
            'outliers' => $this->outliers($ccas, $districtNames, $minVisits),
            'min_visits' => $minVisits,
        ];
    }

    /**
     * Each upload on its own (a point of the trend), oldest first. A point carries the same figures the tables show for that upload.
     *
     * @param  Collection<int, CommercialReadingRouteStat|array<string, mixed>>  $rows
     * @param  array<int, array{from: string, to: string}>  $batches
     * @param  array<int, string>  $districtNames
     * @return list<array<string, mixed>>
     */
    public function trend(Collection $rows, array $batches, array $districtNames = [], ?int $minVisits = null): array
    {
        $rows = $this->normalise($rows);
        $points = [];

        $ordered = $batches;
        uasort($ordered, fn (array $a, array $b) => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        foreach ($ordered as $batchId => $period) {
            $one = $rows->where('b', $batchId)->values();
            $a = $this->analyse($one, [$batchId => $period], $districtNames, $minVisits);
            $workload = $a['workload'];

            $points[] = [
                'batch_id' => (int) $batchId,
                'label' => CommercialPeriods::describe($period['from'], $period['to']),
                'from' => $period['from'], 'to' => $period['to'],
                'ccas_active' => count(array_filter($a['ccas'], fn (array $c) => $c['visited'] > 0)),
                'ccas_listed' => count($a['ccas']),
                'routes' => count(array_filter($a['routes'], fn (array $r) => $r['placed'])),
                'owned_routes' => array_sum(array_column($a['ccas'], 'owned_routes')),
                'strength' => $a['totals']['strength'], 'read' => $a['totals']['read'], 'skipped' => $a['totals']['skipped'], 'visited' => $a['totals']['visited'],
                'unvisited' => $a['totals']['unvisited'], 'coverage' => $a['totals']['coverage'], 'skip_rate' => $a['totals']['skip_rate'],
                'districts' => array_map(fn (array $d) => ['id' => $d['id'], 'name' => $d['name'], 'active_ccas' => $d['active_ccas'], 'strength' => $d['strength'], 'visited' => $d['visited'], 'skip_rate' => $d['skip_rate'], 'coverage' => $d['coverage']], $a['districts']),
                'workload_ccas' => $workload['ccas'], 'workload_largest' => $workload['largest'], 'workload_median' => $workload['median'], 'workload_cv' => $workload['cv'],
                'unvisited_backlog' => $a['backlog']['unvisited_total'], 'routes_zero_visits' => count($a['backlog']['routes_zero_visits']), 'ccas_zero_visits' => count($a['backlog']['ccas_zero_visits']),
                'multi_cca_routes' => count($a['coverage']['multi_cca_routes']), 'ccas_no_routes' => count($a['coverage']['ccas_no_routes']),
                'eligible_ccas' => $a['outliers']['eligible'], 'outlier_ccas' => count($a['outliers']['flagged']),
            ];
        }

        return $points;
    }

    // ---------------------------------------------------------------- rows

    /**
     * Plain arrays, one per row: the models are read once.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function normalise(Collection $rows): Collection
    {
        return $rows->map(function ($r): array {
            if ($r instanceof CommercialReadingRouteStat) {
                $label = $r->route_label_raw;

                return [
                    'id' => (int) $r->id, 'b' => (int) $r->batch_id, 'route_id' => $r->route_id !== null ? (int) $r->route_id : null, 'district_id' => $r->district_id !== null ? (int) $r->district_id : null,
                    'cca' => (string) $r->cca_staff_id, 'name' => $r->cca_name_raw, 'employee_id' => $r->employee_id !== null ? (int) $r->employee_id : null,
                    'label' => $label, 'strength' => (int) $r->verified_strength, 'read' => (int) $r->read_count, 'skipped' => (int) $r->skipped_count, 'visited' => (int) $r->visited_count,
                    'owner' => (bool) $r->is_route_owner, 'flagged' => (bool) $r->is_flagged, 'cca_only' => $label === null,
                ];
            }

            $r['cca_only'] ??= ($r['label'] ?? null) === null;

            return $r;
        })->values();
    }

    // ---------------------------------------------------------------- routes

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, array{from: string, to: string}>  $batches
     * @param  array<int, string>  $districtNames
     * @return array<string, array<string, mixed>> keyed by route key
     */
    protected function routes(Collection $rows, array $batches, array $districtNames): array
    {
        $order = fn (int $batchId) => [$batches[$batchId]['to'] ?? '', $batches[$batchId]['from'] ?? '', $batchId];
        $routes = [];

        foreach ($rows->where('cca_only', false)->groupBy(fn (array $r) => $r['route_id'] !== null ? 'r'.$r['route_id'] : 'l'.mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $r['label'])))) as $key => $group) {
            $group = $group->values();
            $latest = $group->pluck('b')->unique()->sortByDesc(fn ($b) => $order((int) $b), SORT_REGULAR)->first();
            $inLatest = $group->where('b', $latest)->values();

            // the owner of the route in its latest upload (the flag was written at import; recomputed when an older batch lacks it)
            $owner = $inLatest->firstWhere('owner', true) ?? $inLatest->get(ReadingRouteOwner::ownerIndex($inLatest->map(fn (array $r) => ['cca' => $r['cca'], 'strength' => $r['strength'], 'visited' => $r['visited']])->all()) ?? 0);
            $read = (int) $group->sum('read');
            $skipped = (int) $group->sum('skipped');
            $visited = (int) $group->sum('visited');
            $strength = (int) $owner['strength'];
            $districtId = $group->pluck('district_id')->filter()->first();

            // routes with more than one row in ONE upload (owner + assigned, no visits)
            $multi = $group->groupBy('b')->filter(fn (Collection $perBatch) => $perBatch->count() > 1);
            $latestMulti = $multi->isEmpty() ? null : $multi->sortByDesc(fn ($rows, $b) => $order((int) $b), SORT_REGULAR)->first();

            $routes[(string) $key] = [
                'key' => (string) $key, 'route_id' => $group->first()['route_id'], 'label' => $owner['label'], 'placed' => $group->first()['route_id'] !== null && $districtId !== null,
                'district_id' => $districtId, 'district' => $districtId !== null ? ($districtNames[$districtId] ?? 'District '.$districtId) : null,
                'strength' => $strength, 'read' => $read, 'skipped' => $skipped, 'visited' => $visited,
                'unvisited' => $strength - $visited, 'coverage' => ReadingAnalyticsService::rate($visited, $strength), 'skip_rate' => ReadingAnalyticsService::rate($skipped, $visited),
                'owner_cca' => $owner['cca'], 'owner_name' => $owner['name'], 'latest_batch' => (int) $latest,
                'ccas' => $group->pluck('cca')->unique()->values()->all(),
                'multi_batches' => $multi->count(),
                'multi_rows' => $latestMulti === null ? [] : $latestMulti->map(fn (array $r) => ['cca' => $r['cca'], 'name' => $r['name'], 'strength' => $r['strength'], 'visited' => $r['visited'], 'owner' => (bool) ($r['cca'] === $owner['cca'] && $r['b'] === (int) $latest) || (bool) $r['owner']])->values()->all(),
            ];
        }

        return $routes;
    }

    /**
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<string, array<string, mixed>>  $ccas
     * @return array{strength: int, read: int, skipped: int, visited: int, unvisited: int, coverage: float|null, skip_rate: float|null}
     */
    protected function totals(array $routes, array $ccas): array
    {
        $strength = (int) array_sum(array_column($routes, 'strength'));
        $visited = (int) array_sum(array_column($routes, 'visited'));
        $skipped = (int) array_sum(array_column($routes, 'skipped'));

        return [
            'strength' => $strength, 'read' => (int) array_sum(array_column($routes, 'read')), 'skipped' => $skipped, 'visited' => $visited,
            'unvisited' => $strength - $visited, 'coverage' => ReadingAnalyticsService::rate($visited, $strength), 'skip_rate' => ReadingAnalyticsService::rate($skipped, $visited),
        ];
    }

    // ---------------------------------------------------------------- CCAs (R1)

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<int, string>  $districtNames
     * @return array<string, array<string, mixed>> keyed by staff id
     */
    protected function ccas(Collection $rows, array $routes, array $districtNames, int $minVisits): array
    {
        $ownedBy = [];

        foreach ($routes as $key => $route) {
            $ownedBy[$route['owner_cca']][] = $key;
        }

        $ccas = [];

        foreach ($rows->groupBy('cca') as $staffId => $group) {
            $staffId = (string) $staffId;
            $own = $group->where('cca_only', false);
            $ownedKeys = $ownedBy[$staffId] ?? [];
            $ownedStrength = (int) array_sum(array_map(fn ($k) => $routes[$k]['strength'], $ownedKeys));
            $visitedOwned = (int) $own->filter(fn (array $r) => in_array($r['route_id'] !== null ? 'r'.$r['route_id'] : 'l'.mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $r['label']))), $ownedKeys, true))->sum('visited');
            $visited = (int) $own->sum('visited');
            $skipped = (int) $own->sum('skipped');

            // the CCA's district: where it owns most strength, else where its rows are, else (no routes) the home district of its row
            $byDistrict = [];

            foreach ($ownedKeys as $k) {
                if ($routes[$k]['district_id'] !== null) {
                    $byDistrict[$routes[$k]['district_id']] = ($byDistrict[$routes[$k]['district_id']] ?? 0) + $routes[$k]['strength'];
                }
            }

            if ($byDistrict === []) {
                foreach ($group->pluck('district_id')->filter() as $d) {
                    $byDistrict[$d] = ($byDistrict[$d] ?? 0) + 1;
                }
            }

            arsort($byDistrict);
            $districtId = $byDistrict === [] ? null : (int) array_key_first($byDistrict);

            $ccas[$staffId] = [
                'staff_id' => $staffId, 'name' => $group->pluck('name')->filter()->first(), 'employee_id' => $group->pluck('employee_id')->filter()->first(),
                'district_id' => $districtId, 'district' => $districtId !== null ? ($districtNames[$districtId] ?? 'District '.$districtId) : null,
                'route_rows' => $own->count(), 'owned_routes' => count($ownedKeys), 'owned_strength' => $ownedStrength,
                'read' => (int) $own->sum('read'), 'skipped' => $skipped, 'visited' => $visited, 'visited_owned' => $visitedOwned,
                'unvisited' => $ownedStrength - $visitedOwned, 'coverage' => ReadingAnalyticsService::rate($visitedOwned, $ownedStrength), 'skip_rate' => ReadingAnalyticsService::rate($skipped, $visited),
                'no_routes' => $own->isEmpty(), 'zero_visits' => $own->isNotEmpty() && $visited === 0,
                'eligible' => $visited >= $minVisits && $visited > 0, 'rank' => null,
            ];
        }

        // rank on skip rate (lowest = 1) only among the CCAs with enough visits
        $eligible = array_filter($ccas, fn (array $c) => $c['eligible'] && $c['skip_rate'] !== null);
        uasort($eligible, fn (array $a, array $b) => [$a['skip_rate'], -$a['visited'], ReadingRouteOwner::staffOrder($a['staff_id'], $b['staff_id'])] <=> [$b['skip_rate'], -$b['visited'], 0]);
        $rank = 0;

        foreach (array_keys($eligible) as $staffId) {
            $ccas[$staffId]['rank'] = ++$rank;
        }

        uasort($ccas, fn (array $a, array $b) => [$a['rank'] ?? PHP_INT_MAX, ReadingRouteOwner::staffOrder($a['staff_id'], $b['staff_id'])] <=> [$b['rank'] ?? PHP_INT_MAX, 0]);

        return $ccas;
    }

    // ---------------------------------------------------------------- districts (R3)

    /**
     * R3: a district's figures are the SUM OF ITS ROUTES (replacing the home-district proxy of the by-month report).
     *
     * @param  array<string, array<string, mixed>>  $routes
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $districtNames
     * @return list<array<string, mixed>>
     */
    protected function districts(array $routes, Collection $rows, array $districtNames): array
    {
        $out = [];

        foreach (collect($routes)->filter(fn (array $r) => $r['placed'])->groupBy('district_id') as $districtId => $group) {
            $strength = (int) $group->sum('strength');
            $read = (int) $group->sum('read');
            $skipped = (int) $group->sum('skipped');
            $visited = (int) $group->sum('visited');
            $routeIds = $group->pluck('route_id')->all();
            $active = $rows->where('cca_only', false)->filter(fn (array $r) => $r['visited'] > 0 && in_array($r['route_id'], $routeIds, true))->pluck('cca')->unique()->count();

            $out[] = [
                'id' => (int) $districtId, 'name' => $districtNames[(int) $districtId] ?? 'District '.$districtId, 'routes' => $group->count(),
                'ccas' => $group->pluck('owner_cca')->unique()->count(), 'active_ccas' => $active,
                'strength' => $strength, 'read' => $read, 'skipped' => $skipped, 'visited' => $visited, 'unvisited' => $strength - $visited,
                'coverage' => ReadingAnalyticsService::rate($visited, $strength), 'skip_rate' => ReadingAnalyticsService::rate($skipped, $visited),
            ];
        }

        usort($out, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    // ---------------------------------------------------------------- workload (R4)

    /**
     * R4: routes and verified strength per CCA that owns routes, the spread (largest, median, a coefficient of variation: the population
     * standard deviation over the mean) and the CCAs far above or below the median strength (the existing workload percentages).
     *
     * @param  array<string, array<string, mixed>>  $ccas
     * @return array<string, mixed>
     */
    protected function workload(array $ccas): array
    {
        $with = array_filter($ccas, fn (array $c) => $c['owned_routes'] > 0);
        $strengths = array_values(array_map(fn (array $c) => $c['owned_strength'], $with));
        $routeCounts = array_values(array_map(fn (array $c) => $c['owned_routes'], $with));
        $median = $this->median($strengths);
        $mean = $strengths === [] ? null : array_sum($strengths) / count($strengths);
        $stdev = $mean === null ? null : sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $strengths)) / count($strengths));
        $low = (int) config('gwl.commercial_workload_low_pct', 50);
        $high = (int) config('gwl.commercial_workload_high_pct', 150);
        $largest = null;

        foreach ($with as $c) {
            if ($largest === null || $c['owned_strength'] > $largest['owned_strength']) {
                $largest = $c;
            }
        }

        $rows = [];

        foreach ($with as $c) {
            $pct = $median ? round($c['owned_strength'] / $median * 100, 1) : null;
            $rows[] = $c + ['pct_of_median' => $pct, 'band' => $pct === null ? 'none' : ($pct > $high ? 'high' : ($pct < $low ? 'low' : 'normal'))];
        }

        usort($rows, fn (array $a, array $b) => [-$a['owned_strength'], ReadingRouteOwner::staffOrder($a['staff_id'], $b['staff_id'])] <=> [-$b['owned_strength'], 0]);

        return [
            'ccas' => count($with), 'routes' => array_sum($routeCounts), 'strength' => array_sum($strengths),
            'largest' => $largest ? $largest['owned_strength'] : null, 'largest_cca' => $largest ? $largest['staff_id'] : null,
            'median' => $median, 'median_routes' => $this->median($routeCounts), 'mean' => $mean === null ? null : round($mean, 1),
            'cv' => $mean ? round($stdev / $mean * 100, 1) : null, 'low_pct' => $low, 'high_pct' => $high,
            'rows' => $rows,
            'high' => array_values(array_filter($rows, fn (array $r) => $r['band'] === 'high')),
            'low' => array_values(array_filter($rows, fn (array $r) => $r['band'] === 'low')),
        ];
    }

    // ---------------------------------------------------------------- the unvisited backlog (R5)

    /**
     * R5: the routes and the CCAs with the most unvisited customers (only a positive backlog is listed: over a summed range revisits can
     * take it below zero), and the CCAs with routes but ZERO visits.
     *
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<string, array<string, mixed>>  $ccas
     * @return array<string, mixed>
     */
    protected function backlog(array $routes, array $ccas): array
    {
        $byRoute = array_values(array_filter($routes, fn (array $r) => $r['unvisited'] > 0));
        usort($byRoute, fn (array $a, array $b) => [-$a['unvisited'], strcmp((string) $a['label'], (string) $b['label'])] <=> [-$b['unvisited'], 0]);

        $byCca = array_values(array_filter($ccas, fn (array $c) => $c['owned_routes'] > 0 && $c['unvisited'] > 0));
        usort($byCca, fn (array $a, array $b) => [-$a['unvisited'], ReadingRouteOwner::staffOrder($a['staff_id'], $b['staff_id'])] <=> [-$b['unvisited'], 0]);

        return [
            'unvisited_total' => (int) array_sum(array_map(fn (array $r) => max(0, $r['unvisited']), $routes)),
            'routes' => $byRoute,
            'ccas' => $byCca,
            'routes_zero_visits' => array_values(array_filter($routes, fn (array $r) => $r['visited'] === 0)),
            'ccas_zero_visits' => array_values(array_filter($ccas, fn (array $c) => $c['zero_visits'])),
        ];
    }

    // ---------------------------------------------------------------- routes with no owner or several CCAs (R6)

    /**
     * R6 (the part that comes from the uploads): routes with more than one CCA row in one upload (the owner and the assigned-no-visits
     * rows) and CCAs with no routes. Routes known from elsewhere with no reading row are added by the caller (they need the route master).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<string, array<string, mixed>>  $ccas
     * @return array{multi_cca_routes: list<array<string, mixed>>, ccas_no_routes: list<array<string, mixed>>, unplaced_rows: int}
     */
    protected function roleFindings(Collection $rows, array $routes, array $ccas): array
    {
        $multi = array_values(array_filter($routes, fn (array $r) => $r['multi_batches'] > 0));
        usort($multi, fn (array $a, array $b) => strcmp((string) $a['label'], (string) $b['label']));

        return [
            'multi_cca_routes' => $multi,
            'ccas_no_routes' => array_values(array_filter($ccas, fn (array $c) => $c['no_routes'])),
            'unplaced_rows' => $rows->where('cca_only', false)->filter(fn (array $r) => $r['route_id'] === null || $r['district_id'] === null)->count(),
        ];
    }

    // ---------------------------------------------------------------- outliers against district peers (R7)

    /**
     * R7: a CCA whose skip rate is more than commercial_skip_outlier_sigma standard deviations above the mean of the ELIGIBLE CCAs of the
     * SAME district (the population standard deviation; the CCA itself is one of the peers, as in the by-month analysis). A district needs at
     * least MIN_PEERS eligible CCAs, otherwise it is listed as "too few".
     *
     * @param  array<string, array<string, mixed>>  $ccas
     * @param  array<int, string>  $districtNames
     * @return array<string, mixed>
     */
    protected function outliers(array $ccas, array $districtNames, int $minVisits): array
    {
        $sigma = (float) config('gwl.commercial_skip_outlier_sigma', 2.0);
        $districts = [];
        $flagged = [];
        $eligibleTotal = 0;

        $eligible = array_filter($ccas, fn (array $c) => $c['eligible'] && $c['skip_rate'] !== null && $c['district_id'] !== null);

        foreach (collect($eligible)->groupBy('district_id') as $districtId => $group) {
            $rates = $group->pluck('skip_rate')->map(fn ($v) => (float) $v)->all();
            $count = count($rates);
            $eligibleTotal += $count;
            $mean = array_sum($rates) / $count;
            $stdev = sqrt(array_sum(array_map(fn ($r) => ($r - $mean) ** 2, $rates)) / $count);
            $enough = $count >= self::MIN_PEERS;
            $entry = ['id' => (int) $districtId, 'name' => $districtNames[(int) $districtId] ?? 'District '.$districtId, 'eligible' => $count, 'enough' => $enough, 'mean' => round($mean, 2), 'stdev' => round($stdev, 2), 'threshold' => $enough && $stdev > 0 ? round($mean + $sigma * $stdev, 2) : null, 'flagged' => 0];

            if ($enough && $stdev > 0) {
                foreach ($group as $c) {
                    $z = ($c['skip_rate'] - $mean) / $stdev;

                    if ($z > $sigma) {
                        $flagged[] = $c + ['z' => round($z, 2), 'district_mean' => $entry['mean'], 'district_threshold' => $entry['threshold']];
                        $entry['flagged']++;
                    }
                }
            }

            $districts[] = $entry;
        }

        usort($districts, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        usort($flagged, fn (array $a, array $b) => [-$a['z'], ReadingRouteOwner::staffOrder($a['staff_id'], $b['staff_id'])] <=> [-$b['z'], 0]);

        $without = count(array_filter($ccas, fn (array $c) => $c['district_id'] === null && $c['eligible']));

        return ['sigma' => $sigma, 'min_visits' => $minVisits, 'min_peers' => self::MIN_PEERS, 'districts' => $districts, 'flagged' => $flagged, 'eligible' => $eligibleTotal, 'eligible_without_district' => $without];
    }

    // ---------------------------------------------------------------- strength against metered accounts (R8)

    /**
     * R8: each route's verified strength against the metered accounts (working or faulty meter) of the newest customer list on the same
     * canonical route. A route code printed under more than one district is AMBIGUOUS (the join cannot tell the districts apart): those
     * routes are listed apart and left out of the agreement figures.
     *
     * @param  array<string, array<string, mixed>>  $routes  from analyse()
     * @param  array<int, int>  $metered  canonical route id => metered accounts
     * @param  array<int, true>  $ambiguous  route ids whose code is printed under more than one district
     * @return array<string, mixed>
     */
    public function strengthAgainstCustomers(array $routes, array $metered, array $ambiguous, ?float $thresholdPct = null): array
    {
        $threshold = $thresholdPct ?? (float) config('gwl.commercial_strength_mismatch_pct', 15);
        $compared = [];
        $ambiguousRows = [];
        $noMatch = 0;

        foreach ($routes as $route) {
            if (! $route['placed'] || $route['route_id'] === null) {
                continue;
            }

            $count = $metered[$route['route_id']] ?? null;
            $row = ['route_id' => $route['route_id'], 'label' => $route['label'], 'district' => $route['district'], 'district_id' => $route['district_id'], 'strength' => $route['strength'], 'metered' => $count];

            if (isset($ambiguous[$route['route_id']])) {
                $ambiguousRows[] = $row;

                continue;
            }

            if ($count === null || $count <= 0) {
                $noMatch++;

                continue;
            }

            $compared[] = $row + ['difference' => $route['strength'] - $count, 'relative_pct' => round(($route['strength'] - $count) / $count * 100, 1)];
        }

        $abs = array_map(fn (array $r) => abs($r['relative_pct']), $compared);
        $signed = array_map(fn (array $r) => $r['relative_pct'], $compared);
        $beyond = array_values(array_filter($compared, fn (array $r) => abs($r['relative_pct']) > $threshold));
        usort($beyond, fn (array $a, array $b) => [-abs($a['relative_pct']), strcmp((string) $a['label'], (string) $b['label'])] <=> [-abs($b['relative_pct']), 0]);
        usort($ambiguousRows, fn (array $a, array $b) => strcmp((string) $a['label'], (string) $b['label']));

        return [
            'threshold' => $threshold, 'compared' => count($compared), 'median_abs_pct' => $this->median($abs) === null ? null : round($this->median($abs), 1),
            'median_signed_pct' => $this->median($signed) === null ? null : round($this->median($signed), 1),
            'beyond' => $beyond, 'ambiguous' => $ambiguousRows, 'no_match' => $noMatch,
        ];
    }

    /** @param  list<int|float>  $values */
    public function median(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
