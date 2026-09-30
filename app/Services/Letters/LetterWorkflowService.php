<?php

namespace App\Services\Letters;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\LetterNotification;
use App\Models\LetterRemark;
use App\Models\LetterSnCounter;
use App\Models\LetterStatusLog;
use App\Models\MailLetter;
use App\Models\Region;
use App\Models\RoutingHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LetterWorkflowService
{
    public function create(Employee $creator, array $data): MailLetter
    {
        return DB::transaction(function () use ($creator, $data) {
            $letter = MailLetter::create([
                'sn_number' => $this->nextSnNumber((int) $data['region_id']),
                'subject' => $data['subject'],
                'ref_no' => $data['ref_no'] ?? null,
                'type' => $data['type'],
                'memo_sender_id' => $data['type'] === 'Internal' ? ($data['memo_sender_id'] ?? null) : null,
                'company_sender' => $data['type'] === 'External' ? ($data['company_sender'] ?? null) : null,
                'date_on_letter' => $data['date_on_letter'],
                'region_id' => $data['region_id'],
                'created_by_id' => $creator->id,
            ]);

            LetterStatusLog::create([
                'letter_id' => $letter->id,
                'secretariat_id' => $creator->id,
                'status' => 'Received',
            ]);

            AuditLog::record('create_letter', 'letters', 'mail_letters', $letter->id, null, $letter->toArray());

            return $letter;
        });
    }

    public function markInReview(MailLetter $letter, Employee $actor): void
    {
        $log = $this->currentLog($letter, $actor);

        if ($log && $log->status === 'Received' && ! $letter->isClosed() && ! $this->pendingIncomingRoute($letter, $actor)) {
            $log->update(['status' => 'In Review']);
        }
    }

    public function dispatch(MailLetter $letter, Employee $from, Employee $to): void
    {
        if (! $this->canDispatch($letter, $from)) {
            throw new \RuntimeException('Confirm hardcopy receipt before dispatching this letter.');
        }

        if ($from->id === $to->id) {
            throw new \RuntimeException('Dispatch recipient must be different from the current secretariat.');
        }

        DB::transaction(fn () => $this->recordHop($letter, $from, $to));
    }

    /**
     * Hand many letters from $from to $to at once as one numbered transmittal. All-or-nothing: if any letter is not
     * dispatchable by $from any more, nothing is created, so the printed sheet can never disagree with what was
     * handed over. Letter ids come from the client, so they are only ever resolved through visibleLettersQuery.
     *
     * @param  array<int|string>  $letterIds
     */
    public function dispatchBatch(Employee $from, Employee $to, array $letterIds, ?string $note = null): LetterDispatchBatch
    {
        $ids = collect($letterIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $max = max(1, (int) config('gwl.letters_max_batch_size', 50));

        if ($ids->isEmpty()) {
            throw new \RuntimeException('Select at least one letter to dispatch.');
        }

        if ($ids->count() > $max) {
            throw new \RuntimeException("A transmittal can hold at most {$max} letters; you selected {$ids->count()}.");
        }

        if ($from->id === $to->id) {
            throw new \RuntimeException('Dispatch recipient must be different from the current secretariat.');
        }

        if (! $this->secretaryQuery()->whereKey($to->id)->exists()) {
            throw new \RuntimeException('The selected recipient cannot receive letters.');
        }

        $note = filled($note) ? Str::limit(trim($note), 500, '') : null;

        return DB::transaction(function () use ($from, $to, $ids, $note) {
            $letters = $this->visibleLettersQuery($from)
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (MailLetter $letter) => $ids->search($letter->id))
                ->values();

            // Ids that are not visible to $from are indistinguishable from ids that do not exist: say nothing about them.
            if ($letters->count() !== $ids->count()) {
                throw new \RuntimeException('Some of the selected letters are no longer available to you. Refresh and select again.');
            }

            $desk = $this->deskState($letters, $from);
            $blocked = $letters->reject(fn (MailLetter $letter) => $desk[$letter->id]['canDispatch']);

            if ($blocked->isNotEmpty()) {
                throw new \RuntimeException('These letters can no longer be dispatched by you: '.$blocked->pluck('sn_number')->join(', ').'. Nothing was dispatched.');
            }

            $batch = LetterDispatchBatch::create([
                'from_secretariat_id' => $from->id,
                'to_secretariat_id' => $to->id,
                'note' => $note,
                'letters_count' => $letters->count(),
                'confirmed_count' => 0,
                'dispatched_at' => now(),
            ]);
            $batch->update(['batch_no' => LetterDispatchBatch::numberFor($batch->id, $batch->dispatched_at)]);

            foreach ($letters as $letter) {
                $this->recordHop($letter, $from, $to, $batch, notify: false);
            }

            LetterNotification::create([
                'title' => 'Letters dispatched to you',
                'message' => $letters->count().' '.Str::plural('letter', $letters->count()).' ('.$batch->batch_no.') need hardcopy receipt confirmation.',
                'secretariat_id' => $to->id,
                'letter_id' => null,
                'batch_id' => $batch->id,
            ]);

            AuditLog::record('dispatch_letter_batch', 'letters', 'letter_dispatch_batches', $batch->id, null, [
                'batch_no' => $batch->batch_no,
                'from_secretariat_id' => $from->id,
                'to_secretariat_id' => $to->id,
                'letters_count' => $letters->count(),
                'letter_ids' => $letters->pluck('id')->all(),
            ]);

            return $batch;
        });
    }

    /** One hop: the routing row, the recipient's log, the sender's Dispatched log and the audit row. */
    protected function recordHop(MailLetter $letter, Employee $from, Employee $to, ?LetterDispatchBatch $batch = null, bool $notify = true): RoutingHistory
    {
        $hop = RoutingHistory::create([
            'letter_id' => $letter->id,
            'from_secretariat_id' => $from->id,
            'to_secretariat_id' => $to->id,
            'batch_id' => $batch?->id,
            'received_confirm' => false,
        ]);

        LetterStatusLog::create([
            'letter_id' => $letter->id,
            'secretariat_id' => $to->id,
            'status' => 'Received',
        ]);

        if ($notify) {
            LetterNotification::create([
                'title' => 'Letter dispatched to you',
                'message' => $letter->sn_number . ' needs hardcopy receipt confirmation.',
                'secretariat_id' => $to->id,
                'letter_id' => $letter->id,
            ]);
        }

        $this->currentLog($letter, $from)?->update([
            'status' => 'Dispatched',
            'out_date' => today(),
        ]);

        AuditLog::record('dispatch_letter', 'letters', 'mail_letters', $letter->id, null, [
            'from_secretariat_id' => $from->id,
            'to_secretariat_id' => $to->id,
        ], $batch ? ['batch_id' => $batch->id, 'batch_no' => $batch->batch_no] : null);

        return $hop;
    }

    public function confirmHardcopy(MailLetter $letter, Employee $actor): void
    {
        $this->confirmHardcopies($actor, [$letter->id]);
    }

    /**
     * Confirm receipt of the hardcopy of every letter in $letterIds that has a pending hop addressed to $actor (and,
     * when $batch is given, belongs to that transmittal). Anything else in the list is ignored, never an error, so a
     * stale or tampered selection can only ever confirm the actor's own pending hops. This is the only confirm path.
     * Returns the number of hops confirmed.
     *
     * @param  array<int|string>  $letterIds
     */
    public function confirmHardcopies(Employee $actor, array $letterIds, ?LetterDispatchBatch $batch = null): int
    {
        $ids = collect($letterIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        return DB::transaction(function () use ($actor, $ids, $batch) {
            $routes = RoutingHistory::query()
                ->where('to_secretariat_id', $actor->id)
                ->where('received_confirm', false)
                ->whereIn('letter_id', $ids->all())
                ->when($batch, fn (Builder $query) => $query->where('batch_id', $batch->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($routes->isEmpty()) {
                throw new \RuntimeException('No pending hardcopy receipt confirmation was found.');
            }

            $letters = MailLetter::query()->whereIn('id', $routes->pluck('letter_id')->unique()->all())->get()->keyBy('id');

            foreach ($routes as $route) {
                $route->update([
                    'received_confirm' => true,
                    'confirmed_at' => now(),
                    'confirmed_by_id' => $actor->id,
                ]);

                $this->markInReview($letters[$route->letter_id], $actor);

                AuditLog::record('confirm_letter_hardcopy', 'letters', 'mail_letters', $route->letter_id, null, [
                    'routing_history_id' => $route->id,
                ], $route->batch_id ? ['batch_id' => $route->batch_id] : null);
            }

            $routes->pluck('batch_id')->filter()->unique()->each(fn ($batchId) => $this->refreshBatchProgress((int) $batchId));

            return $routes->count();
        });
    }

    /** confirmed_count / completed_at are recomputed from the hops, never incremented, so they cannot drift. */
    protected function refreshBatchProgress(int $batchId): void
    {
        $batch = LetterDispatchBatch::query()->find($batchId);

        if (! $batch) {
            return;
        }

        $hops = $batch->routingHistories();
        $pending = (clone $hops)->where('received_confirm', false)->count();

        $batch->update([
            'confirmed_count' => (clone $hops)->where('received_confirm', true)->count(),
            'completed_at' => $pending === 0 ? ($batch->completed_at ?? now()) : null,
        ]);
    }

    /** Hops addressed to $actor that still wait for their confirmation: the number on the Transmittals sidebar entry. */
    public function pendingIncomingCount(Employee $actor): int
    {
        return RoutingHistory::query()
            ->where('to_secretariat_id', $actor->id)
            ->where('received_confirm', false)
            ->count();
    }

    /** Quick filter "Awaiting my confirmation": letters with an unconfirmed hop addressed to $actor. */
    public function whereAwaitingConfirmation(Builder $letters, Employee $actor): Builder
    {
        return $letters->whereHas('routingHistories', fn (Builder $hops) => $hops
            ->where('to_secretariat_id', $actor->id)
            ->where('received_confirm', false));
    }

    /**
     * Quick filter "Ready to dispatch": the SQL twin of holdsLetter()/deskState() - the letter is open, no hop is
     * waiting for $actor, and $actor's newest log for it (created_at, then id, as everywhere else) is Received or
     * In Review.
     */
    public function whereReadyToDispatch(Builder $letters, Employee $actor): Builder
    {
        return $letters
            ->whereNull('closed_at')
            ->whereDoesntHave('routingHistories', fn (Builder $hops) => $hops
                ->where('to_secretariat_id', $actor->id)
                ->where('received_confirm', false))
            ->whereHas('statusLogs', fn (Builder $logs) => $logs
                ->where('secretariat_id', $actor->id)
                ->whereIn('status', ['Received', 'In Review'])
                ->whereNotExists(fn ($newer) => $newer
                    ->select(DB::raw(1))
                    ->from('letter_status_logs as newer')
                    ->whereColumn('newer.letter_id', 'letter_status_logs.letter_id')
                    ->whereColumn('newer.secretariat_id', 'letter_status_logs.secretariat_id')
                    ->where(fn ($later) => $later
                        ->whereColumn('newer.created_at', '>', 'letter_status_logs.created_at')
                        ->orWhere(fn ($tie) => $tie
                            ->whereColumn('newer.created_at', 'letter_status_logs.created_at')
                            ->whereColumn('newer.id', '>', 'letter_status_logs.id')))));
    }

    public function close(MailLetter $letter, Employee $actor): void
    {
        if ($letter->created_by_id !== $actor->id) {
            throw new \RuntimeException('Only the creator can close this letter.');
        }

        DB::transaction(function () use ($letter, $actor) {
            $locked = MailLetter::query()->lockForUpdate()->findOrFail($letter->id);

            if ($locked->isClosed()) {
                throw new \RuntimeException('This letter is already closed.');
            }

            if ($locked->routingHistories()->where('received_confirm', false)->exists()) {
                throw new \RuntimeException('This letter is still waiting for hardcopy confirmation and cannot be closed yet.');
            }

            $locked->update(['closed_at' => now(), 'closed_by_id' => $actor->id]);

            AuditLog::record('close_letter', 'letters', 'mail_letters', $locked->id);
        });

        $letter->refresh();
    }

    public function reopen(MailLetter $letter, Employee $actor): void
    {
        if ($letter->created_by_id !== $actor->id) {
            throw new \RuntimeException('Only the creator can reopen this letter.');
        }

        DB::transaction(function () use ($letter, $actor) {
            $locked = MailLetter::query()->lockForUpdate()->findOrFail($letter->id);

            if (! $locked->isClosed()) {
                throw new \RuntimeException('This letter is not closed.');
            }

            $locked->update(['closed_at' => null, 'closed_by_id' => null]);

            // Letters closed before closed_at existed had every holder's log rewritten to Closed, so nobody could
            // act on them once reopened; hand those back to the creator. Otherwise the holder simply carries on.
            if ($this->latestLogOf($locked)?->status === 'Closed') {
                LetterStatusLog::create([
                    'letter_id' => $locked->id,
                    'secretariat_id' => $actor->id,
                    'status' => 'In Review',
                ]);
            }

            AuditLog::record('reopen_letter', 'letters', 'mail_letters', $locked->id);
        });

        $letter->refresh();
    }

    public function updateLetter(MailLetter $letter, Employee $actor, array $data): void
    {
        if ($letter->created_by_id !== $actor->id) {
            throw new \RuntimeException('Only the creator can edit this letter.');
        }

        $old = $letter->only(['subject', 'ref_no', 'date_on_letter', 'memo_sender_id', 'company_sender', 'type']);

        $letter->update([
            'subject' => $data['subject'],
            'ref_no' => $data['ref_no'] ?? null,
            'date_on_letter' => $data['date_on_letter'],
            'type' => $data['type'],
            'memo_sender_id' => $data['type'] === 'Internal' ? ($data['memo_sender_id'] ?? null) : null,
            'company_sender' => $data['type'] === 'External' ? ($data['company_sender'] ?? null) : null,
        ]);

        AuditLog::record('update_letter', 'letters', 'mail_letters', $letter->id, $old, $letter->fresh()->toArray());
    }

    public function addRemark(MailLetter $letter, Employee $actor, array $data): LetterRemark
    {
        $this->assertMayAnnotate($letter, $actor);

        $remark = LetterRemark::create([
            'letter_id' => $letter->id,
            'author_id' => $actor->id,
            'remark_secretariat_id' => $actor->id,
            'manager_id' => $data['manager_id'] ?? null,
            'chief_manager_id' => $data['chief_manager_id'] ?? null,
            'remark_content' => trim($data['remark_content']),
            'secretary_remark_content' => filled($data['secretary_remark_content'] ?? null)
                ? trim($data['secretary_remark_content'])
                : null,
            'created_by_id' => $actor->id,
        ]);

        AuditLog::record('add_letter_remark', 'letters', 'mail_letters', $letter->id, null, [
            'remark_id' => $remark->id,
        ]);

        return $remark;
    }

    public function updateRemark(LetterRemark $remark, Employee $actor, array $data): void
    {
        if ($remark->author_id !== $actor->id) {
            throw new \RuntimeException('Only the remark creator can edit it.');
        }

        $this->assertMayAnnotate($remark->letter, $actor);

        $fields = ['manager_id', 'chief_manager_id', 'remark_content', 'secretary_remark_content'];
        $old = $remark->only($fields);

        $remark->update([
            'manager_id' => $data['manager_id'] ?? null,
            'chief_manager_id' => $data['chief_manager_id'] ?? null,
            'remark_content' => trim($data['remark_content']),
            'secretary_remark_content' => filled($data['secretary_remark_content'] ?? null)
                ? trim($data['secretary_remark_content'])
                : null,
        ]);

        AuditLog::record(
            'update_letter_remark',
            'letters',
            'mail_letters',
            $remark->letter_id,
            $old,
            $remark->only($fields),
            ['remark_id' => $remark->id],
        );
    }

    /** Whether $actor has the letter on their desk: confirmed, not yet dispatched, and the letter is open. */
    public function holdsLetter(MailLetter $letter, Employee $actor): bool
    {
        $log = $this->currentLog($letter, $actor);

        return $log
            && in_array($log->status, ['Received', 'In Review'], true)
            && ! $letter->isClosed()
            && ! $this->pendingIncomingRoute($letter, $actor);
    }

    public function canDispatch(MailLetter $letter, Employee $actor): bool
    {
        return $this->holdsLetter($letter, $actor);
    }

    /**
     * The actor's desk state for a whole page of letters in two queries (their status logs, their unconfirmed
     * incoming hops), keyed by letter id: currentLog, pendingRoute, holdsLetter, canDispatch. Same rules as
     * currentLog()/pendingIncomingRoute()/canDispatch(), without the per-row lookups.
     *
     * @param  Collection<int, MailLetter>  $letters
     * @return Collection<int, array{currentLog: ?LetterStatusLog, pendingRoute: ?RoutingHistory, holdsLetter: bool, canDispatch: bool}>
     */
    public function deskState(Collection $letters, Employee $actor): Collection
    {
        $ids = $letters->pluck('id')->all();

        $logs = LetterStatusLog::query()
            ->whereIn('letter_id', $ids)
            ->where('secretariat_id', $actor->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('letter_id');

        $routes = RoutingHistory::query()
            ->whereIn('letter_id', $ids)
            ->where('to_secretariat_id', $actor->id)
            ->where('received_confirm', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('letter_id');

        return $letters->mapWithKeys(function (MailLetter $letter) use ($logs, $routes) {
            $log = $logs->get($letter->id)?->first();
            $pending = $routes->get($letter->id)?->first();

            $holds = $log !== null
                && in_array($log->status, ['Received', 'In Review'], true)
                && ! $letter->isClosed()
                && $pending === null;

            return [$letter->id => [
                'currentLog' => $log,
                'pendingRoute' => $pending,
                'holdsLetter' => $holds,
                'canDispatch' => $holds,
            ]];
        });
    }

    public function currentLog(MailLetter $letter, Employee $actor): ?LetterStatusLog
    {
        return LetterStatusLog::query()
            ->where('letter_id', $letter->id)
            ->where('secretariat_id', $actor->id)
            ->latest()
            ->orderByDesc('id')
            ->first();
    }

    public function pendingIncomingRoute(MailLetter $letter, Employee $actor): ?RoutingHistory
    {
        return RoutingHistory::query()
            ->where('letter_id', $letter->id)
            ->where('to_secretariat_id', $actor->id)
            ->where('received_confirm', false)
            ->latest()
            ->orderByDesc('id')
            ->first();
    }

    public function visibleLettersQuery(Employee $actor): Builder
    {
        return MailLetter::query()
            ->whereHas('statusLogs', fn (Builder $query) => $query->where('secretariat_id', $actor->id));
    }

    public function secretaryQuery(?string $search = null): Builder
    {
        return Employee::query()
            ->active()
            ->visibleInErp()
            ->where(fn (Builder $query) => $this->whereHasAnyUserRole($query, ['secretary']))
            ->when($search, function (Builder $query) use ($search) {
                $query->where(function (Builder $searchQuery) use ($search) {
                    $searchQuery
                        ->where('full_name', 'like', '%' . $search . '%')
                        ->orWhere('staff_id', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('full_name');
    }

    public function regionalManagersQuery(Employee $actor): Builder
    {
        return $this->regionalRoleQuery($actor, [
            'manager',
            'departmental_manager',
            'district_manager',
        ]);
    }

    public function regionalChiefManagersQuery(Employee $actor): Builder
    {
        return $this->regionalRoleQuery($actor, [
            'chief_manager',
            'regional_chief_manager',
        ]);
    }

    /** Remarks are written by whoever has the letter on their desk, after confirming the hardcopy. */
    protected function assertMayAnnotate(MailLetter $letter, Employee $actor): void
    {
        if ($letter->isClosed()) {
            throw new \RuntimeException('This letter is closed. Re-open it before adding remarks.');
        }

        if ($this->pendingIncomingRoute($letter, $actor)) {
            throw new \RuntimeException('Confirm hardcopy receipt before adding remarks to this letter.');
        }

        if (! $this->holdsLetter($letter, $actor)) {
            throw new \RuntimeException('Only the current holder of this letter can add remarks.');
        }
    }

    protected function latestLogOf(MailLetter $letter): ?LetterStatusLog
    {
        return LetterStatusLog::query()
            ->where('letter_id', $letter->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * PREFIX-YYYY-NNN from the locked (prefix, year) counter. Must run inside a transaction (create() provides it): the
     * row lock is what makes two secretaries saving at once take different numbers, and it is held until that commit.
     */
    protected function nextSnNumber(int $regionId): string
    {
        $prefix = $this->regionPrefix(Region::findOrFail($regionId));
        $year = now()->year;
        $counter = $this->lockedSnCounter($prefix, $year);

        do {
            $counter->last_number++;
            $sn = $this->formatSn($prefix, $year, $counter->last_number);
            // A number taken outside the counter (hand-entered, imported) is skipped rather than colliding with the unique index.
        } while (MailLetter::query()->where('sn_number', $sn)->exists());

        $counter->save();

        return $sn;
    }

    /** The serial-number prefix of $region; one without a prefix yet is given one here (see Region::assignLetterPrefix()). */
    protected function regionPrefix(Region $region): string
    {
        return $region->assignLetterPrefix();
    }

    /** Numbers are padded to three digits and go on past them: ...-999, ...-1000, ...-1001. */
    protected function formatSn(string $prefix, int $year, int $number): string
    {
        return $prefix.'-'.$year.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The (prefix, year) counter row, locked. A missing row is created seeded from the highest number already issued
     * under that prefix and year; if another request creates it first (unique [prefix, year]) we read theirs once.
     */
    protected function lockedSnCounter(string $prefix, int $year): LetterSnCounter
    {
        $find = fn () => LetterSnCounter::query()->where('prefix', $prefix)->where('year', $year)->lockForUpdate()->first();

        if ($counter = $find()) {
            return $counter;
        }

        try {
            $this->createSnCounter($prefix, $year);
        } catch (UniqueConstraintViolationException $e) {
            if (! ($counter = $find())) {
                throw $e;
            }
        }

        return $counter ?? $find();
    }

    /** In its own (savepoint) transaction so losing the race to the unique index cannot poison the caller's. */
    protected function createSnCounter(string $prefix, int $year): void
    {
        DB::transaction(fn () => LetterSnCounter::query()->create([
            'prefix' => $prefix,
            'year' => $year,
            'last_number' => $this->highestIssuedNumber($prefix, $year),
        ]));
    }

    /** Numerically, in PHP: never by sorting the sn_number strings. The LIKE only narrows the rows the regex then checks. */
    protected function highestIssuedNumber(string $prefix, int $year): int
    {
        $pattern = '/^'.preg_quote($prefix, '/').'-'.$year.'-(\d+)$/';

        return MailLetter::query()
            ->where('sn_number', 'like', $prefix.'-'.$year.'-%')
            ->pluck('sn_number')
            ->map(fn (string $sn) => preg_match($pattern, $sn, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;
    }

    protected function regionalRoleQuery(Employee $actor, array $roles): Builder
    {
        return Employee::query()
            ->active()
            ->visibleInErp()
            ->when(
                $actor->region_id,
                fn (Builder $query) => $query->where('region_id', $actor->region_id),
                fn (Builder $query) => $query->whereNull('region_id')
            )
            ->where(fn (Builder $query) => $this->whereHasAnyUserRole($query, $roles))
            ->orderBy('full_name');
    }

    protected function whereHasAnyUserRole(Builder $query, array $roles): Builder
    {
        return $query
            ->whereHas('user.roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', $roles))
            ->orWhereHas('userByStaffId.roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', $roles));
    }
}
