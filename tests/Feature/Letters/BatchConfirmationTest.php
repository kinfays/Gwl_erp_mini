<?php

namespace Tests\Feature\Letters;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\RoutingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class BatchConfirmationTest extends TestCase
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

    /** @return array{0: \Illuminate\Support\Collection, 1: LetterDispatchBatch} */
    private function sendBatch(int $count = 3, ?Employee $to = null): array
    {
        $letters = $this->createLetters($this->hrSec, $count);

        return [$letters, $this->lettersWorkflow()->dispatchBatch($this->hrSec, $to ?? $this->cmSec, $letters->pluck('id')->all())];
    }

    public function test_confirming_every_line_completes_the_batch(): void
    {
        [$letters, $batch] = $this->sendBatch(3);

        $confirmed = $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $letters->pluck('id')->all(), $batch);

        $this->assertSame(3, $confirmed);
        $batch->refresh();
        $this->assertSame(3, $batch->confirmed_count);
        $this->assertNotNull($batch->completed_at);
        $this->assertTrue($batch->isComplete());

        foreach (RoutingHistory::query()->get() as $hop) {
            $this->assertTrue($hop->received_confirm);
            $this->assertNotNull($hop->confirmed_at);
            $this->assertSame($this->cmSec->id, $hop->confirmed_by_id);
            $this->assertSame($this->cmSec->id, $hop->confirmedBy->id);
        }

        foreach ($letters as $letter) {
            $this->assertSame('In Review', $this->lettersWorkflow()->currentLog($letter, $this->cmSec)->status);
            $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->cmSec));
        }
    }

    public function test_confirming_a_subset_leaves_the_batch_open_until_the_rest_is_confirmed(): void
    {
        [$letters, $batch] = $this->sendBatch(3);

        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $letters->take(2)->pluck('id')->all(), $batch);

        $batch->refresh();
        $this->assertSame(2, $batch->confirmed_count);
        $this->assertNull($batch->completed_at);
        $this->assertSame(1, $batch->pendingCount());
        $this->assertFalse($this->lettersWorkflow()->canDispatch($letters[2], $this->cmSec), 'the unconfirmed line stays blocked');
        $this->assertNotNull($this->lettersWorkflow()->pendingIncomingRoute($letters[2], $this->cmSec));

        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [$letters[2]->id], $batch);

        $batch->refresh();
        $this->assertSame(3, $batch->confirmed_count);
        $this->assertNotNull($batch->completed_at);
    }

    public function test_completed_at_is_not_moved_by_a_later_recount(): void
    {
        [$letters, $batch] = $this->sendBatch(1);
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $letters->pluck('id')->all());
        $completedAt = $batch->fresh()->completed_at;

        $this->travel(2)->hours();
        $second = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($second, $this->hrSec, $this->cmSec); // an unbatched hop: not part of the batch
        $this->lettersWorkflow()->confirmHardcopy($second, $this->cmSec);

        $this->assertEquals($completedAt, $batch->fresh()->completed_at);
    }

    public function test_only_the_recipients_own_hops_can_be_confirmed(): void
    {
        [$letters, $batch] = $this->sendBatch(2);

        try {
            $this->lettersWorkflow()->confirmHardcopies($this->matSec, $letters->pluck('id')->all(), $batch);
            $this->fail('Someone else must not be able to confirm the hops.');
        } catch (\RuntimeException $e) {
            $this->assertSame('No pending hardcopy receipt confirmation was found.', $e->getMessage());
        }

        // Neither can the sender.
        try {
            $this->lettersWorkflow()->confirmHardcopies($this->hrSec, $letters->pluck('id')->all());
            $this->fail('The sender must not be able to confirm the hops.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, RoutingHistory::query()->where('received_confirm', true)->count());
        $this->assertSame(0, $batch->fresh()->confirmed_count);
    }

    public function test_letters_that_are_not_pending_for_the_actor_are_ignored_not_confirmed(): void
    {
        [$batched] = $this->sendBatch(2);
        $forMat = $this->createLetter($this->hrSec, ['subject' => 'For the materials desk']);
        $this->lettersWorkflow()->dispatch($forMat, $this->hrSec, $this->matSec);
        $notSent = $this->createLetter($this->hrSec, ['subject' => 'Still with HR']);

        $confirmed = $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [...$batched->pluck('id')->all(), $forMat->id, $notSent->id, 999999]);

        $this->assertSame(2, $confirmed);
        $this->assertNotNull($this->lettersWorkflow()->pendingIncomingRoute($forMat, $this->matSec), "someone else's hop is untouched");
    }

    public function test_confirming_against_a_batch_only_touches_that_batch(): void
    {
        [$firstLetters, $first] = $this->sendBatch(2);
        [$secondLetters, $second] = $this->sendBatch(2);

        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [...$firstLetters->pluck('id')->all(), ...$secondLetters->pluck('id')->all()], $first);

        $this->assertSame(2, $first->fresh()->confirmed_count);
        $this->assertSame(0, $second->fresh()->confirmed_count);
        $this->assertNull($second->fresh()->completed_at);
    }

    public function test_confirming_twice_is_refused_the_second_time(): void
    {
        [$letters] = $this->sendBatch(2);
        $ids = $letters->pluck('id')->all();

        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $ids);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No pending hardcopy receipt confirmation was found.');
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $ids);
    }

    public function test_every_confirmation_is_audited_per_letter_with_the_batch(): void
    {
        [$letters, $batch] = $this->sendBatch(2);

        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $letters->pluck('id')->all(), $batch);

        $audits = AuditLog::query()->where('action', 'confirm_letter_hardcopy')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame($letters->pluck('id')->all(), $audits->pluck('target_id')->all());
        foreach ($audits as $audit) {
            $this->assertSame(['batch_id' => $batch->id], $audit->metadata);
            $this->assertNotNull($audit->new_values['routing_history_id']);
        }
    }

    public function test_the_single_letter_confirm_still_works_as_before_and_records_who_and_when(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);

        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        $hop = RoutingHistory::query()->sole();
        $this->assertTrue($hop->received_confirm);
        $this->assertNotNull($hop->confirmed_at);
        $this->assertSame($this->cmSec->id, $hop->confirmed_by_id);
        $this->assertSame('In Review', $this->lettersWorkflow()->currentLog($letter, $this->cmSec)->status);

        $audit = AuditLog::query()->where('action', 'confirm_letter_hardcopy')->sole();
        $this->assertSame($hop->id, $audit->new_values['routing_history_id']);
        $this->assertNull($audit->metadata, 'an un-batched confirmation carries no batch metadata');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No pending hardcopy receipt confirmation was found.');
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
    }

    public function test_unbatched_hops_can_be_confirmed_together_in_one_call(): void
    {
        $letters = $this->createLetters($this->hrSec, 3);
        foreach ($letters as $letter) {
            $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        }

        $confirmed = $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $letters->pluck('id')->all());

        $this->assertSame(3, $confirmed);
        $this->assertSame(3, RoutingHistory::query()->where('received_confirm', true)->whereNull('batch_id')->count());
        $this->assertSame(0, $this->lettersWorkflow()->pendingIncomingCount($this->cmSec));
    }

    public function test_pending_incoming_count_counts_batched_and_single_hops(): void
    {
        $this->sendBatch(3);
        $single = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($single, $this->hrSec, $this->cmSec);

        $this->assertSame(4, $this->lettersWorkflow()->pendingIncomingCount($this->cmSec));
        $this->assertSame(0, $this->lettersWorkflow()->pendingIncomingCount($this->hrSec));

        $this->lettersWorkflow()->confirmHardcopy($single, $this->cmSec);
        $this->assertSame(3, $this->lettersWorkflow()->pendingIncomingCount($this->cmSec));
    }

    public function test_the_recipient_can_pass_a_confirmed_batch_letter_on_in_another_transmittal(): void
    {
        [$letters, $batch] = $this->sendBatch(2);
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $letters->pluck('id')->all(), $batch);

        $onward = $this->lettersWorkflow()->dispatchBatch($this->cmSec, $this->matSec, $letters->pluck('id')->all());

        $this->assertSame(2, $onward->letters_count);
        $this->assertSame(2, $batch->fresh()->confirmed_count, 'the first transmittal keeps its own count');
        $this->assertSame(2, $this->lettersWorkflow()->pendingIncomingCount($this->matSec));
    }
}
