<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Models\District;
use App\Models\User;
use App\Services\Commercial\LocationMatcher;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The customer-list import, as a resumable pipeline whose memory does not depend on the size of the file:
 *
 *   upload()   store the file, create the batch (queued); the same file twice is the same batch (idempotent)
 *   locate     read only the FILTERS block, resolve region and district (an unknown one parks the batch as "needs a match"
 *              BEFORE any customer row is read)
 *   stage      stream the sheet, build staging rows in chunks, reconcile every route against its own TOTALS row
 *   merge      set-based merge into commercial_customers (CustomerMergeService)
 *   missing    flag accounts the file no longer lists
 *   rollup     the immutable facts (CustomerRollupService)
 *   complete   clean staging, label superseded batches (CustomerBatchLifecycle)
 *
 * Each phase stores its progress on the batch, so a job that dies is simply run again and carries on. No exception that
 * leaves this class carries customer data: failures are re-thrown as a sanitized message.
 */
class CustomerImportService
{
    /** Staging rows committed together (the resume point moves with each). */
    protected const STAGE_CHUNK = 2000;

    /** Issues kept on the batch before the rest are only counted. */
    protected const ISSUE_CAP = 40;

    /** Seconds spent in each phase of the last process() call (the benchmark reports them). @var array<string, float> */
    public array $timings = [];

    public function __construct(
        protected LocationMatcher $locations,
        protected CustomerMergeService $merge,
        protected CustomerRollupService $rollups,
        protected CustomerBatchLifecycle $lifecycle,
    ) {
    }

    // ---------------------------------------------------------------- upload

    /**
     * Stores the file and creates the batch. Returns the existing batch when this exact file is already live or being
     * processed (the checksum is the identity), so uploading twice never does the work twice.
     *
     * @return array{batch: CommercialCustomerBatch, duplicate: bool}
     */
    public function register(string $sourcePath, string $originalName, string $periodType, Carbon $asOf, ?User $actor, ?string $notes = null): array
    {
        if (! in_array($periodType, [CommercialCustomerBatch::PERIOD_WEEKLY, CommercialCustomerBatch::PERIOD_MONTHLY], true)) {
            throw new CustomerImportException('The period must be weekly or monthly.');
        }

        $hash = (string) hash_file('sha256', $sourcePath);
        $existing = CommercialCustomerBatch::query()->where('file_hash', $hash)->whereNotIn('status', [CommercialCustomerBatch::STATUS_VOIDED, CommercialCustomerBatch::STATUS_FAILED, CommercialCustomerBatch::STATUS_BLOCKED])->orderByDesc('id')->get()
            // The same bytes are a duplicate while the earlier upload is still in flight, or is still the district's newest data.
            // Once newer data has replaced it, identical content is a legitimate new upload (the base came back to that state).
            ->first(fn (CommercialCustomerBatch $batch) => ! $batch->isLive() || $this->lifecycle->newest((int) $batch->district_id)?->id === $batch->id);

        if ($existing) {
            return ['batch' => $existing, 'duplicate' => true];
        }

        try {
            $reader = new XlsxStreamReader($sourcePath);
            $sheet = $this->sheetName($reader);
            $reader->close();
        } catch (Throwable) {
            throw new CustomerImportException('This file could not be read as an Excel (.xlsx) workbook.');
        }

        if ($sheet === null) {
            throw new CustomerImportException('This is not a customer list report: no "rptCustomerDetails" sheet was found.');
        }

        $path = 'commercial/customer-imports/'.$hash.'.xlsx';
        Storage::disk('local')->put($path, fopen($sourcePath, 'r'));

        $batch = CommercialCustomerBatch::query()->create([
            'period_type' => $periodType,
            'period_key' => CommercialCustomerBatch::periodKey($periodType, $asOf),
            'as_of_date' => $asOf->toDateString(),
            'source_filename' => mb_substr($originalName, 0, 255),
            'file_path' => $path,
            'file_hash' => $hash,
            'status' => CommercialCustomerBatch::STATUS_QUEUED,
            'phase' => CommercialCustomerBatch::PHASE_QUEUED,
            'notes' => $notes ? mb_substr($notes, 0, 2000) : null,
            'imported_by' => $actor?->id,
        ]);

        return ['batch' => $batch, 'duplicate' => false];
    }

    // ---------------------------------------------------------------- process

    /**
     * Runs (or resumes) the pipeline. Safe to call again after a failure or a restart.
     *
     * @param  callable(CommercialCustomerBatch): void|null  $tick  called as work progresses (the job keeps its lock alive)
     *
     * @throws CustomerDistrictBusy when another batch of the same district is being processed
     */
    public function process(CommercialCustomerBatch $batch, ?callable $tick = null): CommercialCustomerBatch
    {
        $batch->refresh();

        if (! in_array($batch->status, [CommercialCustomerBatch::STATUS_QUEUED, CommercialCustomerBatch::STATUS_PROCESSING, CommercialCustomerBatch::STATUS_FAILED, CommercialCustomerBatch::STATUS_NEEDS_MATCH], true)) {
            return $batch;
        }

        $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_PROCESSING, 'error_message' => null, 'started_at' => $batch->started_at ?? now()])->save();
        $lock = null;

        try {
            if (in_array($batch->phase, [CommercialCustomerBatch::PHASE_QUEUED, CommercialCustomerBatch::PHASE_PARSE], true)) {
                $batch->forceFill(['phase' => CommercialCustomerBatch::PHASE_PARSE])->save();

                if (! $this->timed('locate', fn () => $this->locate($batch))) {
                    return $batch->refresh();
                }
            }

            $lock = Cache::lock('commercial-customers:district:'.$batch->district_id, 14400);

            if (! $lock->get()) {
                $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_QUEUED])->save();

                throw new CustomerDistrictBusy;
            }

            if ($batch->phase === CommercialCustomerBatch::PHASE_PARSE) {
                if (! $this->timed('stage', fn () => $this->stage($batch, $tick))) {
                    return $batch->refresh();
                }
            }

            if ($batch->phase === CommercialCustomerBatch::PHASE_MERGE) {
                $this->timed('merge', function () use ($batch, $tick): void {
                    $this->merge->prepare($batch);
                    $this->merge->merge($batch, $tick);
                    $batch->forceFill(['phase' => CommercialCustomerBatch::PHASE_MISSING])->save();
                });
            }

            if ($batch->phase === CommercialCustomerBatch::PHASE_MISSING) {
                $this->timed('missing', function () use ($batch): void {
                    $this->merge->markMissing($batch);
                    $batch->forceFill(['phase' => CommercialCustomerBatch::PHASE_ROLLUP])->save();
                });
            }

            if ($batch->phase === CommercialCustomerBatch::PHASE_ROLLUP) {
                $this->timed('rollups', fn () => $this->rollups->build($batch, (new CustomerLookups)->billingStatusIds()));
                $this->timed('complete', fn () => $this->lifecycle->complete($batch));
            }

            return $batch->refresh();
        } catch (CustomerDistrictBusy $busy) {
            throw $busy;
        } catch (CustomerImportException $exception) {
            $this->fail($batch, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            // The original message can carry bound values (customer data): keep the class and the phase only.
            $detail = $exception instanceof \Illuminate\Database\QueryException ? ' SQLSTATE '.($exception->errorInfo[0] ?? '?').'/'.($exception->errorInfo[1] ?? '?') : '';

            // The driver's own text can echo a value; it is only added while debugging locally.
            if (config('app.debug') && $exception instanceof \Illuminate\Database\QueryException) {
                $detail .= ': '.mb_substr((string) ($exception->errorInfo[2] ?? ''), 0, 200);
            } elseif (config('app.debug')) {
                $detail .= ': '.mb_substr($exception->getMessage(), 0, 200).' @ '.basename($exception->getFile()).':'.$exception->getLine();
            }

            $message = 'The import stopped in the "'.$batch->phase.'" step ('.class_basename($exception).$detail.'). Run it again; it carries on where it stopped.';
            $this->fail($batch, $message);

            throw new CustomerImportException($message);
        } finally {
            $lock?->release();
        }
    }

    /** @template T @param  callable(): T  $work @return T */
    protected function timed(string $phase, callable $work): mixed
    {
        $start = microtime(true);

        try {
            return $work();
        } finally {
            $this->timings[$phase] = ($this->timings[$phase] ?? 0) + microtime(true) - $start;
        }
    }

    public function fail(CommercialCustomerBatch $batch, string $message): void
    {
        $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_FAILED, 'error_message' => mb_substr($message, 0, 500), 'finished_at' => now()])->save();
    }

    /** Puts a parked ("needs a match") batch back on the queue after the location was matched. */
    public function resumeAfterMatch(CommercialCustomerBatch $batch): void
    {
        $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_QUEUED, 'phase' => CommercialCustomerBatch::PHASE_PARSE])->save();
    }

    // ---------------------------------------------------------------- locate

    /** Reads the FILTERS block and fixes the batch's region and district. False = stop (parked or blocked). */
    protected function locate(CommercialCustomerBatch $batch): bool
    {
        $reader = new XlsxStreamReader(Storage::disk('local')->path($batch->file_path));
        $sheet = $this->sheetName($reader);
        $filters = null;

        try {
            foreach ((new CustomerListParser)->parse($this->take($reader->rows((string) $sheet), 60)) as $event) {
                if ($event['kind'] === 'filters') {
                    $filters = $event;

                    break;
                }
            }
        } finally {
            $reader->close();
        }

        if (! $filters || ! $filters['region'] || ! $filters['district']) {
            return $this->block($batch, ['The REGION / DISTRICT filter block was not found at the top of the sheet, so the file cannot be placed.']);
        }

        $batch->forceFill(['region_label_raw' => mb_substr($filters['region'], 0, 120), 'district_label_raw' => mb_substr($filters['district'], 0, 120)])->save();

        $region = $this->locations->resolveRegion($filters['region']);

        if (! $region) {
            return $this->park($batch, 'The region "'.$filters['region'].'" is not recognised. Match it to a region to continue.');
        }

        // A batch with no uploader (the console command, the benchmark) is a system load and unrestricted.
        $uploader = $batch->imported_by ? User::query()->find($batch->imported_by) : null;

        if ($uploader && ! CustomerScope::canSeeRegion($uploader, (int) $region->id)) {
            return $this->block($batch, ['This file is for the '.$region->region_name.' region, which your account may not load.']);
        }

        $district = $this->locations->resolveDistrict($filters['district'], (int) $region->id);

        if (! $district) {
            $batch->forceFill(['region_id' => $region->id])->save();

            return $this->park($batch, 'The district "'.$filters['district'].'" is not recognised in '.$region->region_name.'. Match it to a district to continue.');
        }

        $newer = CommercialCustomerBatch::query()->live()->where('district_id', $district->id)->whereDate('as_of_date', '>', $batch->as_of_date)->orderByDesc('as_of_date')->first();

        if ($newer) {
            $batch->forceFill(['region_id' => $region->id, 'district_id' => $district->id])->save();

            return $this->block($batch, ['A newer file (as of '.Carbon::parse($newer->as_of_date)->format('d M Y').') has already been imported for '.$district->district_name.'. A file older than the current data cannot be applied; upload a current one.']);
        }

        $batch->forceFill(['region_id' => $region->id, 'district_id' => $district->id, 'phase' => CommercialCustomerBatch::PHASE_PARSE])->save();

        return true;
    }

    // ---------------------------------------------------------------- stage

    /** Streams the sheet into staging and reconciles it against its own totals. False = blocked. */
    protected function stage(CommercialCustomerBatch $batch, ?callable $tick): bool
    {
        $reader = new XlsxStreamReader(Storage::disk('local')->path($batch->file_path));
        $sheet = (string) $this->sheetName($reader);
        $district = District::query()->find($batch->district_id);

        $lookups = new CustomerLookups;
        $builder = new CustomerRecordBuilder($lookups, (int) $batch->region_id, (int) $batch->district_id, Carbon::parse($batch->as_of_date));
        $resumeAfter = (int) $batch->staged_through_row;

        $errors = [];
        $warnings = [];
        $routes = [];
        $countRead = 0;
        $malformed = 0;
        $buffer = [];
        $lastRow = $resumeAfter;
        $otherDistricts = [];
        $noRoute = false;
        $columnsMissing = false;

        $insertSeconds = 0.0;
        $buildSeconds = 0.0;

        $flush = function () use (&$buffer, &$lastRow, $batch, $tick, &$insertSeconds): void {
            if ($buffer === []) {
                return;
            }

            $started = microtime(true);
            $per = max(1, intdiv(30000, count($buffer[0])));

            // A hand-built multi-row INSERT: the query builder's per-row compilation costs more than the database does.
            $columns = implode(', ', array_map(fn (string $column) => "`{$column}`", array_keys($buffer[0])));
            $group = '('.implode(', ', array_fill(0, count($buffer[0]), '?')).')';

            DB::transaction(function () use ($buffer, $batch, $per, &$lastRow, $columns, $group): void {
                foreach (array_chunk($buffer, $per) as $part) {
                    DB::insert(
                        "INSERT INTO commercial_customer_staging ({$columns}) VALUES ".implode(', ', array_fill(0, count($part), $group)),
                        array_merge(...array_map('array_values', $part))
                    );
                }

                DB::table('commercial_customer_batches')->where('id', $batch->id)->update(['staged_through_row' => $lastRow]);
            });

            $buffer = [];
            $insertSeconds += microtime(true) - $started;

            if ($tick) {
                $tick($batch);
            }
        };

        try {
            foreach ((new CustomerListParser)->parse($reader->rows($sheet)) as $event) {
                switch ($event['kind']) {
                    case 'missing_columns':
                        $columnsMissing = true;
                        $errors[] = ['row' => 'Header', 'message' => 'Column(s) not found: '.implode(', ', $event['columns']).'.'];

                        break;

                    case 'heading':
                        $key = $this->routeKey($event['route']);
                        $routes[$key] ??= ['route' => $event['route'], 'read' => 0, 'balance' => 0, 'expected' => null, 'expected_balance' => null];

                        if ($event['district'] && $this->locations->normalize($event['district']) !== $this->locations->normalize((string) $batch->district_label_raw) && count($otherDistricts) < 5) {
                            $otherDistricts[$event['district']] = true;
                        }

                        break;

                    case 'totals':
                        $key = $this->routeKey($event['route']);
                        $routes[$key] ??= ['route' => $event['route'], 'read' => 0, 'balance' => 0, 'expected' => null, 'expected_balance' => null];
                        $routes[$key]['expected'] = $event['count'];
                        $routes[$key]['expected_balance'] = $event['balance'] === null ? null : (int) round($event['balance'] * 100);

                        break;

                    case 'malformed':
                        $malformed++;

                        if (count($warnings) < self::ISSUE_CAP) {
                            $warnings[] = ['row' => $event['row_no'], 'message' => $event['reason'].'.'];
                        }

                        break;

                    case 'row':
                        if ($columnsMissing) {
                            break;
                        }

                        $countRead++;
                        $routeName = $event['route'] ?? '(no route)';

                        if ($event['route'] === null && ! $noRoute) {
                            $noRoute = true;
                            $warnings[] = ['row' => $event['row_no'], 'message' => 'Customer rows appear before any route heading; they are filed under "(no route)".'];
                        }

                        $key = $this->routeKey($routeName);
                        $routes[$key] ??= ['route' => $routeName, 'read' => 0, 'balance' => 0, 'expected' => null, 'expected_balance' => null];
                        $routes[$key]['read']++;
                        $routes[$key]['balance'] += CustomerValues::pesewas($event['values']['balance'] ?? null) ?? 0;

                        if ($event['row_no'] <= $resumeAfter) {
                            break;   // already staged by an earlier run
                        }

                        $built = microtime(true);
                        $buffer[] = ['batch_id' => $batch->id, 'row_no' => $event['row_no']] + $builder->build($event['values'], $routeName);
                        $buildSeconds += microtime(true) - $built;
                        $lastRow = $event['row_no'];

                        if (count($buffer) >= self::STAGE_CHUNK) {
                            $flush();
                        }

                        break;
                }
            }

            $flush();
        } finally {
            $reader->close();
        }

        $this->timings['stage_insert'] = ($this->timings['stage_insert'] ?? 0) + $insertSeconds;
        $this->timings['stage_build'] = ($this->timings['stage_build'] ?? 0) + $buildSeconds;

        // ---- reconcile: every route against its own totals row
        $control = [];
        $routeErrors = 0;

        foreach ($routes as $route) {
            $row = [
                'route' => $route['route'], 'read' => $route['read'], 'expected' => $route['expected'],
                'read_balance' => $route['balance'], 'expected_balance' => $route['expected_balance'], 'ok' => true,
            ];

            if ($route['expected'] === null) {
                if ($route['read'] > 0) {
                    $warnings[] = ['row' => $route['route'], 'message' => 'No totals row was found for this route, so its '.$route['read'].' customers could not be checked.'];
                }
            } elseif ($route['expected'] !== $route['read']) {
                $row['ok'] = false;
                $routeErrors++;

                if (count($errors) < self::ISSUE_CAP) {
                    $errors[] = ['row' => $route['route'], 'message' => 'The route lists '.number_format($route['read']).' customers but its totals row says '.number_format($route['expected']).'.'];
                }
            }

            // Balances are compared to the pesewa after rounding each row; a GH¢1 slack covers a source that carries more decimals.
            if ($route['expected_balance'] !== null && abs($route['expected_balance'] - $route['balance']) > 100) {
                $row['ok'] = false;
                $routeErrors++;

                if (count($errors) < self::ISSUE_CAP) {
                    $errors[] = ['row' => $route['route'], 'message' => 'The balances add up to GH¢ '.number_format($route['balance'] / 100, 2).' but the totals row says GH¢ '.number_format($route['expected_balance'] / 100, 2).'.'];
                }
            }

            $control[] = $row;
        }

        $duplicates = $this->duplicates($batch);

        if ($duplicates['count'] > 0) {
            $errors[] = ['row' => implode(', ', $duplicates['rows']), 'message' => number_format($duplicates['count']).' account number(s) appear more than once in the file. Fix the export and upload it again.'];
        }

        if ($malformed > 0) {
            $warnings[] = ['row' => 'File', 'message' => number_format($malformed).' row(s) could not be read (no usable account number); they were not imported.'];
        }

        $phones = $builder->phoneStats;

        if (array_sum($phones) > 0) {
            // Counts only, never a number.
            $warnings[] = ['row' => 'Mobile', 'message' => 'Mobile cells: '.number_format($phones['multi']).' hold two or more numbers; '
                .number_format($phones['recovered']).' number(s) were separated from a run of digits, '.number_format($phones['repaired']).' had a stray 0 after 233 removed, '
                .number_format($phones['placeholders']).' placeholder number(s) (such as all one digit) were set aside.'];
        }

        if ($countRead === 0 && ! $columnsMissing) {
            $errors[] = ['row' => 'File', 'message' => 'No customer rows were found in the sheet.'];
        }

        foreach ($otherDistricts as $label => $flag) {
            $warnings[] = ['row' => 'Heading', 'message' => 'A route heading names the district "'.$label.'", not '.$batch->district_label_raw.'. Every row is filed under '.$batch->district_label_raw.'.'];
        }

        foreach (['categories' => 'category code(s)', 'statuses' => 'account status code(s)', 'meter_statuses' => 'meter status code(s)'] as $key => $label) {
            if ($lookups->created[$key] !== []) {
                $warnings[] = ['row' => 'Lookup', 'message' => 'New '.$label.' found and added for review: '.implode(', ', array_slice(array_unique($lookups->created[$key]), 0, 10)).'.'];
            }
        }

        $batch->forceFill([
            'rows_read' => $countRead,
            'rows_malformed' => $malformed,
            'duplicate_accounts' => $duplicates['count'],
            'control_totals' => array_slice($control, 0, 500),
            'errors' => array_slice($errors, 0, self::ISSUE_CAP),
            'warnings' => array_slice($warnings, 0, self::ISSUE_CAP),
            'reconciliation_passed' => $errors === [],
        ])->save();

        if ($errors !== []) {
            CustomerMergeService::clearStaging($batch->id);
            $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_BLOCKED, 'phase' => CommercialCustomerBatch::PHASE_DONE, 'finished_at' => now()])->save();
            $this->lifecycle->discardFile($batch);

            return false;
        }

        $batch->forceFill(['phase' => CommercialCustomerBatch::PHASE_MERGE])->save();

        return true;
    }

    /** @return array{count: int, rows: list<int>} accounts that occur more than once in the staged file, and a few row numbers */
    protected function duplicates(CommercialCustomerBatch $batch): array
    {
        $total = (int) DB::table('commercial_customer_staging')->where('batch_id', $batch->id)->count();
        $distinct = (int) DB::table('commercial_customer_staging')->where('batch_id', $batch->id)->distinct()->count('account_no');

        if ($total === $distinct) {
            return ['count' => 0, 'rows' => []];
        }

        $rows = DB::table('commercial_customer_staging AS s')->where('s.batch_id', $batch->id)
            ->whereIn('s.account_no', fn ($q) => $q->from('commercial_customer_staging')->select('account_no')->where('batch_id', $batch->id)->groupBy('account_no')->havingRaw('COUNT(*) > 1'))
            ->orderBy('s.row_no')->limit(10)->pluck('s.row_no')->all();

        return ['count' => $total - $distinct, 'rows' => array_map('intval', $rows)];
    }

    // ---------------------------------------------------------------- helpers

    protected function sheetName(XlsxStreamReader $reader): ?string
    {
        $candidates = array_values(array_filter($reader->sheetNames(), fn (string $name) => ! preg_match('/^\s*document\s*map\s*$/i', $name)));

        foreach ($candidates as $name) {
            if (preg_match('/rptCustomer|customer/i', $name)) {
                return $name;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    protected function routeKey(string $route): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $route) ?? $route));
    }

    /** @return Generator<int, list<mixed>> */
    protected function take(iterable $rows, int $limit): Generator
    {
        foreach ($rows as $number => $row) {
            if ($limit-- <= 0) {
                return;
            }

            yield $number => $row;
        }
    }

    /** @param  list<string>  $messages */
    protected function block(CommercialCustomerBatch $batch, array $messages): bool
    {
        $batch->forceFill([
            'status' => CommercialCustomerBatch::STATUS_BLOCKED,
            'phase' => CommercialCustomerBatch::PHASE_DONE,
            'errors' => array_map(fn (string $message) => ['row' => 'File', 'message' => $message], $messages),
            'finished_at' => now(),
        ])->save();
        $this->lifecycle->discardFile($batch);

        return false;
    }

    protected function park(CommercialCustomerBatch $batch, string $message): bool
    {
        $batch->forceFill([
            'status' => CommercialCustomerBatch::STATUS_NEEDS_MATCH,
            'phase' => CommercialCustomerBatch::PHASE_PARSE,
            'warnings' => [['row' => 'File', 'message' => $message]],
        ])->save();

        return false;
    }
}
