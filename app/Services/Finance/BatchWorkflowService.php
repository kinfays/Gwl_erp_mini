<?php

namespace App\Services\Finance;

use App\Events\Finance\PvBatchActioned;
use App\Events\Finance\PvVouchersAssigned;
use App\Models\AuditLog;
use App\Models\FinBatchEvent;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvBatch;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every write to a batch, and the writes that treat a group of vouchers as one (docs/20-module-finance-audit.md, section 10).
 * A batch is a CONTAINER for sending, assigning, tracking and printing: it is not a unit of approval. Audit still ticks or
 * queries each voucher on its own, so the per-voucher work (the submitted event, the assignment, the tick) is always done by
 * PaymentVoucherWorkflowService, one voucher and one event at a time, and this class only decides which vouchers and in what order.
 *
 * Each method follows the voucher service's pattern: the permission, then inside one transaction the batch (and its vouchers) are
 * RE-READ with lockForUpdate and authorised on that copy (another location's batch is a 404), then the rule, then an immutable
 * fin_batch_events row and an AuditLog row (ids and counts only: never a payee, amount, title, remark or comment), and after the
 * commit the books version is bumped and the notice event is fired.
 *
 *   Draft batch: add / remove vouchers, edit the title and remarks, send, cancel (vouchers are released).
 *   Sent batch: membership is frozen. Only the Audit manager's assignment (and the per-voucher work) goes on.
 */
class BatchWorkflowService
{
    public function __construct(
        protected FinanceScope $scope,
        protected PaymentVoucherWorkflowService $vouchers,
    ) {}

    // ------------------------------------------------------------------ Finance: build and send

    /**
     * A new DRAFT batch from selected vouchers: Draft vouchers of the creator's location that are in no batch. super_admin must say
     * which district's books ($districtId). The batch number is generated here (never typed).
     *
     * @param  array<int, int|string>  $pvIds
     */
    public function create(User $actor, array $pvIds, ?string $title = null, ?string $remarks = null, ?int $districtId = null): FinPvBatch
    {
        $this->authorise($actor, 'finance.create_pv');
        $title = $this->optionalText($title, 150, 'title');
        $remarks = $this->optionalText($remarks, 1000, 'remarks');
        $ids = $this->ids($pvIds, 0);
        $this->assertRoom(count($ids));
        $location = $this->scope->recordingLocation($actor, $districtId);

        return DB::transaction(function () use ($actor, $ids, $title, $remarks, $location) {
            $members = $this->lockMembers($actor, $ids, $location, null);

            $batch = FinPvBatch::query()->create([
                'batch_no' => $this->nextNumber($location),
                'title' => $title,
                'remarks' => $remarks,
                ...$location,
                'status' => FinPvBatch::DRAFT,
                'created_by' => $actor->id,
                'lock_version' => 1,
            ]);

            $this->log($batch, FinBatchEvent::CREATED, $actor);
            $this->attach($batch, $members, $actor);
            $this->audit('finance.batch_created', $batch, 'finance', ['vouchers' => $members->count()]);
            $this->afterCommit($batch);

            return $batch;
        });
    }

    /**
     * Adds Draft vouchers (of the batch's location, in no batch) to a DRAFT batch. $expectedVersion is the batch version the screen
     * opened: a newer one raises PvConflictException.
     *
     * @param  array<int, int|string>  $pvIds
     */
    public function addVouchers(User $actor, FinPvBatch|int $batch, array $pvIds, ?int $expectedVersion = null): FinPvBatch
    {
        $this->authorise($actor, 'finance.create_pv');
        $ids = $this->ids($pvIds, 0);

        return DB::transaction(function () use ($actor, $batch, $ids, $expectedVersion) {
            $fresh = $this->lockedBatch($actor, $batch, FinanceScope::AS_FINANCE);
            $this->assertDraft($fresh, 'Vouchers can be added only while the batch is a draft. Once it is sent, its vouchers are frozen.');
            $this->assertVersion($fresh, $expectedVersion);

            $already = Pv::query()->where('batch_id', $fresh->id)->count();
            $this->assertRoom($already + count($ids));

            $members = $this->lockMembers($actor, $ids, $this->placeOf($fresh), $fresh);
            $this->attach($fresh, $members, $actor);
            $this->bump($fresh);
            $this->audit('finance.batch_vouchers_added', $fresh, 'finance', ['vouchers' => $members->count(), 'lock_version' => $fresh->lock_version]);
            $this->afterCommit($fresh);

            return $fresh;
        });
    }

    /**
     * Takes vouchers out of a DRAFT batch (they stay drafts, free to be sent alone or put in another batch).
     *
     * @param  array<int, int|string>  $pvIds
     */
    public function removeVouchers(User $actor, FinPvBatch|int $batch, array $pvIds, ?int $expectedVersion = null): FinPvBatch
    {
        $this->authorise($actor, 'finance.create_pv');
        $ids = $this->ids($pvIds, 0);

        return DB::transaction(function () use ($actor, $batch, $ids, $expectedVersion) {
            $fresh = $this->lockedBatch($actor, $batch, FinanceScope::AS_FINANCE);
            $this->assertDraft($fresh, 'Vouchers can be taken out only while the batch is a draft. Once it is sent, its vouchers are frozen.');
            $this->assertVersion($fresh, $expectedVersion);

            $members = Pv::query()->where('batch_id', $fresh->id)->whereIn('id', $ids)->lockForUpdate()->get();

            if ($members->count() !== count($ids)) {
                throw ValidationException::withMessages(['vouchers' => 'One of those vouchers is not in this batch.']);
            }

            foreach ($members as $member) {
                $member->forceFill(['batch_id' => null])->save();
                $this->log($fresh, FinBatchEvent::PV_REMOVED, $actor, $member->id);
            }

            $this->bump($fresh);
            $this->audit('finance.batch_vouchers_removed', $fresh, 'finance', ['vouchers' => $members->count(), 'lock_version' => $fresh->lock_version]);
            $this->afterCommit($fresh);

            return $fresh;
        });
    }

    /** The optional title and remarks of a DRAFT batch. */
    public function updateDetails(User $actor, FinPvBatch|int $batch, ?string $title, ?string $remarks, ?int $expectedVersion = null): FinPvBatch
    {
        $this->authorise($actor, 'finance.create_pv');
        $title = $this->optionalText($title, 150, 'title');
        $remarks = $this->optionalText($remarks, 1000, 'remarks');

        return DB::transaction(function () use ($actor, $batch, $title, $remarks, $expectedVersion) {
            $fresh = $this->lockedBatch($actor, $batch, FinanceScope::AS_FINANCE);
            $this->assertDraft($fresh, 'The title and remarks can be changed only while the batch is a draft.');
            $this->assertVersion($fresh, $expectedVersion);

            $fresh->forceFill(['title' => $title, 'remarks' => $remarks])->save();
            $this->bump($fresh);
            $this->audit('finance.batch_updated', $fresh, 'finance', ['changed_fields' => ['title', 'remarks'], 'lock_version' => $fresh->lock_version]);
            $this->afterCommit($fresh);

            return $fresh;
        });
    }

    /**
     * Sends the batch to Audit: ALL OR NOTHING in one transaction. Every voucher must pass the same completeness checks as a single
     * send; if any fails, nothing is sent and BatchSendException lists each failing voucher and why. Each voucher then gets its own
     * normal `submitted` event (round 1) carrying the batch id, and the batch becomes Sent with its membership frozen.
     *
     * @param  array<int, int>  $voucherVersions  optional pv id => the lock_version the screen showed; a changed voucher raises PvConflictException
     */
    public function send(User $actor, FinPvBatch|int $batch, ?int $expectedVersion = null, array $voucherVersions = []): FinPvBatch
    {
        $this->authorise($actor, 'finance.create_pv');

        return DB::transaction(function () use ($actor, $batch, $expectedVersion, $voucherVersions) {
            $fresh = $this->lockedBatch($actor, $batch, FinanceScope::AS_FINANCE);
            $this->assertDraft($fresh, 'Only a draft batch can be sent to Audit.');
            $this->assertVersion($fresh, $expectedVersion);

            $members = Pv::query()->where('batch_id', $fresh->id)->orderBy('id')->lockForUpdate()->get();

            if ($members->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'The batch has no vouchers. Add some, or cancel it.']);
            }

            foreach ($members as $member) {
                if (isset($voucherVersions[$member->id]) && (int) $voucherVersions[$member->id] !== (int) $member->lock_version) {
                    throw new PvConflictException;
                }
            }

            $failures = [];

            foreach ($members as $member) {
                $problems = $this->vouchers->sendProblems($member);

                if ($problems !== []) {
                    $failures[] = ['pv_id' => $member->id, 'pv_no' => $member->pv_no, 'reasons' => $problems];
                }
            }

            if ($failures !== []) {
                throw BatchSendException::for($failures);
            }

            foreach ($members as $member) {
                $this->vouchers->submitForBatch($actor, $member, $fresh);
            }

            $fresh->forceFill(['status' => FinPvBatch::SENT, 'sent_at' => now(), 'sent_by' => $actor->id])->save();
            $this->bump($fresh);
            $this->log($fresh, FinBatchEvent::SENT, $actor);
            $this->audit('finance.batch_sent', $fresh, 'finance', ['vouchers' => $members->count()]);
            $this->afterCommit($fresh, new PvBatchActioned($fresh->id, FinBatchEvent::SENT, $actor->id, count: $members->count()));

            return $fresh;
        });
    }

    /**
     * Cancels a DRAFT batch (empty or not) with a reason. Its vouchers are released as drafts; nothing is deleted. A sent batch cannot
     * be cancelled: its vouchers are with Audit.
     */
    public function cancel(User $actor, FinPvBatch|int $batch, string $reason): FinPvBatch
    {
        $this->authorise($actor, 'finance.create_pv');
        $reason = $this->requiredText($reason, 500, 'reason', 'Say why the batch is being cancelled.');

        return DB::transaction(function () use ($actor, $batch, $reason) {
            $fresh = $this->lockedBatch($actor, $batch, FinanceScope::AS_FINANCE);
            $this->assertDraft($fresh, 'Only a draft batch can be cancelled. A sent batch is already with Audit.');

            $members = Pv::query()->where('batch_id', $fresh->id)->orderBy('id')->lockForUpdate()->get();

            foreach ($members as $member) {
                $member->forceFill(['batch_id' => null])->save();
                $this->log($fresh, FinBatchEvent::PV_REMOVED, $actor, $member->id);
            }

            $fresh->forceFill(['status' => FinPvBatch::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason])->save();
            $this->bump($fresh);
            $this->log($fresh, FinBatchEvent::CANCELLED, $actor, comment: $reason);
            $this->audit('finance.batch_cancelled', $fresh, 'finance', ['released' => $members->count()]);
            $this->afterCommit($fresh);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------ Audit manager: assignment

    /**
     * Gives every voucher of a SENT batch that is With Audit to one Audit officer (each keeps its own `assigned`/`reassigned` event;
     * vouchers already with that officer, and ones that are not With Audit, are left alone and counted). The officer must be eligible
     * for EVERY voucher: an active audit.review holder based at the location who sent none of them to Audit. All or nothing.
     * Several officers on one batch (a split) is simply assignVouchers() on different selections.
     *
     * @return array{assigned: int, unchanged: int, skipped: int}
     */
    public function assignBatch(User $actor, FinPvBatch|int $batch, int $officerId, ?string $note = null): array
    {
        $this->authorise($actor, 'audit.assign');
        $note = $this->optionalText($note, 500, 'note');

        return DB::transaction(function () use ($actor, $batch, $officerId, $note) {
            $fresh = $this->lockedForAssignment($actor, $batch);

            $members = Pv::query()->where('batch_id', $fresh->id)->orderBy('id')->lockForUpdate()->get();
            $open = $members->where('status', Pv::WITH_AUDIT)->values();

            if ($open->isEmpty()) {
                throw ValidationException::withMessages(['officer' => 'No voucher of this batch is waiting for Audit, so there is nothing to assign.']);
            }

            $this->assertOfficer($fresh, $open->pluck('id')->all(), $officerId);

            $changed = 0;
            $previous = [];

            foreach ($open as $member) {
                $before = $member->assigned_to_user_id;
                $after = $this->vouchers->assign($actor, $member, $officerId, $note, notify: false);

                if ($before === null || (int) $before !== (int) $after->assigned_to_user_id) {
                    $changed++;
                    $previous[] = $before;
                }
            }

            if ($changed > 0) {
                $reassigned = ! in_array(null, $previous, true);
                $from = $reassigned && count(array_unique($previous)) === 1 ? (int) $previous[0] : null;

                $this->log($fresh, $reassigned ? FinBatchEvent::REASSIGNED : FinBatchEvent::ASSIGNED, $actor, comment: $note, from: $from, to: $officerId);
                $this->audit('audit.batch_assigned', $fresh, 'audit', ['to_user_id' => $officerId, 'vouchers' => $changed, 'reassigned' => $reassigned]);
                $this->afterCommit($fresh, new PvBatchActioned($fresh->id, $reassigned ? FinBatchEvent::REASSIGNED : FinBatchEvent::ASSIGNED, $actor->id, $officerId, $changed));
            }

            return ['assigned' => $changed, 'unchanged' => $open->count() - $changed, 'skipped' => $members->count() - $open->count()];
        });
    }

    /**
     * Gives the selected vouchers (loose or from several batches) to one officer: each is assigned or reassigned on its own, with its
     * own event. One voucher raises the normal notice; several raise ONE notice with the count. All or nothing.
     *
     * @param  array<int, int|string>  $pvIds
     * @return array{assigned: int, unchanged: int}
     */
    public function assignVouchers(User $actor, array $pvIds, int $officerId, ?string $note = null): array
    {
        $this->authorise($actor, 'audit.assign');
        $ids = $this->ids($pvIds, 0);

        return DB::transaction(function () use ($actor, $ids, $officerId, $note) {
            $changed = [];

            foreach ($ids as $id) {
                $before = Pv::query()->whereKey($id)->value('assigned_to_user_id');
                $after = $this->vouchers->assign($actor, $id, $officerId, $note, notify: count($ids) === 1);

                if ($before === null || (int) $before !== (int) $after->assigned_to_user_id) {
                    $changed[] = $id;
                }
            }

            if (count($ids) > 1 && $changed !== []) {
                DB::afterCommit(fn () => event(new PvVouchersAssigned($officerId, $changed, $actor->id)));
            }

            return ['assigned' => count($changed), 'unchanged' => count($ids) - count($changed)];
        });
    }

    /**
     * The officers the manager can pick for these vouchers (a combobox's options): active audit.review holders of the location who
     * sent none of the vouchers, each with their open workload (vouchers assigned to them and still With Audit). The vouchers are
     * read through the assignment queue, so ones outside the manager's reach are a 404.
     *
     * @param  array<int, int|string>  $pvIds
     * @return Collection<int, array{value: int, label: string, description: string, open: int}>
     */
    public function officerChoices(User $actor, array $pvIds): Collection
    {
        $this->authorise($actor, 'audit.assign');
        $ids = $this->ids($pvIds, 0);
        $rows = $this->scope->assignmentQueue($actor)->whereIn('fin_pvs.id', $ids)->get();
        abort_unless($rows->count() === count($ids), 404);

        $places = $rows->map(fn (Pv $pv) => $this->placeOf($pv))->unique(fn (array $place) => FinanceBooksVersion::unitKey($place));

        if ($places->count() !== 1) {
            throw ValidationException::withMessages(['officer' => 'Choose vouchers of one location at a time.']);
        }

        $officers = $this->scope->assignableOfficers($places->first(), $ids)->get(['users.id', 'users.full_name', 'users.staff_id']);
        $open = Pv::query()->where('status', Pv::WITH_AUDIT)->whereIn('assigned_to_user_id', $officers->pluck('id'))
            ->selectRaw('assigned_to_user_id, count(*) as open')->groupBy('assigned_to_user_id')->pluck('open', 'assigned_to_user_id');

        return $officers->map(fn (User $officer) => [
            'value' => $officer->id,
            'label' => $officer->full_name,
            'description' => trim('Staff ID '.$officer->staff_id.' · '.(int) ($open[$officer->id] ?? 0).' open'),
            'open' => (int) ($open[$officer->id] ?? 0),
        ]);
    }

    // ------------------------------------------------------------------ bulk acknowledgement

    /**
     * "Acknowledge all remaining" for an Audit officer: ticks, one voucher and one event at a time, every voucher of the batch that is
     * With Audit and assigned to them. A With Audit voucher they can see but cannot act on (assigned to someone else after they worked
     * on it, or one they sent) is skipped and listed with the reason; vouchers they cannot see are only counted. A query is never
     * bulk: it is always one voucher, with its own reason code and comment.
     *
     * @return array{acknowledged: int, skipped: list<array{pv_no: string, reason: string}>, not_yours: int}
     */
    public function acknowledgeRemaining(User $actor, FinPvBatch|int $batch): array
    {
        $this->authorise($actor, 'audit.review');
        $found = $this->scope->findBatch($actor, $batch instanceof FinPvBatch ? $batch->id : $batch, FinanceScope::AS_AUDIT);

        $visible = $this->scope->pvs($actor, FinanceScope::AS_AUDIT)
            ->where('fin_pvs.batch_id', $found->id)->where('fin_pvs.status', Pv::WITH_AUDIT)->orderBy('fin_pvs.id')->get();
        $everyWaiting = Pv::query()->where('batch_id', $found->id)->where('status', Pv::WITH_AUDIT)->count();

        $done = 0;
        $skipped = [];

        foreach ($visible as $pv) {
            try {
                $this->vouchers->auditAcknowledge($actor, $pv);
                $done++;
            } catch (ValidationException $exception) {
                $skipped[] = ['pv_no' => $pv->pv_no, 'reason' => collect($exception->errors())->flatten()->first()];
            }
        }

        $this->audit('audit.batch_bulk_acknowledged', $found, 'audit', ['acknowledged' => $done, 'skipped' => count($skipped)]);

        return ['acknowledged' => $done, 'skipped' => $skipped, 'not_yours' => max(0, $everyWaiting - $visible->count())];
    }

    /**
     * Finance's "Acknowledge all" on a batch: one event per voucher, for every voucher Audit has ticked. Anything else in the batch is
     * left alone; a voucher that cannot be acknowledged is skipped and listed.
     *
     * @return array{acknowledged: int, skipped: list<array{pv_no: string, reason: string}>}
     */
    public function financeAcknowledgeAll(User $actor, FinPvBatch|int $batch): array
    {
        $this->authorise($actor, 'finance.acknowledge');
        $found = $this->scope->findBatch($actor, $batch instanceof FinPvBatch ? $batch->id : $batch, FinanceScope::AS_FINANCE);

        $ticked = $this->scope->pvs($actor, FinanceScope::AS_FINANCE)
            ->where('fin_pvs.batch_id', $found->id)->where('fin_pvs.status', Pv::AUDIT_ACKNOWLEDGED)->orderBy('fin_pvs.id')->get();

        $done = 0;
        $skipped = [];

        foreach ($ticked as $pv) {
            try {
                $this->vouchers->financeAcknowledge($actor, $pv);
                $done++;
            } catch (ValidationException $exception) {
                $skipped[] = ['pv_no' => $pv->pv_no, 'reason' => collect($exception->errors())->flatten()->first()];
            }
        }

        $this->audit('finance.batch_bulk_acknowledged', $found, 'finance', ['acknowledged' => $done, 'skipped' => count($skipped)]);

        return ['acknowledged' => $done, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------ rules

    protected function authorise(User $actor, string $permission): void
    {
        abort_unless($this->scope->can($actor, $permission), 403, 'You do not have permission to do that.');
    }

    /** Re-reads the batch with a write lock and checks the actor reaches it ON THAT COPY: another location's batch is a 404. */
    protected function lockedBatch(User $actor, FinPvBatch|int $batch, string $as): FinPvBatch
    {
        $row = FinPvBatch::query()->lockForUpdate()->find($batch instanceof FinPvBatch ? $batch->id : $batch);
        abort_unless($row && $this->scope->containsBatch($actor, $row, $as), 404);

        return $row;
    }

    /** The same, for the Audit manager's assignment queue (a SENT batch of the location). */
    protected function lockedForAssignment(User $actor, FinPvBatch|int $batch): FinPvBatch
    {
        $row = FinPvBatch::query()->lockForUpdate()->find($batch instanceof FinPvBatch ? $batch->id : $batch);
        abort_unless($row && $this->scope->assignmentBatches($actor)->whereKey($row->id)->exists(), 404);

        return $row;
    }

    protected function assertDraft(FinPvBatch $batch, string $message): void
    {
        if (! $batch->isDraft()) {
            throw ValidationException::withMessages(['batch' => $message]);
        }
    }

    protected function assertVersion(FinPvBatch $batch, ?int $expected): void
    {
        if ($expected !== null && (int) $batch->lock_version !== $expected) {
            throw new PvConflictException;
        }
    }

    protected function assertRoom(int $total): void
    {
        $cap = max(1, (int) config('gwl.fin_batch_max_vouchers'));

        if ($total > $cap) {
            throw ValidationException::withMessages(['vouchers' => "A batch holds at most {$cap} vouchers."]);
        }
    }

    /** The officer must be eligible for every one of the vouchers (see FinanceScope::assignableOfficers()). */
    protected function assertOfficer(FinPvBatch $batch, array $pvIds, int $officerId): void
    {
        if (! $this->scope->assignableOfficers($this->placeOf($batch), $pvIds)->whereKey($officerId)->exists()) {
            throw ValidationException::withMessages(['officer' => 'Choose an active Audit officer of this location who did not send any of these vouchers to Audit.']);
        }
    }

    /**
     * @param  array<int, int|string>  $pvIds
     * @return list<int>
     */
    protected function ids(array $pvIds, int $minimum): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pvIds), fn (int $id) => $id > 0)));

        if (count($ids) <= $minimum) {
            throw ValidationException::withMessages(['vouchers' => 'Choose at least one voucher.']);
        }

        return $ids;
    }

    /**
     * Locks the vouchers and checks each can go into a batch of $location: reachable (404 otherwise), a Draft, in no batch (or, adding
     * to $batch, not already in it), and of the batch's own location. Every problem is reported at once.
     *
     * @param  list<int>  $ids
     * @param  array{region_id: int, district_id: int|null}  $location
     * @return Collection<int, Pv>
     */
    protected function lockMembers(User $actor, array $ids, array $location, ?FinPvBatch $batch): Collection
    {
        $rows = Pv::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $problems = [];

        foreach ($ids as $id) {
            $pv = $rows->get($id);
            abort_unless($pv && $this->scope->containsPv($actor, $pv, FinanceScope::AS_FINANCE), 404);

            if (! $this->scope->sameLocation($this->placeOf($pv), $location)) {
                $problems[] = "PV {$pv->pv_no} belongs to another location.";
            } elseif ($blocker = $pv->batchBlocker()) {
                // The wording and the rule are the model's (the lists use the same sentence to say why a row cannot be ticked).
                $problems[] = $batch && (int) $pv->batch_id === (int) $batch->id
                    ? "PV {$pv->pv_no} is already in this batch."
                    : "PV {$pv->pv_no} {$blocker}.";
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['vouchers' => $problems]);
        }

        return $rows->values();
    }

    /** @param Collection<int, Pv> $members */
    protected function attach(FinPvBatch $batch, Collection $members, User $actor): void
    {
        foreach ($members as $member) {
            $member->forceFill(['batch_id' => $batch->id])->save();
            $this->log($batch, FinBatchEvent::PV_ADDED, $actor, $member->id);
        }
    }

    protected function bump(FinPvBatch $batch): void
    {
        $batch->forceFill(['lock_version' => $batch->lock_version + 1])->save();
    }

    /**
     * BT-<LOCATION CODE>-<YEAR>-<NNNN>, from a counter per location and year read under a write lock (the unique index on
     * batch_no is the backstop). The location code is "HO" for Head Office, otherwise the region's letter prefix.
     *
     * @param  array{region_id: int, district_id: int|null}  $location
     */
    protected function nextNumber(array $location): string
    {
        $unit = FinanceBooksVersion::unitKey($location);
        $year = (int) now()->format('Y');
        $now = now();

        DB::table('fin_batch_counters')->insertOrIgnore(['unit' => $unit, 'year' => $year, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $row = DB::table('fin_batch_counters')->where('unit', $unit)->where('year', $year)->lockForUpdate()->first();
        $next = (int) $row->last_number + 1;
        DB::table('fin_batch_counters')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => $now]);

        if ($unit === 'ho') {
            $code = 'HO';
        } else {
            $region = Region::query()->findOrFail($location['region_id']);
            $code = Str::upper($region->assignLetterPrefix());
            $code = $code === 'HO' ? $code.$region->id : $code;
        }

        return sprintf('BT-%s-%d-%04d', $code, $year, $next);
    }

    protected function optionalText(mixed $value, int $max, string $field): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => "Keep it to {$max} characters."]);
        }

        return $text;
    }

    protected function requiredText(mixed $value, int $max, string $field, string $message): string
    {
        $text = trim((string) $value);

        if ($text === '' || mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => $message.' (up to '.$max.' characters)']);
        }

        return $text;
    }

    /** @return array{region_id: int, district_id: int|null} */
    protected function placeOf(Pv|FinPvBatch $row): array
    {
        return ['region_id' => (int) $row->region_id, 'district_id' => $row->district_id !== null ? (int) $row->district_id : null];
    }

    // ------------------------------------------------------------------ writing the trail

    protected function log(FinPvBatch $batch, string $action, User $actor, ?int $pvId = null, ?string $comment = null, ?int $from = null, ?int $to = null): FinBatchEvent
    {
        return FinBatchEvent::query()->create([
            'batch_id' => $batch->id,
            'action' => $action,
            'actor_user_id' => $actor->id,
            'pv_id' => $pvId,
            'from_user_id' => $from,
            'to_user_id' => $to,
            'comment' => $comment,
        ]);
    }

    /** Ids and counts only: never the title, remarks, a reason, or anything about the vouchers' payees or amounts. */
    protected function audit(string $action, FinPvBatch $batch, string $module, array $metadata = []): void
    {
        AuditLog::record($action, $module, $batch->getTable(), (int) $batch->getKey(), null, null, $metadata + [
            'region_id' => $batch->region_id,
            'district_id' => $batch->district_id,
        ]);
    }

    /** After the commit: cached analytics are dropped (books version) and the notice event, if any, is fired. */
    protected function afterCommit(FinPvBatch $batch, ?object $event = null): void
    {
        $place = $this->placeOf($batch);

        DB::afterCommit(function () use ($place, $event) {
            FinanceBooksVersion::touch($place);

            if ($event !== null) {
                event($event);
            }
        });
    }
}
