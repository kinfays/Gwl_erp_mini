<?php

namespace Tests\Feature\Letters;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\LetterNotification;
use App\Models\LetterStatusLog;
use App\Models\RoutingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class BatchDispatchTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected Employee $cmSec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
        $this->cmSec = $this->letterStaff('CM001', $this->accraOffice);
    }

    private function nothingCreated(int $hops = 0): void
    {
        $this->assertSame($hops, RoutingHistory::query()->count());
        $this->assertSame(0, LetterDispatchBatch::query()->count());
        $this->assertSame(0, LetterNotification::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'dispatch_letter_batch')->count());
    }

    public function test_dispatch_batch_creates_the_hops_logs_batch_and_a_single_notification(): void
    {
        $letters = $this->createLetters($this->hrSec, 3);

        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all(), '  Morning mail  ');

        $this->assertSame(3, $batch->letters_count);
        $this->assertSame(0, $batch->confirmed_count);
        $this->assertNull($batch->completed_at);
        $this->assertSame('Morning mail', $batch->note);
        $this->assertSame($this->hrSec->id, $batch->from_secretariat_id);
        $this->assertSame($this->cmSec->id, $batch->to_secretariat_id);
        $this->assertSame('TR-'.now()->year.'-'.str_pad((string) $batch->id, 6, '0', STR_PAD_LEFT), $batch->batch_no);

        $hops = RoutingHistory::query()->orderBy('id')->get();
        $this->assertCount(3, $hops);
        $this->assertSame([$batch->id], $hops->pluck('batch_id')->unique()->all());
        $this->assertSame($letters->pluck('id')->all(), $hops->pluck('letter_id')->all());
        $this->assertSame([false], $hops->pluck('received_confirm')->unique()->all());

        foreach ($letters as $letter) {
            $senderLog = $this->lettersWorkflow()->currentLog($letter, $this->hrSec);
            $this->assertSame('Dispatched', $senderLog->status);
            $this->assertTrue($senderLog->out_date->isToday());
            $this->assertSame('Received', $this->lettersWorkflow()->currentLog($letter, $this->cmSec)->status);
        }

        $notification = LetterNotification::query()->sole();
        $this->assertSame($this->cmSec->id, $notification->secretariat_id);
        $this->assertNull($notification->letter_id);
        $this->assertSame($batch->id, $notification->batch_id);
        $this->assertStringContainsString($batch->batch_no, $notification->message);
        $this->assertStringContainsString('3 letters', $notification->message);
    }

    public function test_it_audits_every_letter_with_the_batch_and_the_batch_itself(): void
    {
        $letters = $this->createLetters($this->hrSec, 2);

        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all());

        $perLetter = AuditLog::query()->where('action', 'dispatch_letter')->orderBy('id')->get();
        $this->assertCount(2, $perLetter);
        foreach ($perLetter as $audit) {
            $this->assertSame(['batch_id' => $batch->id, 'batch_no' => $batch->batch_no], $audit->metadata);
            $this->assertSame($this->cmSec->id, $audit->new_values['to_secretariat_id']);
        }
        $this->assertSame($letters->pluck('id')->all(), $perLetter->pluck('target_id')->all());

        $audit = AuditLog::query()->where('action', 'dispatch_letter_batch')->sole();
        $this->assertSame($batch->id, $audit->target_id);
        $this->assertSame('letter_dispatch_batches', $audit->target_type);
        $this->assertSame(2, $audit->new_values['letters_count']);
        $this->assertSame($letters->pluck('id')->all(), $audit->new_values['letter_ids']);
    }

    public function test_the_recipient_cannot_dispatch_until_each_hop_is_confirmed(): void
    {
        $letters = $this->createLetters($this->hrSec, 2);
        $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all());

        foreach ($letters as $letter) {
            $this->assertFalse($this->lettersWorkflow()->canDispatch($letter, $this->cmSec));
            $this->assertFalse($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));
            $this->assertNotNull($this->lettersWorkflow()->pendingIncomingRoute($letter, $this->cmSec));
        }
    }

    public function test_batch_numbers_are_distinct_and_follow_the_id(): void
    {
        $first = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$this->createLetter($this->hrSec)->id]);
        $second = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$this->createLetter($this->hrSec)->id]);

        $this->assertNotSame($first->batch_no, $second->batch_no);
        $this->assertStringEndsWith(str_pad((string) $second->id, 6, '0', STR_PAD_LEFT), $second->batch_no);
    }

    public function test_one_letter_that_is_not_dispatchable_refuses_the_whole_batch(): void
    {
        $ready = $this->createLetters($this->hrSec, 2);
        $alreadySent = $this->createLetter($this->hrSec, ['subject' => 'Already sent']);
        $this->lettersWorkflow()->dispatch($alreadySent, $this->hrSec, $this->cmSec);
        $closed = $this->createLetter($this->hrSec, ['subject' => 'Closed']);
        $this->lettersWorkflow()->close($closed, $this->hrSec);

        $hopsBefore = RoutingHistory::query()->count();
        $notificationsBefore = LetterNotification::query()->count();
        $logsBefore = LetterStatusLog::query()->count();

        try {
            $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [...$ready->pluck('id')->all(), $alreadySent->id, $closed->id]);
            $this->fail('A batch containing a letter that cannot be dispatched must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($alreadySent->sn_number, $e->getMessage());
            $this->assertStringContainsString($closed->sn_number, $e->getMessage());
            $this->assertStringNotContainsString($ready[0]->sn_number, $e->getMessage());
            $this->assertStringContainsString('Nothing was dispatched', $e->getMessage());
        }

        $this->assertSame(0, LetterDispatchBatch::query()->count());
        $this->assertSame($hopsBefore, RoutingHistory::query()->count());
        $this->assertSame($notificationsBefore, LetterNotification::query()->count());
        $this->assertSame($logsBefore, LetterStatusLog::query()->count());
        $this->assertSame('Received', $this->lettersWorkflow()->currentLog($ready[0], $this->hrSec)->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'dispatch_letter_batch')->count());
    }

    public function test_a_letter_with_an_unconfirmed_incoming_hop_cannot_go_in_a_batch(): void
    {
        $incoming = $this->createLetter($this->cmSec);
        $this->lettersWorkflow()->dispatch($incoming, $this->cmSec, $this->hrSec);
        $mine = $this->createLetter($this->hrSec);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($incoming->sn_number);

        $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$mine->id, $incoming->id]);
    }

    public function test_more_letters_than_the_configured_maximum_is_refused(): void
    {
        config(['gwl.letters_max_batch_size' => 3]);
        $letters = $this->createLetters($this->hrSec, 4);

        try {
            $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all());
            $this->fail('A batch over the maximum size must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('at most 3 letters', $e->getMessage());
        }

        $this->nothingCreated();

        // Exactly at the limit is fine.
        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->take(3)->pluck('id')->all());
        $this->assertSame(3, $batch->letters_count);
    }

    public function test_the_default_maximum_is_fifty(): void
    {
        $this->assertSame(50, config('gwl.letters_max_batch_size'));
    }

    public function test_the_recipient_must_be_a_different_active_secretary(): void
    {
        $letters = $this->createLetters($this->hrSec, 2);
        $ids = $letters->pluck('id')->all();

        $cases = [
            'the sender themselves' => [$this->hrSec, 'Dispatch recipient must be different from the current secretariat.'],
            'an inactive secretary' => [$this->letterStaff('OFF01', $this->accraOffice, null, employeeActive: false), 'The selected recipient cannot receive letters.'],
            'someone who is not a secretary' => [$this->letterStaff('MGR01', $this->accraOffice, ['manager']), 'The selected recipient cannot receive letters.'],
        ];

        foreach ($cases as $label => [$recipient, $message]) {
            try {
                $this->lettersWorkflow()->dispatchBatch($this->hrSec, $recipient, $ids);
                $this->fail("{$label} must not be accepted as the recipient.");
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage(), $label);
            }
        }

        $this->nothingCreated();
    }

    public function test_letters_the_actor_cannot_see_are_refused_without_being_named(): void
    {
        $mine = $this->createLetter($this->hrSec);
        $someoneElses = $this->createLetter($this->cmSec, ['subject' => 'Confidential']);

        try {
            $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$mine->id, $someoneElses->id]);
            $this->fail('A letter the actor cannot see must not be dispatchable.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($someoneElses->sn_number, $e->getMessage());
            $this->assertStringNotContainsString('Confidential', $e->getMessage());
        }

        // An id that does not exist looks exactly the same.
        try {
            $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$mine->id, 999999]);
            $this->fail('An unknown letter id must be refused.');
        } catch (\RuntimeException $e2) {
            $this->assertSame($e->getMessage(), $e2->getMessage());
        }

        $this->nothingCreated();
        $this->assertSame('Received', $this->lettersWorkflow()->currentLog($someoneElses, $this->cmSec)->status);
    }

    public function test_an_empty_selection_is_refused_and_duplicate_ids_count_once(): void
    {
        try {
            $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, []);
            $this->fail('An empty selection must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Select at least one letter to dispatch.', $e->getMessage());
        }

        $letter = $this->createLetter($this->hrSec);
        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$letter->id, (string) $letter->id, $letter->id]);

        $this->assertSame(1, $batch->letters_count);
        $this->assertSame(1, RoutingHistory::query()->count());
    }

    public function test_a_blank_note_is_stored_as_null_and_a_long_one_is_cut_to_500_characters(): void
    {
        $blank = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$this->createLetter($this->hrSec)->id], '   ');
        $this->assertNull($blank->note);

        $long = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, [$this->createLetter($this->hrSec)->id], str_repeat('x', 800));
        $this->assertSame(500, strlen($long->note));
    }

    public function test_single_dispatch_is_unchanged_one_notification_per_letter_and_no_batch(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);

        $hop = RoutingHistory::query()->sole();
        $this->assertNull($hop->batch_id);
        $notification = LetterNotification::query()->sole();
        $this->assertSame($letter->id, $notification->letter_id);
        $this->assertNull($notification->batch_id);
        $this->assertSame('Letter dispatched to you', $notification->title);
        $this->assertSame(0, LetterDispatchBatch::query()->count());

        $audit = AuditLog::query()->where('action', 'dispatch_letter')->sole();
        $this->assertNull($audit->metadata);
    }

    public function test_batch_relations(): void
    {
        $letters = $this->createLetters($this->hrSec, 2);
        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all());

        $this->assertSame($this->hrSec->id, $batch->fromSecretariat->id);
        $this->assertSame($this->cmSec->id, $batch->toSecretariat->id);
        $this->assertCount(2, $batch->routingHistories);
        $this->assertSame($batch->id, $batch->routingHistories->first()->batch->id);
        $this->assertCount(1, $batch->notifications);
        $this->assertSame($batch->id, LetterNotification::query()->sole()->batch->id);
        $this->assertSame(2, $batch->pendingCount());
    }
}
