<?php

namespace App\Services\Commercial\BillingDetails;

use App\Models\CommercialImportBatch;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Models\Permission;
use App\Models\User;
use App\Services\Commercial\BatchLifecycleService;
use App\Services\Commercial\CommercialImportException;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\LocationMatcher;
use App\Services\Commercial\ReportFileReader;
use App\Services\Commercial\ReportImportService;
use App\Services\Commercial\RouteResolver;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The Detailed Billing Report (rptBillingDetails_ExP; design 8.39): one row per customer, about 67,000 per region and month, about 133 MB of
 * sheet XML. It is read in the BACKGROUND, like the customer list, and it never keeps a customer:
 *
 *   register()   a cheap look at the top of the file (period, region, columns), the duplicate check, the stored copy and a QUEUED batch
 *   process()    the job: ONE streamed pass (BillingDetailsScan) -> reconcile -> write the rollups, bands and capped lists -> either wait for
 *                the person to check them (the "review" tick) or go live. Safe to run again: it clears what an earlier try left first.
 *   confirm()    a checked batch goes live (the customer list is linked, the staging table emptied)
 *   discard()    a batch that never went live is deleted with everything it wrote
 *
 * A batch is LIVE (counts in an analysis) only while its status is imported or superseded (CommercialImportBatch::scopeLive()); queued,
 * processing, awaiting_review, blocked and failed are not. The period rules are CommercialPeriods', exactly as billing and reading by route.
 * A district user's file keeps only their own district's routes (decided in the scan; the rest are skipped, counted, never stored).
 *
 * Nothing about a person is read from the file (no name, address, phone or meter number cell is mapped), and a failure message holds a class,
 * an SQLSTATE or one of our own sentences, never a SQL statement (its bindings would be account numbers).
 */
class BillingDetailsImportService extends ReportImportService
{
    public const PHASE_QUEUED = 'queued';

    public const PHASE_READING = 'reading';

    public const PHASE_WRITING = 'writing';

    public const PHASE_REVIEW = 'review';

    public const PHASE_LINKING = 'linking';

    public const PHASE_DONE = 'done';

    public const PHASE_BLOCKED = 'blocked';

    public const PHASE_FAILED = 'failed';

    /** The label a customer row gets when it comes before any route heading. */
    public const NO_ROUTE_HEADING = '(no route heading)';

    protected const STAGING = 'commercial_billing_detail_accounts';

    public function __construct(
        ReportFileReader $reader,
        LocationMatcher $locations,
        BatchLifecycleService $lifecycle,
        protected BillingDetailsParser $parser,
        protected BillingDetailsScan $scan,
        protected RouteResolver $routes,
    ) {
        parent::__construct($reader, $locations, $lifecycle);
    }

    // ---------------------------------------------------------------- looking at the file (cheap)

    /** Whether a workbook is a Detailed Billing Report (the title and header text of its first sheet; a streamed read of the first rows). */
    public function looksLike(string $path): bool
    {
        return $this->parser->detect($path) !== null;
    }

    /**
     * What the top of the file says and whether it may be uploaded: the period (any dates; a month name alone is the whole month), the
     * region, the columns, the effect on what is already loaded. No customer row is read.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $path, ?int $restrictToRegionId = null): array
    {
        $out = ['detected' => null, 'errors' => [], 'warnings' => [], 'period' => null, 'period_raw' => null, 'region' => null, 'region_label' => null, 'unresolved_region' => null, 'columns' => [], 'checks' => []];
        $detected = $this->parser->detect($path);

        if ($detected === null) {
            $out['errors'][] = ['row' => 'File', 'message' => 'This is not the Detailed Billing Report (rptBillingDetails_ExP): no sheet with an Account #, Bill Status, Month Total and Balance Due header was found.'];

            return $out;
        }

        $out['detected'] = $detected;
        $out['columns'] = $detected['fields'];

        if ($detected['missing'] !== []) {
            $out['errors'][] = ['row' => 'Layout', 'message' => 'Could not find the '.implode(', ', array_map([$this, 'columnName'], $detected['missing'])).' column header'.(count($detected['missing']) === 1 ? '' : 's').'.'];
        }

        $out['period_raw'] = $detected['period'];

        if ($detected['period'] === null) {
            $out['errors'][] = ['row' => 'Filters', 'message' => 'The BILL PERIOD line was not found in the file, so the upload cannot be given dates.'];
        } elseif (! ($out['period'] = $this->readPeriod($detected['period']))) {
            $out['errors'][] = ['row' => 'Filters', 'message' => "The BILL PERIOD \"{$detected['period']}\" could not be read as a month, a day or two dates."];
        }

        $out['region_label'] = $detected['region'];

        if ($detected['region'] === null) {
            $out['errors'][] = ['row' => 'Filters', 'message' => 'The REGION line was not found in the file, so the upload cannot be tied to a region.'];
        } elseif (! ($out['region'] = $this->locations->resolveRegion($detected['region']))) {
            $out['unresolved_region'] = $detected['region'];
            $out['errors'][] = ['row' => 'Filters', 'message' => "Region '{$detected['region']}' does not match any region. Map it to a region on the Report uploads screen once; it is remembered."];
        }

        if ($restrictToRegionId !== null && $out['region'] && $out['region']->id !== $restrictToRegionId) {
            $out['errors'][] = ['row' => 'Filters', 'message' => "This report is for {$out['region']->region_name}; you can only upload reports for your own region."];
        }

        if ($out['region'] && $out['period']) {
            $others = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->live()->where('region_id', $out['region']->id)->get()->keyBy('id');
            $effect = (new CommercialPeriods)->effectOfUpload($out['period']['from'], $out['period']['to'], $others->map(fn (CommercialImportBatch $b) => ['id' => $b->id, 'from' => $b->period_from->toDateString(), 'to' => $b->period_to->toDateString(), 'order' => $b->id])->values()->all());

            foreach ($effect['replaces'] as $id) {
                $out['warnings'][] = ['row' => 'Existing data', 'message' => "Batch #{$id} already covers this region and exactly this period. This newer file replaces it in every view; the earlier batch stays on record."];
            }

            foreach ($effect['shadows'] as $id) {
                $out['warnings'][] = ['row' => 'Existing data', 'message' => "This period contains batch #{$id} (".CommercialPeriods::describe($others[$id]->period_from, $others[$id]->period_to).'): this newer file replaces it in every view. That batch stays on record.'];
            }

            foreach ($effect['overlaps'] as $id) {
                $out['warnings'][] = ['row' => 'Existing data', 'message' => "This period overlaps batch #{$id} (".CommercialPeriods::describe($others[$id]->period_from, $others[$id]->period_to).') only in part. Both are kept; a view that includes both uses the newest upload first, so some days may then be left to the older one.'];
            }
        }

        $out['checks'] = [
            ['label' => 'Detailed Billing Report recognised', 'passed' => true, 'detail' => 'sheet '.$detected['sheet'].', '.count($detected['fields']).' columns found'],
            ['label' => 'Bill period read', 'passed' => $out['period'] !== null, 'detail' => $out['period'] ? CommercialPeriods::describe($out['period']['from'], $out['period']['to']).' ('.CommercialPeriods::label($out['period']['from'], $out['period']['to']).')' : ($detected['period'] ?? 'not found')],
            ['label' => 'Region recognised', 'passed' => $out['region'] !== null, 'detail' => $out['region']?->region_name ?? ($detected['region'] ?? 'not found')],
        ];

        return $out;
    }

    /**
     * The answer the generic Uploads preview gives for this file: what it is and that it must go through the background form (a 130 MB sheet
     * is never read inside a page request). Blocked, so the Import button of the generic form stays off.
     *
     * @return array<string, mixed>
     */
    public function preview(string $path, ?int $restrictToRegionId = null): array
    {
        $info = $this->inspect($path, $restrictToRegionId);
        $errors = $info['errors'];
        $period = $info['period'];

        if ($errors === [] || $info['detected'] !== null) {
            $errors[] = ['row' => 'File', 'message' => 'This is the Detailed Billing Report (rptBillingDetails_ExP). It is read in the background: upload it with the "Detailed billing report" form below this one. Its routes, districts, customers and bill-status counts are shown on the upload\'s own page before anything is loaded.'];
        }

        return $this->finish([
            'report_type' => CommercialImportBatch::TYPE_BILLING_DETAILS,
            'errors' => $errors,
            'warnings' => $info['warnings'],
            'checks' => $info['checks'],
            'total_rows' => 0, 'valid_count' => 0, 'matched_count' => 0, 'unmatched_count' => 0, 'system_count' => 0,
            'region' => ['raw' => $info['region_label'], 'id' => $info['region']?->id, 'name' => $info['region']?->region_name],
            'unresolved_region' => $info['unresolved_region'],
            'period' => ['from' => $period['from'] ?? null, 'to' => $period['to'] ?? null],
            'months' => [], 'will_replace' => null, 'preview_rows' => [],
            'details_report' => ['period_label' => $period ? CommercialPeriods::describe($period['from'], $period['to']) : null, 'period_kind' => $period ? CommercialPeriods::label($period['from'], $period['to']) : null, 'columns' => count($info['columns'])],
            'parsed' => [],
        ]);
    }

    // ---------------------------------------------------------------- registering an upload

    /**
     * Stores the file and creates its QUEUED batch (the caller dispatches the job). A file already uploaded gives back that batch.
     *
     * @param  list<int>|null  $districtIds  a district user keeps only their own district's routes; kept on the batch so the job applies it
     * @return array{batch: CommercialImportBatch, duplicate: bool}
     *
     * @throws CommercialImportException when the file cannot be uploaded (the message says why)
     */
    public function register(string $sourcePath, string $originalName, ?User $actor, ?string $notes = null, bool $review = false, ?int $restrictToRegionId = null, ?array $districtIds = null): array
    {
        $hash = (string) hash_file('sha256', $sourcePath);

        if ($existing = $this->duplicateOf($hash)) {
            return ['batch' => $existing, 'duplicate' => true];
        }

        $info = $this->inspect($sourcePath, $restrictToRegionId);

        if ($info['errors'] !== []) {
            throw new CommercialImportException(implode(' ', array_map(fn (array $e) => $e['message'], array_slice($info['errors'], 0, 3))));
        }

        $period = $info['period'];
        $path = 'commercial/billing-details/'.$hash.'.xlsx';
        Storage::disk('local')->put($path, fopen($sourcePath, 'r'));

        $batch = CommercialImportBatch::query()->create([
            'report_type' => CommercialImportBatch::TYPE_BILLING_DETAILS,
            'region_id' => $info['region']->id,
            'region_label_raw' => $info['region_label'],
            'period_from' => $period['from'],
            'period_to' => $period['to'],
            'granularity' => CommercialPeriods::label($period['from'], $period['to']) === CommercialPeriods::MONTH ? CommercialImportBatch::GRANULARITY_MONTHLY : CommercialImportBatch::GRANULARITY_MULTI_MONTH,
            'source_filename' => mb_substr($originalName, 0, 255),
            'file_path' => $path,
            'file_hash' => $hash,
            'status' => CommercialImportBatch::STATUS_QUEUED,
            'phase' => self::PHASE_QUEUED,
            'review' => $review,
            'imported_by' => $actor?->id,
            'notes' => $notes ? mb_substr($notes, 0, 2000) : null,
            'control_totals' => ['district_filter' => $districtIds, 'period_raw' => $info['period_raw'], 'columns' => $info['columns'], 'warnings' => array_map(fn (array $w) => $w['message'], $info['warnings'])],
        ]);

        Audit::log(
            action: 'commercial.billing_details_uploaded',
            module: Permission::MODULE_COMMERCIAL,
            targetType: CommercialImportBatch::class,
            targetId: $batch->id,
            metadata: ['region_id' => $batch->region_id, 'period_from' => $period['from'], 'period_to' => $period['to'], 'size_mb' => round(filesize($sourcePath) / 1048576, 1), 'review' => $review, 'district_limited' => $districtIds !== null]
        );

        return ['batch' => $batch, 'duplicate' => false];
    }

    // ---------------------------------------------------------------- the background run

    /**
     * Reads the stored file in one streamed pass, checks it against its own totals and writes the rollups, bands and capped lists. A batch
     * that is blocked by the reconciliation stays blocked (nothing is written); one that finishes either waits for the person to check it
     * (the "review" tick) or goes live. Running it again clears what an earlier try wrote and starts over, so it is safe to retry.
     *
     * @throws CommercialImportException with a safe message when the run fails (the batch is then marked failed)
     */
    public function process(CommercialImportBatch $batch): CommercialImportBatch
    {
        $batch = CommercialImportBatch::query()->findOrFail($batch->id);

        if (! in_array($batch->status, [CommercialImportBatch::STATUS_QUEUED, CommercialImportBatch::STATUS_PROCESSING, CommercialImportBatch::STATUS_FAILED, CommercialImportBatch::STATUS_BLOCKED], true)) {
            return $batch;   // already waiting for review, live or voided: nothing to run
        }

        if (! $batch->file_path || ! Storage::disk('local')->exists($batch->file_path)) {
            $this->markFailed($batch, 'The stored file is no longer there. Discard this upload and upload the file again.');

            throw new CommercialImportException('The stored file is no longer there.');
        }

        $batch->forceFill(['status' => CommercialImportBatch::STATUS_PROCESSING, 'phase' => self::PHASE_READING, 'progress' => ['rows' => 0, 'routes' => 0], 'error_message' => null, 'started_at' => now(), 'finished_at' => null])->save();
        $this->clearOutput($batch);

        try {
            $control = $batch->control_totals ?? [];
            $detected = $this->parser->detect(Storage::disk('local')->path($batch->file_path));

            if ($detected === null) {
                throw new CommercialImportException('The stored file is no longer recognised as a Detailed Billing Report.');
            }

            $scan = $this->scan->run(Storage::disk('local')->path($batch->file_path), [
                'sheet' => $detected['sheet'],
                'batch_id' => $batch->id,
                'region_id' => $batch->region_id,
                'period_from' => $batch->period_from->toDateString(),
                'period_to' => $batch->period_to->toDateString(),
                'district_ids' => $control['district_filter'] ?? null,
            ], function (array $progress) use ($batch): void {
                DB::table('commercial_import_batches')->where('id', $batch->id)->update(['progress' => json_encode($progress + ['at' => now()->toDateTimeString()]), 'updated_at' => now()]);
            });

            $verdict = $this->evaluate($batch, $scan);
            $summary = $this->summary($batch, $scan, $verdict);

            if ($verdict['errors'] !== []) {
                $this->clearOutput($batch);
                $batch->forceFill([
                    'status' => CommercialImportBatch::STATUS_BLOCKED, 'phase' => self::PHASE_BLOCKED, 'error_message' => mb_substr($verdict['errors'][0]['message'], 0, 255),
                    'row_count' => $scan['rows'], 'warning_count' => count($verdict['warnings']), 'reconciliation_passed' => false, 'control_totals' => $summary + ['errors' => array_map(fn (array $e) => $e['message'], $verdict['errors'])],
                    'finished_at' => now(),
                ])->save();

                return $batch;
            }

            $batch->forceFill(['phase' => self::PHASE_WRITING])->save();
            $this->write($batch, $scan);

            $batch->forceFill([
                'row_count' => $scan['rows'], 'matched_count' => $scan['rows'], 'warning_count' => count($verdict['warnings']), 'reconciliation_passed' => $verdict['passed'],
                'control_totals' => $summary, 'progress' => ['rows' => $scan['rows_seen'], 'routes' => count($scan['routes'])],
            ])->save();

            if ($batch->review) {
                $batch->forceFill(['status' => CommercialImportBatch::STATUS_AWAITING_REVIEW, 'phase' => self::PHASE_REVIEW, 'finished_at' => now()])->save();

                return $batch;
            }

            return $this->complete($batch);
        } catch (Throwable $e) {
            $message = $this->safeMessage($e);
            $this->markFailed($batch, $message);
            Log::warning('Billing details import failed', ['batch_id' => $batch->id, 'exception' => $e::class, 'sqlstate' => $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : null, 'phase' => $batch->phase]);

            throw new CommercialImportException($message);
        }
    }

    /**
     * The reconciliation (the Phase 8a tolerance): a route whose rows disagree with its own totals row is FLAGGED and the file still imports;
     * it is blocked only when more than commercial_reconcile_max_route_failures_pct of the routes fail, or a grand total is off by more than
     * commercial_reconcile_grand_total_tolerance_pct. Also blocked: a missing column, or no customer row at all.
     *
     * @param  array<string, mixed>  $scan
     * @return array{errors: list<array{row: string, message: string}>, warnings: list<array{row: string, message: string}>, passed: bool}
     */
    public function evaluate(CommercialImportBatch $batch, array $scan): array
    {
        $errors = [];
        $warnings = [];
        $limit = $this->routeFailureLimitPct();
        $grand = $scan['grand'];

        if ($scan['missing_columns'] !== []) {
            $errors[] = ['row' => 'Layout', 'message' => 'Could not find the '.implode(', ', array_map([$this, 'columnName'], $scan['missing_columns'])).' column header'.(count($scan['missing_columns']) === 1 ? '' : 's').'.'];
        }

        if ($scan['rows'] === 0) {
            $errors[] = ['row' => 'File', 'message' => $scan['skipped_rows'] > 0 ? 'None of the routes in this file belong to your district, so nothing can be imported. Rows of other districts are skipped.' : 'No customer rows were found, so there is nothing to import.'];
        }

        $failedPct = $grand['checked_routes'] === 0 ? 0.0 : $grand['failed_routes'] / $grand['checked_routes'] * 100;

        if ($grand['failed_routes'] > 0 && $failedPct > $limit) {
            $errors[] = ['row' => 'Reconciliation', 'message' => number_format($grand['failed_routes']).' of '.number_format($grand['checked_routes']).' routes ('.number_format($failedPct, 1).'%) disagree with their own totals row, which is more than the '.rtrim(rtrim(number_format($limit, 2), '0'), '.').'% of routes a file may have.'];
        }

        foreach (['count' => 'Customer count', 'month_total' => 'Month total', 'paid' => 'Paid this month', 'balance_due' => 'Balance due'] as $measure => $label) {
            $rows = $grand['rows'][$measure];
            $reported = $grand['reported'][$measure];
            $factor = $measure === 'count' ? 1 : 100;

            if ($grand['checked_routes'] > 0 && $this->beyondGrandTolerance($rows / $factor, $reported / $factor)) {
                $errors[] = ['row' => 'Reconciliation', 'message' => $label.': the customer rows add up to '.number_format($rows / $factor, $factor === 1 ? 0 : 2).' but the routes\' totals rows add up to '.number_format($reported / $factor, $factor === 1 ? 0 : 2).', more than the '.rtrim(rtrim(number_format((float) config('gwl.commercial_reconcile_grand_total_tolerance_pct', 0.5), 2), '0'), '.').'% a grand total may differ by.'];
            }
        }

        $flagged = array_values(array_filter($scan['routes'], fn (array $r) => $r['failed']));

        foreach (array_slice($flagged, 0, 12) as $route) {
            $warnings[] = ['row' => 'Route '.$route['label'], 'message' => 'Route '.$route['label'].' is flagged: '.$this->describeDiffs($route['diffs']).' (the route is kept).'];
        }

        if (count($flagged) > 12) {
            $warnings[] = ['row' => 'Reconciliation', 'message' => 'and '.(count($flagged) - 12).' more flagged routes.'];
        }

        $unplaced = [];

        foreach ($scan['routes'] as $route) {
            if (! $route['skipped'] && $route['district_id'] === null && $route['label'] !== self::NO_ROUTE_HEADING) {
                $unplaced[$route['district_label'] ?? '(no district in the label)'] = ($unplaced[$route['district_label'] ?? '(no district in the label)'] ?? 0) + 1;
            }
        }

        foreach ($unplaced as $label => $count) {
            $warnings[] = ['row' => 'District', 'message' => "District \"{$label}\" is not recognised in ".($batch->region?->region_name ?? 'this region').": {$count} route(s) are imported without a district. Resolve the district on the upload's page and they are placed."];
        }

        $noTotals = count(array_filter($scan['routes'], fn (array $r) => ! $r['skipped'] && $r['totals'] === null && $r['customers'] > 0));

        if ($noTotals > 0) {
            $warnings[] = ['row' => 'Reconciliation', 'message' => $noTotals.' route(s) have no totals row, so they could not be checked against it.'];
        }

        if ($scan['duplicates'] > 0) {
            $warnings[] = ['row' => 'Integrity', 'message' => number_format($scan['duplicates']).' account number(s) appear more than once in the file (both rows are counted, as the file\'s own totals count them).'];
        }

        if ($scan['malformed'] > 0) {
            $warnings[] = ['row' => 'Integrity', 'message' => number_format($scan['malformed']).' row(s) have no usable account number and were not read.'];
        }

        if ($scan['orphan_rows'] > 0) {
            $warnings[] = ['row' => 'Layout', 'message' => number_format($scan['orphan_rows']).' customer row(s) come before any route heading and are kept under "'.self::NO_ROUTE_HEADING.'".'];
        }

        if ($scan['unknown_statuses'] !== []) {
            $warnings[] = ['row' => 'Lookups', 'message' => 'Account status code(s) not in the status lookups: '.implode(', ', array_slice($scan['unknown_statuses'], 0, 10)).'. They are counted but not classed as active or billing.'];
        }

        if ($scan['skipped_rows'] > 0) {
            $warnings[] = ['row' => 'District', 'message' => number_format($scan['skipped_rows']).' customer row(s) in '.number_format($scan['skipped_routes']).' route(s) of other districts were skipped (not yours); nothing of them was stored.'];
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'passed' => $errors === [] && $flagged === []];
    }

    // ---------------------------------------------------------------- what the batch page and the review show

    /**
     * Counts and lists for the batch page (stored in control_totals): never a name, address, phone or meter number; account numbers only in the
     * capped exception lists.
     *
     * @param  array<string, mixed>  $scan
     * @param  array<string, mixed>  $verdict
     * @return array<string, mixed>
     */
    protected function summary(CommercialImportBatch $batch, array $scan, array $verdict): array
    {
        $kept = array_values(array_filter($scan['routes'], fn (array $r) => ! $r['skipped']));
        $money = fn (int $index) => round(array_sum(array_column($scan['rollups'], $index)) / 100, 2);

        return [
            'district_filter' => $batch->control_totals['district_filter'] ?? null,
            'period_raw' => $batch->control_totals['period_raw'] ?? ($scan['filters']['period'] ?? null),
            'columns' => $batch->control_totals['columns'] ?? [],
            'scan' => [
                'rows_seen' => $scan['rows_seen'], 'customers' => $scan['rows'], 'skipped_rows' => $scan['skipped_rows'], 'skipped_routes' => $scan['skipped_routes'],
                'routes' => count(array_filter($kept, fn (array $r) => $r['label'] !== self::NO_ROUTE_HEADING)), 'districts' => count(array_unique($scan['district_keys'])),
                'malformed' => $scan['malformed'], 'duplicates' => $scan['duplicates'], 'orphan_rows' => $scan['orphan_rows'],
                'bill_status' => $scan['counts']['bill'], 'customer_status' => $scan['counts']['status'], 'meter_status' => $scan['counts']['meter'], 'category' => $scan['counts']['category'],
                'unknown_statuses' => $scan['unknown_statuses'], 'unknown_categories' => $scan['unknown_categories'],
                'month_total' => $money(BillingDetailsScan::C_MONTH_TOTAL), 'paid_this_month' => $money(BillingDetailsScan::C_PAID), 'balance_due' => $money(BillingDetailsScan::C_BALANCE),
                'integrity' => [
                    'components_off' => $this->columnSum($scan['rollups'], BillingDetailsScan::C_COMPONENTS), 'negative_consume' => $this->columnSum($scan['rollups'], BillingDetailsScan::C_NEGATIVE),
                    'bill_month_outside' => $this->columnSum($scan['rollups'], BillingDetailsScan::C_OUTSIDE), 'tariff_mismatch' => $this->columnSum($scan['rollups'], BillingDetailsScan::C_TARIFF_MISMATCH),
                    'duplicates' => $scan['duplicates'],
                ],
            ],
            'routes_checked' => $scan['grand']['checked_routes'], 'routes_failed' => $scan['grand']['failed_routes'],
            'grand' => ['rows' => $scan['grand']['rows'], 'reported' => $scan['grand']['reported']],
            'routes' => array_map(fn (array $r) => ['seq' => $r['seq'], 'label' => $r['label'], 'route_id' => $r['route_id'], 'district_id' => $r['district_id'], 'district_label' => $r['district_label'], 'customers' => $r['customers'], 'failed' => $r['failed']], $kept),
            'flagged_routes' => array_map(fn (array $r) => ['label' => $r['label'], 'district_id' => $r['district_id'], 'diffs' => $r['diffs']], array_slice(array_values(array_filter($kept, fn (array $r) => $r['failed'])), 0, 200)),
            'concentration' => $this->concentrationRows($scan['concentration']),
            'warnings' => array_map(fn (array $w) => $w['message'], array_slice($verdict['warnings'], 0, 100)),
            'checks' => [
                ['label' => 'Bill period read', 'passed' => true, 'detail' => CommercialPeriods::describe($batch->period_from, $batch->period_to)],
                ['label' => 'Every route adds up to its own totals row', 'passed' => $scan['grand']['failed_routes'] === 0, 'detail' => $scan['grand']['checked_routes'].' route(s) checked'.($scan['grand']['failed_routes'] === 0 ? '' : ', '.$scan['grand']['failed_routes'].' flagged')],
                ['label' => 'Each account appears once', 'passed' => $scan['duplicates'] === 0, 'detail' => $scan['duplicates'] === 0 ? number_format($scan['rows']).' customers' : number_format($scan['duplicates']).' repeated'],
                ['label' => 'Month total is the five charges added', 'passed' => $this->columnSum($scan['rollups'], BillingDetailsScan::C_COMPONENTS) === 0, 'detail' => $this->columnSum($scan['rollups'], BillingDetailsScan::C_COMPONENTS).' customer(s) off by more than 5 pesewas'],
            ],
            'link' => null,
        ];
    }

    /** @param  array<string, list<int>>  $rollups */
    protected function columnSum(array $rollups, int $index): int
    {
        return (int) array_sum(array_column($rollups, $index));
    }

    /**
     * @param  list<array{district: string, category: string, owing: int, amount: int, top_n: int, top_amount: int}>  $rows
     * @return list<array<string, mixed>>
     */
    protected function concentrationRows(array $rows): array
    {
        return array_map(fn (array $r) => ['district' => $r['district'], 'category' => $r['category'], 'owing' => $r['owing'], 'amount' => round($r['amount'] / 100, 2), 'top_n' => $r['top_n'], 'top_amount' => round($r['top_amount'] / 100, 2)], $rows);
    }

    /** @param  array<string, array{0: int|string, 1: int|string}>  $diffs */
    public function describeDiffs(array $diffs): string
    {
        $labels = ['count' => 'customers', 'month_total' => 'month total', 'paid' => 'paid this month', 'balance_due' => 'balance due'];

        return implode('; ', array_map(function (string $key) use ($diffs, $labels): string {
            [$rows, $reported] = $diffs[$key];
            $format = fn ($value) => $key === 'count' ? number_format((int) $value) : number_format($value / 100, 2);

            return ($labels[$key] ?? $key).' '.$format($rows).' on the rows, '.$format($reported).' on the totals row';
        }, array_keys($diffs)));
    }

    // ---------------------------------------------------------------- writing

    /**
     * Writes the rollups, bands and capped lists of a scan in one transaction (a failure leaves nothing). The staging table is already filled
     * by the scan and stays until the batch goes live or is discarded.
     *
     * @param  array<string, mixed>  $scan
     */
    protected function write(CommercialImportBatch $batch, array $scan): void
    {
        $byLabel = [];

        foreach ($scan['routes'] as $route) {
            if (! $route['skipped']) {
                $byLabel[$route['label']] ??= $route;
            }
        }

        DB::transaction(function () use ($batch, $scan, $byLabel): void {
            $this->clearOutput($batch, staging: false);
            $now = now();
            $money = array_flip(BillingDetailsScan::MONEY);

            // rows are inserted a chunk at a time, so the insert rows (a few KB each) never exist all at once
            $buffer = [];
            $flush = function (string $table, int $size) use (&$buffer): void {
                if (count($buffer) >= $size) {
                    DB::table($table)->insert($buffer);
                    $buffer = [];
                }
            };

            foreach ($scan['rollups'] as $key => $values) {
                [$label, $category, $status, $meter, $bill] = explode("\x1f", $key);
                $route = $byLabel[$label] ?? null;
                $row = [
                    'batch_id' => $batch->id, 'route_id' => $route['route_id'] ?? null, 'district_id' => $route['district_id'] ?? null, 'route_label_raw' => $label,
                    'district_label_raw' => $route['district_label'] ?? null, 'category_code' => $category, 'customer_status' => $status, 'meter_status' => $meter, 'bill_status' => $bill,
                ];

                foreach (BillingDetailsScan::COLUMNS as $index => $column) {
                    $row[$column] = isset($money[$index]) ? $this->decimal($values[$index], 100, 2) : ($index === BillingDetailsScan::C_CONSUME ? $this->decimal($values[$index], 1000, 3) : $values[$index]);
                }

                $buffer[] = $row;
                $flush('commercial_billing_detail_rollups', 100);
            }

            $flush('commercial_billing_detail_rollups', 1);

            foreach ($scan['bands'] as $key => $byBand) {
                [$dKey, $category] = explode("\x1f", $key, 2);

                foreach ($byBand as $band => [$customers, $consumeK]) {
                    $buffer[] = [
                        'batch_id' => $batch->id, 'district_id' => ctype_digit($dKey) ? (int) $dKey : null, 'district_key' => $dKey, 'category_code' => $category, 'band' => (string) $band,
                        'customers' => $customers, 'consume' => $this->decimal($consumeK, 1000, 3),
                    ];
                    $flush('commercial_billing_detail_bands', 200);
                }
            }

            $flush('commercial_billing_detail_bands', 1);

            foreach ($scan['issues'] as $kind => $byD) {
                foreach ($byD as $list) {
                    foreach ($list as [, $account, $seq, $amount]) {
                        $route = $scan['routes'][$seq];
                        $buffer[] = [
                            'batch_id' => $batch->id, 'kind' => $kind, 'account_no' => $account, 'route_id' => $route['route_id'], 'route_label_raw' => $route['label'],
                            'district_id' => $route['district_id'], 'district_label_raw' => (string) ($route['district_label'] ?? ''), 'amount' => $this->decimal($amount, 100, 2), 'created_at' => $now,
                        ];
                        $flush('commercial_billing_detail_issues', 300);
                    }
                }
            }

            $flush('commercial_billing_detail_issues', 1);
        });
    }

    protected function decimal(int $scaled, int $factor, int $places): string
    {
        return number_format($scaled / $factor, $places, '.', '');
    }

    /** Removes everything a run wrote for a batch (a retry starts clean). */
    protected function clearOutput(CommercialImportBatch $batch, bool $staging = true): void
    {
        DB::table('commercial_billing_detail_rollups')->where('batch_id', $batch->id)->delete();
        DB::table('commercial_billing_detail_bands')->where('batch_id', $batch->id)->delete();
        DB::table('commercial_billing_detail_issues')->where('batch_id', $batch->id)->delete();

        if ($staging) {
            DB::table(self::STAGING)->where('batch_id', $batch->id)->delete();
        }
    }

    // ---------------------------------------------------------------- going live, or not

    /** A batch the person has checked goes live. */
    public function confirm(CommercialImportBatch $batch): CommercialImportBatch
    {
        $batch = CommercialImportBatch::query()->findOrFail($batch->id);

        if ($batch->status !== CommercialImportBatch::STATUS_AWAITING_REVIEW) {
            throw new CommercialImportException('Only an upload that is waiting for its check can be loaded.');
        }

        return $this->complete($batch);
    }

    /**
     * Makes the batch live: the customer list is linked (ops_route_id), the superseded label is recomputed, the staging table and the stored
     * workbook are removed, and one audit row of counts is written.
     */
    public function complete(CommercialImportBatch $batch): CommercialImportBatch
    {
        $batch->forceFill(['phase' => self::PHASE_LINKING])->save();
        $link = $this->linkCustomers($batch);

        return DB::transaction(function () use ($batch, $link): CommercialImportBatch {
            $control = $batch->control_totals ?? [];
            $control['link'] = $link;

            $batch->forceFill([
                'status' => CommercialImportBatch::STATUS_IMPORTED, 'phase' => self::PHASE_DONE, 'imported_at' => now(), 'finished_at' => $batch->finished_at ?? now(), 'error_message' => null, 'control_totals' => $control,
            ])->save();

            $changes = $this->lifecycle->refreshStatuses($batch->region_id, $batch->report_type, $batch->id);

            if ($changes['superseded'] !== []) {
                $batch->update(['supersedes_batch_id' => max($changes['superseded'])]);
            }

            DB::table(self::STAGING)->where('batch_id', $batch->id)->delete();

            if ($batch->file_path) {
                Storage::disk('local')->delete($batch->file_path);
            }

            Audit::log(
                action: 'commercial.batch_imported',
                module: Permission::MODULE_COMMERCIAL,
                targetType: CommercialImportBatch::class,
                targetId: $batch->id,
                metadata: [
                    'report_type' => $batch->report_type, 'region_id' => $batch->region_id, 'source_filename' => $batch->source_filename,
                    'period_from' => $batch->period_from->toDateString(), 'period_to' => $batch->period_to->toDateString(),
                    'customers' => (int) ($control['scan']['customers'] ?? 0), 'routes' => (int) ($control['scan']['routes'] ?? 0), 'districts' => (int) ($control['scan']['districts'] ?? 0),
                    'flagged_routes' => (int) ($control['routes_failed'] ?? 0), 'duplicates' => (int) ($control['scan']['duplicates'] ?? 0), 'warnings' => $batch->warning_count,
                    'reconciliation_passed' => $batch->reconciliation_passed, 'linked_accounts' => (int) ($link['linked'] ?? 0),
                ]
            );

            return $batch->refresh();
        });
    }

    /**
     * Deletes a batch that never went live (waiting for its check, blocked or failed) with everything it wrote. A running batch cannot be
     * discarded, and a live one is voided instead.
     */
    public function discard(CommercialImportBatch $batch, ?int $actorId = null): void
    {
        $batch = CommercialImportBatch::query()->findOrFail($batch->id);

        if (! in_array($batch->status, [CommercialImportBatch::STATUS_AWAITING_REVIEW, CommercialImportBatch::STATUS_BLOCKED, CommercialImportBatch::STATUS_FAILED], true)) {
            throw new CommercialImportException('Only an upload that is waiting for its check, blocked or failed can be discarded. A loaded one is voided.');
        }

        DB::transaction(function () use ($batch, $actorId): void {
            Audit::log(
                action: 'commercial.billing_details_discarded',
                module: Permission::MODULE_COMMERCIAL,
                targetType: CommercialImportBatch::class,
                targetId: $batch->id,
                metadata: ['status' => $batch->status, 'region_id' => $batch->region_id, 'period_from' => $batch->period_from->toDateString(), 'period_to' => $batch->period_to->toDateString(), 'actor_id' => $actorId]
            );

            $this->clearOutput($batch);

            if ($batch->file_path) {
                Storage::disk('local')->delete($batch->file_path);
            }

            $batch->delete();
        });
    }

    /** Puts a failed or blocked batch back in the queue (the caller dispatches the job). */
    public function requeue(CommercialImportBatch $batch): CommercialImportBatch
    {
        $batch = CommercialImportBatch::query()->findOrFail($batch->id);

        if (! in_array($batch->status, [CommercialImportBatch::STATUS_FAILED, CommercialImportBatch::STATUS_BLOCKED], true)) {
            throw new CommercialImportException('Only a failed or blocked upload can be run again.');
        }

        $batch->forceFill(['status' => CommercialImportBatch::STATUS_QUEUED, 'phase' => self::PHASE_QUEUED, 'error_message' => null, 'finished_at' => null])->save();

        return $batch;
    }

    // ---------------------------------------------------------------- the customer list link (ops_route_id)

    /**
     * Fills commercial_customers.ops_route_id (the canonical route of the billing row) for the accounts of this file that exist in the customer
     * list, one route at a time (set-based), and only for the districts this batch is the NEWEST of (the greatest period, then the latest upload).
     * It does NOT change who sees which customer (that is Phase 8e, behind a setting). Reports how many accounts were in billing but not in the list
     * and how many accounts of the list's districts were not in the file.
     *
     * @return array<string, mixed>
     */
    public function linkCustomers(CommercialImportBatch $batch): array
    {
        if (! config('gwl.commercial_customer_list_enabled') || ! Schema::hasTable('commercial_customers') || ! Schema::hasColumn('commercial_customers', 'ops_route_id')) {
            return ['available' => false, 'reason' => 'The customer list is switched off, so there is nothing to link.'];
        }

        $staged = (int) DB::table(self::STAGING)->where('batch_id', $batch->id)->count();
        $inList = (int) DB::table(self::STAGING.' as s')->join('commercial_customers as c', 'c.account_no', '=', 's.account_no')->where('s.batch_id', $batch->id)->count();

        if ($inList === 0 && DB::table('commercial_customers')->limit(1)->doesntExist()) {
            return ['available' => false, 'reason' => 'No customer list has been loaded yet, so there is nothing to link. Load it and upload this report again to link the accounts.', 'accounts' => $staged];
        }

        $linked = 0;
        $older = 0;
        $districts = [];
        $newest = [];

        foreach ($batch->control_totals['routes'] ?? [] as $route) {
            if ($route['route_id'] === null || $route['district_id'] === null) {
                continue;
            }

            $districts[$route['district_id']] = true;
            $newest[$route['district_id']] ??= $this->isNewestFor($batch, (int) $route['district_id']);

            if (! $newest[$route['district_id']]) {
                $older++;

                continue;
            }

            $linked += DB::affectingStatement(
                'UPDATE commercial_customers SET ops_route_id = ? WHERE account_no IN (SELECT account_no FROM '.self::STAGING.' WHERE batch_id = ? AND route_seq = ?) AND (ops_route_id IS NULL OR ops_route_id <> ?)',
                [$route['route_id'], $batch->id, $route['seq'], $route['route_id']]
            );
        }

        $notInFile = $districts === [] ? 0 : (int) DB::table('commercial_customers as c')
            ->whereIn('c.district_id', array_keys($districts))
            ->whereNull('c.missing_since_batch_id')
            ->whereNotExists(fn ($q) => $q->from(self::STAGING.' as s')->whereColumn('s.account_no', 'c.account_no')->where('s.batch_id', $batch->id))
            ->count();

        return [
            'available' => true, 'accounts' => $staged, 'in_customer_list' => $inList, 'not_in_customer_list' => $staged - $inList, 'linked' => $linked,
            'list_accounts_not_in_file' => $notInFile, 'routes_left_to_a_newer_upload' => $older,
        ];
    }

    /** True when no other live upload of the region has data for the district with a later period (or the same period and a later id). */
    protected function isNewestFor(CommercialImportBatch $batch, int $districtId): bool
    {
        return ! CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->live()
            ->where('region_id', $batch->region_id)->whereKeyNot($batch->id)
            ->whereExists(fn ($q) => $q->from('commercial_billing_detail_rollups as r')->whereColumn('r.batch_id', 'commercial_import_batches.id')->where('r.district_id', $districtId))
            ->where(fn ($q) => $q->whereDate('period_to', '>', $batch->period_to->toDateString())->orWhere(fn ($same) => $same->whereDate('period_to', $batch->period_to->toDateString())->where('id', '>', $batch->id)))
            ->exists();
    }

    // ---------------------------------------------------------------- districts that were not known

    /**
     * Places the routes whose district nobody could place at upload time (after an alias was added): their rollups, bands and lists get the
     * route and district, and the stored summary follows. Returns how many routes were placed.
     */
    public function rematchBatch(CommercialImportBatch $batch): int
    {
        $labels = $batch->detailRollups()->whereNull('route_id')->where('route_label_raw', '!=', self::NO_ROUTE_HEADING)->distinct()->pluck('route_label_raw');
        $cache = [];
        $placed = 0;
        $control = $batch->control_totals ?? [];

        foreach ($labels as $label) {
            $found = $this->routes->place((string) $label, $batch->region_id, $cache);

            if ($found['district_id'] === null || $found['code'] === null) {
                continue;
            }

            $route = $this->routes->resolveIn(District::query()->findOrFail($found['district_id']), $found['code'], CommercialRoute::SOURCE_BILLING);
            $oldKey = 'l:'.($found['district_label'] ?? '');
            $newKey = (string) $route->district_id;

            $batch->detailRollups()->where('route_label_raw', $label)->update(['route_id' => $route->id, 'district_id' => $route->district_id]);
            $batch->detailIssues()->where('route_label_raw', $label)->update(['route_id' => $route->id, 'district_id' => $route->district_id]);

            // the bands and the concentration of an unplaced district are keyed by its printed label: they move to the district with its first route
            // (a band the district already has takes the unplaced customers added to it; the one-off concentration entry only moves when the district has none)
            foreach (DB::table('commercial_billing_detail_bands')->where('batch_id', $batch->id)->where('district_key', $oldKey)->get() as $band) {
                $target = DB::table('commercial_billing_detail_bands')->where('batch_id', $batch->id)->where('district_key', $newKey)->where('category_code', $band->category_code)->where('band', $band->band);

                if ($target->exists()) {
                    $target->update(['customers' => DB::raw('customers + '.(int) $band->customers), 'consume' => DB::raw('consume + '.(float) $band->consume)]);
                    DB::table('commercial_billing_detail_bands')->where('id', $band->id)->delete();
                } else {
                    DB::table('commercial_billing_detail_bands')->where('id', $band->id)->update(['district_id' => $route->district_id, 'district_key' => $newKey]);
                }
            }

            foreach ($control['routes'] ?? [] as $i => $r) {
                if ($r['label'] === $label) {
                    $control['routes'][$i]['route_id'] = $route->id;
                    $control['routes'][$i]['district_id'] = $route->district_id;
                }
            }

            foreach ($control['concentration'] ?? [] as $i => $c) {
                $has = collect($control['concentration'])->contains(fn (array $x) => $x['district'] === $newKey && $x['category'] === $c['category']);

                if ($c['district'] === $oldKey && ! $has) {
                    $control['concentration'][$i]['district'] = $newKey;
                }
            }

            $placed++;
        }

        $batch->forceFill(['control_totals' => $control])->save();

        return $placed;
    }

    // ---------------------------------------------------------------- helpers

    protected function markFailed(CommercialImportBatch $batch, string $message): void
    {
        $batch->forceFill(['status' => CommercialImportBatch::STATUS_FAILED, 'phase' => self::PHASE_FAILED, 'error_message' => mb_substr($message, 0, 255), 'finished_at' => now()])->save();
    }

    /** A message a person may read and a log may keep: one of our own sentences, an SQLSTATE or a class name. Never the exception's own text (a SQL statement holds account numbers). */
    public function safeMessage(Throwable $e): string
    {
        if ($e instanceof CommercialImportException) {
            return $e->getMessage();
        }

        if ($e instanceof QueryException) {
            return 'A database error stopped the import (SQLSTATE '.($e->errorInfo[0] ?? '?').'). Run it again; if it keeps failing, tell the administrator.';
        }

        return 'The import stopped unexpectedly ('.class_basename($e).'). Run it again; if it keeps failing, tell the administrator.';
    }

    protected function columnName(string $field): string
    {
        return [
            'account' => 'Account #', 'status' => 'Status', 'bill_status' => 'Bill Status', 'consume' => 'Consume', 'month_total' => 'Month Total',
            'previous_balance' => 'Previous Balance', 'paid' => 'Paid This Month', 'balance_due' => 'Balance Due',
        ][$field] ?? $field;
    }

    /**
     * "September-2026 - September-2026" (a month name alone is the whole month), "01-September-2026 - 14-September-2026" or one day, as
     * ['from' => Y-m-d, 'to' => Y-m-d]; null when it cannot be read.
     *
     * @return array{from: string, to: string}|null
     */
    public function readPeriod(string $text): ?array
    {
        $parts = preg_split('/\s+(?:-|–|—|to)\s+/iu', trim($text));

        if (! is_array($parts) || count($parts) < 1 || count($parts) > 2 || trim($parts[0]) === '') {
            return null;
        }

        $from = $this->bound($parts[0], false);
        $to = $this->bound($parts[count($parts) - 1], true);

        return $from && $to && ! $to->lessThan($from) ? ['from' => $from->toDateString(), 'to' => $to->toDateString()] : null;
    }

    protected function bound(string $text, bool $end): ?Carbon
    {
        $text = trim($text);

        try {
            $month = null;

            if (preg_match('/^([A-Za-z]{3,9})[\s\-\/]+(\d{4})$/', $text, $m)) {
                $month = Carbon::parse('1 '.$m[1].' '.$m[2]);
            } elseif (preg_match('/^(\d{4})-(\d{1,2})$/', $text, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
                $month = Carbon::create((int) $m[1], (int) $m[2], 1);
            } elseif (preg_match('/^(\d{1,2})\/(\d{4})$/', $text, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
                $month = Carbon::create((int) $m[2], (int) $m[1], 1);
            }

            if ($month) {
                return $end ? $month->copy()->endOfMonth()->startOfDay() : $month->startOfDay();
            }

            return Carbon::parse($text)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
