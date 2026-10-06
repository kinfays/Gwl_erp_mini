<?php

namespace App\Services\Commercial;

use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads "Billing Summary Report By Routes - Routes By District" (rptBillingSumm_ExP): one sheet, one block per district
 * (a header row, a "Route" column-header row, one row per route, a "<DISTRICT> TOTALS:" row), then REPORT TOTALS and a
 * domestic (category 611) consumption-band table.
 *
 * Columns are found by their header text, never by position. The report is full of identities (receivable = opening +
 * billing + adjustment, routes add up to their district, districts add up to REPORT TOTALS...): a file that breaks any of
 * them has been truncated, filtered or edited, and is blocked. The source's Collection Ratio is not stored.
 */
class BillingSummaryImportService extends ReportImportService
{
    /** Money identities hold to the pesewa; this absorbs rounding in the source. */
    public const TOLERANCE = 0.02;

    /** Columns every billing report must have. The others are kept when present. */
    protected const REQUIRED_FIELDS = [
        'volume_total',
        'opening_balance', 'billing_for_period', 'total_receivable', 'revenue_adjustment',
        'payment_for_month', 'prev_month_payment', 'offset_payments', 'total_payments', 'closing_balance',
        'billed_average_metered', 'billed_average_unmetered', 'billed_actual_reading', 'billed_total',
        'unbilled_suspense_metered', 'unbilled_suspense_unmetered', 'unbilled_disconn_metered', 'unbilled_disconn_unmetered',
        'unbilled_other', 'unbilled_total',
    ];

    protected const FIELD_LABELS = [
        'volume_actual' => 'Volume (Actual)',
        'volume_average' => 'Volume (Average)',
        'volume_total' => 'Volume (Total)',
        'opening_balance' => 'Opening Balance',
        'billing_for_period' => 'Billing For Period',
        'total_receivable' => 'Total Receivable',
        'revenue_adjustment' => 'Revenue Adjustment',
        'payment_for_month' => 'Payment For Month',
        'prev_month_payment' => 'Prev Month Payment',
        'offset_payments' => 'Offset Payments',
        'total_payments' => 'Total Payments',
        'closing_balance' => 'Closing Balance',
        'customers_count' => 'Number Of Customers',
        'billed_average_metered' => 'Billed (Average Metered)',
        'billed_average_unmetered' => 'Billed (Average Unmetered)',
        'billed_actual_reading' => 'Billed (Actual Reading)',
        'billed_total' => 'Total Billed',
        'unbilled_suspense_metered' => 'Unbilled (Suspense Metered)',
        'unbilled_suspense_unmetered' => 'Unbilled (Suspense Unmetered)',
        'unbilled_disconn_metered' => 'Unbilled (Disconnected Metered)',
        'unbilled_disconn_unmetered' => 'Unbilled (Disconnected Unmetered)',
        'unbilled_other' => 'Unbilled (Other Status)',
        'unbilled_total' => 'Total Unbilled',
    ];

    /**
     * @param  list<array{name: string, rows: array<int, array<int, mixed>>}>|null  $sheets  already-read sheets, to avoid reading twice
     * @param  int|null  $restrictToRegionId  when set, a file for any other region is blocked (regional users)
     */
    public function preview(UploadedFile $file, ?int $restrictToRegionId = null, ?array $sheets = null): array
    {
        $sheets ??= $this->reader->readSheets($file);

        $errors = [];
        $warnings = [];

        $sheet = $this->findReportSheet($sheets);

        if (! $sheet) {
            $errors[] = ['row' => 'File', 'message' => 'No district blocks (a VOLUMES header row) were found, so this does not look like a billing summary by route.'];

            return $this->emptyPreview($file, $errors);
        }

        $rows = $sheet['rows'];

        // --- Filter block. ---
        $periodLabel = $this->reader->labelValue($rows, '/^\s*bill(?:ing)?\s+period\b\s*:?\s*(.*)$/i');
        $regionLabel = $this->reader->labelValue($rows, '/^\s*region\b\s*:?\s*(.*)$/i');
        $statusLabel = $this->reader->labelValue($rows, '/^\s*billing\s+status\b\s*:?\s*(.*)$/i');

        $periodMonths = $periodLabel ? $this->reader->monthsIn($periodLabel) : [];
        $periodFrom = $periodMonths === [] ? null : $periodMonths[0]->copy()->startOfMonth();
        $periodTo = $periodMonths === [] ? null : end($periodMonths)->copy()->endOfMonth();
        $multiMonth = $periodFrom && $periodTo && $periodFrom->format('Y-m') !== $periodTo->format('Y-m');

        if (! $periodFrom) {
            $errors[] = ['row' => 'Filters', 'message' => 'The BILL PERIOD line was not found or could not be read, so the batch cannot be tied to a period.'];
        } elseif ($multiMonth) {
            $warnings[] = ['row' => 'Filters', 'message' => 'This report spans '.$periodFrom->format('M Y').' to '.$periodTo->format('M Y').'. It can feed period analyses (districts, bands, estimation) but not a monthly trend; export billing one month at a time for that.'];
        }

        $region = null;
        $unresolvedRegion = null;

        if ($regionLabel === null) {
            $errors[] = ['row' => 'Filters', 'message' => 'The REGION line was not found in the report, so the batch cannot be tied to a region.'];
        } else {
            $region = $this->locations->resolveRegion($regionLabel);

            if (! $region) {
                $unresolvedRegion = $regionLabel;
                $errors[] = ['row' => 'Filters', 'message' => "Region '{$regionLabel}' does not match any region. Pick the right one below to remember it for next time."];
            }
        }

        if ($region && $restrictToRegionId !== null && $region->id !== $restrictToRegionId) {
            $errors[] = ['row' => 'Filters', 'message' => "This report is for {$region->region_name}; you can only upload reports for your own region."];
        }

        [$segment, $segmentWarning] = $this->segmentFor($statusLabel);

        if ($segmentWarning) {
            $warnings[] = ['row' => 'Filters', 'message' => $segmentWarning];
        }

        // --- District blocks. ---
        $walk = $this->walk($rows);
        $errors = [...$errors, ...$walk['errors']];
        $warnings = [...$warnings, ...$walk['warnings']];
        $blocks = $walk['blocks'];
        $reportTotals = $walk['report_totals'];
        $bands = $walk['bands'];

        // --- Match districts. ---
        $routeRows = [];
        $unmatchedDistricts = [];

        foreach ($blocks as $block) {
            $district = $region ? $this->locations->resolveDistrict($block['name'], $region->id) : null;

            if (! $district && $block['routes'] !== []) {
                $unmatchedDistricts[$block['name']] = count($block['routes']);
            }

            foreach ($block['routes'] as $route) {
                $routeRows[] = [
                    'district_id' => $district?->id,
                    'district_label_raw' => $block['name'],
                    'route_code' => $route['code'],
                    ...$route['values'],
                ];
            }
        }

        foreach ($unmatchedDistricts as $name => $count) {
            $warnings[] = ['row' => 'District '.$name, 'message' => "District '{$name}' ({$count} routes) does not match any district".($region ? " in {$region->region_name}" : '').'. The batch can still be imported; resolve it on the batch screen.'];
        }

        // --- Reconciliation. ---
        $reconciliation = $this->reconcile($blocks, $reportTotals);
        $errors = [...$errors, ...$reconciliation['errors']];
        $checks = $reconciliation['checks'];

        if ($periodFrom) {
            $checks[] = ['label' => 'Bill period read', 'passed' => true, 'detail' => $periodFrom->format('M Y').($multiMonth ? ' to '.$periodTo->format('M Y') : '')];
        }

        $checks[] = ['label' => 'Region recognised', 'passed' => $region !== null, 'detail' => $region?->region_name ?? ($regionLabel ?? 'not found')];

        // --- What this file replaces. ---
        $replaces = null;

        if ($region && $periodFrom) {
            $replaces = CommercialImportBatch::query()
                ->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)
                ->notVoided()
                ->where('region_id', $region->id)
                ->where('customer_segment', $segment)
                ->whereDate('period_from', $periodFrom->toDateString())
                ->whereDate('period_to', $periodTo->toDateString())
                ->latest('id')
                ->first();

            if ($replaces) {
                $warnings[] = ['row' => 'Existing data', 'message' => "Batch #{$replaces->id} already covers this region, segment and period ({$replaces->routes()->count()} routes). This file will replace it; the earlier batch stays on record."];
            }
        }

        if ($routeRows === [] && $walk['errors'] === []) {
            $errors[] = ['row' => 'File', 'message' => 'No route rows were found, so there is nothing to import.'];
        }

        $matchedRoutes = collect($routeRows)->whereNotNull('district_id')->count();

        $attributes = [
            'report_type' => CommercialImportBatch::TYPE_BILLING_SUMMARY,
            'region_id' => $region?->id,
            'region_label_raw' => $regionLabel,
            'period_from' => $periodFrom?->toDateString(),
            'period_to' => $periodTo?->toDateString(),
            'granularity' => $multiMonth ? CommercialImportBatch::GRANULARITY_MULTI_MONTH : CommercialImportBatch::GRANULARITY_MONTHLY,
            'billing_status_raw' => $statusLabel,
            'customer_segment' => $segment,
            'source_filename' => $file->getClientOriginalName(),
            'file_hash' => $this->reader->fileHash($file),
            'row_count' => count($routeRows),
            'matched_count' => $matchedRoutes,
            'warning_count' => count($warnings),
            'reconciliation_passed' => $errors === [],
            'control_totals' => [
                'report_totals' => $reportTotals,
                'district_totals' => collect($blocks)->mapWithKeys(fn (array $block) => [$block['name'] => $block['totals']])->all(),
                'checks' => $checks,
                'warnings' => array_map(fn (array $warning) => $warning['message'], array_slice($warnings, 0, 100)),
            ],
        ];

        if ($duplicate = $this->duplicateOf($attributes['file_hash'])) {
            $errors[] = ['row' => 'File', 'message' => $this->duplicateMessage($duplicate)];
            $attributes['reconciliation_passed'] = false;
        }

        return $this->finish([
            'report_type' => CommercialImportBatch::TYPE_BILLING_SUMMARY,
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
            'total_rows' => count($routeRows),
            'valid_count' => count($routeRows),
            'matched_count' => $matchedRoutes,
            'unmatched_count' => count($unmatchedDistricts),
            'system_count' => 0,
            'district_count' => count($blocks),
            'region' => ['raw' => $regionLabel, 'id' => $region?->id, 'name' => $region?->region_name],
            'unresolved_region' => $unresolvedRegion,
            'period' => ['from' => $attributes['period_from'], 'to' => $attributes['period_to']],
            'months' => $periodFrom ? [$periodFrom->toDateString()] : [],
            'segment' => $segment,
            'will_replace' => $replaces?->id,
            'preview_rows' => array_slice($routeRows, 0, 8),
            'parsed' => ['attributes' => $attributes, 'routes' => $routeRows, 'bands' => $bands],
        ]);
    }

    /**
     * Turns a clean preview into a batch with its routes and consumption bands. Routes whose district could not be
     * matched are still written: resolving the district is the point of the batch screen.
     */
    public function createBatch(array $batchAttributes, array $parsed, ?string $importFilePath = null, ?int $actorId = null): CommercialImportBatch
    {
        if (empty($parsed['attributes']) || empty($parsed['routes'])) {
            throw new CommercialImportException('There is nothing to import.');
        }

        $attributes = $parsed['attributes'];

        try {
            return DB::transaction(function () use ($batchAttributes, $parsed, $attributes, $importFilePath, $actorId): CommercialImportBatch {
                if ($duplicate = $this->duplicateOf($attributes['file_hash'])) {
                    throw new CommercialImportException($this->duplicateMessage($duplicate));
                }

                $batch = CommercialImportBatch::query()->create([
                    ...$attributes,
                    'status' => CommercialImportBatch::STATUS_IMPORTED,
                    'file_path' => $importFilePath,
                    'imported_by' => $actorId ?? auth()->id(),
                    'imported_at' => now(),
                    'notes' => $batchAttributes['notes'] ?? null,
                ]);

                foreach ($parsed['routes'] as $route) {
                    $batch->routes()->create($route);
                }

                foreach ($parsed['bands'] as $band) {
                    $batch->bands()->create($band);
                }

                $changes = $this->lifecycle->refreshStatuses($batch->region_id, $batch->report_type, $batch->id);

                if ($changes['superseded'] !== []) {
                    $batch->update(['supersedes_batch_id' => max($changes['superseded'])]);
                }

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
                        'customer_segment' => $batch->customer_segment,
                        'rows' => $batch->row_count,
                        'matched' => $batch->routes()->whereNotNull('district_id')->count(),
                        'unmatched' => $batch->routes()->whereNull('district_id')->count(),
                        'warnings' => $batch->warning_count,
                        'reconciliation_passed' => $batch->reconciliation_passed,
                        'control_totals' => ['report_totals' => $attributes['control_totals']['report_totals'] ?? []],
                    ]
                );

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            // Two uploads of the same file racing each other; the unique index on file_hash is the last word.
            throw new CommercialImportException('This exact file has already been uploaded.');
        }
    }

    /**
     * Re-runs the district lookup for the routes that were unmatched, after an alias or a district has been added.
     *
     * @return int routes that matched this time
     */
    public function rematchBatch(CommercialImportBatch $batch): int
    {
        $matched = 0;

        $labels = $batch->routes()->whereNull('district_id')->pluck('district_label_raw')->unique();

        foreach ($labels as $label) {
            $district = $this->locations->resolveDistrict($label, $batch->region_id);

            if (! $district) {
                continue;
            }

            $matched += $batch->routes()
                ->whereNull('district_id')
                ->where('district_label_raw', $label)
                ->update(['district_id' => $district->id]);
        }

        $this->refreshBatchCounts($batch);

        return $matched;
    }

    public function refreshBatchCounts(CommercialImportBatch $batch): void
    {
        $batch->update(['matched_count' => $batch->routes()->whereNotNull('district_id')->count()]);
    }

    /** The sheet that holds the district blocks: the first one with a VOLUMES header cell. */
    protected function findReportSheet(array $sheets): ?array
    {
        foreach ($sheets as $sheet) {
            foreach ($sheet['rows'] as $row) {
                if ($this->volumesIndex($row) !== null) {
                    return $sheet;
                }
            }
        }

        return null;
    }

    protected function volumesIndex(array $row): ?int
    {
        foreach ($row as $index => $cell) {
            if (preg_match('/^\s*volumes?\b/i', $this->reader->text($cell))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Walks the sheet top to bottom: district blocks, REPORT TOTALS, then the consumption-band table.
     *
     * @return array{blocks: list<array<string, mixed>>, report_totals: array<string, float|int|null>|null, bands: list<array<string, mixed>>, errors: list<array{row: string|int, message: string}>, warnings: list<array{row: string|int, message: string}>}
     */
    protected function walk(array $rows): array
    {
        $errors = [];
        $warnings = [];
        $blocks = [];
        $block = null;
        $reportTotals = null;
        $firstColumns = null;
        $bandsStart = null;

        $closeBlock = function () use (&$block, &$blocks, &$errors): void {
            if (! $block) {
                return;
            }

            if ($block['totals'] === null) {
                $errors[] = ['row' => 'District '.($block['name'] ?: $block['label']), 'message' => 'The district block has no totals row, so its routes cannot be checked.'];
            }

            $blocks[] = $block;
            $block = null;
        };

        foreach ($rows as $index => $row) {
            $first = $this->reader->firstFilledIndex($row);

            if ($first === null) {
                continue;
            }

            $firstText = $this->reader->text($row[$first]);

            // Consumption bands close the report; everything after belongs to them.
            if (preg_match('/breakdown/i', $firstText) && preg_match('/\b\d{3}\b|domestic/i', $firstText)) {
                $closeBlock();
                $bandsStart = $index;

                break;
            }

            if (($volumes = $this->volumesIndex($row)) !== null) {
                $closeBlock();

                $label = '';

                foreach ($row as $position => $cell) {
                    if ($position >= $volumes) {
                        break;
                    }

                    $text = $this->reader->text($cell);

                    if ($text !== '') {
                        $label = $text;
                        break;
                    }
                }

                $block = ['label' => $label, 'name' => preg_match('/^district$/i', $label) ? '' : $label, 'group_row' => $row, 'columns' => null, 'route_column' => null, 'routes' => [], 'totals' => null, 'seen_codes' => []];

                continue;
            }

            if (preg_match('/^report\s+totals?\b/i', $firstText)) {
                $closeBlock();

                if ($firstColumns) {
                    $reportTotals = $this->extractValues($row, $firstColumns);
                }

                continue;
            }

            if (! $block) {
                continue;
            }

            // The Route row names the columns.
            if ($block['columns'] === null) {
                $routeColumn = null;

                foreach ($row as $position => $cell) {
                    if (preg_match('/^\s*routes?\s*$/i', $this->reader->text($cell))) {
                        $routeColumn = $position;
                        break;
                    }
                }

                if ($routeColumn === null) {
                    continue;
                }

                $block['route_column'] = $routeColumn;
                $block['columns'] = $this->mapColumns($block['group_row'], $row);

                $missing = array_values(array_diff(self::REQUIRED_FIELDS, array_keys($block['columns'])));

                if ($missing !== []) {
                    $errors[] = ['row' => 'Layout', 'message' => 'Could not find the column'.(count($missing) === 1 ? '' : 's').' '.implode(', ', array_map(fn ($field) => self::FIELD_LABELS[$field], $missing)).' under the header of '.($block['label'] ?: 'a district block').'.'];
                    $block['columns'] = [];
                }

                $firstColumns ??= $block['columns'];

                continue;
            }

            if (preg_match('/^(.*?)\s*\btotals?\b\s*:?\s*$/i', $firstText, $matches)) {
                $block['totals'] = $this->extractValues($row, $block['columns']);

                if ($block['name'] === '') {
                    $block['name'] = trim($matches[1]);
                }

                $closeBlock();

                continue;
            }

            $code = $this->reader->text($row[$block['route_column']] ?? null);

            if ($code === '') {
                continue;
            }

            if (isset($block['seen_codes'][$code])) {
                $errors[] = ['row' => 'Route '.$code, 'message' => "Route {$code} appears twice in the {$block['label']} block."];

                continue;
            }

            $block['seen_codes'][$code] = true;
            $block['routes'][] = ['code' => $code, 'values' => $this->extractValues($row, $block['columns'])];
        }

        $closeBlock();

        // A header that said "District" gets its name from the totals row, or failing that from the route codes.
        foreach ($blocks as $key => $done) {
            if ($done['name'] === '') {
                $fromCode = $done['routes'] === [] ? '' : trim((string) preg_replace('/[\s\d-]+$/', '', $done['routes'][0]['code']));
                $blocks[$key]['name'] = $fromCode !== '' ? $fromCode : 'District '.($key + 1);
            }

            if ($done['routes'] === [] && $done['totals'] !== null) {
                $warnings[] = ['row' => 'District '.$blocks[$key]['name'], 'message' => "District {$blocks[$key]['name']} has no routes in this report."];
            }
        }

        if ($blocks === []) {
            $errors[] = ['row' => 'File', 'message' => 'No district blocks could be read.'];
        } elseif ($reportTotals === null) {
            $errors[] = ['row' => 'File', 'message' => 'The REPORT TOTALS row was not found, so the districts cannot be checked against the report\'s own total.'];
        }

        return [
            'blocks' => $blocks,
            'report_totals' => $reportTotals,
            'bands' => $bandsStart === null ? [] : $this->parseBands($rows, $bandsStart),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Field => column index, from the group row (VOLUMES, AMOUNTS, Number Billed...) and the Route row beneath it. A group
     * label covers every column to its right until the next group label, so merged cells work.
     *
     * @return array<string, int>
     */
    protected function mapColumns(array $groupRow, array $headerRow): array
    {
        $columns = [];
        $group = '';
        $width = max(count($groupRow), count($headerRow));

        for ($position = 0; $position < $width; $position++) {
            $groupText = $this->reader->text($groupRow[$position] ?? null);

            if ($groupText !== '') {
                $group = $groupText;
            }

            $sub = $this->reader->text($headerRow[$position] ?? null);

            if ($sub === '') {
                continue;
            }

            $field = $this->fieldFor($group, $sub);

            if ($field !== null && ! isset($columns[$field])) {
                $columns[$field] = $position;
            }
        }

        return $columns;
    }

    protected function fieldFor(string $group, string $sub): ?string
    {
        $text = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', "{$group} | {$sub}")));

        $rules = [
            '/unbilled.*suspense.*unmetered/' => 'unbilled_suspense_unmetered',
            '/unbilled.*suspense.*metered/' => 'unbilled_suspense_metered',
            '/unbilled.*disconn\w*.*unmetered/' => 'unbilled_disconn_unmetered',
            '/unbilled.*disconn\w*.*metered/' => 'unbilled_disconn_metered',
            '/unbilled.*other/' => 'unbilled_other',
            '/unbilled.*total/' => 'unbilled_total',
            '/billed.*average.*unmetered/' => 'billed_average_unmetered',
            '/billed.*average.*metered/' => 'billed_average_metered',
            '/billed.*actual/' => 'billed_actual_reading',
            '/billed.*total/' => 'billed_total',
            '/volumes?.*actual/' => 'volume_actual',
            '/volumes?.*average/' => 'volume_average',
            '/volumes?.*total/' => 'volume_total',
            '/opening/' => 'opening_balance',
            '/billing for/' => 'billing_for_period',
            '/total receivable/' => 'total_receivable',
            '/revenue adj/' => 'revenue_adjustment',
            '/payments? for (the )?month/' => 'payment_for_month',
            '/prev\w* month/' => 'prev_month_payment',
            '/offset/' => 'offset_payments',
            '/total payments?/' => 'total_payments',
            '/closing/' => 'closing_balance',
            '/number of.*customers?|\bcustomers?\b/' => 'customers_count',
        ];

        foreach ($rules as $pattern => $field) {
            if (preg_match($pattern, $text)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $columns
     * @return array<string, float|int|null>
     */
    protected function extractValues(array $row, array $columns): array
    {
        $values = [];

        foreach ($columns as $field => $position) {
            $values[$field] = in_array($field, [...CommercialBillingRoute::AMOUNT_FIELDS, ...CommercialBillingRoute::VOLUME_FIELDS], true)
                ? $this->reader->amount($row[$position] ?? null)
                : $this->reader->integer($row[$position] ?? null);
        }

        return $values;
    }

    /**
     * The domestic consumption bands: rows whose first cell is a band such as "<=5" or ">5".
     *
     * @return list<array{category_code: string, band: string, customers: int, volume: float, amount: float}>
     */
    protected function parseBands(array $rows, int $titleIndex): array
    {
        $title = $this->reader->text($rows[$titleIndex][$this->reader->firstFilledIndex($rows[$titleIndex]) ?? 0] ?? null);
        $category = preg_match('/\b(\d{3})\b/', $title, $matches) ? $matches[1] : '611';

        // Header text decides which column is which; without it the order is customers, volume, amount.
        $positions = ['customers' => null, 'volume' => null, 'amount' => null];
        $bands = [];

        for ($i = $titleIndex + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $first = $this->reader->firstFilledIndex($row);

            if ($first === null) {
                continue;
            }

            $label = $this->reader->text($row[$first]);

            if (preg_match('/^\s*(?:<|>|≤|≥|up\s*to|above|over|below|under)/i', $label)) {
                $numbers = [];

                foreach ($row as $position => $cell) {
                    if ($position > $first && $this->reader->amount($cell) !== null) {
                        $numbers[$position] = $this->reader->amount($cell);
                    }
                }

                $byHeader = $positions['customers'] !== null && $positions['volume'] !== null && $positions['amount'] !== null;
                $values = $byHeader
                    ? [$numbers[$positions['customers']] ?? 0, $numbers[$positions['volume']] ?? 0, $numbers[$positions['amount']] ?? 0]
                    : array_slice(array_values($numbers), 0, 3) + [0, 0, 0];

                $bands[] = [
                    'category_code' => $category,
                    'band' => preg_replace('/\s+/', '', $label),
                    'customers' => (int) round($values[0]),
                    'volume' => (float) $values[1],
                    'amount' => (float) $values[2],
                ];

                continue;
            }

            foreach ($row as $position => $cell) {
                $text = strtolower($this->reader->text($cell));

                foreach (array_keys($positions) as $key) {
                    if ($positions[$key] === null && str_contains($text, $key)) {
                        $positions[$key] = $position;
                    }
                }
            }
        }

        return $bands;
    }

    /**
     * Route identities, routes against their district, districts against REPORT TOTALS.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, float|int|null>|null  $reportTotals
     * @return array{errors: list<array{row: string, message: string}>, checks: list<array{label: string, passed: bool, detail: string}>}
     */
    protected function reconcile(array $blocks, ?array $reportTotals): array
    {
        $errors = [];
        $routeCount = 0;
        $routesOff = 0;
        $districtMismatches = 0;
        // Number Of Customers is not a count the report adds up (design section 1.3), so it is read but never reconciled.
        $fields = array_values(array_diff(array_keys(self::FIELD_LABELS), ['customers_count']));

        $computed = array_fill_keys($fields, 0.0);

        foreach ($blocks as $block) {
            $sums = array_fill_keys($fields, 0.0);

            foreach ($block['routes'] as $route) {
                $routeCount++;
                $problems = $this->identityProblems($route['values']);

                if ($problems !== []) {
                    $routesOff++;

                    foreach ($problems as $problem) {
                        $errors[] = ['row' => 'Route '.$route['code'], 'message' => "{$route['code']} ({$block['name']}): {$problem}"];
                    }
                }

                foreach ($fields as $field) {
                    $sums[$field] += (float) ($route['values'][$field] ?? 0);
                }
            }

            foreach ($fields as $field) {
                $computed[$field] += $sums[$field];
            }

            if ($block['totals'] === null) {
                continue;
            }

            foreach ($fields as $field) {
                if (! array_key_exists($field, $block['totals']) || $block['totals'][$field] === null) {
                    continue;
                }

                if (abs($sums[$field] - (float) $block['totals'][$field]) > self::TOLERANCE) {
                    $districtMismatches++;
                    $errors[] = ['row' => 'District '.$block['name'], 'message' => self::FIELD_LABELS[$field].': the routes add up to '.$this->figure($sums[$field]).' but the '.$block['name'].' totals row shows '.$this->figure((float) $block['totals'][$field]).'.'];
                }
            }
        }

        $reportMismatches = 0;

        if ($reportTotals !== null) {
            foreach ($fields as $field) {
                if (! array_key_exists($field, $reportTotals) || $reportTotals[$field] === null) {
                    continue;
                }

                if (abs($computed[$field] - (float) $reportTotals[$field]) > self::TOLERANCE) {
                    $reportMismatches++;
                    $errors[] = ['row' => 'Report totals', 'message' => self::FIELD_LABELS[$field].': the routes add up to '.$this->figure($computed[$field]).' but REPORT TOTALS shows '.$this->figure((float) $reportTotals[$field]).'.'];
                }
            }
        }

        return [
            'errors' => $errors,
            'checks' => [
                [
                    'label' => 'Every route balances (receivable, payments, closing balance, billed, unbilled)',
                    'passed' => $routesOff === 0 && $routeCount > 0,
                    'detail' => $routeCount.' routes checked'.($routesOff ? ", {$routesOff} off" : ''),
                ],
                [
                    'label' => 'Routes add up to each district totals row',
                    'passed' => $districtMismatches === 0 && $blocks !== [] && collect($blocks)->every(fn (array $block) => $block['totals'] !== null),
                    'detail' => count($blocks).' districts'.($districtMismatches ? ", {$districtMismatches} figure(s) off" : ''),
                ],
                [
                    'label' => 'Routes add up to REPORT TOTALS',
                    'passed' => $reportTotals !== null && $reportMismatches === 0,
                    'detail' => $reportTotals === null ? 'REPORT TOTALS row not found' : ($reportMismatches ? "{$reportMismatches} figure(s) off" : 'Billing For Period '.$this->figure($computed['billing_for_period'])),
                ],
            ],
        ];
    }

    /**
     * The per-route identities from the design (section 1.3).
     *
     * @param  array<string, float|int|null>  $v
     * @return list<string>
     */
    protected function identityProblems(array $v): array
    {
        $n = fn (string $key): float => (float) ($v[$key] ?? 0);
        $problems = [];

        if (abs($n('total_receivable') - ($n('opening_balance') + $n('billing_for_period') + $n('revenue_adjustment'))) > self::TOLERANCE) {
            $problems[] = 'Total Receivable '.$this->figure($n('total_receivable')).' is not Opening Balance + Billing For Period + Revenue Adjustment ('.$this->figure($n('opening_balance') + $n('billing_for_period') + $n('revenue_adjustment')).').';
        }

        if (abs($n('total_payments') - ($n('payment_for_month') + $n('prev_month_payment') + $n('offset_payments'))) > self::TOLERANCE) {
            $problems[] = 'Total Payments '.$this->figure($n('total_payments')).' is not Payment For Month + Prev Month Payment + Offset Payments ('.$this->figure($n('payment_for_month') + $n('prev_month_payment') + $n('offset_payments')).').';
        }

        if (abs($n('closing_balance') - ($n('total_receivable') - $n('total_payments'))) > self::TOLERANCE) {
            $problems[] = 'Closing Balance '.$this->figure($n('closing_balance')).' is not Total Receivable - Total Payments ('.$this->figure($n('total_receivable') - $n('total_payments')).').';
        }

        if (abs($n('billed_total') - ($n('billed_average_metered') + $n('billed_average_unmetered') + $n('billed_actual_reading'))) > self::TOLERANCE) {
            $problems[] = 'Total Billed '.(int) $n('billed_total').' is not Average Metered + Average Unmetered + Actual Reading ('.(int) ($n('billed_average_metered') + $n('billed_average_unmetered') + $n('billed_actual_reading')).').';
        }

        $unbilled = $n('unbilled_suspense_metered') + $n('unbilled_suspense_unmetered') + $n('unbilled_disconn_metered') + $n('unbilled_disconn_unmetered') + $n('unbilled_other');

        if (abs($n('unbilled_total') - $unbilled) > self::TOLERANCE) {
            $problems[] = 'Total Unbilled '.(int) $n('unbilled_total').' is not the sum of its five reasons ('.(int) $unbilled.').';
        }

        return $problems;
    }

    /** @return array{0: string, 1: string|null} [segment, warning] */
    protected function segmentFor(?string $statusLabel): array
    {
        if ($statusLabel === null || trim($statusLabel) === '') {
            return [CommercialImportBatch::SEGMENT_ALL, null];
        }

        if (preg_match('/new\s*service/i', $statusLabel)) {
            return [CommercialImportBatch::SEGMENT_NEW_SERVICE, null];
        }

        $slug = Str::of($statusLabel)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->toString();

        return [$slug !== '' ? $slug : CommercialImportBatch::SEGMENT_ALL, "BILLING STATUS '{$statusLabel}' is not a segment seen before; it is stored as '{$slug}'."];
    }

    protected function figure(float $value): string
    {
        return number_format($value, 2);
    }

    /** A preview for a file that is not a billing report at all. */
    protected function emptyPreview(UploadedFile $file, array $errors): array
    {
        $attributes = [
            'report_type' => CommercialImportBatch::TYPE_BILLING_SUMMARY,
            'file_hash' => $this->reader->fileHash($file),
            'source_filename' => $file->getClientOriginalName(),
        ];

        return $this->finish([
            'report_type' => CommercialImportBatch::TYPE_BILLING_SUMMARY,
            'errors' => $errors,
            'warnings' => [],
            'checks' => [],
            'total_rows' => 0,
            'valid_count' => 0,
            'matched_count' => 0,
            'unmatched_count' => 0,
            'system_count' => 0,
            'district_count' => 0,
            'region' => ['raw' => null, 'id' => null, 'name' => null],
            'unresolved_region' => null,
            'period' => ['from' => null, 'to' => null],
            'months' => [],
            'segment' => null,
            'will_replace' => null,
            'preview_rows' => [],
            'parsed' => ['attributes' => $attributes, 'routes' => [], 'bands' => []],
        ]);
    }
}
