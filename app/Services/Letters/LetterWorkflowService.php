<?php

namespace App\Services\Letters;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterDelivery;
use App\Models\LetterDispatchBatch;
use App\Models\LetterNotification;
use App\Models\LetterRemark;
use App\Models\LetterSnCounter;
use App\Models\LetterStatusLog;
use App\Models\MailLetter;
use App\Models\Permission;
use App\Models\Region;
use App\Models\RoutingHistory;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

        $this->assertEligibleRecipient($from, $to);

        DB::transaction(fn () => $this->recordHop($letter, $from, $to));
    }

    /** The id behind the picker is never trusted: the recipient must be someone recipientsQuery() would list for $from. */
    protected function assertEligibleRecipient(Employee $from, Employee $to): void
    {
        if (! $this->recipientsQuery(null, $from)->whereKey($to->id)->exists()) {
            throw new \RuntimeException('The selected recipient cannot receive letters.');
        }
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

        $this->assertEligibleRecipient($from, $to);

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

        // The link is what lets recall/reject delete exactly the log this hop created.
        LetterStatusLog::create([
            'letter_id' => $letter->id,
            'secretariat_id' => $to->id,
            'routing_history_id' => $hop->id,
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
                ->awaiting()
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
        $pending = (clone $hops)->awaiting()->count();

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
            ->awaiting()
            ->count();
    }

    /** Quick filter "Awaiting my confirmation": letters with an unconfirmed hop addressed to $actor. */
    public function whereAwaitingConfirmation(Builder $letters, Employee $actor): Builder
    {
        return $letters->whereHas('routingHistories', fn (Builder $hops) => $hops
            ->where('to_secretariat_id', $actor->id)
            ->awaiting());
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
                ->awaiting())
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

    // ---- Recall, reject, remind ---------------------------------------------------------------------------
    //
    // Only a hop that is still awaiting confirmation can be taken back: once the recipient has confirmed, custody has
    // changed and they must dispatch the letter back themselves. Whoever takes the row lock first wins a race between
    // a confirm and a recall/reject; the other gets the message below (the D1 handling shows it).

    /**
     * The sender takes back a hand-over the recipient has not confirmed yet.
     */
    public function recall(RoutingHistory $hop, Employee $sender, ?string $note = null): void
    {
        $note = $this->cleanNote($note);

        DB::transaction(function () use ($hop, $sender, $note) {
            $locked = RoutingHistory::query()->lockForUpdate()->findOrFail($hop->id);

            if ($locked->from_secretariat_id !== $sender->id) {
                throw new \RuntimeException('Only the sender of a hand-over can recall it.');
            }

            $this->assertAwaiting($locked, recall: true);
            $this->resolveHop($locked, 'recalled', $note);
            $this->afterResolution(collect([$locked]));
        });
    }

    /**
     * Recall every line of a transmittal that is still unconfirmed, all or nothing ("the whole bag went to the wrong
     * desk"). Lines the recipient already confirmed stay where they are. Returns the number of lines recalled.
     */
    public function recallBatch(LetterDispatchBatch $batch, Employee $sender, ?string $note = null): int
    {
        $note = $this->cleanNote($note);

        return DB::transaction(function () use ($batch, $sender, $note) {
            $locked = LetterDispatchBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($locked->from_secretariat_id !== $sender->id) {
                throw new \RuntimeException('Only the sender of a transmittal can recall it.');
            }

            $hops = $locked->routingHistories()->awaiting()->orderBy('id')->lockForUpdate()->get();

            if ($hops->isEmpty()) {
                throw new \RuntimeException('Every line of this transmittal has been confirmed or already resolved, so there is nothing to recall.');
            }

            $hops->each(fn (RoutingHistory $hop) => $this->resolveHop($hop, 'recalled', $note));
            $this->afterResolution($hops);

            AuditLog::record('recall_letter_batch', 'letters', 'letter_dispatch_batches', $locked->id, null, [
                'batch_no' => $locked->batch_no,
                'routing_history_ids' => $hops->pluck('id')->all(),
                'letter_ids' => $hops->pluck('letter_id')->all(),
            ], array_filter(['note' => $note]) ?: null);

            return $hops->count();
        });
    }

    /**
     * The recipient refuses a hand-over they will not take (wrong desk, not meant for them). The reason is mandatory and
     * goes to the sender in a notification.
     */
    public function reject(RoutingHistory $hop, Employee $recipient, string $reason): void
    {
        $this->rejectLines($recipient, [$hop->id], $reason);
    }

    /**
     * Reject several of the recipient's own awaiting hops with one reason ("Reject ticked"). Hop ids come from the
     * client, so only hops addressed to $recipient that are still awaiting are touched; anything else is ignored. Each
     * sender gets one notification per transmittal (or per letter for single dispatches). Returns the number rejected.
     *
     * @param  array<int|string>  $hopIds
     */
    public function rejectLines(Employee $recipient, array $hopIds, string $reason): int
    {
        $reason = $this->cleanReason($reason);
        $ids = collect($hopIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        return DB::transaction(function () use ($recipient, $ids, $reason) {
            $hops = RoutingHistory::query()
                ->where('to_secretariat_id', $recipient->id)
                ->awaiting()
                ->whereIn('id', $ids->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($hops->isEmpty()) {
                // Either someone else's hop, or it was confirmed/recalled since the page was drawn.
                throw new \RuntimeException('No pending hardcopy receipt confirmation was found.');
            }

            $hops->each(fn (RoutingHistory $hop) => $this->resolveHop($hop, 'rejected', $reason));
            $this->afterResolution($hops);
            $this->notifySendersOfRejection($hops, $recipient, $reason);

            $hops->whereNotNull('batch_id')->groupBy('batch_id')->each(function (Collection $lines, $batchId) use ($reason) {
                AuditLog::record('reject_letter_batch', 'letters', 'letter_dispatch_batches', (int) $batchId, null, [
                    'routing_history_ids' => $lines->pluck('id')->all(),
                    'letter_ids' => $lines->pluck('letter_id')->all(),
                ], ['note' => $reason]);
            });

            return $hops->count();
        });
    }

    /**
     * Nudge the recipient about a hand-over (one hop) or every awaiting line of a transmittal. Only the sender, only
     * awaiting hops, and not again inside letters_remind_cooldown_hours. Returns the number of lines reminded.
     */
    public function remind(Employee $sender, RoutingHistory|LetterDispatchBatch $target): int
    {
        return DB::transaction(function () use ($sender, $target) {
            if ($target instanceof LetterDispatchBatch) {
                $batch = LetterDispatchBatch::query()->lockForUpdate()->findOrFail($target->id);

                if ($batch->from_secretariat_id !== $sender->id) {
                    throw new \RuntimeException('Only the sender of a transmittal can remind the recipient.');
                }

                $hops = $batch->routingHistories()->awaiting()->orderBy('id')->lockForUpdate()->get();
            } else {
                $batch = null;
                $hop = RoutingHistory::query()->lockForUpdate()->findOrFail($target->id);

                if ($hop->from_secretariat_id !== $sender->id) {
                    throw new \RuntimeException('Only the sender of a hand-over can remind the recipient.');
                }

                $hops = collect([$hop])->filter(fn (RoutingHistory $line) => $line->isAwaiting());
            }

            if ($hops->isEmpty()) {
                throw new \RuntimeException('Nothing is waiting for confirmation any more, so there is nobody to remind.');
            }

            $recipient = Employee::query()->find($hops->first()->to_secretariat_id);

            if ($until = $this->remindCooldownEndsAt($hops)) {
                throw new \RuntimeException('You already reminded '.($recipient?->full_name ?? 'the recipient').' '.$hops->max('reminded_at')->diffForHumans().'. You can remind again '.$this->waitUntilLabel($until).'.');
            }

            $now = now();
            $hops->each(fn (RoutingHistory $line) => $line->update(['reminded_at' => $now]));

            if ($batch) {
                LetterNotification::create([
                    'title' => 'Reminder: letters awaiting your confirmation',
                    'message' => $hops->count().' '.Str::plural('letter', $hops->count()).' ('.$batch->batch_no.') sent '.$batch->dispatched_at->diffForHumans().' still need hardcopy receipt confirmation.',
                    'secretariat_id' => $batch->to_secretariat_id,
                    'letter_id' => null,
                    'batch_id' => $batch->id,
                ]);
            } else {
                $line = $hops->first();
                LetterNotification::create([
                    'title' => 'Reminder: letter awaiting your confirmation',
                    'message' => ($line->letter?->sn_number ?? 'A letter').' sent '.$line->created_at->diffForHumans().' still needs hardcopy receipt confirmation.',
                    'secretariat_id' => $line->to_secretariat_id,
                    'letter_id' => $line->letter_id,
                ]);
            }

            foreach ($hops as $line) {
                AuditLog::record('remind_letter_recipient', 'letters', 'mail_letters', $line->letter_id, null, [
                    'routing_history_id' => $line->id,
                    'to_secretariat_id' => $line->to_secretariat_id,
                ], $line->batch_id ? ['batch_id' => $line->batch_id] : null);
            }

            if ($batch) {
                AuditLog::record('remind_letter_batch', 'letters', 'letter_dispatch_batches', $batch->id, null, [
                    'batch_no' => $batch->batch_no,
                    'routing_history_ids' => $hops->pluck('id')->all(),
                ]);
            }

            return $hops->count();
        });
    }

    /** When a reminder may next be sent for these hops, or null if one may be sent now. */
    public function remindCooldownEndsAt(iterable $hops): ?Carbon
    {
        $hours = max(0, (int) config('gwl.letters_remind_cooldown_hours', 24));
        $last = collect($hops)->pluck('reminded_at')->filter()->max();

        if ($hours === 0 || ! $last) {
            return null;
        }

        $until = Carbon::parse($last)->addHours($hours);

        return $until->isFuture() ? $until : null;
    }

    /** "in 21 hours" / "in 40 minutes": rounded up, so the wait is never understated. */
    public function waitUntilLabel(CarbonInterface $until): string
    {
        $minutes = max(1, (int) ceil(now()->diffInMinutes($until, true)));

        if ($minutes < 60) {
            return 'in '.$minutes.' '.Str::plural('minute', $minutes);
        }

        $hours = (int) ceil($minutes / 60);

        return 'in '.$hours.' '.Str::plural('hour', $hours);
    }

    /** Days an unconfirmed hand-over may wait before it is overdue (amber); red at twice as long. */
    public function alertDays(): int
    {
        return max(1, (int) config('gwl.letters_unconfirmed_alert_days', 2));
    }

    /** 'warning' once a hop has waited the alert days, 'danger' at twice that, null before. */
    public function agingTone(?CarbonInterface $since): ?string
    {
        if (! $since) {
            return null;
        }

        return match (true) {
            $since->lte(now()->subDays($this->alertDays() * 2)) => 'danger',
            $since->lte(now()->subDays($this->alertDays())) => 'warning',
            default => null,
        };
    }

    /** Whole days $since has been waiting, for "waiting 3 days" labels. */
    public function waitingDays(CarbonInterface $since): int
    {
        return max(0, (int) floor($since->diffInDays(now(), true)));
    }

    /** Hops $actor sent that have waited at least the alert days: the dashboard tile and the Sent tab's Overdue filter. */
    public function overdueSentCount(Employee $actor): int
    {
        return RoutingHistory::query()
            ->where('from_secretariat_id', $actor->id)
            ->overdue($this->alertDays())
            ->count();
    }

    /**
     * For each of $letters, the newest hop $actor sent that is still awaiting confirmation (with the recipient
     * loaded), keyed by letter id: the "awaiting confirmation by X" hint and Recall link on the sender's row. One query.
     *
     * @param  Collection<int, MailLetter>  $letters
     * @return Collection<int, RoutingHistory>
     */
    public function outgoingAwaiting(Collection $letters, Employee $actor): Collection
    {
        return RoutingHistory::query()
            ->whereIn('letter_id', $letters->pluck('id')->all())
            ->where('from_secretariat_id', $actor->id)
            ->awaiting()
            ->with('toSecretariat')
            ->orderBy('id')
            ->get()
            ->keyBy('letter_id');
    }

    /** What a recall and a reject both do to one awaiting hop (already locked by the caller). */
    protected function resolveHop(RoutingHistory $hop, string $resolution, ?string $note): void
    {
        $hop->update([
            'resolution' => $resolution,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);

        // The recipient never held the letter: take away the log (and so the visibility) this hop gave them...
        $log = LetterStatusLog::query()->where('routing_history_id', $hop->id)->first()
            // ...found by matching for hops that predate the link and were not backfilled.
            ?? LetterStatusLog::query()
                ->where('letter_id', $hop->letter_id)
                ->where('secretariat_id', $hop->to_secretariat_id)
                ->where('status', 'Received')
                ->whereNull('routing_history_id')
                ->where('created_at', '>=', $hop->created_at)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

        if ($log?->status === 'Received') {
            $log->delete();
        }

        // ...and give the letter back to the sender's desk, so canDispatch() is true for them again.
        $senderLog = LetterStatusLog::query()
            ->where('letter_id', $hop->letter_id)
            ->where('secretariat_id', $hop->from_secretariat_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($senderLog?->status === 'Dispatched') {
            $senderLog->update(['status' => 'Received', 'out_date' => null]);
        }

        // A single dispatch notified the recipient about this letter alone, and they can no longer open it.
        if (! $hop->batch_id) {
            LetterNotification::query()
                ->where('secretariat_id', $hop->to_secretariat_id)
                ->where('letter_id', $hop->letter_id)
                ->whereNull('batch_id')
                ->where('created_at', '>=', $hop->created_at)
                ->delete();
        }

        AuditLog::record($resolution === 'recalled' ? 'recall_letter' : 'reject_letter', 'letters', 'mail_letters', $hop->letter_id, null, [
            'routing_history_id' => $hop->id,
            'from_secretariat_id' => $hop->from_secretariat_id,
            'to_secretariat_id' => $hop->to_secretariat_id,
        ], array_filter(['batch_id' => $hop->batch_id, 'note' => $note]) ?: null);
    }

    /** Batch counters and the recipient's batch notification follow the lines that were just resolved. */
    protected function afterResolution(Collection $hops): void
    {
        $hops->pluck('batch_id')->filter()->unique()->each(function ($batchId) {
            $this->refreshBatchProgress((int) $batchId);

            $batch = LetterDispatchBatch::query()->find($batchId);
            $awaiting = $batch->routingHistories()->awaiting()->count();

            foreach (LetterNotification::query()->where('batch_id', $batch->id)->where('secretariat_id', $batch->to_secretariat_id)->get() as $notification) {
                $awaiting === 0
                    ? $notification->update(['is_read' => true])
                    : $notification->update(['message' => $awaiting.' '.Str::plural('letter', $awaiting).' ('.$batch->batch_no.') need hardcopy receipt confirmation.']);
            }
        });
    }

    /** One notification to each sender: per transmittal for batched lines, per letter for single dispatches. */
    protected function notifySendersOfRejection(Collection $hops, Employee $recipient, string $reason): void
    {
        foreach ($hops->whereNotNull('batch_id')->groupBy('batch_id') as $batchId => $lines) {
            $batch = LetterDispatchBatch::query()->find($batchId);

            LetterNotification::create([
                'title' => 'Letters rejected',
                'message' => $recipient->full_name.' rejected '.$lines->count().' '.Str::plural('letter', $lines->count()).' of '.$batch->batch_no.': '.$reason,
                'secretariat_id' => $batch->from_secretariat_id,
                'letter_id' => null,
                'batch_id' => $batch->id,
            ]);
        }

        foreach ($hops->whereNull('batch_id') as $hop) {
            LetterNotification::create([
                'title' => 'Letter rejected',
                'message' => ($hop->letter?->sn_number ?? 'A letter').' was rejected by '.$recipient->full_name.': '.$reason,
                'secretariat_id' => $hop->from_secretariat_id,
                'letter_id' => $hop->letter_id,
            ]);
        }
    }

    protected function assertAwaiting(RoutingHistory $hop, bool $recall): void
    {
        if ($hop->isResolved()) {
            throw new \RuntimeException('This hand-over was already '.$hop->resolution.'.');
        }

        if ($hop->received_confirm) {
            throw new \RuntimeException($recall
                ? 'The recipient has already confirmed this letter, so it can no longer be recalled. Ask them to dispatch it back.'
                : 'You have already confirmed this letter.');
        }
    }

    protected function cleanNote(?string $note): ?string
    {
        return filled($note) ? Str::limit(trim($note), 500, '') : null;
    }

    protected function cleanReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new \RuntimeException('Give a reason of at least 5 characters so the sender knows why.');
        }

        return Str::limit($reason, 500, '');
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

            if ($locked->routingHistories()->awaiting()->exists()) {
                throw new \RuntimeException('This letter is still waiting for hardcopy confirmation and cannot be closed yet.');
            }

            $locked->update(['closed_at' => now(), 'closed_by_id' => $actor->id]);

            AuditLog::record('close_letter', 'letters', 'mail_letters', $locked->id);
        });

        $letter->refresh();
    }

    /**
     * The last step: the current holder hands the hardcopy to its addressee and records who took it and when. The
     * addressee needs no login; the holder records the paper signature. Exactly one of a staff member
     * (`delivered_to_employee_id`) or an outside party's name (`delivered_to_name`). Closes the letter at letter level
     * (closer = the holder); a manual close/reopen stays with the creator. `delivered_at` defaults to now and cannot be
     * in the future; `note` is optional.
     *
     * @param  array{delivered_to_employee_id?: int|string|null, delivered_to_name?: ?string, delivered_at?: mixed, note?: ?string}  $data
     */
    public function deliver(MailLetter $letter, Employee $holder, array $data): LetterDelivery
    {
        $toEmployeeId = filled($data['delivered_to_employee_id'] ?? null) ? (int) $data['delivered_to_employee_id'] : null;
        $toName = filled($data['delivered_to_name'] ?? null) ? trim((string) $data['delivered_to_name']) : null;

        if (($toEmployeeId === null) === ($toName === null)) {
            throw new \RuntimeException('Record who received the letter: choose a staff member or type the name of the person, not both and not neither.');
        }

        if ($toEmployeeId !== null && ! Employee::query()->whereKey($toEmployeeId)->exists()) {
            throw new \RuntimeException('The selected addressee was not found.');
        }

        $deliveredAt = filled($data['delivered_at'] ?? null) ? Carbon::parse($data['delivered_at']) : now();

        if ($deliveredAt->isAfter(now()->addMinutes(5))) {
            throw new \RuntimeException('The delivery time cannot be in the future.');
        }

        return DB::transaction(function () use ($letter, $holder, $toEmployeeId, $toName, $deliveredAt, $data) {
            $locked = MailLetter::query()->lockForUpdate()->findOrFail($letter->id);

            if ($locked->isClosed()) {
                throw new \RuntimeException('This letter is already closed.');
            }

            if ($this->pendingIncomingRoute($locked, $holder)) {
                throw new \RuntimeException('Confirm hardcopy receipt before delivering this letter.');
            }

            if (! $this->holdsLetter($locked, $holder)) {
                throw new \RuntimeException('Only the current holder of this letter can record its delivery.');
            }

            $delivery = LetterDelivery::create([
                'letter_id' => $locked->id,
                'delivered_to_employee_id' => $toEmployeeId,
                'delivered_to_name' => $toName,
                'delivered_by_id' => $holder->id,
                'delivered_at' => $deliveredAt,
                'note' => $this->cleanNote($data['note'] ?? null),
            ]);

            $locked->update(['closed_at' => now(), 'closed_by_id' => $holder->id]);

            AuditLog::record('deliver_letter', 'letters', 'mail_letters', $locked->id, null, [
                'delivery_id' => $delivery->id,
                'delivered_to_employee_id' => $toEmployeeId,
                'delivered_to_name' => $toName,
                'delivered_by_id' => $holder->id,
                'delivered_at' => $deliveredAt->toDateTimeString(),
            ], $delivery->note ? ['note' => $delivery->note] : null);

            $letter->refresh();

            return $delivery;
        });
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
        $this->assertReviewerMatchesHolder($actor, $data);

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
        $this->assertReviewerMatchesHolder($actor, $data);

        $fields =['manager_id', 'chief_manager_id', 'remark_content', 'secretary_remark_content'];
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
            ->awaiting()
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
            ->awaiting()
            ->latest()
            ->orderByDesc('id')
            ->first();
    }

    public function visibleLettersQuery(Employee $actor): Builder
    {
        return MailLetter::query()
            ->whereHas('statusLogs', fn (Builder $query) => $query->where('secretariat_id', $actor->id));
    }

    /**
     * Who $actor may hand a letter to: active, visible employees whose user can sign in and holds `letters.view` through
     * a role that also gives the Letters module. By permission, not by role name, so an admin can extend it from the role
     * editor. $scope narrows by place: 'mine' (the actor's own office), 'head_office', or null / 'any' for everyone.
     * The actor is never their own recipient.
     */
    public function recipientsQuery(?string $search, Employee $actor, ?string $scope = null): Builder
    {
        return Employee::query()
            ->active()
            ->visibleInErp()
            ->whereKeyNot($actor->id)
            ->where(fn (Builder $query) => $this->whereHasUserWithPermission($query, 'letters.view', withModule: true))
            ->when($scope === 'mine', fn (Builder $query) => $query->where(fn (Builder $office) => $this->whereInActorsOffice($office, $actor, sameDepartment: false)))
            ->when($scope === 'head_office', fn (Builder $query) => $query->where('location_type', 'HeadOffice'))
            ->when(filled($search), function (Builder $query) use ($search) {
                $like = '%'.trim($search).'%';

                $query->where(fn (Builder $match) => $match
                    ->where('full_name', 'like', $like)
                    ->orWhere('staff_id', 'like', $like)
                    ->orWhereHas('department', fn (Builder $department) => $department->where('department_name', 'like', $like)));
            })
            ->orderBy('full_name');
    }

    /** "Name · Department · Location", the label every recipient carries in the picker. */
    public function recipientLabel(Employee $employee): string
    {
        return collect([
            $employee->full_name,
            $employee->department?->department_name,
            $employee->district?->district_name,
        ])->filter()->join(' · ');
    }

    /**
     * The recipient picker for one or several letters the actor is about to hand on: the previous holder (who handed
     * the letter(s) to the actor, when it is the same person for all of them) and recent recipients pinned on top, then
     * the matches for $search and $scope split into Secretaries (people who can record letters) and Managers (the
     * rest). Every one of them is an eligible recipient (recipientsQuery), but the picker is never trusted: dispatch()
     * and dispatchBatch() check again.
     *
     * @param  Collection<int, MailLetter>  $letters
     * @return array{previous: ?Employee, recent: Collection<int, Employee>, secretaries: Collection<int, Employee>, managers: Collection<int, Employee>}
     */
    public function recipientPicker(Employee $actor, Collection $letters, ?string $search, string $scope): array
    {
        $with = ['department', 'district'];
        $previous = $this->previousHolderFor($letters, $actor);

        $recentIds = RoutingHistory::query()
            ->where('from_secretariat_id', $actor->id)
            ->unresolved()
            ->groupBy('to_secretariat_id')
            ->selectRaw('to_secretariat_id, max(id) as last_hop')
            ->orderByDesc('last_hop')
            ->limit(8)
            ->pluck('to_secretariat_id');

        $recent = $this->recipientsQuery(null, $actor)
            ->whereIn('id', $recentIds->all())
            ->when($previous, fn (Builder $query) => $query->whereKeyNot($previous->id))
            ->with($with)
            ->get()
            ->sortBy(fn (Employee $employee) => $recentIds->search($employee->id))
            ->take(5)
            ->values();

        $pinned = $recent->pluck('id')->when($previous, fn (Collection $ids) => $ids->push($previous->id))->all();

        $matches = $this->recipientsQuery($search, $actor, $scope)
            ->when($pinned !== [], fn (Builder $query) => $query->whereNotIn('id', $pinned))
            ->with($with)
            ->limit(40)
            ->get();

        // "Secretary" is what people who can record letters are; everyone else eligible is shown as a manager.
        $secretaryIds = $matches->isEmpty() ? collect() : Employee::query()
            ->whereIn('id', $matches->pluck('id')->all())
            ->where(fn (Builder $query) => $this->whereHasUserWithPermission($query, 'letters.create', withModule: false))
            ->pluck('id');

        [$secretaries, $managers] = $matches->partition(fn (Employee $employee) => $secretaryIds->contains($employee->id));

        return [
            'previous' => $previous,
            'recent' => $recent,
            'secretaries' => $secretaries->values(),
            'managers' => $managers->values(),
        ];
    }

    /**
     * The one person who handed all of $letters to $actor, if they are still an eligible recipient: "Return to
     * previous holder". Null when any letter never reached the actor by a hand-over (they recorded it themselves) or
     * when they came from different people.
     *
     * @param  Collection<int, MailLetter>  $letters
     */
    public function previousHolderFor(Collection $letters, Employee $actor): ?Employee
    {
        if ($letters->isEmpty()) {
            return null;
        }

        $senders = RoutingHistory::query()
            ->whereIn('letter_id', $letters->pluck('id')->all())
            ->where('to_secretariat_id', $actor->id)
            ->unresolved()
            ->orderByDesc('id')
            ->get()
            ->unique('letter_id')
            ->pluck('from_secretariat_id', 'letter_id');

        if ($senders->count() !== $letters->count() || $senders->unique()->count() !== 1) {
            return null;
        }

        return $this->recipientsQuery(null, $actor)->with(['department', 'district'])->find($senders->first());
    }

    /** The roles whose holders may be named as a letter's Manager reviewer, and as its Chief Manager reviewer. */
    public const MANAGER_REVIEWER_ROLES = ['manager', 'departmental_manager', 'district_manager'];

    public const CHIEF_REVIEWER_ROLES = ['chief_manager', 'regional_chief_manager'];

    public function regionalManagersQuery(Employee $actor): Builder
    {
        return $this->regionalRoleQuery($actor, self::MANAGER_REVIEWER_ROLES);
    }

    public function regionalChiefManagersQuery(Employee $actor): Builder
    {
        return $this->regionalRoleQuery($actor, self::CHIEF_REVIEWER_ROLES);
    }

    /**
     * Whether $actor holds letters as a reviewer rather than as a secretary: 'chief' for a (regional) chief manager,
     * 'manager' for a unit / departmental / district manager, null for everyone else. A reviewer writes remarks as
     * themselves, so the remark form locks the reviewer to them and hides the secretary field.
     */
    public function reviewerTier(Employee $actor): ?string
    {
        $user = $actor->user ?? $actor->userByStaffId;

        return match (true) {
            $user === null => null,
            $user->hasRoles(self::CHIEF_REVIEWER_ROLES) => 'chief',
            $user->hasRoles(self::MANAGER_REVIEWER_ROLES) => 'manager',
            default => null,
        };
    }

    /**
     * A manager or chief manager holding the letter writes the remark as themselves: they are the reviewer, there is no
     * secretary typing someone else's comment, and they cannot name a different reviewer. Secretaries (anyone who is not
     * a manager tier) keep the original rules. The form enforces this too; this is the authority.
     */
    protected function assertReviewerMatchesHolder(Employee $actor, array $data): void
    {
        $tier = $this->reviewerTier($actor);

        if ($tier === null) {
            return;
        }

        if (filled($data['secretary_remark_content'] ?? null)) {
            throw new \RuntimeException('Secretary remarks can only be added by a secretary. Write your remark in the manager remarks.');
        }

        if (blank(trim((string) ($data['remark_content'] ?? '')))) {
            throw new \RuntimeException('Enter your remark.');
        }

        $manager = (int) ($data['manager_id'] ?? 0);
        $chief = (int) ($data['chief_manager_id'] ?? 0);

        if ($manager !== ($tier === 'manager' ? $actor->id : 0) || $chief !== ($tier === 'chief' ? $actor->id : 0)) {
            throw new \RuntimeException('As '.($tier === 'chief' ? 'a chief manager' : 'a manager').' you can only record remarks as yourself.');
        }
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

    /**
     * Reviewers for $actor's remarks: holders of $roles in the actor's own office, resolved the way Leave resolves its
     * chain. Head Office is a district that can share a region with a regional office, so it is never scoped by region
     * alone: at Head Office it is the same department at Head Office, everywhere else the same region without Head Office.
     */
    protected function regionalRoleQuery(Employee $actor, array $roles): Builder
    {
        return Employee::query()
            ->active()
            ->visibleInErp()
            ->where(fn (Builder $query) => $this->whereInActorsOffice($query, $actor, sameDepartment: true))
            ->where(fn (Builder $query) => $this->whereHasAnyUserRole($query, $roles))
            ->orderBy('full_name');
    }

    /**
     * Employees in the same office as $actor. location_type first (it follows the district's name, see
     * Employee::locationTypeFor()): Head Office staff are matched among Head Office staff only (and, with
     * $sameDepartment, in their department); regional-office and district staff are matched by region among non-Head
     * Office staff. A location-less actor falls back to the plain region match this module always used.
     */
    protected function whereInActorsOffice(Builder $query, Employee $actor, bool $sameDepartment): Builder
    {
        return match ($actor->location_type) {
            'HeadOffice' => $query
                ->where('location_type', 'HeadOffice')
                ->when($sameDepartment, fn (Builder $scoped) => $scoped->where('department_id', $actor->department_id)),
            'Region', 'District' => $query
                ->whereIn('location_type', ['Region', 'District'])
                ->where('region_id', $actor->region_id),
            default => $query->when(
                $actor->region_id,
                fn (Builder $scoped) => $scoped->where('region_id', $actor->region_id),
                fn (Builder $scoped) => $scoped->whereNull('region_id')
            ),
        };
    }

    protected function whereHasAnyUserRole(Builder $query, array $roles): Builder
    {
        return $query
            ->whereHas('user.roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', $roles))
            ->orWhereHas('userByStaffId.roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', $roles));
    }

    /**
     * Users (linked to the employee by employee_id or by staff_id) who can sign in and hold, through one role,
     * $permission. With $withModule the same role must also give access to the Letters module: someone who cannot open
     * the module must not be handed a letter.
     */
    protected function whereHasUserWithPermission(Builder $query, string $permission, bool $withModule): Builder
    {
        $eligibleUser = fn (Builder $user) => $user
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $role) => $role
                ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('name', $permission))
                ->when($withModule, fn (Builder $scoped) => $scoped->whereHas('moduleAccesses', fn (Builder $access) => $access
                    ->where('module', Permission::MODULE_LETTERS)
                    ->where('can_access', true))));

        return $query
            ->whereHas('user', $eligibleUser)
            ->orWhereHas('userByStaffId', $eligibleUser);
    }
}
