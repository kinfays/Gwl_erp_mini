<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Models\CommercialCustomerUpload;
use App\Models\District;
use App\Models\Region;
use App\Models\User;
use App\Services\Commercial\LocationMatcher;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The customer-list import, as a resumable pipeline whose memory does not depend on the size of the file.
 *
 * A FILE may hold one district, several, or every district of a region (Phase 8b), so a file is a PARENT record
 * (CommercialCustomerUpload) and every district found in it has ONE CHILD batch (CommercialCustomerBatch) that is exactly what a
 * one-district file always was: its own district lock, phases, undo data, control totals and rollups.
 *
 *   register()  store the file, create the parent and its first batch (queued); the same file twice is the same upload
 *   discover    a first, cheap pass: read only the FILTERS block, count the customer rows under every route heading, resolve every
 *               heading's district (an unknown one PARKS the file as "needs a match" before any customer row is staged), apply the
 *               uploader's scope and the "older than the current data" rule per district, and create one child batch per district.
 *               With review = true the file then waits ("awaiting_review") until the person has seen the districts and confirmed
 *   stage       ONE pass over the sheet: every customer row goes to the child of the district its route heading names; each route is
 *               checked against its own TOTALS row
 *   per child   merge, missing, rollup, complete: the existing code, one child at a time under that district's own lock. A failure in
 *               one district stops that child only; the others carry on
 *
 * Only the districts that are in the file are ever touched: a district that is not in it keeps all its data and nothing in it is
 * marked missing. Each phase stores its progress on the batch (and the stage on the parent), so a job that dies is simply run again
 * and carries on. No exception that leaves this class carries customer data: failures are re-thrown as a sanitized message.
 */
class CustomerImportService
{
    /** Staging rows committed together (the resume point moves with each). */
    protected const STAGE_CHUNK = 2000;

    /** Issues kept on the batch before the rest are only counted. */
    protected const ISSUE_CAP = 40;

    /** Seconds spent in each phase of the last process() call (the benchmark reports them). @var array<string, float> */
    public array $timings = [];

    /** How many times the workbook was opened for reading rows (first pass + staging passes), and how many of them were staging passes. A test pins "the staging pass reads the file once". */
    public int $sheetReads = 0;

    public int $stageReads = 0;

    public function __construct(
        protected LocationMatcher $locations,
        protected CustomerMergeService $merge,
        protected CustomerRollupService $rollups,
        protected CustomerBatchLifecycle $lifecycle,
        protected CustomerConsumptionHistory $history,
    ) {
    }

    // ---------------------------------------------------------------- upload

    /**
     * Stores the file and creates the parent upload with its first batch. Returns the existing upload's first batch when this exact
     * file is already live or being processed (the checksum is the identity), so uploading twice never does the work twice.
     *
     * With $review the file stops after the first pass and waits for the person to confirm the districts it holds.
     *
     * @return array{batch: CommercialCustomerBatch, upload: CommercialCustomerUpload, duplicate: bool}
     */
    public function register(string $sourcePath, string $originalName, string $periodType, Carbon $asOf, ?User $actor, ?string $notes = null, bool $review = false): array
    {
        if (! in_array($periodType, [CommercialCustomerBatch::PERIOD_DAILY, CommercialCustomerBatch::PERIOD_WEEKLY, CommercialCustomerBatch::PERIOD_MONTHLY], true)) {
            throw new CustomerImportException('The period must be daily, weekly or monthly.');
        }

        $hash = (string) hash_file('sha256', $sourcePath);
        $existing = CommercialCustomerUpload::query()->where('file_hash', $hash)->whereNotIn('status', [CommercialCustomerUpload::STATUS_VOIDED, CommercialCustomerUpload::STATUS_FAILED, CommercialCustomerUpload::STATUS_BLOCKED])->orderByDesc('id')->get()
            // The same bytes are a duplicate while the earlier upload is still in flight, or while any of its districts is still that district's newest data.
            // Once newer data has replaced it, identical content is a legitimate new upload (the base came back to that state).
            ->first(fn (CommercialCustomerUpload $upload) => $this->stillCounts($upload));

        if ($existing && ($first = $existing->batches()->first())) {
            return ['batch' => $first, 'upload' => $existing, 'duplicate' => true];
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

        $periodKey = CommercialCustomerBatch::periodKey($periodType, $asOf);
        $notes = $notes ? mb_substr($notes, 0, 2000) : null;

        [$upload, $batch] = DB::transaction(function () use ($path, $hash, $originalName, $periodType, $periodKey, $asOf, $notes, $actor, $review): array {
            $upload = CommercialCustomerUpload::query()->create([
                'source_filename' => mb_substr($originalName, 0, 255), 'file_path' => $path, 'file_hash' => $hash, 'as_of_date' => $asOf->toDateString(),
                'period_type' => $periodType, 'period_key' => $periodKey, 'status' => CommercialCustomerUpload::STATUS_QUEUED, 'phase' => CommercialCustomerUpload::PHASE_QUEUED,
                'review' => $review, 'notes' => $notes, 'imported_by' => $actor?->id,
            ]);

            // The upload's first batch: it takes the first district the file turns out to hold (a one-district file has just this one).
            $batch = CommercialCustomerBatch::query()->create($this->childAttributes($upload) + ['status' => CommercialCustomerBatch::STATUS_QUEUED, 'phase' => CommercialCustomerBatch::PHASE_QUEUED]);

            return [$upload, $batch];
        });

        return ['batch' => $batch, 'upload' => $upload, 'duplicate' => false];
    }

    /** The columns every batch of an upload shares with its parent. @return array<string, mixed> */
    protected function childAttributes(CommercialCustomerUpload $upload): array
    {
        return [
            'upload_id' => $upload->id, 'period_type' => $upload->period_type, 'period_key' => $upload->period_key, 'as_of_date' => $upload->as_of_date->toDateString(),
            'source_filename' => $upload->source_filename, 'file_hash' => $upload->file_hash, 'notes' => $upload->notes, 'imported_by' => $upload->imported_by,
        ];
    }

    /** True while the upload is in flight, waiting on a person, or any of its districts is still that district's newest data. */
    protected function stillCounts(CommercialCustomerUpload $upload): bool
    {
        if ($upload->isWorking() || in_array($upload->status, [CommercialCustomerUpload::STATUS_NEEDS_MATCH, CommercialCustomerUpload::STATUS_AWAITING_REVIEW], true)) {
            return true;
        }

        return $upload->batches()->get()->contains(fn (CommercialCustomerBatch $batch) => $batch->isLive() && $this->lifecycle->newest((int) $batch->district_id)?->id === $batch->id);
    }

    // ---------------------------------------------------------------- process

    /**
     * Runs (or resumes) the pipeline of the upload this batch belongs to and returns the batch as it now is. Safe to call again after a
     * failure or a restart.
     *
     * @param  callable(CommercialCustomerBatch): void|null  $tick  called as work progresses (the job keeps its lock alive)
     *
     * @throws CustomerDistrictBusy when another batch of one of the file's districts is being processed (the rest were done)
     */
    public function process(CommercialCustomerBatch $batch, ?callable $tick = null): CommercialCustomerBatch
    {
        $batch->refresh();

        $this->processUpload($this->uploadOf($batch), $tick);

        return CommercialCustomerBatch::query()->find($batch->id) ?? $batch;
    }

    /**
     * Runs (or resumes) a whole upload: discovery, the one staging pass, then every district's child. A child that fails does not stop
     * the others; once they are all settled the first failure (or a busy district) is reported by an exception, so the job is tried again
     * and carries on with exactly the children that are not done.
     *
     * @param  callable(CommercialCustomerBatch): void|null  $tick
     *
     * @throws CustomerDistrictBusy
     * @throws CustomerImportException
     */
    public function processUpload(CommercialCustomerUpload $upload, ?callable $tick = null): CommercialCustomerUpload
    {
        $upload->refresh();

        if (in_array($upload->status, [CommercialCustomerUpload::STATUS_IMPORTED, CommercialCustomerUpload::STATUS_BLOCKED, CommercialCustomerUpload::STATUS_VOIDED, CommercialCustomerUpload::STATUS_AWAITING_REVIEW], true)) {
            return $upload;
        }

        if ($upload->phase === CommercialCustomerUpload::PHASE_DONE && $this->runnable($upload)->isEmpty()) {
            return $upload;
        }

        $upload->forceFill(['error_message' => null, 'started_at' => $upload->started_at ?? now()])->save();

        try {
            if (in_array($upload->phase, [CommercialCustomerUpload::PHASE_QUEUED, CommercialCustomerUpload::PHASE_DISCOVER], true)) {
                $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_DISCOVERING, 'phase' => CommercialCustomerUpload::PHASE_DISCOVER])->save();

                if (! $this->timed('locate', fn () => $this->discover($upload))) {
                    return $upload->refresh();   // parked for a match, or blocked: both already recorded
                }

                if ($upload->review) {
                    $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_AWAITING_REVIEW, 'phase' => CommercialCustomerUpload::PHASE_REVIEW])->save();

                    return $upload;
                }

                $upload->forceFill(['phase' => CommercialCustomerUpload::PHASE_STAGE])->save();
            }

            if ($upload->phase === CommercialCustomerUpload::PHASE_REVIEW) {
                return $upload;   // waiting for confirm()
            }

            $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_PROCESSING])->save();

            if ($upload->phase === CommercialCustomerUpload::PHASE_STAGE) {
                $this->timed('stage', fn () => $this->stageUpload($upload, $tick));
                $upload->forceFill(['phase' => CommercialCustomerUpload::PHASE_CHILDREN])->save();
                $this->lifecycleDiscard($upload);   // the workbook holds personal data: it goes the moment it has been read
            }
        } catch (CustomerImportException $exception) {
            $this->failRunnable($upload, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $message = $this->sanitised($exception, 'parse');
            $this->failRunnable($upload, $message);

            throw new CustomerImportException($message);
        }

        // ---- the children, one at a time; a failure in one district does not stop the others
        $busy = false;
        $failure = null;

        foreach ($this->runnable($upload) as $child) {
            try {
                $this->runChild($child, $tick);
            } catch (CustomerDistrictBusy) {
                $busy = true;
            } catch (CustomerImportException $exception) {
                $failure ??= $exception;
            }
        }

        $this->settle($upload);

        if ($failure) {
            throw $failure;
        }

        if ($busy) {
            throw new CustomerDistrictBusy;
        }

        return $upload->refresh();
    }

    /** @return Collection<int, CommercialCustomerBatch> the children that still have work to do (staged or waiting to be), in id order */
    protected function runnable(CommercialCustomerUpload $upload): Collection
    {
        return $upload->batches()->whereIn('status', [CommercialCustomerBatch::STATUS_QUEUED, CommercialCustomerBatch::STATUS_PROCESSING, CommercialCustomerBatch::STATUS_FAILED])->get();
    }

    /** The upload of a batch; a batch from before Phase 8b, or one made by hand, gets a parent of its own. */
    protected function uploadOf(CommercialCustomerBatch $batch): CommercialCustomerUpload
    {
        if ($batch->upload_id && ($upload = CommercialCustomerUpload::query()->find($batch->upload_id))) {
            return $upload;
        }

        $upload = CommercialCustomerUpload::query()->create([
            'region_id' => $batch->region_id, 'region_label_raw' => $batch->region_label_raw, 'source_filename' => $batch->source_filename, 'file_path' => $batch->file_path,
            'file_hash' => $batch->file_hash, 'as_of_date' => $batch->as_of_date->toDateString(), 'period_type' => $batch->period_type, 'period_key' => $batch->period_key,
            'status' => $batch->status === CommercialCustomerBatch::STATUS_SUPERSEDED ? CommercialCustomerUpload::STATUS_IMPORTED : $batch->status,
            'phase' => $batch->file_path && ! $batch->isLive() ? CommercialCustomerUpload::PHASE_DISCOVER : CommercialCustomerUpload::PHASE_DONE,
            'notes' => $batch->notes, 'imported_by' => $batch->imported_by, 'districts_found' => $batch->district_id ? 1 : 0, 'districts_selected' => $batch->district_id ? 1 : 0,
        ]);

        $batch->forceFill(['upload_id' => $upload->id, 'file_path' => null])->save();

        return $upload;
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

    /** The message kept when an unexpected exception stops a step. The driver's own text can echo a value, so it is only added while debugging locally. */
    protected function sanitised(Throwable $exception, string $phase): string
    {
        $detail = $exception instanceof \Illuminate\Database\QueryException ? ' SQLSTATE '.($exception->errorInfo[0] ?? '?').'/'.($exception->errorInfo[1] ?? '?') : '';

        if (config('app.debug') && $exception instanceof \Illuminate\Database\QueryException) {
            $detail .= ': '.mb_substr((string) ($exception->errorInfo[2] ?? ''), 0, 200);
        } elseif (config('app.debug')) {
            $detail .= ': '.mb_substr($exception->getMessage(), 0, 200).' @ '.basename($exception->getFile()).':'.$exception->getLine();
        }

        return 'The import stopped in the "'.$phase.'" step ('.class_basename($exception).$detail.'). Run it again; it carries on where it stopped.';
    }

    /** A step that touches the whole file failed: every child that was waiting on it is marked failed (their resume points are safe). */
    protected function failRunnable(CommercialCustomerUpload $upload, string $message): void
    {
        foreach ($this->runnable($upload) as $child) {
            $this->fail($child, $message);
        }

        $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_FAILED, 'error_message' => mb_substr($message, 0, 500), 'finished_at' => now()])->save();
    }

    /**
     * Puts a parked ("needs a match") upload back on the queue after the location was matched. The first pass runs again (it is cheap)
     * and now finds every name it could not place before.
     */
    public function resumeAfterMatch(CommercialCustomerBatch|CommercialCustomerUpload $subject): void
    {
        $upload = $subject instanceof CommercialCustomerUpload ? $subject : $this->uploadOf($subject);

        $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_QUEUED, 'phase' => CommercialCustomerUpload::PHASE_DISCOVER, 'unrecognised' => null, 'error_message' => null])->save();

        foreach ($upload->batches()->where('status', CommercialCustomerBatch::STATUS_NEEDS_MATCH)->get() as $child) {
            $child->forceFill(['status' => CommercialCustomerBatch::STATUS_QUEUED, 'phase' => CommercialCustomerBatch::PHASE_PARSE])->save();
        }
    }

    /**
     * The person has seen the districts the file holds: only the ones in $districtIds (null = all of them) are loaded. A district left
     * out loses its (still empty) batch and nothing of it is ever read.
     *
     * @param  list<int>|null  $districtIds
     */
    public function confirm(CommercialCustomerUpload $upload, ?array $districtIds = null): CommercialCustomerUpload
    {
        $upload->refresh();

        if ($upload->status !== CommercialCustomerUpload::STATUS_AWAITING_REVIEW) {
            throw new CustomerImportException('This upload is not waiting for its districts to be checked.');
        }

        $entries = $upload->districts ?? [];
        $chosen = 0;

        foreach ($entries as $index => $entry) {
            if (($entry['state'] ?? null) !== 'load') {
                continue;
            }

            if ($districtIds !== null && ! in_array((int) $entry['district_id'], array_map('intval', $districtIds), true)) {
                $entries[$index]['state'] = 'unticked';

                if (! empty($entry['batch_id'])) {
                    CommercialCustomerBatch::query()->whereKey($entry['batch_id'])->where('status', CommercialCustomerBatch::STATUS_QUEUED)->delete();
                }

                $entries[$index]['batch_id'] = null;

                continue;
            }

            $chosen++;
        }

        $skipped = (int) collect($entries)->whereIn('state', ['unticked', 'not_yours', 'blocked'])->sum('rows');
        $upload->forceFill(['districts' => $entries, 'districts_selected' => $chosen, 'rows_skipped' => $skipped])->save();

        if ($chosen === 0) {
            $this->blockUpload($upload, ['No district was chosen, so nothing was loaded.']);

            return $upload->refresh();
        }

        $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_QUEUED, 'phase' => CommercialCustomerUpload::PHASE_STAGE])->save();

        return $upload;
    }

    /** The person who uploaded it (or a manager) drops an upload that is waiting for its districts to be checked: nothing was loaded, the file goes. */
    public function cancel(CommercialCustomerUpload $upload): CommercialCustomerUpload
    {
        $upload->refresh();

        if (! in_array($upload->status, [CommercialCustomerUpload::STATUS_AWAITING_REVIEW, CommercialCustomerUpload::STATUS_NEEDS_MATCH], true)) {
            throw new CustomerImportException('Only an upload that is waiting for a check or a match can be cancelled.');
        }

        $this->blockUpload($upload, ['This upload was cancelled before anything was loaded.']);

        return $upload->refresh();
    }

    // ---------------------------------------------------------------- discover

    /**
     * The first, cheap pass: reads the FILTERS block and counts the customer rows under every route heading, resolves each heading's
     * district, and creates one child batch per district. False = stop (parked for a match, or blocked); both are recorded on the upload.
     */
    protected function discover(CommercialCustomerUpload $upload): bool
    {
        $found = $this->scanFile($upload);

        if ($found['filters'] === null || ! $found['filters']['region']) {
            return $this->blockUpload($upload, ['The REGION line of the filter block was not found at the top of the sheet, so the file cannot be placed.']);
        }

        $upload->forceFill(['region_label_raw' => mb_substr($found['filters']['region'], 0, 120)])->save();
        $region = $this->locations->resolveRegion($found['filters']['region']);

        if (! $region) {
            return $this->parkUpload($upload, null, [], 'The region "'.$found['filters']['region'].'" is not recognised. Match it to a region to continue.');
        }

        $upload->forceFill(['region_id' => $region->id])->save();

        // A batch with no uploader (the console command, the benchmark) is a system load and unrestricted.
        $uploader = $upload->imported_by ? User::query()->find($upload->imported_by) : null;

        if ($uploader && ! CustomerScope::canSeeRegion($uploader, (int) $region->id)) {
            return $this->blockUpload($upload, ['This file is for the '.$region->region_name.' region, which your account may not load.']);
        }

        if ($found['missing_columns'] !== []) {
            return $this->blockUpload($upload, ['Column(s) not found: '.implode(', ', $found['missing_columns']).'.'], 'Header');
        }

        if ($found['rows'] === 0) {
            return $this->blockUpload($upload, ['No customer rows were found in the sheet.']);
        }

        // ---- which district is each heading's? Several spellings may be one district (an alias), so group by the district found.
        $places = [];            // district id => ['district' => District, 'labels' => [raw], 'keys' => [normalised], 'rows' => n, 'routes' => n]
        $unrecognised = [];      // raw label => ['label', 'rows', 'routes']
        $unplaceable = 0;

        foreach ($found['groups'] as $key => $group) {
            if ($key === '') {
                $unplaceable += $group['rows'];

                continue;
            }

            $district = $this->locations->resolveDistrict($group['label'], (int) $region->id);

            if (! $district) {
                $unrecognised[$group['label']] = ['label' => $group['label'], 'rows' => $group['rows'], 'routes' => count($group['routes'])];

                continue;
            }

            $place = $places[$district->id] ?? ['district' => $district, 'labels' => [], 'keys' => [], 'rows' => 0, 'routes' => []];
            $place['labels'][] = $group['label'];
            $place['keys'][] = (string) $key;
            $place['rows'] += $group['rows'];
            $place['routes'] = $place['routes'] + $group['routes'];
            $places[$district->id] = $place;
        }

        if ($unrecognised !== []) {
            $names = implode(', ', array_map(fn (array $u) => '"'.$u['label'].'" ('.number_format($u['rows']).' customers)', array_slice($unrecognised, 0, 8)));

            return $this->parkUpload($upload, $region, array_values($unrecognised), count($unrecognised) === 1
                ? 'The district "'.array_key_first($unrecognised).'" is not recognised in '.$region->region_name.'. Match it to a district to continue.'
                : count($unrecognised).' district names are not recognised in '.$region->region_name.': '.$names.'. Match each of them to a district to continue.');
        }

        if ($unplaceable > 0) {
            return $this->blockUpload($upload, [number_format($unplaceable).' customer rows sit under a route heading that names no district, and the file has no DISTRICT: line, so they cannot be placed.']);
        }

        // ---- the uploader's scope (CommercialScope: do not re-implement it), then the older-file rule, per district
        $entries = [];
        $notYours = 0;
        $notYoursRows = 0;
        $kept = [];

        foreach ($places as $districtId => $place) {
            if ($uploader && ! CustomerScope::canSeeDistrict($uploader, (int) $districtId, (int) $region->id)) {
                $notYours++;
                $notYoursRows += $place['rows'];
                // a district user is not told the name of another district: only that one was skipped
                $entries[] = ['label' => null, 'keys' => [], 'district_id' => null, 'name' => null, 'rows' => $place['rows'], 'routes' => count($place['routes']), 'state' => 'not_yours', 'batch_id' => null, 'note' => null];

                continue;
            }

            $kept[$districtId] = $place;
        }

        if ($kept === []) {
            $only = count($places) === 1 ? reset($places)['district'] : null;

            return $this->blockUpload($upload, [$only
                ? 'This file is for '.$only->district_name.', which is not your district: its rows were skipped and nothing was stored.'
                : 'This file has none of your district: '.$notYours.' other '.($notYours === 1 ? 'district' : 'districts').' skipped (not yours); nothing was stored.']);
        }

        $existing = $upload->batches()->get();
        $unassigned = $existing->filter(fn (CommercialCustomerBatch $b) => $b->district_id === null)->values();
        $loadable = 0;

        foreach ($kept as $districtId => $place) {
            /** @var District $district */
            $district = $place['district'];
            $child = $existing->firstWhere('district_id', $districtId) ?? $unassigned->shift() ?? new CommercialCustomerBatch($this->childAttributes($upload));
            $newer = CommercialCustomerBatch::query()->live()->where('district_id', $districtId)->whereDate('as_of_date', '>', $upload->as_of_date->toDateString())->orderByDesc('as_of_date')->first();

            $attributes = [
                'upload_id' => $upload->id, 'region_id' => $region->id, 'district_id' => $districtId, 'region_label_raw' => mb_substr((string) $found['filters']['region'], 0, 120),
                'district_label_raw' => mb_substr((string) $place['labels'][0], 0, 120),
            ];

            if ($newer) {
                $message = 'A newer file (as of '.Carbon::parse($newer->as_of_date)->format('d M Y').') has already been imported for '.$district->district_name.'. A file older than the current data cannot be applied; upload a current one.';
                $child->forceFill($attributes + $this->childAttributes($upload) + [
                    'status' => CommercialCustomerBatch::STATUS_BLOCKED, 'phase' => CommercialCustomerBatch::PHASE_DONE, 'errors' => [['row' => 'File', 'message' => $message]], 'finished_at' => now(),
                ])->save();
                $state = 'blocked';
                $note = $message;
            } else {
                // a child that has already begun (a resumed upload) keeps its phase and its resume points
                $fresh = $child->phase === null || in_array($child->phase, [CommercialCustomerBatch::PHASE_QUEUED, CommercialCustomerBatch::PHASE_PARSE], true);
                $child->forceFill($attributes + $this->childAttributes($upload) + ($fresh ? ['status' => CommercialCustomerBatch::STATUS_QUEUED, 'phase' => CommercialCustomerBatch::PHASE_PARSE, 'errors' => null] : []))->save();
                $state = 'load';
                $note = null;
                $loadable++;
            }

            $entries[] = [
                'label' => $place['labels'][0], 'keys' => $place['keys'], 'district_id' => $districtId, 'name' => $district->district_name, 'rows' => $place['rows'],
                'routes' => count($place['routes']), 'state' => $state, 'batch_id' => $child->id, 'note' => $note,
            ];
        }

        $routes = array_sum(array_column($entries, 'routes'));
        $warnings = [];

        if ($notYours > 0) {
            $warnings[] = ['row' => 'File', 'message' => $notYours.' other '.($notYours === 1 ? 'district' : 'districts').' skipped (not yours); nothing stored.'];
        }

        $upload->forceFill([
            'districts' => $entries, 'districts_found' => count($entries), 'districts_selected' => $loadable, 'routes_found' => $routes, 'rows_found' => $found['rows'],
            'rows_skipped' => $notYoursRows + (int) collect($entries)->where('state', 'blocked')->sum('rows'), 'unrecognised' => null, 'warnings' => $warnings ?: null, 'errors' => null,
        ])->save();

        if ($loadable === 0) {
            // every district of the file is older than its current data: nothing to load, the reasons are on each batch
            $this->settle($upload);
            $this->lifecycleDiscard($upload);
            $upload->forceFill(['phase' => CommercialCustomerUpload::PHASE_DONE])->save();

            return false;
        }

        return true;
    }

    /**
     * The first pass over the sheet. It builds no customer values: it reads the FILTERS block, every route heading and counts the rows
     * under each, grouped by the district the parser says they belong to.
     *
     * @return array{filters: array<string, mixed>|null, groups: array<string, array{label: string, rows: int, routes: array<string, bool>}>, rows: int, missing_columns: list<string>}
     */
    protected function scanFile(CommercialCustomerUpload $upload): array
    {
        $reader = new XlsxStreamReader(Storage::disk('local')->path((string) $upload->file_path));
        $sheet = (string) $this->sheetName($reader);
        $filters = null;
        $groups = [];
        $rows = 0;
        $missing = [];
        $this->sheetReads++;

        try {
            foreach ((new CustomerListParser)->parse($reader->rows($sheet), false) as $event) {
                switch ($event['kind']) {
                    case 'filters':
                        $filters = $event;

                        break;

                    case 'missing_columns':
                        $missing = array_values(array_unique([...$missing, ...$event['columns']]));

                        break;

                    case 'heading':
                        $label = $event['effective_district'];
                        $key = $label === null ? '' : $this->locations->normalize($label);
                        $groups[$key] ??= ['label' => (string) $label, 'rows' => 0, 'routes' => []];
                        $groups[$key]['routes'][$this->routeKey($event['route'])] = true;

                        break;

                    case 'row':
                        $label = $event['district'];
                        $key = $label === null ? '' : $this->locations->normalize($label);
                        $groups[$key] ??= ['label' => (string) $label, 'rows' => 0, 'routes' => []];
                        $groups[$key]['rows']++;
                        $groups[$key]['routes'][$this->routeKey($event['route'] ?? '(no route)')] = true;
                        $rows++;

                        break;
                }
            }
        } finally {
            $reader->close();
        }

        // a district named only by a heading that has no customers under it is not a district of this file
        $groups = array_filter($groups, fn (array $g) => $g['rows'] > 0);

        return ['filters' => $filters, 'groups' => $groups, 'rows' => $rows, 'missing_columns' => $missing];
    }

    // ---------------------------------------------------------------- stage

    /**
     * ONE pass over the sheet for every district that still needs staging: each customer row goes to the child of the district its
     * route heading names, in chunks that are committed together with every child's resume point. Memory stays flat (a chunk of
     * rows and a few counters per route); the time is that of one read of the file, however many districts it holds. Then every child
     * is reconciled against its own route totals: a route that disagrees is FLAGGED and the file still imports, and a child is blocked
     * only beyond the tolerance settings.
     */
    protected function stageUpload(CommercialCustomerUpload $upload, ?callable $tick): void
    {
        $children = $upload->batches()->whereIn('status', [CommercialCustomerBatch::STATUS_QUEUED, CommercialCustomerBatch::STATUS_PROCESSING, CommercialCustomerBatch::STATUS_FAILED])
            ->where('phase', CommercialCustomerBatch::PHASE_PARSE)->get()->keyBy('id');

        if ($children->isEmpty()) {
            return;
        }

        $lookups = new CustomerLookups;
        $asOf = Carbon::parse($upload->as_of_date);
        $state = [];           // child id => per-district counters
        $childByKey = [];      // normalised district label => child id

        foreach ($upload->districts ?? [] as $entry) {
            $childId = $entry['batch_id'] ?? null;

            if (($entry['state'] ?? null) !== 'load' || ! $childId || ! $children->has($childId)) {
                continue;
            }

            foreach ($entry['keys'] as $key) {
                $childByKey[$key] = (int) $childId;
            }

            /** @var CommercialCustomerBatch $child */
            $child = $children[$childId];
            $child->forceFill(['status' => CommercialCustomerBatch::STATUS_PROCESSING, 'error_message' => null, 'started_at' => $child->started_at ?? now()])->save();
            $state[(int) $childId] = [
                'batch' => $child, 'builder' => new CustomerRecordBuilder($lookups, (int) $child->region_id, (int) $child->district_id, $asOf),
                'resume' => (int) $child->staged_through_row, 'routes' => [], 'read' => 0, 'malformed' => 0, 'warnings' => [], 'noRoute' => false,
            ];
        }

        if ($state === []) {
            return;
        }

        $reader = new XlsxStreamReader(Storage::disk('local')->path((string) $upload->file_path));
        $sheet = (string) $this->sheetName($reader);
        $this->sheetReads++;
        $this->stageReads++;

        $buffer = [];
        $lastRow = [];        // child id => the last file row in the buffer
        $skipped = 0;
        $insertSeconds = 0.0;
        $buildSeconds = 0.0;
        $normalise = fn (?string $label) => $label === null ? '' : $this->locations->normalize($label);
        $blank = fn (string $route) => ['route' => $route, 'read' => 0, 'balance' => 0, 'expected' => null, 'expected_balance' => null];

        $flush = function () use (&$buffer, &$lastRow, &$insertSeconds, $tick, $children): void {
            if ($buffer === []) {
                return;
            }

            $started = microtime(true);
            $per = max(1, intdiv(30000, count($buffer[0])));

            // A hand-built multi-row INSERT: the query builder's per-row compilation costs more than the database does.
            $columns = implode(', ', array_map(fn (string $column) => "`{$column}`", array_keys($buffer[0])));
            $group = '('.implode(', ', array_fill(0, count($buffer[0]), '?')).')';

            DB::transaction(function () use ($buffer, $per, $columns, $group, $lastRow): void {
                foreach (array_chunk($buffer, $per) as $part) {
                    DB::insert(
                        "INSERT INTO commercial_customer_staging ({$columns}) VALUES ".implode(', ', array_fill(0, count($part), $group)),
                        array_merge(...array_map('array_values', $part))
                    );
                }

                // every child's resume point moves in the same transaction as its rows
                foreach ($lastRow as $childId => $row) {
                    DB::table('commercial_customer_batches')->where('id', $childId)->update(['staged_through_row' => $row]);
                }
            });

            $buffer = [];
            $lastRow = [];
            $insertSeconds += microtime(true) - $started;

            if ($tick && ($first = $children->first())) {
                $tick($first);
            }
        };

        try {
            foreach ((new CustomerListParser)->parse($reader->rows($sheet)) as $event) {
                $childId = $childByKey[$normalise($event['district'] ?? $event['effective_district'] ?? null)] ?? null;

                switch ($event['kind']) {
                    case 'heading':
                        $childId = $childByKey[$normalise($event['effective_district'])] ?? null;

                        if ($childId !== null) {
                            $state[$childId]['routes'][$this->routeKey($event['route'])] ??= $blank($event['route']);
                        }

                        break;

                    case 'totals':
                        if ($childId !== null) {
                            $key = $this->routeKey($event['route']);
                            $state[$childId]['routes'][$key] ??= $blank($event['route']);
                            $state[$childId]['routes'][$key]['expected'] = $event['count'];
                            $state[$childId]['routes'][$key]['expected_balance'] = $event['balance'] === null ? null : (int) round($event['balance'] * 100);
                        }

                        break;

                    case 'malformed':
                        if ($childId !== null) {
                            $state[$childId]['malformed']++;

                            if (count($state[$childId]['warnings']) < self::ISSUE_CAP) {
                                $state[$childId]['warnings'][] = ['row' => $event['row_no'], 'message' => $event['reason'].'.'];
                            }
                        }

                        break;

                    case 'row':
                        if ($childId === null) {
                            $skipped++;   // a district that is not being loaded (not yours, left out, or older than its data)

                            break;
                        }

                        $s = &$state[$childId];
                        $s['read']++;
                        $routeName = $event['route'] ?? '(no route)';

                        if ($event['route'] === null && ! $s['noRoute']) {
                            $s['noRoute'] = true;
                            $s['warnings'][] = ['row' => $event['row_no'], 'message' => 'Customer rows appear before any route heading; they are filed under "(no route)".'];
                        }

                        $key = $this->routeKey($routeName);
                        $s['routes'][$key] ??= $blank($routeName);
                        $s['routes'][$key]['read']++;
                        $s['routes'][$key]['balance'] += CustomerValues::pesewas($event['values']['balance'] ?? null) ?? 0;

                        if ($event['row_no'] <= $s['resume']) {
                            unset($s);

                            break;   // already staged by an earlier run
                        }

                        $built = microtime(true);
                        $buffer[] = ['batch_id' => $childId, 'row_no' => $event['row_no']] + $s['builder']->build($event['values'], $routeName);
                        $buildSeconds += microtime(true) - $built;
                        $lastRow[$childId] = $event['row_no'];
                        unset($s);

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

        // accounts that appear under more than one district of THIS file (one account is one customer: it can only be in one district)
        $cross = $this->crossDistrictDuplicates(array_keys($state));

        foreach ($state as $childId => $s) {
            $this->reconcile($s['batch'], $s, $lookups, $cross[$childId] ?? 0);
        }

        // (rows_skipped was counted by the first pass: $skipped here is the same rows seen again)
        $this->settle($upload);
    }

    /**
     * The routes that disagree with their own totals row in ANY district of the upload, together (the parent's view of its children).
     *
     * @return list<array{district: string, batch_id: int, route: string, read: int, expected: int|null, count_difference: int|null, balance_difference: float|null}>
     */
    public function flaggedRoutes(CommercialCustomerUpload $upload): array
    {
        $flagged = [];

        foreach ($upload->batches()->with('district')->get() as $batch) {
            foreach ($batch->control_totals ?? [] as $route) {
                if (! ($route['ok'] ?? true)) {
                    $flagged[] = [
                        'district' => $batch->district?->district_name ?? (string) $batch->district_label_raw, 'batch_id' => $batch->id, 'route' => (string) $route['route'],
                        'read' => (int) $route['read'], 'expected' => $route['expected'] ?? null, 'count_difference' => $route['count_difference'] ?? null, 'balance_difference' => $route['balance_difference'] ?? null,
                    ];
                }
            }
        }

        return $flagged;
    }

    /** @param  list<int>  $childIds @return array<int, int> child id => rows whose account also appears under another district of the file */
    protected function crossDistrictDuplicates(array $childIds): array
    {
        if (count($childIds) < 2) {
            return [];
        }

        return DB::table('commercial_customer_staging AS s')->whereIn('s.batch_id', $childIds)
            ->whereIn('s.account_no', fn ($q) => $q->from('commercial_customer_staging')->select('account_no')->whereIn('batch_id', $childIds)->groupBy('account_no')->havingRaw('COUNT(DISTINCT batch_id) > 1'))
            ->selectRaw('s.batch_id AS batch_id, COUNT(*) AS n')->groupBy('s.batch_id')->pluck('n', 'batch_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * One child's routes against its own totals rows. A route that disagrees is FLAGGED (listed in control_totals with the row and amount
     * differences) and the child still imports; it is blocked only when more than the allowed share of its routes fail, or its grand
     * total is off by more than the tolerance (both are Settings).
     *
     * @param  array<string, mixed>  $s  the child's counters from stageUpload()
     */
    protected function reconcile(CommercialCustomerBatch $batch, array $s, CustomerLookups $lookups, int $crossDuplicates): void
    {
        $errors = [];
        $warnings = $s['warnings'];
        $control = [];
        $failed = 0;
        $withTotals = 0;
        $sumRead = 0;
        $sumExpected = 0;
        $sumBalance = 0;
        $sumExpectedBalance = 0;

        foreach ($s['routes'] as $route) {
            $row = [
                'route' => $route['route'], 'read' => $route['read'], 'expected' => $route['expected'],
                'read_balance' => $route['balance'], 'expected_balance' => $route['expected_balance'], 'ok' => true,
                'count_difference' => null, 'balance_difference' => null,
            ];

            if ($route['expected'] === null) {
                if ($route['read'] > 0) {
                    $warnings[] = ['row' => $route['route'], 'message' => 'No totals row was found for this route, so its '.$route['read'].' customers could not be checked.'];
                }
            } else {
                $withTotals++;
                $sumRead += $route['read'];
                $sumExpected += (int) $route['expected'];

                if ($route['expected'] !== $route['read']) {
                    $row['ok'] = false;
                    $row['count_difference'] = $route['read'] - (int) $route['expected'];
                }
            }

            // Balances are compared to the pesewa after rounding each row; a GH¢1 slack covers a source that carries more decimals.
            if ($route['expected_balance'] !== null) {
                $sumBalance += $route['balance'];
                $sumExpectedBalance += $route['expected_balance'];

                if (abs($route['expected_balance'] - $route['balance']) > 100) {
                    $row['ok'] = false;
                    $row['balance_difference'] = round(($route['balance'] - $route['expected_balance']) / 100, 2);
                }
            }

            if (! $row['ok']) {
                $failed++;

                if (count($warnings) < self::ISSUE_CAP) {
                    $parts = [];

                    if ($row['count_difference'] !== null) {
                        $parts[] = 'lists '.number_format($route['read']).' customers but its totals row says '.number_format((int) $route['expected']).' ('.($row['count_difference'] > 0 ? '+' : '').$row['count_difference'].')';
                    }

                    if ($row['balance_difference'] !== null) {
                        $parts[] = 'balances add up to GH¢ '.number_format($route['balance'] / 100, 2).' but the totals row says GH¢ '.number_format($route['expected_balance'] / 100, 2);
                    }

                    $warnings[] = ['row' => $route['route'], 'message' => 'Flagged: the route '.implode('; ', $parts).'.'];
                }
            }

            $control[] = $row;
        }

        $failurePct = $withTotals > 0 ? $failed / $withTotals * 100 : 0.0;
        $limitPct = max(0.0, (float) config('gwl.commercial_reconcile_max_route_failures_pct', 2.0));
        $grandPct = max(0.0, (float) config('gwl.commercial_reconcile_grand_total_tolerance_pct', 0.5));

        if ($failed > 0 && $failurePct > $limitPct) {
            $errors[] = ['row' => 'Reconciliation', 'message' => number_format($failed).' of '.number_format($withTotals).' routes ('.number_format($failurePct, 1).'%) disagree with their own totals row, which is more than the '.rtrim(rtrim(number_format($limitPct, 2), '0'), '.').'% a file may have.'];
        }

        if ($withTotals > 0 && abs($sumRead - $sumExpected) > abs($sumExpected) * $grandPct / 100 + 1e-9) {
            $errors[] = ['row' => 'Reconciliation', 'message' => 'The file lists '.number_format($sumRead).' customers but its route totals add up to '.number_format($sumExpected).', more than the '.rtrim(rtrim(number_format($grandPct, 2), '0'), '.').'% the grand total may differ by.'];
        }

        if ($sumExpectedBalance !== 0 && abs($sumBalance - $sumExpectedBalance) > abs($sumExpectedBalance) * $grandPct / 100 + 100) {
            $errors[] = ['row' => 'Reconciliation', 'message' => 'The balances add up to GH¢ '.number_format($sumBalance / 100, 2).' but the route totals say GH¢ '.number_format($sumExpectedBalance / 100, 2).', more than the '.rtrim(rtrim(number_format($grandPct, 2), '0'), '.').'% the grand total may differ by.'];
        }

        // flagged routes first, so the cap never hides one
        usort($control, fn (array $a, array $b) => [$a['ok'] ? 1 : 0] <=> [$b['ok'] ? 1 : 0]);

        $duplicates = $this->duplicates($batch);

        if ($duplicates['count'] > 0) {
            $errors[] = ['row' => implode(', ', $duplicates['rows']), 'message' => number_format($duplicates['count']).' account number(s) appear more than once in the file. Fix the export and upload it again.'];
        }

        if ($crossDuplicates > 0) {
            $errors[] = ['row' => 'File', 'message' => number_format($crossDuplicates).' account number(s) of this district also appear under another district of the file. An account is in one district only: fix the export and upload it again.'];
        }

        if ($s['malformed'] > 0) {
            $warnings[] = ['row' => 'File', 'message' => number_format($s['malformed']).' row(s) could not be read (no usable account number); they were not imported.'];
        }

        /** @var CustomerRecordBuilder $builder */
        $builder = $s['builder'];
        $phones = $builder->phoneStats;

        if (array_sum($phones) > 0) {
            // Counts only, never a number.
            $warnings[] = ['row' => 'Mobile', 'message' => 'Mobile cells: '.number_format($phones['multi']).' hold two or more numbers; '
                .number_format($phones['recovered']).' number(s) were separated from a run of digits, '.number_format($phones['repaired']).' had a stray 0 after 233 removed, '
                .number_format($phones['placeholders']).' placeholder number(s) (such as all one digit) were set aside.'];
        }

        $emails = $builder->emailStats;

        if (array_sum($emails) > 0) {
            // Counts only, never an address.
            $warnings[] = ['row' => 'E-mail', 'message' => 'E-mail cells: '.number_format($emails['multi']).' hold two addresses (the first is the one used, the second is kept for search); '
                .number_format($emails['invalid']).' held a piece that is not a valid address (the valid ones were kept)'.($emails['extra'] > 0 ? '; '.number_format($emails['extra']).' address(es) beyond the second were dropped' : '').'.'];
        }

        if ($s['read'] === 0) {
            $errors[] = ['row' => 'File', 'message' => 'No customer rows were found in the sheet.'];
        }

        foreach (['categories' => 'category code(s)', 'statuses' => 'account status code(s)', 'meter_statuses' => 'meter status code(s)'] as $key => $label) {
            if ($lookups->created[$key] !== []) {
                $warnings[] = ['row' => 'Lookup', 'message' => 'New '.$label.' found and added for review: '.implode(', ', array_slice(array_unique($lookups->created[$key]), 0, 10)).'.'];
            }
        }

        $batch->forceFill([
            'rows_read' => $s['read'],
            'rows_malformed' => $s['malformed'],
            'duplicate_accounts' => $duplicates['count'],
            'control_totals' => array_slice($control, 0, 500),
            'errors' => array_slice($errors, 0, self::ISSUE_CAP),
            'warnings' => array_slice($warnings, 0, self::ISSUE_CAP),
            'reconciliation_passed' => $errors === [] && $failed === 0,
        ])->save();

        if ($errors !== []) {
            CustomerMergeService::clearStaging($batch->id);
            $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_BLOCKED, 'phase' => CommercialCustomerBatch::PHASE_DONE, 'finished_at' => now()])->save();

            return;
        }

        $batch->forceFill(['phase' => CommercialCustomerBatch::PHASE_MERGE])->save();
    }

    /** @return array{count: int, rows: list<int>} accounts that occur more than once in the staged file of this district, and a few row numbers */
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

    // ---------------------------------------------------------------- one district

    /**
     * merge, flag what is missing, roll up and complete ONE child, under its district's own lock. A failure here is that child's alone:
     * it is marked failed (its resume point is stored) and the others carry on.
     *
     * @throws CustomerDistrictBusy
     * @throws CustomerImportException
     */
    protected function runChild(CommercialCustomerBatch $batch, ?callable $tick): void
    {
        $batch->refresh();

        if (! in_array($batch->status, [CommercialCustomerBatch::STATUS_QUEUED, CommercialCustomerBatch::STATUS_PROCESSING, CommercialCustomerBatch::STATUS_FAILED], true)
            || ! in_array($batch->phase, [CommercialCustomerBatch::PHASE_MERGE, CommercialCustomerBatch::PHASE_MISSING, CommercialCustomerBatch::PHASE_ROLLUP], true)) {
            return;
        }

        $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_PROCESSING, 'error_message' => null, 'started_at' => $batch->started_at ?? now()])->save();
        $lock = Cache::lock('commercial-customers:district:'.$batch->district_id, 14400);

        if (! $lock->get()) {
            $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_QUEUED])->save();

            throw new CustomerDistrictBusy;
        }

        try {
            // another upload of this district may have landed since the first pass: the older-file rule is checked again under the lock
            if ($batch->phase === CommercialCustomerBatch::PHASE_MERGE && (int) $batch->merge_cursor === 0 && ($newer = $this->newerData($batch))) {
                CustomerMergeService::clearStaging($batch->id);
                $batch->forceFill([
                    'status' => CommercialCustomerBatch::STATUS_BLOCKED, 'phase' => CommercialCustomerBatch::PHASE_DONE, 'finished_at' => now(),
                    'errors' => [['row' => 'File', 'message' => 'A newer file (as of '.Carbon::parse($newer->as_of_date)->format('d M Y').') has already been imported for this district. A file older than the current data cannot be applied; upload a current one.']],
                ])->save();

                return;
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
                $this->timed('history', fn () => $this->history->record($batch, (new CustomerLookups)->billingStatusIds()));
                $this->timed('rollups', fn () => $this->rollups->build($batch, (new CustomerLookups)->billingStatusIds()));
                $this->timed('complete', fn () => $this->lifecycle->complete($batch));
            }
        } catch (CustomerDistrictBusy $busy) {
            throw $busy;
        } catch (CustomerImportException $exception) {
            $this->fail($batch, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            // The original message can carry bound values (customer data): keep the class and the phase only.
            $message = $this->sanitised($exception, (string) $batch->phase);
            $this->fail($batch, $message);

            throw new CustomerImportException($message);
        } finally {
            $lock->release();
        }
    }

    protected function newerData(CommercialCustomerBatch $batch): ?CommercialCustomerBatch
    {
        return CommercialCustomerBatch::query()->live()->where('district_id', $batch->district_id)->whereDate('as_of_date', '>', $batch->as_of_date)->orderByDesc('as_of_date')->first();
    }

    // ---------------------------------------------------------------- the upload's own state

    /** The upload's status and counters from its children (the one rule is CommercialCustomerUpload::statusFromBatches()). */
    public function settle(CommercialCustomerUpload $upload): void
    {
        $batches = $upload->batches()->get();
        $status = CommercialCustomerUpload::statusFromBatches($batches, $upload->status);
        $terminal = in_array($status, [CommercialCustomerUpload::STATUS_IMPORTED, CommercialCustomerUpload::STATUS_PARTIAL, CommercialCustomerUpload::STATUS_BLOCKED, CommercialCustomerUpload::STATUS_VOIDED], true);

        $upload->forceFill([
            'status' => $status,
            'phase' => $terminal ? CommercialCustomerUpload::PHASE_DONE : $upload->phase,
            'finished_at' => $terminal ? ($upload->finished_at ?? now()) : null,
        ])->save();

        if ($terminal) {
            $this->lifecycleDiscard($upload);
        }
    }

    /** The uploaded workbook holds personal data: it is deleted the moment it has been read (or the upload is blocked). */
    protected function lifecycleDiscard(CommercialCustomerUpload $upload): void
    {
        $this->lifecycle->discardFile($upload);
    }

    /** Blocks the whole upload (and every batch of it that has not finished), with the reasons shown on both. @param  list<string>  $messages */
    protected function blockUpload(CommercialCustomerUpload $upload, array $messages, string $row = 'File'): bool
    {
        $errors = array_map(fn (string $message) => ['row' => $row, 'message' => $message], $messages);

        foreach ($upload->batches()->whereNotIn('status', [CommercialCustomerBatch::STATUS_IMPORTED, CommercialCustomerBatch::STATUS_SUPERSEDED, CommercialCustomerBatch::STATUS_VOIDED, CommercialCustomerBatch::STATUS_BLOCKED])->get() as $child) {
            $child->forceFill(['status' => CommercialCustomerBatch::STATUS_BLOCKED, 'phase' => CommercialCustomerBatch::PHASE_DONE, 'errors' => $errors, 'finished_at' => now()])->save();
        }

        $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_BLOCKED, 'phase' => CommercialCustomerUpload::PHASE_DONE, 'errors' => $errors, 'finished_at' => now()])->save();
        $this->lifecycleDiscard($upload);

        return false;
    }

    /**
     * Parks the upload: a region or district name nobody could place. Nothing has been staged. The names (with their customer counts, never
     * a customer) are kept so the person can match each of them; the file's first batch mirrors it so a one-district file looks as it always did.
     *
     * @param  list<array{label: string, rows: int, routes: int}>  $unrecognised
     */
    protected function parkUpload(CommercialCustomerUpload $upload, ?Region $region, array $unrecognised, string $message): bool
    {
        $upload->forceFill([
            'status' => CommercialCustomerUpload::STATUS_NEEDS_MATCH, 'phase' => CommercialCustomerUpload::PHASE_DISCOVER, 'region_id' => $region?->id ?? $upload->region_id,
            'unrecognised' => $unrecognised === [] ? null : $unrecognised, 'warnings' => [['row' => 'File', 'message' => $message]],
        ])->save();

        $first = $upload->batches()->first();

        if ($first) {
            $first->forceFill([
                'status' => CommercialCustomerBatch::STATUS_NEEDS_MATCH, 'phase' => CommercialCustomerBatch::PHASE_PARSE, 'region_id' => $region?->id,
                'region_label_raw' => $upload->region_label_raw, 'district_label_raw' => $unrecognised[0]['label'] ?? $first->district_label_raw,
                'warnings' => [['row' => 'File', 'message' => $message]],
            ])->save();
        }

        return false;
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
}
