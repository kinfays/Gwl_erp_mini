<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\CommercialReadingStrength;
use App\Models\Employee;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads "Customer Meter Reading Report - CCA Summary - By Month" (rptReadingSummDate): a Document map sheet, one sheet
 * per meter reader and a final grand-total sheet.
 *
 * The source's percentages and Unvisited columns are measured against the whole region's customers, so they say nothing
 * about a single reader; only the counts are kept and every rate is recomputed from them. A file whose reader rows do
 * not add up to its own grand-total sheet has been truncated, filtered or edited and is blocked.
 */
class ReadingSummaryImportService extends ReportImportService
{
    /** Visited is read + skipped; a reader row may be this many visits off before the file is blocked. */
    public const VISITED_TOLERANCE = 1;

    /**
     * @param  list<array{name: string, rows: array<int, array<int, mixed>>}>|null  $sheets  already-read sheets, to avoid reading twice
     * @param  int|null  $restrictToRegionId  when set, a file for any other region is blocked (regional users)
     */
    public function preview(UploadedFile $file, ?int $restrictToRegionId = null, ?array $sheets = null): array
    {
        $sheets ??= $this->reader->readSheets($file);

        $errors = [];
        $warnings = [];
        $readerSheets = [];
        $grandCandidates = [];
        $layoutProblems = [];

        foreach ($sheets as $sheet) {
            if (preg_match('/document\s*map/i', $sheet['name'])) {
                continue;
            }

            $parsed = $this->parseSheet($sheet['rows']);

            if ($parsed['months'] === [] && $parsed['problem'] === null) {
                // The grand-total sheet of the real export has no month rows: one row of overall Read / Skipped / Visited.
                if ($parsed['reader'] === null && ($overall = $this->parseOverallTotals($sheet['rows'])) !== null) {
                    $grandCandidates[] = ['reader' => null, 'months' => [], 'problem' => null, 'overall' => $overall, 'sheet' => $sheet['name']];
                }

                continue;
            }

            if ($parsed['problem'] !== null) {
                $layoutProblems[$parsed['problem']][] = $sheet['name'];

                continue;
            }

            if ($parsed['reader'] !== null) {
                $readerSheets[] = [...$parsed, 'sheet' => $sheet['name'], 'rows' => $sheet['rows']];
            } else {
                $grandCandidates[] = [...$parsed, 'sheet' => $sheet['name']];
            }
        }

        foreach ($layoutProblems as $message => $names) {
            $errors[] = [
                'row' => 'Layout',
                'message' => $message.' ('.count($names).' sheet'.(count($names) === 1 ? '' : 's').': '.implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ', ...' : '').')',
            ];
        }

        if ($readerSheets === [] && $layoutProblems === []) {
            $errors[] = ['row' => 'File', 'message' => 'No meter-reader sheets with monthly rows were found, so there is nothing to import.'];
        }

        // --- Readers: one sheet each, never twice. ---
        $seenReaders = [];

        foreach ($readerSheets as $sheet) {
            $staffId = $sheet['reader']['staff_id'];

            if (isset($seenReaders[$staffId])) {
                $errors[] = ['row' => 'Sheet '.$sheet['sheet'], 'message' => "Reader {$staffId} appears on more than one sheet ('{$seenReaders[$staffId]}' and '{$sheet['sheet']}')."];
            }

            $seenReaders[$staffId] ??= $sheet['sheet'];
        }

        // --- Region (the access-scoping key, so it must resolve). ---
        $regionLabel = null;

        foreach ($readerSheets as $sheet) {
            $regionLabel = $this->reader->labelValue($sheet['rows'], '/^\s*region\b\s*:?\s*(.*)$/i');

            if ($regionLabel !== null) {
                break;
            }
        }

        $region = null;
        $unresolvedRegion = null;

        if ($readerSheets !== []) {
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

        // --- Grand-total sheet: the control totals. ---
        $grand = $this->pickGrandSheet($grandCandidates);

        if ($readerSheets !== [] && ! $grand) {
            $errors[] = ['row' => 'File', 'message' => 'The grand-total sheet was not found, so the reader rows cannot be checked against the report\'s own totals.'];
        }

        // --- Months, strengths and the per-reader rows. ---
        $monthKeys = [];
        $strengthVotes = [];
        $sums = [];
        $visitedOff = [];
        $statRows = [];

        foreach ($readerSheets as $sheet) {
            foreach ($sheet['months'] as $monthKey => $values) {
                $monthKeys[$monthKey] = true;
                $strengthVotes[$monthKey][(int) $values['strength']] = ($strengthVotes[$monthKey][(int) $values['strength']] ?? 0) + 1;

                foreach (['read', 'skipped', 'visited'] as $measure) {
                    $sums[$monthKey][$measure] = ($sums[$monthKey][$measure] ?? 0) + $values[$measure];
                }

                if (abs($values['visited'] - ($values['read'] + $values['skipped'])) > self::VISITED_TOLERANCE) {
                    $visitedOff[] = "{$sheet['reader']['staff_id']} in ".Carbon::parse($monthKey)->format('M Y')." (visited {$values['visited']}, read {$values['read']} + skipped {$values['skipped']})";
                }

                $statRows[] = [
                    'month' => $monthKey,
                    'reader_staff_id' => $sheet['reader']['staff_id'],
                    'reader_name_raw' => $sheet['reader']['name'],
                    'read_count' => $values['read'],
                    'skipped_count' => $values['skipped'],
                    'visited_count' => $values['visited'],
                ];
            }
        }

        ksort($monthKeys);
        $months = array_keys($monthKeys);

        $strengths = [];

        foreach ($months as $monthKey) {
            $votes = $strengthVotes[$monthKey] ?? [];
            arsort($votes);
            $chosen = (int) array_key_first($votes);

            if (count($votes) > 1) {
                $warnings[] = ['row' => Carbon::parse($monthKey)->format('M Y'), 'message' => 'Verified strength differs between reader sheets ('.implode(', ', array_map('number_format', array_keys($votes))).'); '.number_format($chosen).' was used because most sheets show it.'];
            }

            $strengths[] = ['month' => $monthKey, 'verified_strength' => $chosen];
        }

        // --- Reconciliation. ---
        $checks = [];

        if ($grand) {
            foreach (['read' => 'Read', 'skipped' => 'Skipped', 'visited' => 'Visited'] as $measure => $label) {
                $readerTotal = 0;
                $grandTotal = 0;
                $mismatches = [];

                if ($grand['months'] !== []) {
                    foreach (array_unique([...$months, ...array_keys($grand['months'])]) as $monthKey) {
                        $fromReaders = $sums[$monthKey][$measure] ?? 0;
                        $fromGrand = $grand['months'][$monthKey][$measure] ?? 0;
                        $readerTotal += $fromReaders;
                        $grandTotal += $fromGrand;

                        if ($fromReaders !== $fromGrand) {
                            $mismatches[] = Carbon::parse($monthKey)->format('M Y').": reader sheets total ".number_format($fromReaders).' but the grand-total sheet shows '.number_format($fromGrand);
                        }
                    }
                } else {
                    // Overall totals only: every reader and every month together.
                    $readerTotal = array_sum(array_column($sums, $measure));
                    $grandTotal = (int) ($grand['overall'][$measure] ?? 0);

                    if ($readerTotal !== $grandTotal) {
                        $mismatches[] = 'overall: reader sheets total '.number_format($readerTotal).' but the grand-total sheet shows '.number_format($grandTotal);
                    }
                }

                $checks[] = [
                    'label' => "{$label}: reader rows add up to the grand-total sheet",
                    'passed' => $mismatches === [],
                    'detail' => number_format($readerTotal).' on reader sheets, '.number_format($grandTotal).' on the grand-total sheet',
                ];

                foreach (array_slice($mismatches, 0, 12) as $mismatch) {
                    $errors[] = ['row' => 'Reconciliation', 'message' => "{$label} count, {$mismatch}."];
                }
            }
        }

        $checks[] = [
            'label' => 'Visited = read + skipped on every reader row',
            'passed' => $visitedOff === [],
            'detail' => $visitedOff === [] ? count($statRows).' rows checked' : count($visitedOff).' row(s) off by more than '.self::VISITED_TOLERANCE,
        ];

        foreach (array_slice($visitedOff, 0, 12) as $row) {
            $errors[] = ['row' => 'Reconciliation', 'message' => "Visited does not equal read + skipped for {$row}."];
        }

        $checks[] = [
            'label' => 'Each reader appears on one sheet only',
            'passed' => count($seenReaders) === count($readerSheets),
            'detail' => count($readerSheets).' reader sheets',
        ];

        $checks[] = [
            'label' => 'Region recognised',
            'passed' => $region !== null,
            'detail' => $region?->region_name ?? ($regionLabel ?? 'not found'),
        ];

        // --- Match readers to the staff directory. ---
        $employees = $this->employeesByStaffId(array_keys($seenReaders));
        $readerSummaries = [];

        foreach ($statRows as $index => $row) {
            $statRows[$index] = [...$row, ...$this->resolveReader($row['reader_staff_id'], $row['reader_name_raw'], $employees)];
        }

        foreach ($statRows as $row) {
            $readerSummaries[$row['reader_staff_id']] = [
                'name' => $row['reader_name_raw'],
                'match_status' => $row['match_status'],
            ];
        }

        $unmatchedReaders = collect($readerSummaries)->where('match_status', CommercialReadingStat::MATCH_UNMATCHED);

        foreach ($unmatchedReaders as $staffId => $summary) {
            $warnings[] = ['row' => 'Reader '.$staffId, 'message' => "{$staffId} - {$summary['name']} is not in the staff directory. The batch can still be imported; link the reader on the batch screen."];
        }

        // --- What this file replaces. ---
        $willReplace = 0;

        if ($region && $months !== []) {
            $willReplace = CommercialReadingStat::query()
                ->effective()
                // whereDate: the month column may be stored with a time part ("2026-06-01 00:00:00").
                ->where(function ($query) use ($months) {
                    foreach ($months as $month) {
                        $query->orWhereDate('month', $month);
                    }
                })
                ->whereHas('batch', fn ($batch) => $batch->where('region_id', $region->id))
                ->count();

            if ($willReplace > 0) {
                $warnings[] = ['row' => 'Existing data', 'message' => number_format($willReplace).' reader-month rows already loaded for '.$this->monthRange($months).' will be replaced by this file. The earlier batch stays on record.'];
            }
        }

        $matchedRows = collect($statRows)->where('match_status', CommercialReadingStat::MATCH_MATCHED)->count();
        $systemRows = collect($statRows)->where('match_status', CommercialReadingStat::MATCH_SYSTEM_ACCOUNT)->count();
        $reconciliationPassed = $errors === [] && $grand !== null && collect($checks)->every(fn (array $check) => $check['passed']);

        $attributes = [
            'report_type' => CommercialImportBatch::TYPE_READING_SUMMARY,
            'region_id' => $region?->id,
            'region_label_raw' => $regionLabel,
            'period_from' => $months === [] ? null : $months[0],
            'period_to' => $months === [] ? null : Carbon::parse(end($months))->endOfMonth()->toDateString(),
            'granularity' => CommercialImportBatch::GRANULARITY_MONTHLY,
            'billing_status_raw' => null,
            'customer_segment' => null,
            'source_filename' => $file->getClientOriginalName(),
            'file_hash' => $this->reader->fileHash($file),
            'row_count' => count($statRows),
            // Rows that need no matching work: found in the directory, or the system account (kept, never matched).
            'matched_count' => $matchedRows + $systemRows,
            'warning_count' => count($warnings),
            'reconciliation_passed' => $reconciliationPassed,
            'control_totals' => [
                'grand_total_sheet' => $grand ? $grand['sheet'] : null,
                'grand_totals' => $grand ? ($grand['months'] !== [] ? $this->monthTotals($grand['months']) : ['overall' => $grand['overall']]) : [],
                'reader_totals' => $this->monthTotals($sums),
                'checks' => $checks,
                'warnings' => array_map(fn (array $warning) => $warning['message'], array_slice($warnings, 0, 100)),
            ],
        ];

        $duplicate = $this->duplicateOf($attributes['file_hash']);

        if ($duplicate) {
            $errors[] = ['row' => 'File', 'message' => $this->duplicateMessage($duplicate)];
        }

        return $this->finish([
            'report_type' => CommercialImportBatch::TYPE_READING_SUMMARY,
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
            'total_rows' => count($statRows),
            'valid_count' => count($statRows),
            'matched_count' => $matchedRows,
            'unmatched_count' => $unmatchedReaders->count(),
            'system_count' => $systemRows,
            'reader_count' => count($seenReaders),
            'region' => ['raw' => $regionLabel, 'id' => $region?->id, 'name' => $region?->region_name],
            'unresolved_region' => $unresolvedRegion,
            'period' => ['from' => $attributes['period_from'], 'to' => $attributes['period_to']],
            'months' => $months,
            'will_replace' => $willReplace,
            'preview_rows' => array_slice($statRows, 0, 8),
            'parsed' => ['attributes' => $attributes, 'strengths' => $strengths, 'stats' => $statRows],
        ]);
    }

    /**
     * Turns a clean preview into a batch with its strengths and one row per reader per month. Readers that could not be
     * matched are still written: resolving them is the point of the batch screen.
     */
    public function createBatch(array $batchAttributes, array $parsed, ?string $importFilePath = null, ?int $actorId = null): CommercialImportBatch
    {
        if (empty($parsed['attributes']) || empty($parsed['stats'])) {
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

                foreach ($parsed['strengths'] as $strength) {
                    $batch->strengths()->create($strength);
                }

                foreach ($parsed['stats'] as $stat) {
                    $batch->stats()->create(collect($stat)->only([
                        'month', 'reader_staff_id', 'reader_name_raw', 'employee_id', 'district_id',
                        'read_count', 'skipped_count', 'visited_count', 'match_status',
                    ])->all());
                }

                $changes = $this->lifecycle->refreshStatuses($batch->region_id, $batch->report_type, $batch->id);

                if ($changes['superseded'] !== []) {
                    $batch->update(['supersedes_batch_id' => max($changes['superseded'])]);
                }

                $stats = $batch->stats();

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
                        'matched' => (clone $stats)->where('match_status', CommercialReadingStat::MATCH_MATCHED)->count(),
                        'unmatched' => (clone $stats)->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)->count(),
                        'system_account' => (clone $stats)->where('match_status', CommercialReadingStat::MATCH_SYSTEM_ACCOUNT)->count(),
                        'warnings' => $batch->warning_count,
                        'reconciliation_passed' => $batch->reconciliation_passed,
                        'control_totals' => [
                            'grand_totals' => $attributes['control_totals']['grand_totals'] ?? [],
                            'reader_totals' => $attributes['control_totals']['reader_totals'] ?? [],
                        ],
                    ]
                );

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            // Two uploads of the same file racing each other; the unique index on file_hash is the last word.
            throw new CommercialImportException('This exact file has already been uploaded.');
        }

        return $batch;
    }

    /**
     * Re-runs the staff-directory lookup for the readers that were unmatched, after HR has added or corrected them.
     *
     * @return int readers (distinct staff IDs) that matched this time
     */
    public function rematchBatch(CommercialImportBatch $batch): int
    {
        $unmatched = $batch->stats()->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)->get();

        if ($unmatched->isEmpty()) {
            return 0;
        }

        $employees = $this->employeesByStaffId($unmatched->pluck('reader_staff_id')->unique()->all());
        $matchedReaders = [];

        foreach ($unmatched as $stat) {
            $resolved = $this->resolveReader($stat->reader_staff_id, $stat->reader_name_raw, $employees);

            if ($resolved['match_status'] === CommercialReadingStat::MATCH_UNMATCHED) {
                continue;
            }

            $stat->update([
                'employee_id' => $resolved['employee_id'],
                'district_id' => $resolved['district_id'],
                'match_status' => $resolved['match_status'],
            ]);

            $matchedReaders[$stat->reader_staff_id] = true;
        }

        $this->refreshBatchCounts($batch);

        return count($matchedReaders);
    }

    /** Keeps the batch's matched_count in step after readers are matched or linked. */
    public function refreshBatchCounts(CommercialImportBatch $batch): void
    {
        $batch->update([
            'matched_count' => $batch->stats()->where('match_status', '!=', CommercialReadingStat::MATCH_UNMATCHED)->count(),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Employee>  $employees  keyed by staff ID
     * @return array{employee_id: int|null, district_id: int|null, match_status: string}
     */
    protected function resolveReader(string $staffId, ?string $name, $employees): array
    {
        // The 00000 System Administrator account has real counts but is no reader: kept, never ranked or counted.
        if (preg_match('/^0+$/', $staffId) || preg_match('/system\s*administrator/i', (string) $name)) {
            return ['employee_id' => null, 'district_id' => null, 'match_status' => CommercialReadingStat::MATCH_SYSTEM_ACCOUNT];
        }

        $employee = $employees[$staffId] ?? null;

        if (! $employee) {
            return ['employee_id' => null, 'district_id' => null, 'match_status' => CommercialReadingStat::MATCH_UNMATCHED];
        }

        return ['employee_id' => $employee->id, 'district_id' => $employee->district_id, 'match_status' => CommercialReadingStat::MATCH_MATCHED];
    }

    /**
     * @param  list<string>  $staffIds
     * @return \Illuminate\Support\Collection<string, Employee>
     */
    protected function employeesByStaffId(array $staffIds)
    {
        return Employee::query()
            ->whereIn('staff_id', array_map('strval', $staffIds))
            ->get()
            ->keyBy(fn (Employee $employee) => (string) $employee->staff_id);
    }

    /**
     * One sheet: the reader label, then one row per month.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{reader: array{staff_id: string, name: string}|null, months: array<string, array<string, int>>, problem: string|null}
     */
    protected function parseSheet(array $rows): array
    {
        $reader = $this->readerLabel($rows);

        $monthRows = [];
        $firstMonthIndex = null;

        foreach ($rows as $index => $row) {
            $first = $this->reader->firstFilledIndex($row);

            if ($first === null || ! $this->isMonthLabel($row[$first])) {
                continue;
            }

            $month = $this->reader->parseMonth($row[$first], allowSerial: true);

            if (! $month) {
                continue;
            }

            $monthRows[$index] = [$month, $row];
            $firstMonthIndex ??= $index;
        }

        if ($monthRows === []) {
            return ['reader' => $reader, 'months' => [], 'problem' => null];
        }

        $columns = $this->mapColumns($rows, (int) $firstMonthIndex);
        $missing = array_keys(array_filter($columns, fn ($column) => $column === null));

        if ($missing !== []) {
            $names = ['strength' => 'Verified Strength', 'read' => 'Read #', 'skipped' => 'Skipped #', 'visited' => 'Visited #'];

            return ['reader' => $reader, 'months' => [], 'problem' => 'Could not find the '.implode(', ', array_map(fn ($key) => $names[$key], $missing)).' column header'.(count($missing) === 1 ? '' : 's')];
        }

        $months = [];

        foreach ($monthRows as [$month, $row]) {
            $counts = [
                'strength' => $this->reader->integer($row[$columns['strength']] ?? null),
                'read' => $this->reader->integer($row[$columns['read']] ?? null),
                'skipped' => $this->reader->integer($row[$columns['skipped']] ?? null),
                'visited' => $this->reader->integer($row[$columns['visited']] ?? null),
            ];

            // A month with nothing in any count column is a placeholder row, not data.
            if ($counts['read'] === null && $counts['skipped'] === null && $counts['visited'] === null) {
                continue;
            }

            $months[$month->toDateString()] = array_map(fn ($value) => $value ?? 0, $counts);
        }

        return ['reader' => $reader, 'months' => $months, 'problem' => null];
    }

    /**
     * The overall totals of the grand-total sheet: a header row naming Read / Skipped / Visited and, a row or two below,
     * the figures in the same columns. @return array{read: int, skipped: int, visited: int}|null
     */
    protected function parseOverallTotals(array $rows): ?array
    {
        foreach ($rows as $index => $row) {
            $columns = [];

            foreach ($row as $column => $cell) {
                $classified = $this->classifyHeader($this->reader->text($cell));

                if ($classified && $classified[1] === 'bare' && in_array($classified[0], ['read', 'skipped', 'visited'], true)) {
                    $columns[$classified[0]] = $column;
                }
            }

            if (count($columns) < 3) {
                continue;
            }

            for ($below = $index + 1; $below <= $index + 3 && isset($rows[$below]); $below++) {
                $values = array_map(fn (int $column) => $this->reader->integer($rows[$below][$column] ?? null), $columns);

                if (! in_array(null, $values, true)) {
                    return ['read' => $values['read'], 'skipped' => $values['skipped'], 'visited' => $values['visited']];
                }
            }
        }

        return null;
    }

    /** @return array{staff_id: string, name: string}|null */
    protected function readerLabel(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 25) as $row) {
            foreach ($row as $cell) {
                if (preg_match('/^\s*(\d{3,})\s*-\s*(?=.*[A-Za-z])(.+?)\s*$/u', $this->reader->text($cell), $matches)) {
                    return ['staff_id' => $matches[1], 'name' => trim($matches[2])];
                }
            }
        }

        return null;
    }

    /** "Mon 01-Jun-2026", "June-2026" or a real date, and nothing else (a filter line that merely mentions a month is not a row). */
    protected function isMonthLabel(mixed $value): bool
    {
        if (is_string($value)) {
            return (bool) preg_match('/^\s*(?:[A-Za-z]{3,9}\.?,?\s+)?(?:\d{1,2}[-\s\/])?[A-Za-z]{3,9}[-\s\/,]+\d{4}\s*$/', $value)
                && $this->reader->parseMonth($value) !== null;
        }

        return $this->reader->parseMonth($value, allowSerial: true) !== null;
    }

    /**
     * Column index for each measure, found from the header text above the first month row. A group header such as a
     * merged "Read" over "#" and "%" is resolved to its "#" column.
     *
     * @return array{strength: int|null, read: int|null, skipped: int|null, visited: int|null}
     */
    protected function mapColumns(array $rows, int $upTo): array
    {
        $explicit = [];
        $bare = [];

        for ($i = 0; $i < $upTo; $i++) {
            foreach ($rows[$i] as $column => $cell) {
                $classified = $this->classifyHeader($this->reader->text($cell));

                if (! $classified) {
                    continue;
                }

                [$key, $kind] = $classified;

                if ($kind === 'count') {
                    $explicit[$key] ??= $column;
                } elseif ($kind === 'bare') {
                    $bare[$key] ??= [$column, $i];
                }
            }
        }

        $map = [];

        foreach (['strength', 'read', 'skipped', 'visited'] as $key) {
            if (isset($explicit[$key])) {
                $map[$key] = $explicit[$key];
            } elseif (isset($bare[$key])) {
                [$column, $rowIndex] = $bare[$key];
                $map[$key] = $key === 'strength' ? $column : ($this->subHeaderColumn($rows, $rowIndex, $upTo, $column) ?? $column);
            } else {
                $map[$key] = null;
            }
        }

        return $map;
    }

    /** @return array{0: string, 1: string}|null [measure, count|pct|bare] */
    protected function classifyHeader(string $text): ?array
    {
        $normalized = strtolower((string) preg_replace('/[^a-z#%]/i', '', $text));

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'strength')) {
            return ['strength', str_contains($normalized, '%') ? 'pct' : 'count'];
        }

        if (! preg_match('/^(unvisited|visited|skipped|read)(#|no|count|%|pct|percent)?$/', $normalized, $matches)) {
            return null;
        }

        $kind = match ($matches[2] ?? '') {
            '#', 'no', 'count' => 'count',
            '%', 'pct', 'percent' => 'pct',
            default => 'bare',
        };

        return [$matches[1], $kind];
    }

    /** The "#" cell under (or beside) a merged group header, when the sub-header row has one. */
    protected function subHeaderColumn(array $rows, int $groupRow, int $upTo, int $column): ?int
    {
        for ($i = $groupRow + 1; $i < $upTo; $i++) {
            foreach ([$column, $column + 1] as $candidate) {
                if (in_array(strtolower($this->reader->text($rows[$i][$candidate] ?? null)), ['#', 'no', 'no.', 'count', 'number'], true)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * The sheet that carries the report's own totals: the one without a reader label that says "total", else the last.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    protected function pickGrandSheet(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (preg_match('/total/i', $candidate['sheet'])) {
                return $candidate;
            }
        }

        return end($candidates);
    }

    /**
     * @param  array<string, array<string, int>>  $months
     * @return array<string, array<string, int>>
     */
    protected function monthTotals(array $months): array
    {
        ksort($months);

        return collect($months)
            ->map(fn (array $values) => collect($values)->only(['read', 'skipped', 'visited'])->all())
            ->all();
    }
}
