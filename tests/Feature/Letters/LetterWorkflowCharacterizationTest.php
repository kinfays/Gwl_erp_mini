<?php

namespace Tests\Feature\Letters;

use App\Livewire\Letters\ActiveLetters;
use App\Livewire\Letters\Dashboard;
use App\Livewire\Letters\NewLetter;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterNotification;
use App\Models\LetterRemark;
use App\Models\LetterStatusLog;
use App\Models\MailLetter;
use App\Models\RoutingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

/**
 * Pins the single-letter flow as it behaves today: create -> dispatch -> unconfirmed recipient blocked -> confirm
 * -> remark -> close / reopen, with the audit rows each step writes. Where today's behaviour is a known defect
 * (see letters-module-review-and-batch-design.md §3.3) the test says so; those tests are updated when the defect
 * is fixed.
 */
class LetterWorkflowCharacterizationTest extends TestCase
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

    private function auditActions(string $action)
    {
        return AuditLog::query()->where('module', 'letters')->where('action', $action)->get();
    }

    private function dispatchTo(MailLetter $letter, Employee $from, Employee $to): void
    {
        $this->lettersWorkflow()->dispatch($letter, $from, $to);
    }

    public function test_create_assigns_sn_number_first_log_and_audit_row(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $this->assertSame('GA-'.now()->year.'-001', $letter->sn_number);
        $this->assertSame($this->hrSec->id, $letter->created_by_id);

        $log = LetterStatusLog::query()->where('letter_id', $letter->id)->sole();
        $this->assertSame($this->hrSec->id, $log->secretariat_id);
        $this->assertSame('Received', $log->status);
        $this->assertFalse($log->is_closed);

        $this->assertCount(1, $this->auditActions('create_letter'));

        $second = $this->createLetter($this->hrSec, ['subject' => 'Second']);
        $this->assertSame('GA-'.now()->year.'-002', $second->sn_number);
    }

    public function test_new_letter_component_creates_letter_for_secretary_and_redirects_to_it(): void
    {
        $this->actingAs($this->letterUserOf($this->hrSec));

        Livewire::test(NewLetter::class)
            ->set('subject', 'Laptop request')
            ->set('type', 'External')
            ->set('company_sender', 'Acme Supplies Ltd')
            ->set('date_on_letter', '2026-09-01')
            ->set('region_id', $this->accra->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('letters.active', ['letter' => MailLetter::query()->value('id')]));

        $this->assertSame(1, MailLetter::query()->count());
    }

    public function test_dispatch_creates_hop_recipient_log_notification_and_audit_row(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);

        $hop = RoutingHistory::query()->sole();
        $this->assertSame($this->hrSec->id, $hop->from_secretariat_id);
        $this->assertSame($this->cmSec->id, $hop->to_secretariat_id);
        $this->assertFalse($hop->received_confirm);

        $senderLog = $this->lettersWorkflow()->currentLog($letter, $this->hrSec);
        $this->assertSame('Dispatched', $senderLog->status);
        $this->assertTrue($senderLog->out_date->isToday());

        $receiverLog = $this->lettersWorkflow()->currentLog($letter, $this->cmSec);
        $this->assertSame('Received', $receiverLog->status);
        $this->assertFalse($receiverLog->is_closed);

        $notification = LetterNotification::query()->sole();
        $this->assertSame($this->cmSec->id, $notification->secretariat_id);
        $this->assertSame($letter->id, $notification->letter_id);
        $this->assertFalse($notification->is_read);

        $audit = $this->auditActions('dispatch_letter')->sole();
        $this->assertSame($letter->id, $audit->target_id);
        $this->assertSame($this->cmSec->id, $audit->new_values['to_secretariat_id']);
    }

    public function test_dispatch_to_self_or_by_non_holder_is_refused(): void
    {
        $letter = $this->createLetter($this->hrSec);

        try {
            $this->dispatchTo($letter, $this->hrSec, $this->hrSec);
            $this->fail('Dispatching to yourself should be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Dispatch recipient must be different from the current secretariat.', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->dispatchTo($letter, $this->cmSec, $this->hrSec);
    }

    public function test_dispatched_sender_and_unconfirmed_recipient_cannot_dispatch(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);

        $this->assertFalse($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));
        $this->assertFalse($this->lettersWorkflow()->canDispatch($letter, $this->cmSec));

        try {
            $this->dispatchTo($letter, $this->cmSec, $this->hrSec);
            $this->fail('An unconfirmed recipient must not be able to dispatch.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Confirm hardcopy receipt before dispatching this letter.', $e->getMessage());
        }

        $this->assertSame(1, RoutingHistory::query()->count());
    }

    public function test_confirm_hardcopy_confirms_the_hop_moves_recipient_to_in_review_and_audits(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);

        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        $hop = RoutingHistory::query()->sole();
        $this->assertTrue($hop->received_confirm);
        $this->assertSame('In Review', $this->lettersWorkflow()->currentLog($letter, $this->cmSec)->status);
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->cmSec));

        $audit = $this->auditActions('confirm_letter_hardcopy')->sole();
        $this->assertSame($hop->id, $audit->new_values['routing_history_id']);
    }

    public function test_confirm_without_a_pending_hop_is_refused(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No pending hardcopy receipt confirmation was found.');

        $this->lettersWorkflow()->confirmHardcopy($letter, $this->hrSec);
    }

    public function test_confirmed_recipient_can_forward_the_letter_on_and_the_chain_is_recorded(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        $this->dispatchTo($letter, $this->cmSec, $this->hrSec);

        $this->assertSame(2, RoutingHistory::query()->count());
        $this->assertSame('Dispatched', $this->lettersWorkflow()->currentLog($letter, $this->cmSec)->status);
        $this->assertNotNull($this->lettersWorkflow()->pendingIncomingRoute($letter, $this->hrSec));
    }

    public function test_visibility_is_limited_to_employees_with_a_status_log(): void
    {
        $outsider = $this->letterStaff('OUT01', $this->accraOffice);
        $letter = $this->createLetter($this->hrSec);

        $this->assertTrue($this->lettersWorkflow()->visibleLettersQuery($this->hrSec)->whereKey($letter->id)->exists());
        $this->assertFalse($this->lettersWorkflow()->visibleLettersQuery($outsider)->whereKey($letter->id)->exists());

        $this->dispatchTo($letter, $this->hrSec, $outsider);

        $this->assertTrue($this->lettersWorkflow()->visibleLettersQuery($outsider)->whereKey($letter->id)->exists());
    }

    public function test_confirmed_holder_can_add_a_remark_and_it_is_audited(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        $remark = $this->lettersWorkflow()->addRemark($letter, $this->cmSec, [
            'remark_content' => '  Refer to Materials  ',
            'secretary_remark_content' => 'Noted',
        ]);

        $this->assertSame('Refer to Materials', $remark->remark_content);
        $this->assertSame('Noted', $remark->secretary_remark_content);
        $this->assertSame($this->cmSec->id, $remark->author_id);

        $audit = $this->auditActions('add_letter_remark')->sole();
        $this->assertSame($remark->id, $audit->new_values['remark_id']);
    }

    public function test_only_the_remark_author_can_update_a_remark(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $remark = $this->lettersWorkflow()->addRemark($letter, $this->hrSec, ['remark_content' => 'First']);

        $this->lettersWorkflow()->updateRemark($remark, $this->hrSec, ['remark_content' => 'Edited']);
        $this->assertSame('Edited', $remark->fresh()->remark_content);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only the remark creator can edit it.');
        $this->lettersWorkflow()->updateRemark($remark, $this->cmSec, ['remark_content' => 'Hijacked']);
    }

    public function test_only_the_creator_can_close_reopen_or_edit(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        foreach (['close' => 'Only the creator can close this letter.', 'reopen' => 'Only the creator can reopen this letter.'] as $method => $message) {
            try {
                $this->lettersWorkflow()->{$method}($letter, $this->cmSec);
                $this->fail("A non-creator must not be able to {$method}.");
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only the creator can edit this letter.');
        $this->lettersWorkflow()->updateLetter($letter, $this->cmSec, $this->letterData());
    }

    public function test_update_letter_changes_details_and_audits_old_and_new_values(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $this->lettersWorkflow()->updateLetter($letter, $this->hrSec, $this->letterData(['subject' => 'Renamed']));

        $this->assertSame('Renamed', $letter->fresh()->subject);
        $audit = $this->auditActions('update_letter')->sole();
        $this->assertSame('Request for a laptop', $audit->old_values['subject']);
        $this->assertSame('Renamed', $audit->new_values['subject']);
    }

    /** D2: closing is a state of the letter; nobody's status log is rewritten, and reopening restores the same desks. */
    public function test_close_and_reopen_set_and_clear_the_letter_flag_and_leave_every_log_alone(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->dispatchTo($letter, $this->cmSec, $this->hrSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->hrSec);

        $logsBefore = $letter->statusLogs()->orderBy('id')->get(['id', 'secretariat_id', 'status', 'is_closed', 'out_date'])->toArray();

        $this->lettersWorkflow()->close($letter, $this->hrSec);

        $this->assertTrue($letter->isClosed());
        $this->assertSame($this->hrSec->id, $letter->fresh()->closed_by_id);
        $this->assertNotNull($letter->fresh()->closed_at);
        $this->assertSame($logsBefore, $letter->statusLogs()->orderBy('id')->get(['id', 'secretariat_id', 'status', 'is_closed', 'out_date'])->toArray());
        $this->assertCount(1, $this->auditActions('close_letter'));

        $this->lettersWorkflow()->reopen($letter, $this->hrSec);

        $this->assertFalse($letter->isClosed());
        $this->assertNull($letter->fresh()->closed_at);
        $this->assertNull($letter->fresh()->closed_by_id);
        $this->assertSame($logsBefore, $letter->statusLogs()->orderBy('id')->get(['id', 'secretariat_id', 'status', 'is_closed', 'out_date'])->toArray(), 'reopen must not add logs or change any holder\'s status');
        $this->assertSame('Dispatched', $this->lettersWorkflow()->currentLog($letter, $this->cmSec)->status);
        $this->assertSame('In Review', $this->lettersWorkflow()->currentLog($letter, $this->hrSec)->status);
        $this->assertCount(1, $this->auditActions('reopen_letter'));
    }

    /** D2: the letter may be closed from a desk the creator does not hold, but not while a hop is unconfirmed. */
    public function test_close_is_refused_while_a_hop_is_unconfirmed(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);

        try {
            $this->lettersWorkflow()->close($letter, $this->hrSec);
            $this->fail('Closing a letter with an unconfirmed hop should be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('waiting for hardcopy confirmation', $e->getMessage());
        }

        $this->assertFalse($letter->fresh()->isClosed());
        $this->assertCount(0, $this->auditActions('close_letter'));

        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->lettersWorkflow()->close($letter, $this->hrSec);

        $this->assertTrue($letter->fresh()->isClosed());
    }

    public function test_closing_twice_or_reopening_an_open_letter_is_refused(): void
    {
        $letter = $this->createLetter($this->hrSec);

        try {
            $this->lettersWorkflow()->reopen($letter, $this->hrSec);
            $this->fail('An open letter cannot be reopened.');
        } catch (\RuntimeException $e) {
            $this->assertSame('This letter is not closed.', $e->getMessage());
        }

        $this->lettersWorkflow()->close($letter, $this->hrSec);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This letter is already closed.');
        $this->lettersWorkflow()->close($letter, $this->hrSec);
    }

    public function test_a_closed_letter_cannot_be_dispatched_or_remarked_on_until_reopened(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->close($letter, $this->hrSec);

        $this->assertFalse($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));

        try {
            $this->lettersWorkflow()->addRemark($letter, $this->hrSec, ['remark_content' => 'Too late']);
            $this->fail('Remarks on a closed letter should be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('This letter is closed. Re-open it before adding remarks.', $e->getMessage());
        }

        $this->lettersWorkflow()->reopen($letter, $this->hrSec);

        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));
    }

    /**
     * A letter closed by the old code has every holder's log rewritten to Closed, so on reopen nobody could act on
     * it; the creator gets the letter back as before.
     */
    public function test_reopening_a_legacy_closed_letter_hands_it_back_to_the_creator(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $letter->statusLogs()->update(['status' => 'Closed', 'is_closed' => true]);
        $letter->update(['closed_at' => now(), 'closed_by_id' => $this->hrSec->id]);

        $this->lettersWorkflow()->reopen($letter, $this->hrSec);

        $this->assertSame('In Review', $this->lettersWorkflow()->currentLog($letter, $this->hrSec)->status);
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->hrSec));
    }

    public function test_migration_backfills_closed_at_from_letters_whose_logs_were_closed(): void
    {
        $closed = $this->createLetter($this->hrSec, ['subject' => 'Closed before']);
        $closed->statusLogs()->update(['status' => 'Closed', 'is_closed' => true]);
        $open = $this->createLetter($this->hrSec, ['subject' => 'Still open']);

        $migration = require database_path('migrations/2026_09_30_000004_add_closed_state_to_mail_letters_table.php');
        $migration->up();
        $migration->up(); // safe to re-run

        $this->assertNotNull($closed->fresh()->closed_at);
        $this->assertSame($this->hrSec->id, $closed->fresh()->closed_by_id);
        $this->assertNull($open->fresh()->closed_at);
        $this->assertNull($open->fresh()->closed_by_id);
    }

    public function test_active_and_closed_tabs_follow_the_letter_for_every_holder(): void
    {
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Tabbed letter']);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        foreach ([$this->hrSec, $this->cmSec] as $holder) {
            $this->actingAs($this->letterUserOf($holder));

            Livewire::test(ActiveLetters::class)
                ->assertSee('Tabbed letter')
                ->call('setTab', 'closed')
                ->assertDontSee('Tabbed letter');
        }

        $this->lettersWorkflow()->close($letter, $this->hrSec);

        foreach ([$this->hrSec, $this->cmSec] as $holder) {
            $this->actingAs($this->letterUserOf($holder));

            Livewire::test(ActiveLetters::class)
                ->assertDontSee('Tabbed letter')
                ->call('setTab', 'closed')
                ->assertSee('Tabbed letter');
        }

        $this->lettersWorkflow()->reopen($letter, $this->hrSec);

        foreach ([$this->hrSec, $this->cmSec] as $holder) {
            $this->actingAs($this->letterUserOf($holder));

            Livewire::test(ActiveLetters::class)
                ->assertSee('Tabbed letter')
                ->call('setTab', 'closed')
                ->assertDontSee('Tabbed letter');
        }
    }

    public function test_dashboard_counts_use_the_letter_level_closed_state(): void
    {
        $inHand = $this->createLetter($this->hrSec, ['subject' => 'In hand']);
        $sent = $this->createLetter($this->hrSec, ['subject' => 'Sent on']);
        $done = $this->createLetter($this->hrSec, ['subject' => 'Done']);
        $this->dispatchTo($sent, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->close($done, $this->hrSec);

        $this->actingAs($this->letterUserOf($this->hrSec));

        Livewire::test(Dashboard::class)
            ->assertViewHas('stats', ['total' => 3, 'pending' => 1, 'dispatched' => 1, 'closed' => 1])
            ->assertViewHas('attentionLetters', fn ($letters) => $letters->pluck('id')->all() === [$inHand->id]);

        // A holder who is later than the creator sees the closed state too, without their log having been touched.
        $this->lettersWorkflow()->confirmHardcopy($sent, $this->cmSec);
        $this->lettersWorkflow()->close($sent, $this->hrSec);
        $this->actingAs($this->letterUserOf($this->cmSec));

        Livewire::test(Dashboard::class)
            ->assertViewHas('stats', ['total' => 1, 'pending' => 0, 'dispatched' => 0, 'closed' => 1]);
    }

    public function test_desk_state_matches_the_per_letter_lookups_in_two_queries(): void
    {
        $held = $this->createLetter($this->hrSec, ['subject' => 'Held']);
        $sent = $this->createLetter($this->hrSec, ['subject' => 'Sent']);
        $incoming = $this->createLetter($this->cmSec, ['subject' => 'Incoming']);
        $closed = $this->createLetter($this->hrSec, ['subject' => 'Closed']);
        $this->dispatchTo($sent, $this->hrSec, $this->cmSec);
        $this->dispatchTo($incoming, $this->cmSec, $this->hrSec);
        $this->lettersWorkflow()->close($closed, $this->hrSec);

        $letters = $this->lettersWorkflow()->visibleLettersQuery($this->hrSec)->get();
        $this->assertCount(4, $letters);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $state = $this->lettersWorkflow()->deskState($letters, $this->hrSec);
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();

        foreach ($letters as $letter) {
            $expectedLog = $this->lettersWorkflow()->currentLog($letter, $this->hrSec);
            $expectedRoute = $this->lettersWorkflow()->pendingIncomingRoute($letter, $this->hrSec);

            $this->assertSame($expectedLog?->id, $state[$letter->id]['currentLog']?->id, $letter->subject);
            $this->assertSame($expectedRoute?->id, $state[$letter->id]['pendingRoute']?->id, $letter->subject);
            $this->assertSame($this->lettersWorkflow()->canDispatch($letter, $this->hrSec), $state[$letter->id]['canDispatch'], $letter->subject);
            $this->assertSame($state[$letter->id]['canDispatch'], $state[$letter->id]['holdsLetter']);
        }

        $this->assertTrue($state[$held->id]['canDispatch']);
        $this->assertFalse($state[$sent->id]['canDispatch']);
        $this->assertNotNull($state[$incoming->id]['pendingRoute']);
        $this->assertFalse($state[$incoming->id]['canDispatch']);
        $this->assertFalse($state[$closed->id]['canDispatch']);
    }

    public function test_desk_state_picks_the_newest_log_when_several_share_a_timestamp(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->dispatchTo($letter, $this->cmSec, $this->hrSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->hrSec);

        // The letter is back with its creator: two logs for hrSec, created within the same second.
        $newest = $letter->statusLogs()->where('secretariat_id', $this->hrSec->id)->orderByDesc('id')->first();
        $this->assertNotSame(1, $letter->statusLogs()->where('secretariat_id', $this->hrSec->id)->count());

        $state = $this->lettersWorkflow()->deskState(collect([$letter]), $this->hrSec);

        $this->assertSame($newest->id, $state[$letter->id]['currentLog']->id);
        $this->assertSame($newest->id, $this->lettersWorkflow()->currentLog($letter, $this->hrSec)->id);
        $this->assertTrue($state[$letter->id]['canDispatch']);
    }

    /** D7: the number of queries behind the list no longer grows with the number of rows on the page. */
    public function test_active_letters_query_count_does_not_grow_with_the_rows(): void
    {
        $queriesFor = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ActiveLetters::class);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        foreach (range(1, 2) as $i) {
            $this->dispatchTo($this->createLetter($this->hrSec, ['subject' => "Letter {$i}"]), $this->hrSec, $this->cmSec);
        }

        $this->actingAs($this->letterUserOf($this->cmSec));
        $queriesFor(); // warm-up: the first render also loads the acting user's roles and permissions
        $withTwo = $queriesFor();

        foreach (range(3, 8) as $i) {
            $this->dispatchTo($this->createLetter($this->hrSec, ['subject' => "Letter {$i}"]), $this->hrSec, $this->cmSec);
        }

        $this->assertSame($withTwo, $queriesFor());
    }

    public function test_pending_rows_show_confirm_and_holders_show_dispatch_buttons(): void
    {
        $sent = $this->createLetter($this->hrSec, ['subject' => 'Went to CM']);
        $mine = $this->createLetter($this->cmSec, ['subject' => 'CM own letter']);
        $this->dispatchTo($sent, $this->hrSec, $this->cmSec);

        $this->actingAs($this->letterUserOf($this->cmSec));

        Livewire::test(ActiveLetters::class)
            ->assertSee('Went to CM')
            ->assertSee('Confirm Hardcopy')
            ->assertSee('CM own letter')
            ->assertSee('Dispatch');
    }

    public function test_a_closed_letter_shows_the_closed_status_to_every_holder(): void
    {
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Filed away']);
        $this->lettersWorkflow()->close($letter, $this->hrSec);

        $this->actingAs($this->letterUserOf($this->hrSec));

        Livewire::test(ActiveLetters::class)
            ->call('setTab', 'closed')
            ->assertSee('Filed away')
            ->assertSee('Closed');
    }

    public function test_livewire_dispatch_and_confirm_flow(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $this->actingAs($this->letterUserOf($this->hrSec));
        Livewire::test(ActiveLetters::class)
            ->call('openLetter', $letter->id)
            ->set('dispatchToId', $this->cmSec->id)
            ->call('dispatchLetter')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Letter dispatched to '.$this->cmSec->full_name.'.');

        $this->assertSame(1, RoutingHistory::query()->count());

        $this->actingAs($this->letterUserOf($this->cmSec));
        Livewire::test(ActiveLetters::class)
            ->call('openLetter', $letter->id, true)
            ->assertSet('confirmPrompt', true)
            ->call('confirmHardcopy')
            ->assertSet('confirmPrompt', false)
            ->assertDispatched('toast', type: 'success', message: 'Hardcopy receipt confirmed.');

        $this->assertTrue(RoutingHistory::query()->sole()->received_confirm);
    }

    public function test_livewire_remark_flow_records_remark_for_a_holder(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $this->actingAs($this->letterUserOf($this->hrSec));
        Livewire::test(ActiveLetters::class)
            ->call('openLetter', $letter->id)
            ->set('secretaryRemarkContent', 'Received from the registry')
            ->call('addRemark')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Remark added.');

        $this->assertSame(1, LetterRemark::query()->count());
    }

    public function test_livewire_actions_need_the_matching_permission_and_module_access(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);
        $this->dispatchTo($letter, $this->hrSec, $viewer);
        $this->lettersWorkflow()->confirmHardcopy($letter, $viewer);

        $this->actingAs($this->letterUserOf($viewer));
        Livewire::test(ActiveLetters::class)
            ->call('openLetter', $letter->id)
            ->set('dispatchToId', $this->hrSec->id)
            ->call('dispatchLetter')
            ->assertForbidden();

        Livewire::test(ActiveLetters::class)
            ->call('openLetter', $letter->id)
            ->set('secretaryRemarkContent', 'Nope')
            ->call('addRemark')
            ->assertForbidden();

        $stranger = $this->letterStaff('STR01', $this->accraOffice, ['leave_applicant']);
        $this->actingAs($this->letterUserOf($stranger));

        Livewire::test(ActiveLetters::class)->assertForbidden();
    }

    /** D1: a stale click / double click / second device reaches the service; the rule's message is toasted, not a 500. */
    public function test_stale_livewire_actions_toast_the_rule_instead_of_throwing(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);

        // The sender's dispatch button is hidden after dispatch, but a stale tab can still call the action.
        $this->actingAs($this->letterUserOf($this->hrSec));
        Livewire::test(ActiveLetters::class)
            ->set('selectedLetterId', $letter->id)
            ->set('dispatchToId', $this->cmSec->id)
            ->call('dispatchLetter')
            ->assertDispatched('toast', type: 'error', message: 'Confirm hardcopy receipt before dispatching this letter.')
            ->assertNotDispatched('toast', type: 'success', message: 'Letter dispatched to '.$this->cmSec->full_name.'.')
            ->call('confirmHardcopy')
            ->assertDispatched('toast', type: 'error', message: 'No pending hardcopy receipt confirmation was found.')
            ->call('closeLetter')
            ->assertDispatched('toast', type: 'error', message: 'This letter is still waiting for hardcopy confirmation and cannot be closed yet.');

        $this->assertSame(1, RoutingHistory::query()->count());
        $this->assertFalse($letter->fresh()->isClosed());
    }

    public function test_stale_reopen_edit_and_remark_actions_toast_instead_of_throwing(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $remark = $this->lettersWorkflow()->addRemark($letter, $this->hrSec, ['remark_content' => 'First']);

        $this->actingAs($this->letterUserOf($this->hrSec));
        $component = Livewire::test(ActiveLetters::class)->call('openLetter', $letter->id);

        // Reopening a letter that is open (another device already reopened it).
        $component->call('reopenLetter')
            ->assertDispatched('toast', type: 'error', message: 'This letter is not closed.');

        // Remarking after the letter was closed elsewhere.
        $this->lettersWorkflow()->close($letter, $this->hrSec);
        $component->set('secretaryRemarkContent', 'Late note')
            ->call('addRemark')
            ->assertDispatched('toast', type: 'error', message: 'This letter is closed. Re-open it before adding remarks.');
        $this->assertSame(1, LetterRemark::query()->count());

        $component->call('startEditRemark', $remark->id)
            ->set('editingRemarkContent', '')
            ->set('editingSecretaryRemarkContent', 'Edited late')
            ->call('updateRemark')
            ->assertDispatched('toast', type: 'error', message: 'This letter is closed. Re-open it before adding remarks.');
        $this->assertSame('First', $remark->fresh()->remark_content);
    }

    /** D4: remarks come from whoever holds the open letter after confirming the hardcopy - nobody else. */
    public function test_remarks_are_restricted_to_the_current_confirmed_holder(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);

        $cases = [
            'unconfirmed recipient' => [$this->cmSec, 'Confirm hardcopy receipt before adding remarks to this letter.'],
            'past holder' => [$this->hrSec, 'Only the current holder of this letter can add remarks.'],
            'someone who never held it' => [$this->letterStaff('OUT02', $this->accraOffice), 'Only the current holder of this letter can add remarks.'],
        ];

        foreach ($cases as $label => [$actor, $message]) {
            try {
                $this->lettersWorkflow()->addRemark($letter, $actor, ['remark_content' => 'Not allowed']);
                $this->fail("{$label} must not be able to add a remark.");
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage(), $label);
            }
        }

        $this->assertSame(0, LetterRemark::query()->count());
        $this->assertCount(0, $this->auditActions('add_letter_remark'));

        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->lettersWorkflow()->addRemark($letter, $this->cmSec, ['remark_content' => 'Confirmed holder']);

        $this->assertSame(1, LetterRemark::query()->count());
    }

    public function test_a_remark_can_only_be_edited_while_its_author_still_holds_the_letter(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $remark = $this->lettersWorkflow()->addRemark($letter, $this->hrSec, ['remark_content' => 'First']);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);

        try {
            $this->lettersWorkflow()->updateRemark($remark, $this->hrSec, ['remark_content' => 'Edited after dispatch']);
            $this->fail('A past holder must not edit their remark.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Only the current holder of this letter can add remarks.', $e->getMessage());
        }

        $this->assertSame('First', $remark->fresh()->remark_content);
        $this->assertCount(0, $this->auditActions('update_letter_remark'));
    }

    public function test_the_remark_form_is_only_offered_to_the_current_holder(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        $this->actingAs($this->letterUserOf($this->cmSec));
        Livewire::test(ActiveLetters::class)
            ->call('openLetter', $letter->id)
            ->assertSee('Add a remark');

        $this->actingAs($this->letterUserOf($this->hrSec));
        Livewire::test(ActiveLetters::class)
            ->call('openLetter', $letter->id)
            ->assertDontSee('Add a remark')
            ->assertSee('Remarks can be added by whoever currently holds this open letter.');
    }

    /** D5: updating a remark is audited with the old and new values and the remark id. */
    public function test_updating_a_remark_is_audited(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $remark = $this->lettersWorkflow()->addRemark($letter, $this->hrSec, [
            'remark_content' => 'First',
            'secretary_remark_content' => 'Sec note',
        ]);

        $this->lettersWorkflow()->updateRemark($remark, $this->hrSec, ['remark_content' => 'Edited']);

        $audit = $this->auditActions('update_letter_remark')->sole();
        $this->assertSame('letters', $audit->module);
        $this->assertSame('mail_letters', $audit->target_type);
        $this->assertSame($letter->id, $audit->target_id);
        $this->assertSame('First', $audit->old_values['remark_content']);
        $this->assertSame('Sec note', $audit->old_values['secretary_remark_content']);
        $this->assertSame('Edited', $audit->new_values['remark_content']);
        $this->assertNull($audit->new_values['secretary_remark_content']);
        $this->assertSame(['remark_id' => $remark->id], $audit->metadata);
    }

    /** D8: opening a letter from a notification link or from the row behaves the same. */
    public function test_open_letter_prompts_for_confirmation_whichever_way_it_is_opened(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->dispatchTo($letter, $this->hrSec, $this->cmSec);
        $this->actingAs($this->letterUserOf($this->cmSec));

        foreach ([true, false] as $fromNotification) {
            Livewire::test(ActiveLetters::class)
                ->call('openLetter', $letter->id, $fromNotification)
                ->assertSet('selectedLetterId', $letter->id)
                ->assertSet('confirmPrompt', true);
        }

        $this->assertSame('Received', $this->lettersWorkflow()->currentLog($letter, $this->cmSec)->status, 'opening an unconfirmed letter must not move it to In Review');
    }
}
