<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Models\Employee;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads "Customer Meter Reading Report - CCA Summary - By Route" (rptReadingSumm): a Document map sheet (the list of CCAs as
 * "staffid - NAME") and ONE SHEET PER CCA. A CCA sheet has a merged filter cell (REPORT PERIOD: any dates the uploader chose, REGION),
 * a row with the CCA label, a header row (Route, Verified Strength, Read, Skipped, Visited, Unvisited), one row per route labelled
 * "DISTRICT code" and a "<CCA label> TOTALS :" row. Columns are found from the header text, never by letter.
 *
 * What is kept and what is not (design 8.36):
 *   * the source's percentages and its Unvisited column are never stored: unvisited is strength - visited, rates are recomputed;
 *   * verified strength is PER ROUTE, and a route can appear under two CCAs in one period, so for each route the OWNER row (the most
 *     visits; ReadingRouteOwner) carries the strength once and the other rows are kept as "assigned, no visits";
 *   * a CCA with no route rows, or with routes but no visits, is kept: it is a finding;
 *   * the excluded account (Settings) has its sheet skipped, shown in the preview and never stored.
 *
 * Checks, in the existing pipeline (preview, confirm, batch page, void, audit): per row read + skipped = visited and
 * strength - visited = the source's own unvisited; per sheet the TOTALS row against the sum of its rows; the Phase 8a tolerance (a row
 * or sheet that fails is FLAGGED and the file still imports; it is blocked only above the two Settings). Sheets are read one at a time,
 * so memory does not depend on the number of CCAs.
 */
class ReadingRouteImportService extends ReportImportService
{
    /** A row may be this many customers off its own relations before it is flagged (the same slack as the by-month report). */
    public const TOLERANCE = ReadingSummaryImportService::VISITED_TOLERANCE;

    public function __construct(
        ReportFileReader $reader,
        LocationMatcher $locations,
        BatchLifecycleService $lifecycle,
        protected RouteResolver $routes,
    ) {
        parent::__construct($reader, $locations, $lifecycle);
    }

    // ---------------------------------------------------------------- preview

    /**
     * @param  int|null  $restrictToRegionId  when set, a report for any other region is blocked
     * @param  list<int>|null  $districtIds  a district user keeps only their own district's rows (the rest are skipped, counted, never stored)
     */
    public function preview(UploadedFile $file, ?int $restrictToRegionId = null, ?array $districtIds = null): array
    {
        $errors = [];
        $warnings = [];
        $sheets = [];            // parsed CCA sheets (compact: no grids are kept)
        $excluded = [];
        $periodTexts = [];
        $regionTexts = [];
        $problems = [];

        foreach ($this->reader->sheetNames($file) as $name) {
            if (preg_match('/^\s*document\s*map\s*$/i', $name)) {
                continue;
            }

            $parsed = $this->parseSheet($this->reader->readSheetGrid($file, $name), $name);   // the grid goes out of scope here

            if ($parsed['problem'] !== null) {
                $problems[$parsed['problem']][] = $name;

                continue;
            }

            if ($parsed['cca'] === null) {
                continue;   // a sheet that is not a CCA sheet (no "staffid - NAME" label): nothing to import
            }

            $periodTexts[$parsed['period_text'] ?? ''] = true;
            $regionTexts[$parsed['region_text'] ?? ''] = true;

            if (self::isExcludedAccount($parsed['cca']['staff_id'], $parsed['cca']['name'])) {
                $excluded[$parsed['cca']['staff_id']] = ['staff_id' => $parsed['cca']['staff_id'], 'rows' => count($parsed['rows']), 'read' => array_sum(array_column($parsed['rows'], 'read')), 'skipped' => array_sum(array_column($parsed['rows'], 'skipped')), 'visited' => array_sum(array_column($parsed['rows'], 'visited'))];

                continue;
            }

            $sheets[] = $parsed;
        }

        foreach ($problems as $message => $names) {
            $errors[] = ['row' => 'Layout', 'message' => $message.' ('.count($names).' sheet'.(count($names) === 1 ? '' : 's').': '.implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ', ...' : '').')'];
        }

        if ($sheets === [] && $problems === [] && $excluded === []) {
            $errors[] = ['row' => 'File', 'message' => 'No CCA sheets with route rows were found, so there is nothing to import.'];
        }

        // --- each CCA exactly once ---
        $seen = [];

        foreach ($sheets as $sheet) {
            if (isset($seen[$sheet['cca']['staff_id']])) {
                $errors[] = ['row' => 'Sheet '.$sheet['sheet'], 'message' => "CCA {$sheet['cca']['staff_id']} appears on more than one sheet ('{$seen[$sheet['cca']['staff_id']]}' and '{$sheet['sheet']}')."];
            }

            $seen[$sheet['cca']['staff_id']] ??= $sheet['sheet'];
        }

        // --- the Document map's list of CCAs against the sheets (a warning only: the map is never required) ---
        $mapped = $this->documentMap($file);

        if ($mapped !== null) {
            $sheetIds = array_merge(array_keys($seen), array_keys($excluded));
            $missingSheet = array_values(array_diff(array_keys($mapped), $sheetIds));
            $notMapped = array_values(array_diff($sheetIds, array_keys($mapped)));

            if ($missingSheet !== []) {
                $warnings[] = ['row' => 'Document map', 'message' => count($missingSheet).' CCA(s) are listed on the Document map but have no sheet in the workbook.'];
            }

            if ($notMapped !== []) {
                $warnings[] = ['row' => 'Document map', 'message' => count($notMapped).' CCA sheet(s) are not listed on the Document map.'];
            }
        }

        // --- period and region: the same on every sheet ---
        $periodTexts = array_values(array_filter(array_keys($periodTexts), fn ($t) => $t !== ''));
        $regionTexts = array_values(array_filter(array_keys($regionTexts), fn ($t) => $t !== ''));
        $period = null;
        $periodRaw = $periodTexts[0] ?? null;

        if (count($periodTexts) > 1) {
            $errors[] = ['row' => 'Filters', 'message' => 'The CCA sheets do not all carry the same REPORT PERIOD ('.count($periodTexts).' different ones), so the file is not one report.'];
        } elseif ($periodRaw === null) {
            if ($sheets !== []) {
                $errors[] = ['row' => 'Filters', 'message' => 'The REPORT PERIOD line was not found on the CCA sheets, so the batch cannot be given dates.'];
            }
        } elseif (! ($period = $this->readPeriod($periodRaw))) {
            $errors[] = ['row' => 'Filters', 'message' => "The REPORT PERIOD \"{$periodRaw}\" could not be read as two dates."];
        }

        $regionLabel = $regionTexts[0] ?? null;
        $region = null;
        $unresolvedRegion = null;

        if (count($regionTexts) > 1) {
            $errors[] = ['row' => 'Filters', 'message' => 'The CCA sheets do not all name the same REGION.'];
        } elseif ($sheets !== [] || $excluded !== []) {
            if ($regionLabel === null) {
                $errors[] = ['row' => 'Filters', 'message' => 'The REGION line was not found in the report, so the batch cannot be tied to a region.'];
            } else {
                $region = $this->locations->resolveRegion($regionLabel);

                if (! $region) {
                    $unresolvedRegion = $regionLabel;
                    $errors[] = ['row' => 'Filters', 'message' => "Region '{$regionLabel}' does not match any region. Pick the right one below to remember it for next time."];
                }
            }
        }

        if ($region && $restrictToRegionId !== null && $region->id !== $restrictToRegionId) {
            $errors[] = ['row' => 'Filters', 'message' => "This report is for {$region->region_name}; you can only upload reports for your own region."];
        }

        // --- the rows: routes resolved (WITHOUT creating anything: a preview writes nothing), staff matched ---
        $employees = Employee::query()->whereIn('staff_id', array_map('strval', array_keys($seen)))->get()->keyBy(fn (Employee $e) => (string) $e->staff_id);
        $districtCache = [];
        $rows = [];
        $ccas = [];

        foreach ($sheets as $sheet) {
            $staffId = $sheet['cca']['staff_id'];
            $employee = $employees[$staffId] ?? null;
            $ccas[$staffId] = [
                'staff_id' => $staffId, 'name' => $sheet['cca']['name'], 'employee_id' => $employee?->id, 'home_district_id' => $employee?->district_id,
                'match_status' => $employee ? CommercialReadingRouteStat::MATCH_MATCHED : CommercialReadingRouteStat::MATCH_UNMATCHED,
                'sheet' => $sheet['sheet'], 'rows' => count($sheet['rows']), 'visited' => 0, 'totals_checked' => false, 'totals_ok' => null, 'totals' => $sheet['totals'],
            ];

            foreach ($sheet['rows'] as $row) {
                $placed = $this->place($row['label'], $region?->id, $districtCache);
                $flag = $this->flagOf($row);

                $rows[] = [
                    'cca_staff_id' => $staffId, 'cca_name_raw' => $sheet['cca']['name'], 'employee_id' => $employee?->id, 'match_status' => $ccas[$staffId]['match_status'],
                    'route_label_raw' => $row['label'], 'route_code' => $placed['code'], 'district_label_raw' => $placed['district_label'], 'district_id' => $placed['district_id'],
                    'verified_strength' => $row['strength'], 'read_count' => $row['read'], 'skipped_count' => $row['skipped'], 'visited_count' => $row['visited'],
                    'is_flagged' => $flag !== null, 'flag_reason' => $flag,
                ];
                $ccas[$staffId]['visited'] += $row['visited'];
            }
        }

        // --- per CCA sheet: the TOTALS row against the sum of its rows ---
        $failedSheets = [];
        $withTotals = 0;
        $sumRows = ['strength' => 0, 'read' => 0, 'skipped' => 0, 'visited' => 0];
        $sumTotals = ['strength' => 0, 'read' => 0, 'skipped' => 0, 'visited' => 0];

        foreach ($sheets as $sheet) {
            $staffId = $sheet['cca']['staff_id'];
            $totals = $sheet['totals'];

            if ($totals === null) {
                if ($sheet['rows'] !== []) {
                    $warnings[] = ['row' => 'CCA '.$staffId, 'message' => 'No TOTALS row was found on this sheet, so its '.count($sheet['rows']).' route row(s) could not be checked against it.'];
                }

                continue;
            }

            $withTotals++;
            $ccas[$staffId]['totals_checked'] = true;
            $diffs = [];

            foreach (['strength', 'read', 'skipped', 'visited'] as $measure) {
                if ($totals[$measure] === null) {
                    continue;
                }

                $computed = array_sum(array_column($sheet['rows'], $measure));
                $sumRows[$measure] += $computed;
                $sumTotals[$measure] += $totals[$measure];

                if ($computed !== $totals[$measure]) {
                    $diffs[] = $measure.' '.number_format($computed).' on the rows, '.number_format($totals[$measure]).' on the TOTALS row';
                }
            }

            $ccas[$staffId]['totals_ok'] = $diffs === [];

            if ($diffs !== []) {
                $failedSheets[] = ['staff_id' => $staffId, 'sheet' => $sheet['sheet'], 'detail' => implode('; ', $diffs)];
            }
        }

        $flaggedRows = array_values(array_filter($rows, fn (array $r) => $r['is_flagged']));
        $rowFailurePct = $rows === [] ? 0.0 : count($flaggedRows) / count($rows) * 100;
        $sheetFailurePct = $withTotals === 0 ? 0.0 : count($failedSheets) / $withTotals * 100;
        $limit = $this->routeFailureLimitPct();

        if ($flaggedRows !== [] && $rowFailurePct > $limit) {
            $errors[] = ['row' => 'Reconciliation', 'message' => number_format(count($flaggedRows)).' of '.number_format(count($rows)).' route rows ('.number_format($rowFailurePct, 1).'%) do not satisfy read + skipped = visited and strength - visited = unvisited, which is more than the '.rtrim(rtrim(number_format($limit, 2), '0'), '.').'% a file may have.'];
        }

        if ($failedSheets !== [] && $sheetFailurePct > $limit) {
            $errors[] = ['row' => 'Reconciliation', 'message' => number_format(count($failedSheets)).' of '.number_format($withTotals).' CCA sheets ('.number_format($sheetFailurePct, 1).'%) disagree with their own TOTALS row, which is more than the '.rtrim(rtrim(number_format($limit, 2), '0'), '.').'% a file may have.'];
        }

        foreach (['read', 'skipped', 'visited', 'strength'] as $measure) {
            if ($withTotals > 0 && $this->beyondGrandTolerance((float) $sumRows[$measure], (float) $sumTotals[$measure])) {
                $errors[] = ['row' => 'Reconciliation', 'message' => ucfirst($measure).': the route rows add up to '.number_format($sumRows[$measure]).' but the CCA sheets\' TOTALS rows add up to '.number_format($sumTotals[$measure]).', more than the '.rtrim(rtrim(number_format((float) config('gwl.commercial_reconcile_grand_total_tolerance_pct', 0.5), 2), '0'), '.').'% the grand total may differ by.'];
            }
        }

        foreach (array_slice($flaggedRows, 0, 12) as $row) {
            $warnings[] = ['row' => 'CCA '.$row['cca_staff_id'], 'message' => 'Flagged: route '.$row['route_label_raw'].' '.($row['flag_reason'] === CommercialReadingRouteStat::FLAG_VISITED ? 'visited is not read + skipped' : 'unvisited is not strength - visited').' (the row is kept).'];
        }

        foreach (array_slice($failedSheets, 0, 12) as $failed) {
            $warnings[] = ['row' => 'CCA '.$failed['staff_id'], 'message' => 'Flagged: the sheet\'s TOTALS row disagrees with its rows ('.$failed['detail'].'); the sheet is kept.'];
        }

        // --- places that could not be resolved, and the staff directory ---
        $unplaced = [];

        foreach ($rows as $row) {
            if ($row['district_id'] === null) {
                $unplaced[$row['district_label_raw'] ?? '(no district in the label)'] = ($unplaced[$row['district_label_raw'] ?? '(no district in the label)'] ?? 0) + 1;
            }
        }

        foreach ($unplaced as $label => $count) {
            $warnings[] = ['row' => 'District', 'message' => "District \"{$label}\" is not recognised in ".($region?->region_name ?? 'this region').": {$count} route row(s) are imported without a route. Resolve the district on the batch page and they are placed."];
        }

        foreach ($ccas as $staffId => $cca) {
            if ($cca['match_status'] === CommercialReadingRouteStat::MATCH_UNMATCHED) {
                $warnings[] = ['row' => 'CCA '.$staffId, 'message' => "{$staffId} - {$cca['name']} is not in the staff directory. The batch can still be imported; link the CCA on the batch screen."];
            }
        }

        // --- routes under more than one CCA: the owner, the assigned-no-visits rows ---
        $byRoute = [];

        foreach ($rows as $index => $row) {
            $byRoute[$this->routeKey($row)][] = $index;
        }

        $ownerDecisions = [];
        $assigned = 0;

        foreach ($byRoute as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $candidates = array_map(fn (int $i) => ['cca' => $rows[$i]['cca_staff_id'], 'strength' => (int) $rows[$i]['verified_strength'], 'visited' => (int) $rows[$i]['visited_count']], $indexes);
            $owner = $indexes[ReadingRouteOwner::ownerIndex($candidates)];
            $assigned += count($indexes) - 1;
            $ownerDecisions[] = [
                'route' => $rows[$owner]['route_label_raw'], 'owner' => $rows[$owner]['cca_staff_id'], 'owner_name' => $rows[$owner]['cca_name_raw'],
                'others' => array_values(array_map(fn (int $i) => $rows[$i]['cca_staff_id'], array_filter($indexes, fn (int $i) => $i !== $owner))),
            ];
        }

        if ($ownerDecisions !== []) {
            $warnings[] = ['row' => 'Routes', 'message' => count($ownerDecisions).(count($ownerDecisions) === 1 ? ' route appears' : ' routes appear').' under more than one CCA; the CCA with the visits is the owner and the route\'s verified strength counts once. The other row'.($assigned === 1 ? '' : 's').' ('.$assigned.') are kept as "assigned, no visits".'];
        }

        $routeCounts = [];

        foreach ($rows as $row) {
            $routeCounts[$row['cca_staff_id']] = ($routeCounts[$row['cca_staff_id']] ?? 0) + 1;
        }

        $noRoutes = array_values(array_map(fn (array $c) => ['staff_id' => $c['staff_id'], 'name' => $c['name']], array_filter($ccas, fn (array $c) => ! isset($routeCounts[$c['staff_id']]))));
        $noVisits = array_values(array_map(fn (array $c) => ['staff_id' => $c['staff_id'], 'name' => $c['name'], 'routes' => $routeCounts[$c['staff_id']]], array_filter($ccas, fn (array $c) => isset($routeCounts[$c['staff_id']]) && $c['visited'] === 0)));

        if ($noRoutes !== []) {
            $warnings[] = ['row' => 'CCAs', 'message' => count($noRoutes).' CCA(s) have no route rows at all. They are kept (a finding, not an error).'];
        }

        if ($noVisits !== []) {
            $warnings[] = ['row' => 'CCAs', 'message' => count($noVisits).' CCA(s) have routes but no visits in the period. They are kept (a finding, not an error).'];
        }

        // --- a district user keeps only their own district's rows; the rest are skipped, counted here, never stored ---
        $skipped = ['rows' => 0, 'ccas' => 0];

        if ($districtIds !== null) {
            $keep = fn (array $r) => in_array((int) ($r['district_id'] ?? 0), $districtIds, true);
            $skipped['rows'] = count(array_filter($rows, fn (array $r) => ! $keep($r)));
            $rows = array_values(array_filter($rows, $keep));
            $keptCcas = array_flip(array_column($rows, 'cca_staff_id'));
            $before = count($ccas);
            // a CCA with no route rows is the district user's own only when their home district is
            $ccas = array_filter($ccas, fn (array $c) => isset($keptCcas[$c['staff_id']]) || (! isset($routeCounts[$c['staff_id']]) && in_array((int) ($c['home_district_id'] ?? 0), $districtIds, true)));
            $skipped['ccas'] = $before - count($ccas);

            if ($rows === [] && $sheets !== []) {
                $errors[] = ['row' => 'File', 'message' => 'None of the routes in this file belong to your district, so nothing can be imported. Rows of other districts are skipped.'];
            }
        }

        // --- existing data: the period rules (CommercialPeriods) ---
        if ($region && $period) {
            $others = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY)->notVoided()->where('region_id', $region->id)->get()->keyBy('id');
            $effect = (new CommercialPeriods)->effectOfUpload($period['from'], $period['to'], $others->map(fn (CommercialImportBatch $b) => ['id' => $b->id, 'from' => $b->period_from->toDateString(), 'to' => $b->period_to->toDateString(), 'order' => $b->id])->values()->all());

            foreach ($effect['replaces'] as $id) {
                $warnings[] = ['row' => 'Existing data', 'message' => "Batch #{$id} already covers this region and exactly this period. This newer file replaces it in every view; the earlier batch stays on record."];
            }

            foreach ($effect['shadows'] as $id) {
                $warnings[] = ['row' => 'Existing data', 'message' => "This period contains batch #{$id} (".CommercialPeriods::describe($others[$id]->period_from, $others[$id]->period_to).'): this newer file replaces it in every view. That batch stays on record.'];
            }

            foreach ($effect['overlaps'] as $id) {
                $warnings[] = ['row' => 'Existing data', 'message' => "This period overlaps batch #{$id} (".CommercialPeriods::describe($others[$id]->period_from, $others[$id]->period_to).') only in part. Both are kept; a view that includes both uses the newest upload first, so some days may then be left to the older one.'];
            }
        }

        $checks = [
            ['label' => 'Report period read', 'passed' => $period !== null, 'detail' => $period ? CommercialPeriods::describe($period['from'], $period['to']).' ('.CommercialPeriods::label($period['from'], $period['to']).')' : ($periodRaw ?? 'not found')],
            ['label' => 'Region recognised', 'passed' => $region !== null, 'detail' => $region?->region_name ?? ($regionLabel ?? 'not found')],
            ['label' => 'Read + skipped = visited and strength - visited = unvisited on every route row', 'passed' => $flaggedRows === [], 'detail' => $flaggedRows === [] ? count($rows).' rows checked' : count($flaggedRows).' row(s) flagged'],
            ['label' => 'Each CCA sheet adds up to its own TOTALS row', 'passed' => $failedSheets === [], 'detail' => $withTotals.' sheet(s) checked'.($failedSheets === [] ? '' : ', '.count($failedSheets).' flagged')],
            ['label' => 'Each CCA appears on one sheet only', 'passed' => count($seen) === count($sheets), 'detail' => count($sheets).' CCA sheets'],
        ];

        $districts = collect($rows)->pluck('district_id')->filter()->unique()->count();
        $routesCount = count(array_unique(array_map(fn (array $r) => $this->routeKey($r), $rows)));
        $matchedCount = count(array_filter($ccas, fn (array $c) => $c['match_status'] === CommercialReadingRouteStat::MATCH_MATCHED));
        $reconciliationPassed = $errors === [] && $flaggedRows === [] && $failedSheets === [];
        $attributes = [
            'report_type' => CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY,
            'region_id' => $region?->id,
            'region_label_raw' => $regionLabel,
            'period_from' => $period['from'] ?? null,
            'period_to' => $period['to'] ?? null,
            'granularity' => $period && CommercialPeriods::label($period['from'], $period['to']) === CommercialPeriods::MONTH ? CommercialImportBatch::GRANULARITY_MONTHLY : CommercialImportBatch::GRANULARITY_MULTI_MONTH,
            'billing_status_raw' => null,
            'customer_segment' => null,
            'source_filename' => $file->getClientOriginalName(),
            'file_hash' => $this->reader->fileHash($file),
            'row_count' => count($rows),
            'matched_count' => $matchedCount,
            'warning_count' => count($warnings),
            'reconciliation_passed' => $reconciliationPassed,
            'control_totals' => [
                'ccas' => array_values(array_map(fn (array $c) => ['staff_id' => $c['staff_id'], 'rows' => $c['rows'], 'totals_ok' => $c['totals_ok']], $ccas)),
                'sheets_failed' => array_map(fn (array $f) => ['staff_id' => $f['staff_id'], 'detail' => $f['detail']], array_slice($failedSheets, 0, 100)),
                'flagged_rows' => count($flaggedRows),
                'row_totals' => $sumRows,
                'sheet_totals' => $sumTotals,
                'owner_decisions' => count($ownerDecisions),
                'assigned_no_visits' => $assigned,
                'no_routes' => count($noRoutes),
                'no_visits' => count($noVisits),
                'excluded' => array_values($excluded),
                'checks' => $checks,
                'warnings' => array_map(fn (array $warning) => $warning['message'], array_slice($warnings, 0, 100)),
            ],
        ];

        $duplicate = $this->duplicateOf($attributes['file_hash']);

        if ($duplicate) {
            $errors[] = ['row' => 'File', 'message' => $this->duplicateMessage($duplicate)];
        }

        return $this->finish([
            'report_type' => CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY,
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
            'total_rows' => count($rows),
            'valid_count' => count($rows),
            'matched_count' => $matchedCount,
            'unmatched_count' => count($ccas) - $matchedCount,
            'system_count' => (int) array_sum(array_column($excluded, 'rows')),
            'excluded' => array_values($excluded),
            'skipped_districts' => $skipped,
            'region' => ['raw' => $regionLabel, 'id' => $region?->id, 'name' => $region?->region_name],
            'unresolved_region' => $unresolvedRegion,
            'period' => ['from' => $attributes['period_from'], 'to' => $attributes['period_to']],
            'months' => [],
            'will_replace' => null,
            'preview_rows' => array_slice($rows, 0, 8),
            // what the screen says about THIS report (the generic preview has no sheets, routes or owners)
            'route_report' => [
                'period_label' => $period ? CommercialPeriods::describe($period['from'], $period['to']) : null,
                'period_kind' => $period ? CommercialPeriods::label($period['from'], $period['to']) : null,
                'sheets' => count($sheets), 'routes' => $routesCount, 'districts' => $districts, 'ccas' => count($ccas),
                'owner_decisions' => array_slice($ownerDecisions, 0, 20), 'owner_decision_count' => count($ownerDecisions), 'assigned_no_visits' => $assigned,
                'no_routes' => array_slice($noRoutes, 0, 30), 'no_routes_count' => count($noRoutes),
                'no_visits' => array_slice($noVisits, 0, 30), 'no_visits_count' => count($noVisits),
                'flagged_rows' => count($flaggedRows), 'flagged_sheets' => count($failedSheets), 'unplaced_rows' => array_sum($unplaced),
            ],
            'parsed' => ['attributes' => $attributes, 'rows' => $rows, 'ccas' => array_values($ccas), 'district_filter' => $districtIds],
        ]);
    }

    // ---------------------------------------------------------------- create

    /**
     * Turns a clean preview into a batch: the routes are resolved to their CANONICAL route (created pending when unseen), each route's
     * OWNER row is marked (strength counts once) and the rows are written. A CCA with no routes gets one row with no route.
     */
    public function createBatch(array $batchAttributes, array $parsed, ?string $importFilePath = null, ?int $actorId = null): CommercialImportBatch
    {
        if (empty($parsed['attributes']) || (empty($parsed['rows']) && empty($parsed['ccas']))) {
            throw new CommercialImportException('There is nothing to import.');
        }

        $attributes = $parsed['attributes'];

        try {
            $batch = DB::transaction(function () use ($batchAttributes, $parsed, $attributes, $importFilePath, $actorId): CommercialImportBatch {
                if ($duplicate = $this->duplicateOf($attributes['file_hash'])) {
                    throw new CommercialImportException($this->duplicateMessage($duplicate));
                }

                $batch = CommercialImportBatch::query()->create([
                    ...collect($attributes)->except(['control_totals'])->all(),
                    'control_totals' => $attributes['control_totals'],
                    'status' => CommercialImportBatch::STATUS_IMPORTED,
                    'file_path' => $importFilePath,
                    'imported_by' => $actorId ?? auth()->id(),
                    'imported_at' => now(),
                    'notes' => $batchAttributes['notes'] ?? null,
                ]);

                $rows = $this->resolveRoutes($parsed['rows'] ?? [], $batch->region_id, $parsed['district_filter'] ?? null);
                $now = now();
                $records = [];

                foreach ($rows as $row) {
                    $records[] = $this->record($batch->id, $row, $now);
                }

                // a CCA listed with no route rows at all is kept: one row, no route
                $withRows = array_flip(array_column($rows, 'cca_staff_id'));

                foreach ($parsed['ccas'] ?? [] as $cca) {
                    if (isset($withRows[$cca['staff_id']])) {
                        continue;
                    }

                    if (($parsed['district_filter'] ?? null) !== null && ! in_array((int) ($cca['home_district_id'] ?? 0), $parsed['district_filter'], true)) {
                        continue;
                    }

                    $records[] = $this->record($batch->id, [
                        'cca_staff_id' => $cca['staff_id'], 'cca_name_raw' => $cca['name'], 'employee_id' => $cca['employee_id'], 'match_status' => $cca['match_status'],
                        'route_id' => null, 'district_id' => $cca['home_district_id'], 'route_label_raw' => null, 'district_label_raw' => null,
                        'verified_strength' => 0, 'read_count' => 0, 'skipped_count' => 0, 'visited_count' => 0, 'is_route_owner' => false, 'is_flagged' => false, 'flag_reason' => null,
                    ], $now);
                }

                foreach (array_chunk($records, 400) as $chunk) {
                    DB::table('commercial_reading_route_stats')->insert($chunk);
                }

                $this->markOwners($batch);

                $changes = $this->lifecycle->refreshStatuses($batch->region_id, $batch->report_type, $batch->id);

                if ($changes['superseded'] !== []) {
                    $batch->update(['supersedes_batch_id' => max($changes['superseded'])]);
                }

                $batch->update(['row_count' => $batch->routeStats()->whereNotNull('route_label_raw')->count(), 'matched_count' => $this->matchedCcas($batch)]);

                // Audit: ids and counts only; a CCA's name or staff id never goes into an audit row
                Audit::log(
                    action: 'commercial.batch_imported',
                    module: Permission::MODULE_COMMERCIAL,
                    targetType: CommercialImportBatch::class,
                    targetId: $batch->id,
                    metadata: [
                        'report_type' => $batch->report_type,
                        'region_id' => $batch->region_id,
                        'source_filename' => $batch->source_filename,
                        'period_from' => $batch->period_from->toDateString(),
                        'period_to' => $batch->period_to->toDateString(),
                        'rows' => $batch->row_count,
                        'ccas' => $batch->routeStats()->distinct('cca_staff_id')->count('cca_staff_id'),
                        'routes' => $batch->routeStats()->whereNotNull('route_id')->distinct('route_id')->count('route_id'),
                        'flagged_rows' => (int) ($attributes['control_totals']['flagged_rows'] ?? 0),
                        'owner_decisions' => (int) ($attributes['control_totals']['owner_decisions'] ?? 0),
                        'excluded_rows' => (int) array_sum(array_column($attributes['control_totals']['excluded'] ?? [], 'rows')),
                        'warnings' => $batch->warning_count,
                        'reconciliation_passed' => $batch->reconciliation_passed,
                    ]
                );

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CommercialImportException('This exact file has already been uploaded.');
        }

        return $batch;
    }

    /**
     * Re-resolves what could not be resolved at import: CCAs the staff directory did not know and routes whose district nobody could place
     * (after an alias was added), then marks the owners again. Returns how many CCAs and routes matched this time.
     */
    public function rematchBatch(CommercialImportBatch $batch): int
    {
        $matched = 0;

        $unmatched = $batch->routeStats()->where('match_status', CommercialReadingRouteStat::MATCH_UNMATCHED)->get();

        if ($unmatched->isNotEmpty()) {
            $employees = Employee::query()->whereIn('staff_id', $unmatched->pluck('cca_staff_id')->unique()->map('strval')->all())->get()->keyBy(fn (Employee $e) => (string) $e->staff_id);
            $ccas = [];

            foreach ($unmatched as $stat) {
                if ($employee = $employees[$stat->cca_staff_id] ?? null) {
                    $stat->update(['employee_id' => $employee->id, 'match_status' => CommercialReadingRouteStat::MATCH_MATCHED] + ($stat->route_label_raw === null ? ['district_id' => $employee->district_id] : []));
                    $ccas[$stat->cca_staff_id] = true;
                }
            }

            $matched += count($ccas);
        }

        $unplaced = $batch->routeStats()->whereNull('route_id')->whereNotNull('route_label_raw')->get();

        if ($unplaced->isNotEmpty()) {
            $districtCache = [];
            $routes = 0;

            foreach ($unplaced as $stat) {
                $placed = $this->place((string) $stat->route_label_raw, $batch->region_id, $districtCache);

                if ($placed['district_id'] === null || $placed['code'] === null) {
                    continue;
                }

                $route = $this->routes->resolveIn(District::query()->findOrFail($placed['district_id']), $placed['code'], CommercialRoute::SOURCE_READING);

                // the unique key (batch, route, CCA) holds: a second row of the same CCA on the same canonical route is added into the first
                $twin = $batch->routeStats()->where('route_id', $route->id)->where('cca_staff_id', $stat->cca_staff_id)->first();

                if ($twin) {
                    $twin->update(['verified_strength' => $twin->verified_strength + $stat->verified_strength, 'read_count' => $twin->read_count + $stat->read_count, 'skipped_count' => $twin->skipped_count + $stat->skipped_count, 'visited_count' => $twin->visited_count + $stat->visited_count]);
                    $stat->delete();
                } else {
                    $stat->update(['route_id' => $route->id, 'district_id' => $route->district_id, 'district_label_raw' => $placed['district_label']]);
                }

                $routes++;
            }

            $matched += $routes;
        }

        $this->markOwners($batch);
        $batch->update(['row_count' => $batch->routeStats()->whereNotNull('route_label_raw')->count(), 'matched_count' => $this->matchedCcas($batch)]);

        return $matched;
    }

    /** Keeps the batch's matched_count (CCAs found in the staff directory) in step after CCAs are matched or linked. */
    public function refreshBatchCounts(CommercialImportBatch $batch): void
    {
        $batch->update(['matched_count' => $this->matchedCcas($batch)]);
    }

    protected function matchedCcas(CommercialImportBatch $batch): int
    {
        return (int) $batch->routeStats()->where('match_status', CommercialReadingRouteStat::MATCH_MATCHED)->distinct()->count('cca_staff_id');
    }

    // ---------------------------------------------------------------- owners

    /**
     * Marks, for every route of the batch, the OWNER row (ReadingRouteOwner): the one that carries the route's verified strength. A route
     * whose district could not be placed is grouped by what was printed. Run at import and again after a re-match.
     */
    public function markOwners(CommercialImportBatch $batch): void
    {
        $stats = $batch->routeStats()->whereNotNull('route_label_raw')->get(['id', 'route_id', 'route_label_raw', 'cca_staff_id', 'verified_strength', 'visited_count', 'is_route_owner']);
        $owners = [];

        foreach ($stats->groupBy(fn ($s) => $s->route_id !== null ? 'r'.$s->route_id : 'l'.mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $s->route_label_raw)))) as $group) {
            $group = $group->values();
            $candidates = $group->map(fn ($s) => ['cca' => (string) $s->cca_staff_id, 'strength' => (int) $s->verified_strength, 'visited' => (int) $s->visited_count])->all();
            $owners[] = $group[ReadingRouteOwner::ownerIndex($candidates)]->id;
        }

        $owned = array_flip($owners);

        foreach ($stats as $stat) {
            $should = isset($owned[$stat->id]);

            if ($stat->is_route_owner !== $should) {
                CommercialReadingRouteStat::query()->whereKey($stat->id)->update(['is_route_owner' => $should]);
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * What a printed route label stands for, WITHOUT creating anything: its code and printed district, the resolved district id (the
     * existing canonical route's district when there is one, else the district the label names) or null when nobody can place it.
     *
     * @param  array<string, District|null>  $districtCache
     * @return array{code: string|null, district_label: string|null, district_id: int|null}
     */
    protected function place(string $label, ?int $regionId, array &$districtCache): array
    {
        return $this->routes->place($label, $regionId, $districtCache);
    }

    /**
     * Resolves every distinct route of the rows to its CANONICAL route (creating the unseen ones, pending), sets route_id and the route's
     * district, merges two rows of one CCA that land on the same canonical route (the unique key), and re-applies a district user's filter
     * now that the canonical route is known.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>|null  $districtFilter
     * @return list<array<string, mixed>>
     */
    protected function resolveRoutes(array $rows, ?int $regionId, ?array $districtFilter): array
    {
        $cache = [];
        $out = [];
        $byKey = [];

        foreach ($rows as $row) {
            $row['route_id'] = null;

            if ($row['district_id'] !== null && $row['route_code'] !== null) {
                $cache[$row['district_id'].'|'.$row['route_code']] ??= $this->routes->resolveIn(District::query()->findOrFail($row['district_id']), (string) $row['route_code'], CommercialRoute::SOURCE_READING);
                $route = $cache[$row['district_id'].'|'.$row['route_code']];
                $row['route_id'] = (int) $route->id;
                $row['district_id'] = (int) $route->district_id;
            }

            if ($districtFilter !== null && ! in_array((int) ($row['district_id'] ?? 0), $districtFilter, true)) {
                continue;   // a district user's file keeps only their own district, also after the canonical route is known
            }

            $row['is_route_owner'] = false;

            if ($row['route_id'] !== null) {
                $key = $row['route_id'].'|'.$row['cca_staff_id'];

                if (isset($byKey[$key])) {
                    // one CCA, two printed routes, one canonical route (a code printed under two districts): added, flagged in the counts
                    $first = &$out[$byKey[$key]];
                    foreach (['verified_strength', 'read_count', 'skipped_count', 'visited_count'] as $field) {
                        $first[$field] += $row[$field];
                    }
                    $first['is_flagged'] = $first['is_flagged'] || $row['is_flagged'];
                    unset($first);

                    continue;
                }

                $byKey[$key] = count($out);
            }

            $out[] = $row;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $row @return array<string, mixed> */
    protected function record(int $batchId, array $row, \DateTimeInterface $now): array
    {
        return [
            'batch_id' => $batchId, 'route_id' => $row['route_id'] ?? null, 'district_id' => $row['district_id'] ?? null,
            'cca_staff_id' => $row['cca_staff_id'], 'cca_name_raw' => $row['cca_name_raw'] !== null ? mb_substr((string) $row['cca_name_raw'], 0, 191) : null,
            'employee_id' => $row['employee_id'] ?? null, 'route_label_raw' => isset($row['route_label_raw']) ? mb_substr((string) $row['route_label_raw'], 0, 120) : null,
            'district_label_raw' => isset($row['district_label_raw']) ? mb_substr((string) $row['district_label_raw'], 0, 120) : null,
            'verified_strength' => (int) $row['verified_strength'], 'read_count' => (int) $row['read_count'], 'skipped_count' => (int) $row['skipped_count'], 'visited_count' => (int) $row['visited_count'],
            'is_route_owner' => (bool) ($row['is_route_owner'] ?? false), 'is_flagged' => (bool) ($row['is_flagged'] ?? false), 'flag_reason' => $row['flag_reason'] ?? null,
            'match_status' => $row['match_status'] ?? CommercialReadingRouteStat::MATCH_UNMATCHED, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /** A row's own relations: visited = read + skipped, and strength - visited = the source's unvisited (when it prints one). */
    protected function flagOf(array $row): ?string
    {
        if (abs($row['visited'] - ($row['read'] + $row['skipped'])) > self::TOLERANCE) {
            return CommercialReadingRouteStat::FLAG_VISITED;
        }

        if ($row['unvisited'] !== null && abs(($row['strength'] - $row['visited']) - $row['unvisited']) > self::TOLERANCE) {
            return CommercialReadingRouteStat::FLAG_UNVISITED;
        }

        return null;
    }

    /** The key two rows must share to be the same route in the preview: the resolved district and code, else what was printed. */
    protected function routeKey(array $row): string
    {
        return $row['district_id'] !== null && $row['route_code'] !== null ? $row['district_id'].'|'.$row['route_code'] : 'label|'.mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $row['route_label_raw'])));
    }

    /**
     * "01-October-2026 - 31-October-2026" (any two dates, inclusive) as ['from' => Y-m-d, 'to' => Y-m-d]; null when it cannot be read.
     *
     * @return array{from: string, to: string}|null
     */
    protected function readPeriod(string $text): ?array
    {
        $parts = preg_split('/\s+(?:-|–|—|to)\s+/iu', trim($text));

        if (! is_array($parts) || count($parts) !== 2) {
            return null;
        }

        try {
            $from = Carbon::parse(trim($parts[0]))->startOfDay();
            $to = Carbon::parse(trim($parts[1]))->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $to->lessThan($from) ? null : ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * The CCAs the Document map lists, by staff id; null when the map cannot be read (never an error: the map is only a cross-check).
     * Only the first columns and rows of that sheet are read, so its declared used range cannot exhaust memory.
     *
     * @return array<string, string>|null
     */
    protected function documentMap(UploadedFile $file): ?array
    {
        try {
            foreach ($this->reader->sheetNames($file) as $name) {
                if (! preg_match('/^\s*document\s*map\s*$/i', $name)) {
                    continue;
                }

                $found = [];

                foreach ($this->reader->readSheetGrid($file, $name, 600, ['A', 'B', 'C', 'D', 'E', 'F']) as $row) {
                    foreach ($row as $cell) {
                        if (preg_match('/^\s*(\d{3,})\s*-\s*(?=.*[A-Za-z])(.+?)\s*$/u', $this->reader->text($cell), $m)) {
                            $found[$m[1]] = trim($m[2]);
                        }
                    }
                }

                return $found === [] ? null : $found;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    // ---------------------------------------------------------------- one CCA sheet

    /**
     * One sheet grid into a compact array (the grid is not kept): the CCA label, the filter lines, the route rows and the TOTALS row.
     * Everything is found from the text: the header row is the one with a "Route" cell and a "Strength" cell, a group header ("Read"
     * over "#" and "%") is resolved to its "#" column, and a percentage column is never read.
     *
     * @param  array<int, array<int, mixed>>  $grid
     * @return array{sheet: string, cca: array{staff_id: string, name: string}|null, period_text: string|null, region_text: string|null, rows: list<array<string, mixed>>, totals: array<string, int|null>|null, problem: string|null}
     */
    protected function parseSheet(array $grid, string $sheet): array
    {
        $out = ['sheet' => $sheet, 'cca' => null, 'period_text' => null, 'region_text' => null, 'rows' => [], 'totals' => null, 'problem' => null];

        $out['period_text'] = $this->reader->labelValue($grid, '/^\s*report\s*period\b\s*:?\s*(.*)$/i', 25);
        $out['region_text'] = $this->reader->labelValue($grid, '/^\s*region\b\s*:?\s*(.*)$/i', 25);

        foreach (array_slice($grid, 0, 25) as $row) {
            foreach ($row as $cell) {
                if (preg_match('/^\s*(\d{3,})\s*-\s*(?=.*[A-Za-z])(.+?)\s*$/u', $this->reader->text($cell), $m)) {
                    $out['cca'] = ['staff_id' => $m[1], 'name' => trim($m[2])];

                    break 2;
                }
            }
        }

        $header = $this->headerRow($grid);

        if ($header === null) {
            // a CCA sheet with a label but no header row is a CCA with nothing to read (kept); without a label it is not a CCA sheet
            if ($out['cca'] !== null && $this->hasHeaderWords($grid)) {
                $out['problem'] = 'Could not find the Route and Verified Strength column headers';
            }

            return $out;
        }

        [$headerIndex, $columns] = $header;
        $missing = array_keys(array_filter(['route' => $columns['route'], 'strength' => $columns['strength'], 'read' => $columns['read'], 'skipped' => $columns['skipped'], 'visited' => $columns['visited']], fn ($c) => $c === null));

        if ($missing !== []) {
            $names = ['route' => 'Route', 'strength' => 'Verified Strength', 'read' => 'Read #', 'skipped' => 'Skipped #', 'visited' => 'Visited #'];
            $out['problem'] = 'Could not find the '.implode(', ', array_map(fn ($k) => $names[$k], $missing)).' column header'.(count($missing) === 1 ? '' : 's');

            return $out;
        }

        $count = fn (array $row, string $measure): ?int => $columns[$measure] === null ? null : $this->reader->integer($row[$columns[$measure]] ?? null);

        for ($i = $headerIndex + 1; $i < count($grid); $i++) {
            $row = $grid[$i];
            $first = $this->reader->firstFilledIndex($row);

            if ($first === null) {
                continue;
            }

            $text = $this->reader->text($row[$columns['route']] ?? '') ?: $this->reader->text($row[$first]);

            if (preg_match('/\bTOTALS?\s*:?\s*$/i', $text)) {
                $out['totals'] = ['strength' => $count($row, 'strength'), 'read' => $count($row, 'read'), 'skipped' => $count($row, 'skipped'), 'visited' => $count($row, 'visited'), 'unvisited' => $count($row, 'unvisited')];

                continue;
            }

            // a sub-header row ("#", "%") under the header is not data
            if (in_array(strtolower($text), ['#', '%', 'no', 'no.', 'count'], true) || $this->classify($text) !== null) {
                continue;
            }

            $values = ['strength' => $count($row, 'strength'), 'read' => $count($row, 'read'), 'skipped' => $count($row, 'skipped'), 'visited' => $count($row, 'visited'), 'unvisited' => $count($row, 'unvisited')];

            // a row with a route label and nothing in any count column is a placeholder, not data
            if ($text === '' || ($values['strength'] === null && $values['read'] === null && $values['skipped'] === null && $values['visited'] === null)) {
                continue;
            }

            $out['rows'][] = [
                'label' => mb_substr($text, 0, 120), 'strength' => $values['strength'] ?? 0, 'read' => $values['read'] ?? 0, 'skipped' => $values['skipped'] ?? 0,
                'visited' => $values['visited'] ?? 0, 'unvisited' => $values['unvisited'],
            ];
        }

        return $out;
    }

    /** True when the sheet has words of a header row at all (used to tell "no header" from "not a CCA sheet"). */
    protected function hasHeaderWords(array $grid): bool
    {
        foreach (array_slice($grid, 0, 40) as $row) {
            foreach ($row as $cell) {
                if (str_contains(strtolower($this->reader->text($cell)), 'strength')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The header row (the first with a "Route" cell and a "Strength" cell) and the column index of each measure. A measure header with a
     * "#" (Read #) is that column; a bare group header ("Read" over "#" and "%") is resolved to the "#" cell of the row(s) below, else to
     * its own column. A percentage column is never mapped.
     *
     * @return array{0: int, 1: array<string, int|null>}|null
     */
    protected function headerRow(array $grid): ?array
    {
        foreach (array_slice($grid, 0, 40, true) as $index => $row) {
            $route = null;
            $strength = null;

            foreach ($row as $column => $cell) {
                $text = $this->reader->text($cell);

                if ($route === null && preg_replace('/[^a-z]/', '', strtolower($text)) === 'route') {
                    $route = $column;
                }

                if ($strength === null && str_contains(strtolower($text), 'strength') && ! str_contains($text, '%')) {
                    $strength = $column;
                }
            }

            if ($route === null || $strength === null) {
                continue;
            }

            $explicit = [];
            $bare = [];

            // the group headers (Read, Skipped ...) may sit on the header row or the row above it, the "#" cells on the row(s) below
            foreach ([$index - 1, $index, $index + 1, $index + 2] as $i) {
                foreach ($grid[$i] ?? [] as $column => $cell) {
                    $classified = $this->classify($this->reader->text($cell));

                    if (! $classified) {
                        continue;
                    }

                    [$key, $kind] = $classified;

                    if ($kind === 'count') {
                        $explicit[$key] ??= $column;
                    } elseif ($kind === 'bare' && $i <= $index && ! isset($bare[$key])) {
                        $bare[$key] = [$column, $i];
                    }
                }
            }

            $columns = ['route' => $route, 'strength' => $strength];

            foreach (['read', 'skipped', 'visited', 'unvisited'] as $key) {
                if (isset($explicit[$key])) {
                    $columns[$key] = $explicit[$key];
                } elseif (isset($bare[$key])) {
                    $columns[$key] = $this->subHeader($grid, $bare[$key][1], $bare[$key][0]) ?? $bare[$key][0];
                } else {
                    $columns[$key] = null;
                }
            }

            return [$index + 1 < count($grid) && $this->isSubHeaderRow($grid[$index + 1] ?? []) ? $index + 1 : $index, $columns];
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null [measure, count|pct|bare] */
    protected function classify(string $text): ?array
    {
        $normalized = strtolower((string) preg_replace('/[^a-z#%]/i', '', $text));

        if ($normalized === '' || ! preg_match('/^(unvisited|visited|skipped|read)(#|no|count|%|pct|percent)?$/', $normalized, $m)) {
            return null;
        }

        return [$m[1], match ($m[2] ?? '') {
            '#', 'no', 'count' => 'count',
            '%', 'pct', 'percent' => 'pct',
            default => 'bare',
        }];
    }

    /** The "#" cell under (or beside) a merged group header. */
    protected function subHeader(array $grid, int $headerRow, int $column): ?int
    {
        for ($i = $headerRow + 1; $i <= $headerRow + 2; $i++) {
            foreach ([$column, $column + 1] as $candidate) {
                if (in_array(strtolower($this->reader->text($grid[$i][$candidate] ?? null)), ['#', 'no', 'no.', 'count', 'number'], true)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** A row made only of "#" and "%" cells under the group headers. */
    protected function isSubHeaderRow(array $row): bool
    {
        $filled = array_values(array_filter(array_map(fn ($c) => strtolower($this->reader->text($c)), $row), fn ($t) => $t !== ''));

        return $filled !== [] && count(array_diff($filled, ['#', '%', 'no', 'no.', 'count', 'number'])) === 0;
    }
}
