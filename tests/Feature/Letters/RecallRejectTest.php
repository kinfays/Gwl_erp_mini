<?php

namespace Tests\Feature\Letters;

use App\Livewire\Letters\ActiveLetters;
use App\Livewire\Letters\Notifications;
use App\Livewire\Letters\Transmittals;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\LetterNotification;
use App\Models\LetterStatusLog;
use App\Models\MailLetter;
use App\Models\RoutingHistory;
use App\Services\Letters\LetterWorkflowService;
use App\Support\ErpNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class RecallRejectTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected Employee $cmSec;

    protected Employee $matSec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
        $this->cmSec = $this->letterStaff('CM001', $this->accraOffice);
        $this->matSec = $this->letterStaff('MAT01', $this->accraOffice);
    }

    /** One letter dispatched singly hr -> cm and left unconfirmed. */
    private function single(string $subject = 'Single'): array
    {
        $letter = $this->createLetter($this->hrSec, ['subject' => $subject]);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);

        return [$letter, RoutingHistory::query()->where('letter_id', $letter->id)->sole()];
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: LetterDispatchBatch} */
    private function batch(int $count = 3, ?Employee $to = null): array
    {
        $letters = $this->createLetters($this->hrSec, $count, 'Batch');

        return [$letters, $this->lettersWorkflow()->dispatchBatch($this->hrSec, $to ?? $this->cmSec, $letters->pluck('id')->all())];
    }

    private function audit(string $action)
    {
        return AuditLog::query()->where('action', $action)->orderBy('id')->get();
    }

    private function as(Employee $employee, string $component)
    {
        $this->actingAs($this->letterUserOf($employee));

        return Livewire::test($component);
    }

    // ---- recall: one hop ----------------------------------------------------------------------------------

    public function test_recalling_a_hop_gives_the_letter_back_to_the_senders_desk(): void
    {
        [$letter, $hop] = $this->single();
        $this->assertFalse($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));

        $this->lettersWorkflow()->recall($hop, $this->hrSec, '  Wrong desk  ');

        $hop->refresh();
        $this->assertSame('recalled', $hop->resolution);
        $this->assertNotNull($hop->resolved_at);
        $this->assertSame('Wrong desk', $hop->resolution_note);
        $this->assertFalse($hop->received_confirm);

        $senderLog = $this->lettersWorkflow()->currentLog($letter, $this->hrSec);
        $this->assertSame('Received', $senderLog->status);
        $this->assertNull($senderLog->out_date);
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->hrSec), 'the sender can dispatch it again');

        $this->assertSame(0, LetterStatusLog::query()->where('letter_id', $letter->id)->where('secretariat_id', $this->cmSec->id)->count(), "the recipient's never-held log is deleted");
        $this->assertFalse($this->lettersWorkflow()->visibleLettersQuery($this->cmSec)->whereKey($letter->id)->exists(), 'so the recipient no longer sees the letter');
        $this->assertSame(0, LetterNotification::query()->where('secretariat_id', $this->cmSec->id)->count(), 'and its notification is gone');
    }

    public function test_a_recall_is_audited_with_the_note_and_the_hop(): void
    {
        [$letter, $hop] = $this->single();

        $this->lettersWorkflow()->recall($hop, $this->hrSec, 'Wrong desk');

        $audit = $this->audit('recall_letter')->sole();
        $this->assertSame($letter->id, $audit->target_id);
        $this->assertSame('mail_letters', $audit->target_type);
        $this->assertSame($hop->id, $audit->new_values['routing_history_id']);
        $this->assertSame(['note' => 'Wrong desk'], $audit->metadata);
    }

    public function test_a_recalled_letter_can_be_closed_and_sent_to_someone_else(): void
    {
        [$letter, $hop] = $this->single();
        $this->lettersWorkflow()->recall($hop, $this->hrSec);

        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->matSec);
        $this->assertNotNull($this->lettersWorkflow()->pendingIncomingRoute($letter, $this->matSec));

        [$other, $otherHop] = $this->single('Other');
        $this->lettersWorkflow()->recall($otherHop, $this->hrSec);
        $this->lettersWorkflow()->close($other, $this->hrSec); // a resolved hop no longer blocks close()
        $this->assertTrue($other->fresh()->isClosed());
    }

    public function test_only_the_sender_can_recall_and_only_while_unconfirmed(): void
    {
        [$letter, $hop] = $this->single();

        foreach ([[$this->cmSec, 'Only the sender of a hand-over can recall it.'], [$this->matSec, 'Only the sender of a hand-over can recall it.']] as [$actor, $message]) {
            try {
                $this->lettersWorkflow()->recall($hop, $actor);
                $this->fail('A non-sender must not recall.');
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        try {
            $this->lettersWorkflow()->recall($hop, $this->hrSec);
            $this->fail('A confirmed hop cannot be recalled.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already confirmed', $e->getMessage());
        }

        $this->assertNull($hop->fresh()->resolution);
        $this->assertSame('In Review', $this->lettersWorkflow()->currentLog($letter, $this->cmSec)->status);
        $this->assertSame(0, $this->audit('recall_letter')->count());
    }

    public function test_a_hop_cannot_be_resolved_twice(): void
    {
        [$letter, $hop] = $this->single();
        $this->lettersWorkflow()->recall($hop, $this->hrSec);

        foreach ([fn () => $this->lettersWorkflow()->recall($hop, $this->hrSec), fn () => $this->lettersWorkflow()->reject($hop, $this->cmSec, 'Not for us at all')] as $again) {
            try {
                $again();
                $this->fail('A resolved hop must not be resolved again.');
            } catch (\RuntimeException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'already recalled') || str_contains($e->getMessage(), 'No pending'), $e->getMessage());
            }
        }
    }

    public function test_confirming_a_recalled_hop_is_refused_and_a_recall_after_a_confirm_loses_the_race(): void
    {
        [$recalled, $hop] = $this->single('Recalled');
        $this->lettersWorkflow()->recall($hop, $this->hrSec);

        try {
            $this->lettersWorkflow()->confirmHardcopy($recalled, $this->cmSec);
            $this->fail('A recalled hop must not be confirmable.');
        } catch (\RuntimeException $e) {
            $this->assertSame('No pending hardcopy receipt confirmation was found.', $e->getMessage());
        }

        $this->assertFalse($hop->fresh()->received_confirm);
    }

    public function test_recall_finds_the_log_of_a_hop_that_predates_the_link(): void
    {
        [$letter, $hop] = $this->single();
        DB::table('letter_status_logs')->update(['routing_history_id' => null]); // as a legacy, un-backfilled row

        $this->lettersWorkflow()->recall($hop, $this->hrSec);

        $this->assertSame(0, LetterStatusLog::query()->where('letter_id', $letter->id)->where('secretariat_id', $this->cmSec->id)->count());
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));
    }

    public function test_a_recipient_who_held_the_letter_before_keeps_their_history_when_a_later_hop_is_recalled(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->lettersWorkflow()->dispatch($letter, $this->cmSec, $this->hrSec); // back to hr, unconfirmed
        $back = RoutingHistory::query()->where('from_secretariat_id', $this->cmSec->id)->sole();

        $this->lettersWorkflow()->recall($back, $this->cmSec);

        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->cmSec), 'cm holds it again');
        $this->assertSame(1, LetterStatusLog::query()->where('letter_id', $letter->id)->where('secretariat_id', $this->hrSec->id)->count(), "hr keeps only the log from when they first held it");
        $this->assertTrue($this->lettersWorkflow()->visibleLettersQuery($this->hrSec)->whereKey($letter->id)->exists());
        $this->assertSame('Dispatched', $this->lettersWorkflow()->currentLog($letter, $this->hrSec)->status);
    }

    // ---- recall: a transmittal ----------------------------------------------------------------------------

    public function test_recalling_the_unconfirmed_lines_of_a_partly_confirmed_transmittal(): void
    {
        [$letters, $batch] = $this->batch(3);
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [$letters[0]->id], $batch);

        $recalled = $this->lettersWorkflow()->recallBatch($batch, $this->hrSec, 'Sent to the wrong desk');

        $this->assertSame(2, $recalled);
        $batch->refresh();
        $this->assertSame(1, $batch->confirmed_count, 'confirmed lines stay confirmed');
        $this->assertSame(3, $batch->letters_count);
        $this->assertNotNull($batch->completed_at, 'complete once every line is confirmed or resolved');

        $this->assertTrue($this->lettersWorkflow()->canDispatch($letters[1], $this->hrSec));
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letters[2], $this->hrSec));
        $this->assertFalse($this->lettersWorkflow()->canDispatch($letters[0], $this->hrSec), 'the confirmed line is with cm');
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letters[0], $this->cmSec));
        $this->assertSame(2, RoutingHistory::query()->where('resolution', 'recalled')->count());
        $this->assertSame(0, $this->lettersWorkflow()->pendingIncomingCount($this->cmSec));
    }

    public function test_a_batch_recall_is_audited_per_letter_and_once_for_the_batch(): void
    {
        [$letters, $batch] = $this->batch(2);

        $this->lettersWorkflow()->recallBatch($batch, $this->hrSec, 'Wrong bag');

        $perLetter = $this->audit('recall_letter');
        $this->assertCount(2, $perLetter);
        foreach ($perLetter as $row) {
            $this->assertSame(['batch_id' => $batch->id, 'note' => 'Wrong bag'], $row->metadata);
        }
        $this->assertSame($letters->pluck('id')->all(), $perLetter->pluck('target_id')->all());

        $row = $this->audit('recall_letter_batch')->sole();
        $this->assertSame($batch->id, $row->target_id);
        $this->assertSame('letter_dispatch_batches', $row->target_type);
        $this->assertSame($letters->pluck('id')->all(), $row->new_values['letter_ids']);
        $this->assertSame(['note' => 'Wrong bag'], $row->metadata);
    }

    public function test_a_batch_recall_is_all_or_nothing(): void
    {
        [$letters, $batch] = $this->batch(3);

        $this->app->bind(LetterWorkflowService::class, fn () => new class extends LetterWorkflowService
        {
            private int $calls = 0;

            protected function resolveHop(RoutingHistory $hop, string $resolution, ?string $note): void
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('Simulated failure on the second line.');
                }

                parent::resolveHop($hop, $resolution, $note);
            }
        });

        try {
            $this->lettersWorkflow()->recallBatch($batch, $this->hrSec);
            $this->fail('The simulated failure should abort the recall.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure on the second line.', $e->getMessage());
        }

        $this->assertSame(0, RoutingHistory::query()->whereNotNull('resolution')->count(), 'the first line was rolled back with the rest');
        $this->assertSame(3, LetterStatusLog::query()->where('secretariat_id', $this->cmSec->id)->count());
        $this->assertSame('Dispatched', $this->lettersWorkflow()->currentLog($letters[0], $this->hrSec)->status);
        $this->assertSame(0, $this->audit('recall_letter')->count());
    }

    public function test_a_batch_recall_needs_the_sender_and_something_unconfirmed(): void
    {
        [$letters, $batch] = $this->batch(2);

        try {
            $this->lettersWorkflow()->recallBatch($batch, $this->cmSec);
            $this->fail('Only the sender may recall a transmittal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Only the sender of a transmittal can recall it.', $e->getMessage());
        }

        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $letters->pluck('id')->all(), $batch);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nothing to recall');
        $this->lettersWorkflow()->recallBatch($batch, $this->hrSec);
    }

    public function test_the_recipients_batch_notification_follows_the_recall(): void
    {
        [$letters, $batch] = $this->batch(3);
        $notification = LetterNotification::query()->where('batch_id', $batch->id)->sole();

        $this->lettersWorkflow()->recall($batch->routingHistories()->orderBy('id')->first(), $this->hrSec);
        $notification->refresh();
        $this->assertStringContainsString('2 letters', $notification->message);
        $this->assertFalse($notification->is_read);

        $this->lettersWorkflow()->recallBatch($batch, $this->hrSec);
        $this->assertTrue($notification->fresh()->is_read, 'nothing is left to confirm');
    }

    // ---- reject -------------------------------------------------------------------------------------------

    public function test_rejecting_a_hop_returns_the_letter_and_tells_the_sender_why(): void
    {
        [$letter, $hop] = $this->single('Not ours');

        $this->lettersWorkflow()->reject($hop, $this->cmSec, '  This is meant for Materials  ');

        $hop->refresh();
        $this->assertSame('rejected', $hop->resolution);
        $this->assertSame('This is meant for Materials', $hop->resolution_note);
        $this->assertNotNull($hop->resolved_at);
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));
        $this->assertSame(0, LetterStatusLog::query()->where('letter_id', $letter->id)->where('secretariat_id', $this->cmSec->id)->count());
        $this->assertSame(0, LetterNotification::query()->where('secretariat_id', $this->cmSec->id)->count());

        $notice = LetterNotification::query()->where('secretariat_id', $this->hrSec->id)->sole();
        $this->assertSame('Letter rejected', $notice->title);
        $this->assertSame($letter->id, $notice->letter_id);
        $this->assertStringContainsString($letter->sn_number, $notice->message);
        $this->assertStringContainsString('Employee CM001', $notice->message);
        $this->assertStringContainsString('This is meant for Materials', $notice->message);

        $audit = $this->audit('reject_letter')->sole();
        $this->assertSame($letter->id, $audit->target_id);
        $this->assertSame(['note' => 'This is meant for Materials'], $audit->metadata);
    }

    public function test_a_reject_needs_a_reason_and_the_recipient(): void
    {
        [$letter, $hop] = $this->single();

        foreach (['', '   ', 'no'] as $reason) {
            try {
                $this->lettersWorkflow()->reject($hop, $this->cmSec, $reason);
                $this->fail('A reason of at least 5 characters is required.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('at least 5 characters', $e->getMessage());
            }
        }

        foreach ([$this->hrSec, $this->matSec] as $actor) {
            try {
                $this->lettersWorkflow()->reject($hop, $actor, 'Not addressed to me');
                $this->fail('Only the recipient may reject.');
            } catch (\RuntimeException $e) {
                $this->assertSame('No pending hardcopy receipt confirmation was found.', $e->getMessage());
            }
        }

        $this->assertNull($hop->fresh()->resolution);
        $this->assertSame(0, LetterNotification::query()->where('secretariat_id', $this->hrSec->id)->count());
    }

    public function test_a_confirmed_hop_cannot_be_rejected(): void
    {
        [$letter, $hop] = $this->single();
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No pending hardcopy receipt confirmation was found.');
        $this->lettersWorkflow()->reject($hop, $this->cmSec, 'Changed my mind later');
    }

    public function test_reject_lines_only_touches_the_recipients_own_awaiting_hops_and_sends_one_notice_per_batch(): void
    {
        [$letters, $batch] = $this->batch(3);
        [$single, $singleHop] = $this->single('A single one');
        $forMat = $this->createLetter($this->hrSec, ['subject' => 'For materials']);
        $this->lettersWorkflow()->dispatch($forMat, $this->hrSec, $this->matSec);
        $matHop = RoutingHistory::query()->where('letter_id', $forMat->id)->sole();
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [$letters[2]->id], $batch); // a confirmed line

        $hopIds = $batch->routingHistories()->orderBy('id')->pluck('id')->all();
        $count = $this->lettersWorkflow()->rejectLines($this->cmSec, [...$hopIds, $singleHop->id, $matHop->id, 999999], 'Belongs to another region');

        $this->assertSame(3, $count, 'two awaiting batch lines and the single hop');
        $this->assertSame(['rejected', 'rejected', null], RoutingHistory::query()->whereIn('id', $hopIds)->orderBy('id')->pluck('resolution')->all());
        $this->assertNull($matHop->fresh()->resolution, "someone else's hop is untouched");

        $batchNotice = LetterNotification::query()->where('secretariat_id', $this->hrSec->id)->where('batch_id', $batch->id)->sole();
        $this->assertNull($batchNotice->letter_id);
        $this->assertStringContainsString('rejected 2 letters of '.$batch->batch_no, $batchNotice->message);
        $this->assertSame(1, LetterNotification::query()->where('secretariat_id', $this->hrSec->id)->where('letter_id', $single->id)->count());
        $this->assertSame(2, LetterNotification::query()->where('secretariat_id', $this->hrSec->id)->count());

        $this->assertCount(3, $this->audit('reject_letter'));
        $row = $this->audit('reject_letter_batch')->sole();
        $this->assertSame($batch->id, $row->target_id);
        $this->assertSame(['note' => 'Belongs to another region'], $row->metadata);

        $batch->refresh();
        $this->assertSame(1, $batch->confirmed_count);
        $this->assertNotNull($batch->completed_at);
    }

    public function test_reject_lines_with_nothing_of_the_recipients_is_refused(): void
    {
        [$letters, $batch] = $this->batch(2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No pending hardcopy receipt confirmation was found.');
        $this->lettersWorkflow()->rejectLines($this->matSec, $batch->routingHistories()->pluck('id')->all(), 'Not mine to reject');
    }

    // ---- a resolved hop never looks pending ---------------------------------------------------------------

    public function test_recalled_and_rejected_hops_never_look_pending_anywhere(): void
    {
        [$recalledLetter, $recalledHop] = $this->single('Recalled one');
        [$rejectedLetter, $rejectedHop] = $this->single('Rejected one');
        [$batchLetters, $batch] = $this->batch(2);
        $this->lettersWorkflow()->recall($recalledHop, $this->hrSec);
        $this->lettersWorkflow()->reject($rejectedHop, $this->cmSec, 'Wrong recipient here');
        $this->lettersWorkflow()->recallBatch($batch, $this->hrSec);

        $workflow = $this->lettersWorkflow();
        $everything = MailLetter::query()->get();

        foreach ([$recalledLetter, $rejectedLetter, ...$batchLetters] as $letter) {
            $this->assertNull($workflow->pendingIncomingRoute($letter, $this->cmSec), 'pendingIncomingRoute');
            $this->assertTrue($workflow->canDispatch($letter, $this->hrSec), 'the sender holds it again');
        }

        $this->assertSame(0, $workflow->pendingIncomingCount($this->cmSec), 'sidebar badge');
        $this->assertSame(0, $workflow->overdueSentCount($this->hrSec));
        $this->assertSame([], $workflow->whereAwaitingConfirmation($workflow->visibleLettersQuery($this->cmSec), $this->cmSec)->pluck('id')->all());
        $this->assertSame(0, $workflow->outgoingAwaiting($everything, $this->hrSec)->count());
        $this->assertSame(0, $workflow->deskState($everything, $this->cmSec)->filter(fn ($s) => $s['pendingRoute'] !== null)->count());
        $this->assertSame(0, RoutingHistory::query()->awaiting()->count());
        $this->assertSame(0, LetterStatusLog::query()->where('secretariat_id', $this->cmSec->id)->count());

        // The close-while-in-transit guard ignores them.
        $this->lettersWorkflow()->close($recalledLetter, $this->hrSec);
        $this->assertTrue($recalledLetter->fresh()->isClosed());
    }

    public function test_the_incoming_tab_the_sidebar_and_the_bell_drop_a_recalled_hop(): void
    {
        [$letters, $batch] = $this->batch(2);
        [$single, $singleHop] = $this->single('Single recalled');

        $this->as($this->cmSec, Transmittals::class)
            ->assertSee($batch->batch_no)
            ->assertSee('Single recalled');

        $this->lettersWorkflow()->recallBatch($batch, $this->hrSec);
        $this->lettersWorkflow()->recall($singleHop, $this->hrSec);

        $this->as($this->cmSec, Transmittals::class)
            ->assertDontSee($batch->batch_no)
            ->assertDontSee('Single recalled')
            ->assertSee('Nothing is waiting for your confirmation.');

        $item = collect(app(ErpNavigation::class)->build($this->letterUserOf($this->cmSec)->fresh(), 'letters')['sidebar'])->firstWhere('label', 'Transmittals');
        $this->assertSame(0, $item['badge']);

        // The bell has no dead link to the recalled single letter; the batch notice is read.
        $this->as($this->cmSec, Notifications::class)->call('toggle')->assertDontSee($single->sn_number);
        $this->assertSame(0, LetterNotification::query()->where('secretariat_id', $this->cmSec->id)->where('is_read', false)->count());
    }

    public function test_the_sender_no_longer_sees_awaiting_confirmation_after_a_recall(): void
    {
        [$letter, $hop] = $this->single('Waiting one');

        $this->as($this->hrSec, ActiveLetters::class)->assertSee('awaiting confirmation by Employee CM001');

        $this->lettersWorkflow()->recall($hop, $this->hrSec);

        $this->as($this->hrSec, ActiveLetters::class)
            ->assertDontSee('awaiting confirmation by')
            ->assertSee('Waiting one');
    }

    // ---- Livewire: Transmittals ---------------------------------------------------------------------------

    public function test_the_sent_tab_recalls_a_line_and_shows_it_as_recalled(): void
    {
        [$letters, $batch] = $this->batch(2);
        $hop = $batch->routingHistories()->orderBy('id')->first();

        $this->as($this->hrSec, Transmittals::class)
            ->call('setTab', 'sent')
            ->assertSee('Recall unconfirmed lines (2)')
            ->call('recallLine', $hop->id)
            ->assertDispatched('toast', type: 'success', message: $letters[0]->sn_number.' recalled. It is back on your desk.')
            ->assertSee('Recalled')
            ->assertSee('0 of 2 confirmed · 1 recalled/rejected', false) // the pill counts confirmations only
            ->assertSee('Recall unconfirmed lines (1)');
    }

    public function test_a_fully_recalled_transmittal_is_not_shown_as_a_success(): void
    {
        [$letters, $batch] = $this->batch(2);
        $this->lettersWorkflow()->recallBatch($batch, $this->hrSec);

        $html = $this->as($this->hrSec, Transmittals::class)->call('setTab', 'sent')->html();

        $this->assertSame(1, preg_match('/'.preg_quote($batch->batch_no, '/').'.*?ui-pill-(\w+)[^>]*>\s*0 of 2 confirmed · 2 recalled\/rejected/s', $html, $m));
        $this->assertSame('muted', $m[1], 'complete, but nothing was confirmed: grey, not green');
    }

    public function test_the_sent_tab_recalls_the_unconfirmed_lines_of_a_transmittal(): void
    {
        [$letters, $batch] = $this->batch(3);
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [$letters[0]->id], $batch);

        $this->as($this->hrSec, Transmittals::class)
            ->call('setTab', 'sent')
            ->assertSee('Recall unconfirmed lines (2)')
            ->call('recallBatch', $batch->id)
            ->assertDispatched('toast', type: 'success', message: "2 letters of {$batch->batch_no} recalled. They are back on your desk.")
            ->assertSee('2 recalled/rejected')
            ->assertDontSee('Recall unconfirmed lines');

        $this->as($this->hrSec, Transmittals::class)
            ->call('setTab', 'sent')
            ->call('recallBatch', $batch->id)
            ->assertDispatched('toast', type: 'error', message: 'Every line of this transmittal has been confirmed or already resolved, so there is nothing to recall.');
    }

    public function test_sent_tab_actions_show_the_rule_as_a_toast_when_the_row_changed_meanwhile(): void
    {
        [$letter, $hop] = $this->single();
        $component = $this->as($this->hrSec, Transmittals::class)->call('setTab', 'sent');

        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec); // the recipient confirms in another tab

        $component->call('recallLine', $hop->id)
            ->assertDispatched('toast', type: 'error', message: 'The recipient has already confirmed this letter, so it can no longer be recalled. Ask them to dispatch it back.');

        $this->assertNull($hop->fresh()->resolution);
    }

    public function test_recalling_needs_letters_forward_and_your_own_hop(): void
    {
        [$letter, $hop] = $this->single();
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);

        $this->as($viewer, Transmittals::class)->call('recallLine', $hop->id)->assertForbidden();
        $this->as($viewer, Transmittals::class)->call('recallBatch', 1)->assertForbidden();

        // A secretary who is not the sender is told the hop is not theirs, and nothing changes.
        $this->as($this->matSec, Transmittals::class)
            ->call('recallLine', $hop->id)
            ->assertDispatched('toast', type: 'error', message: 'That hand-over was not found.');
        $this->assertNull($hop->fresh()->resolution);
    }

    public function test_rejecting_ticked_lines_from_the_incoming_tab_needs_a_reason(): void
    {
        [$letters, $batch] = $this->batch(3);
        $hops = $batch->routingHistories()->orderBy('id')->get();

        $component = $this->as($this->cmSec, Transmittals::class)
            ->call('toggleLine', $hops[2]->id) // untick the last
            ->call('openReject', (string) $batch->id)
            ->assertSet('rejectGroup', (string) $batch->id)
            ->assertSee('Why are you rejecting the ticked 2 letters?')
            ->call('rejectTicked')
            ->assertHasErrors(['rejectReason'])
            ->set('rejectReason', 'nope')
            ->call('rejectTicked')
            ->assertHasErrors(['rejectReason']);

        $this->assertSame(0, RoutingHistory::query()->whereNotNull('resolution')->count());

        $component->set('rejectReason', 'Meant for the Materials desk')
            ->call('rejectTicked')
            ->assertHasNoErrors()
            ->assertSet('rejectGroup', null)
            ->assertDispatched('toast', type: 'success', message: 'Rejected 2 letters. The sender has been told why.');

        $this->assertSame(['rejected', 'rejected', null], $hops->map(fn ($hop) => $hop->fresh()->resolution)->all());
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letters[0], $this->hrSec));
        $this->assertFalse($this->lettersWorkflow()->canDispatch($letters[2], $this->hrSec), 'the unticked line is still with cm, awaiting');
        $this->assertSame(1, $this->lettersWorkflow()->pendingIncomingCount($this->cmSec));
    }

    public function test_reject_needs_no_permission_beyond_being_the_recipient(): void
    {
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $viewer);

        $this->as($viewer, Transmittals::class)
            ->call('openReject', 'individual')
            ->set('rejectReason', 'I am not the right person')
            ->call('rejectTicked')
            ->assertDispatched('toast', type: 'success', message: 'Rejected 1 letter. The sender has been told why.');
    }

    public function test_reject_with_nothing_ticked_or_for_someone_elses_transmittal_is_refused(): void
    {
        [$letters, $batch] = $this->batch(1);
        $hop = $batch->routingHistories()->sole();

        $this->as($this->cmSec, Transmittals::class)
            ->call('toggleLine', $hop->id)
            ->call('openReject', (string) $batch->id)
            ->set('rejectReason', 'Nothing ticked here')
            ->call('rejectTicked')
            ->assertDispatched('toast', type: 'error', message: 'Tick at least one letter to reject.');

        $this->as($this->matSec, Transmittals::class)
            ->call('openReject', (string) $batch->id)
            ->set('rejectReason', 'Not addressed to me')
            ->call('rejectTicked')
            ->assertDispatched('toast', type: 'error', message: 'That transmittal is not addressed to you.');

        $this->assertNull($hop->fresh()->resolution);
    }

    public function test_the_sender_is_sent_to_the_sent_tab_from_a_rejection_notice(): void
    {
        [$letters, $batch] = $this->batch(2);
        $this->lettersWorkflow()->rejectLines($this->cmSec, $batch->routingHistories()->pluck('id')->all(), 'Wrong desk altogether');
        $notice = LetterNotification::query()->where('secretariat_id', $this->hrSec->id)->where('batch_id', $batch->id)->sole();

        $this->as($this->hrSec, Notifications::class)
            ->call('toggle')
            ->assertSee('Letters rejected')
            ->call('openNotification', $notice->id)
            ->assertRedirect(route('letters.transmittals', ['tab' => 'sent', 'batch' => $batch->id]));
    }

    // ---- Livewire: Active Letters -------------------------------------------------------------------------

    public function test_the_active_letters_row_offers_recall_to_the_sender(): void
    {
        [$letter, $hop] = $this->single('Recall from list');

        $this->as($this->hrSec, ActiveLetters::class)
            ->assertSee('awaiting confirmation by Employee CM001')
            ->assertSeeHtml('$wire.recallHop('.$hop->id.')') // behind the confirm dialog, not a raw click
            ->call('recallHop', $hop->id)
            ->assertDispatched('toast', type: 'success', message: $letter->sn_number.' recalled. It is back on your desk.');

        $this->assertSame('recalled', $hop->fresh()->resolution);
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));
    }

    public function test_recall_from_the_list_is_only_for_people_who_may_forward_and_only_for_their_own_hop(): void
    {
        [$letter, $hop] = $this->single();
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);

        $this->as($viewer, ActiveLetters::class)->call('recallHop', $hop->id)->assertForbidden();
        $this->as($this->matSec, ActiveLetters::class)
            ->call('recallHop', $hop->id)
            ->assertDispatched('toast', type: 'error', message: 'That hand-over was not found.');

        $this->assertNull($hop->fresh()->resolution);
    }

    public function test_the_recipient_does_not_get_a_recall_link(): void
    {
        [$letter, $hop] = $this->single();

        $this->as($this->cmSec, ActiveLetters::class)
            ->assertDontSee('awaiting confirmation by')
            ->assertDontSeeHtml('$wire.recallHop(');
    }

    public function test_the_drawer_timeline_shows_recalled_and_rejected_hops_with_who_when_and_why(): void
    {
        $recalled = $this->createLetter($this->hrSec, ['subject' => 'Recalled subject']);
        $this->lettersWorkflow()->dispatch($recalled, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->recall(RoutingHistory::query()->where('letter_id', $recalled->id)->sole(), $this->hrSec, 'Sent to the wrong desk');

        $rejected = $this->createLetter($this->hrSec, ['subject' => 'Rejected subject']);
        $this->lettersWorkflow()->dispatch($rejected, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->reject(RoutingHistory::query()->where('letter_id', $rejected->id)->sole(), $this->cmSec, 'Belongs to Materials');

        $this->as($this->hrSec, ActiveLetters::class)
            ->call('openLetter', $recalled->id)
            ->assertSee('Recalled')
            ->assertSee('Recalled by Employee HR001')
            ->assertSee('Sent to the wrong desk')
            ->assertDontSee('Awaiting hardcopy');

        $this->as($this->hrSec, ActiveLetters::class)
            ->call('openLetter', $rejected->id)
            ->assertSee('Rejected')
            ->assertSee('Rejected by Employee CM001')
            ->assertSee('Belongs to Materials')
            ->assertDontSee('Waiting for hardcopy confirmation before this letter can be closed.');
    }
}
