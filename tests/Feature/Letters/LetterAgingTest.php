<?php

namespace Tests\Feature\Letters;

use App\Livewire\Letters\ActiveLetters;
use App\Livewire\Letters\Dashboard;
use App\Livewire\Letters\Notifications;
use App\Livewire\Letters\Transmittals;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\LetterNotification;
use App\Models\RoutingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class LetterAgingTest extends TestCase
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

    private function single(string $subject = 'Single'): array
    {
        $letter = $this->createLetter($this->hrSec, ['subject' => $subject]);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);

        return [$letter, RoutingHistory::query()->where('letter_id', $letter->id)->sole()];
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: LetterDispatchBatch} */
    private function batch(int $count, string $prefix = 'Batch'): array
    {
        $letters = $this->createLetters($this->hrSec, $count, $prefix);

        return [$letters, $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all())];
    }

    private function as(Employee $employee, string $component = Transmittals::class)
    {
        $this->actingAs($this->letterUserOf($employee));

        return Livewire::test($component);
    }

    private function pillToneAfter(string $html, string $sn): ?string
    {
        return preg_match('/'.preg_quote($sn, '/').'<\/td>.*?ui-pill-(info|warning|danger|success|muted)/s', $html, $m) ? $m[1] : null;
    }

    // ---- thresholds ---------------------------------------------------------------------------------------

    public function test_the_defaults_are_two_days_and_twenty_four_hours(): void
    {
        $this->assertSame(2, config('gwl.letters_unconfirmed_alert_days'));
        $this->assertSame(24, config('gwl.letters_remind_cooldown_hours'));
        $this->assertSame(2, $this->lettersWorkflow()->alertDays());
    }

    public function test_a_hop_turns_amber_after_the_alert_days_and_red_at_twice_that(): void
    {
        $workflow = $this->lettersWorkflow();

        $this->assertNull($workflow->agingTone(now()));
        $this->assertNull($workflow->agingTone(now()->subDay()));
        $this->assertSame('warning', $workflow->agingTone(now()->subDays(2)));
        $this->assertSame('warning', $workflow->agingTone(now()->subDays(3)));
        $this->assertSame('danger', $workflow->agingTone(now()->subDays(4)));
        $this->assertNull($workflow->agingTone(null));

        config(['gwl.letters_unconfirmed_alert_days' => 1]);
        $this->assertSame('warning', $workflow->agingTone(now()->subDay()));
        $this->assertSame('danger', $workflow->agingTone(now()->subDays(2)));

        config(['gwl.letters_unconfirmed_alert_days' => 0]);
        $this->assertSame(1, $workflow->alertDays(), 'a zero or negative setting cannot make everything overdue');
    }

    public function test_waiting_days_counts_whole_days(): void
    {
        $this->assertSame(0, $this->lettersWorkflow()->waitingDays(now()->subHours(5)));
        $this->assertSame(3, $this->lettersWorkflow()->waitingDays(now()->subDays(3)->subHours(5)));
    }

    // ---- overdue count and the dashboard tile -------------------------------------------------------------

    public function test_overdue_counts_my_unconfirmed_and_unresolved_hops_older_than_the_alert_days(): void
    {
        [$old, $oldHop] = $this->single('Old single');
        [$batchLetters, $batch] = $this->batch(3);
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [$batchLetters[0]->id], $batch); // confirmed: not counted
        [$recalled, $recalledHop] = $this->single('Old recalled');
        $this->lettersWorkflow()->recall($recalledHop, $this->hrSec);        // resolved: not counted
        $theirs = $this->createLetter($this->cmSec);
        $this->lettersWorkflow()->dispatch($theirs, $this->cmSec, $this->matSec); // someone else's

        $this->travel(3)->days();
        [$fresh] = $this->single('Fresh single'); // younger than the alert days

        $this->assertSame(3, $this->lettersWorkflow()->overdueSentCount($this->hrSec), 'the old single and two unconfirmed batch lines');
        $this->assertSame(1, $this->lettersWorkflow()->overdueSentCount($this->cmSec));
        $this->assertSame(0, $this->lettersWorkflow()->overdueSentCount($this->matSec));
        $this->assertSame(3, RoutingHistory::query()->overdue(2)->where('from_secretariat_id', $this->hrSec->id)->count());
    }

    public function test_the_dashboard_tile_shows_the_overdue_count_and_links_to_sent_overdue(): void
    {
        $this->single('Old one');
        $this->batch(2);
        $this->travel(3)->days();

        $this->as($this->hrSec, Dashboard::class)
            ->assertViewHas('unconfirmedOverdue', 3)
            ->assertSee('Unconfirmed > 2 days')
            ->assertSeeHtml(e(route('letters.transmittals', ['tab' => 'sent', 'filter' => 'overdue'])));

        $this->as($this->cmSec, Dashboard::class)->assertViewHas('unconfirmedOverdue', 0);

        config(['gwl.letters_unconfirmed_alert_days' => 1]);
        $this->as($this->hrSec, Dashboard::class)->assertSee('Unconfirmed > 1 day');

        $this->lettersWorkflow()->recall(RoutingHistory::query()->whereNull('batch_id')->sole(), $this->hrSec);
        $this->as($this->hrSec, Dashboard::class)->assertViewHas('unconfirmedOverdue', 2);
    }

    // ---- Sent tab: Overdue filter and age pills -----------------------------------------------------------

    public function test_the_sent_tab_overdue_filter_keeps_only_what_has_waited_long_enough(): void
    {
        [$oldLetters, $oldBatch] = $this->batch(1, 'OldBatch');
        [$oldSingle] = $this->single('OldSingle');
        $this->travel(3)->days();
        [$freshLetters, $freshBatch] = $this->batch(1, 'FreshBatch');
        [$freshSingle] = $this->single('FreshSingle');

        $this->as($this->hrSec)->call('setTab', 'sent')
            ->assertSee($oldBatch->batch_no)->assertSee($freshBatch->batch_no)->assertSee('OldSingle')->assertSee('FreshSingle')
            ->call('setSentFilter', 'overdue')
            ->assertSet('sentFilter', 'overdue')
            ->assertSee($oldBatch->batch_no)->assertSee('OldSingle')
            ->assertDontSee($freshBatch->batch_no)->assertDontSee('FreshSingle')
            ->call('setSentFilter', '')
            ->assertSee('FreshSingle');
    }

    public function test_the_overdue_filter_can_be_opened_from_a_link_and_says_when_nothing_is_overdue(): void
    {
        $this->batch(1);

        $this->actingAs($this->letterUserOf($this->hrSec));
        Livewire::withQueryParams(['tab' => 'sent', 'filter' => 'overdue'])
            ->test(Transmittals::class)
            ->assertSet('tab', 'sent')
            ->assertSet('sentFilter', 'overdue')
            ->assertSee('Nothing is overdue.');
    }

    public function test_a_line_shows_how_long_it_has_waited_toned_by_age(): void
    {
        [$a] = $this->batch(1, 'Aged');   // will be 4 days old: red
        $this->travel(2)->days();
        [$b] = $this->batch(1, 'Middle'); // 2 days old: amber
        $this->travel(2)->days();
        [$c] = $this->batch(1, 'Fresh');  // today: neutral info

        $html = $this->as($this->hrSec)->call('setTab', 'sent')->html();

        $this->assertSame('danger', $this->pillToneAfter($html, $a[0]->sn_number));
        $this->assertSame('warning', $this->pillToneAfter($html, $b[0]->sn_number));
        $this->assertSame('info', $this->pillToneAfter($html, $c[0]->sn_number));
        $this->assertStringContainsString('waiting 4 days', $html);
        $this->assertStringContainsString('waiting 2 days', $html);
        $this->assertStringContainsString('waiting today', $html);
    }

    public function test_the_incoming_tab_shows_waiting_days_per_line(): void
    {
        [$old] = $this->single('Old incoming');
        $this->travel(3)->days();
        [$new] = $this->single('New incoming');

        $html = $this->as($this->cmSec)->html();

        $this->assertStringContainsString('waiting 3 days', $html);
        $this->assertStringContainsString('waiting today', $html);
        $this->assertMatchesRegularExpression('/ui-badge-warning[^>]*>\s*waiting 3 days/', $html);
        $this->assertMatchesRegularExpression('/ui-badge-neutral[^>]*>\s*waiting today/', $html);
    }

    public function test_the_active_letters_row_shows_who_is_holding_it_up_and_for_how_many_days(): void
    {
        $this->single('Old row');
        $this->travel(3)->days();
        $this->single('New row');

        $this->as($this->hrSec, ActiveLetters::class)
            ->assertSee('awaiting confirmation by Employee CM001')
            ->assertSee('3 d')
            ->assertSee('<1 d');
    }

    // ---- remind -------------------------------------------------------------------------------------------

    public function test_reminding_about_one_hop_notifies_the_recipient_and_stamps_the_hop(): void
    {
        [$letter, $hop] = $this->single();
        LetterNotification::query()->delete();

        $reminded = $this->lettersWorkflow()->remind($this->hrSec, $hop);

        $this->assertSame(1, $reminded);
        $this->assertNotNull($hop->fresh()->reminded_at);

        $notice = LetterNotification::query()->sole();
        $this->assertSame($this->cmSec->id, $notice->secretariat_id);
        $this->assertSame($letter->id, $notice->letter_id);
        $this->assertSame('Reminder: letter awaiting your confirmation', $notice->title);
        $this->assertStringContainsString($letter->sn_number, $notice->message);

        $audit = AuditLog::query()->where('action', 'remind_letter_recipient')->sole();
        $this->assertSame($letter->id, $audit->target_id);
        $this->assertSame($hop->id, $audit->new_values['routing_history_id']);
        $this->assertNull($audit->metadata);
    }

    public function test_reminding_about_a_transmittal_sends_one_notification_and_audits_each_letter_and_the_batch(): void
    {
        [$letters, $batch] = $this->batch(3);
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [$letters[0]->id], $batch);
        LetterNotification::query()->delete();

        $reminded = $this->lettersWorkflow()->remind($this->hrSec, $batch);

        $this->assertSame(2, $reminded, 'only the lines still awaiting');
        $this->assertSame(2, RoutingHistory::query()->whereNotNull('reminded_at')->count());

        $notice = LetterNotification::query()->sole();
        $this->assertSame($batch->id, $notice->batch_id);
        $this->assertNull($notice->letter_id);
        $this->assertSame($this->cmSec->id, $notice->secretariat_id);
        $this->assertStringContainsString('2 letters', $notice->message);

        $perLetter = AuditLog::query()->where('action', 'remind_letter_recipient')->get();
        $this->assertCount(2, $perLetter);
        $this->assertSame([['batch_id' => $batch->id]], $perLetter->pluck('metadata')->unique()->values()->all());
        $row = AuditLog::query()->where('action', 'remind_letter_batch')->sole();
        $this->assertSame($batch->id, $row->target_id);
        $this->assertSame('letter_dispatch_batches', $row->target_type);
    }

    public function test_a_reminder_is_refused_inside_the_cooldown_and_allowed_after_it(): void
    {
        [$letter, $hop] = $this->single();
        $this->lettersWorkflow()->remind($this->hrSec, $hop);
        $notifications = LetterNotification::query()->count();

        $this->travel(3)->hours();

        try {
            $this->lettersWorkflow()->remind($this->hrSec, $hop->fresh());
            $this->fail('A second reminder inside 24 hours must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('You already reminded Employee CM001', $e->getMessage());
            $this->assertStringContainsString('3 hours ago', $e->getMessage());
            $this->assertStringContainsString('again in 21 hours', $e->getMessage());
        }

        $this->assertSame($notifications, LetterNotification::query()->count());
        $this->assertNotNull($this->lettersWorkflow()->remindCooldownEndsAt([$hop->fresh()]));

        $this->travel(22)->hours();
        $this->assertNull($this->lettersWorkflow()->remindCooldownEndsAt([$hop->fresh()]));
        $this->assertSame(1, $this->lettersWorkflow()->remind($this->hrSec, $hop->fresh()));
        $this->assertSame($notifications + 1, LetterNotification::query()->count());
    }

    public function test_the_cooldown_is_configurable_and_zero_switches_it_off(): void
    {
        [$letter, $hop] = $this->single();
        config(['gwl.letters_remind_cooldown_hours' => 0]);

        $this->lettersWorkflow()->remind($this->hrSec, $hop);
        $this->assertSame(1, $this->lettersWorkflow()->remind($this->hrSec, $hop->fresh()));

        config(['gwl.letters_remind_cooldown_hours' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->lettersWorkflow()->remind($this->hrSec, $hop->fresh());
    }

    public function test_only_the_sender_can_remind_and_only_about_awaiting_hops(): void
    {
        [$letters, $batch] = $this->batch(2);
        [$letter, $hop] = $this->single();

        foreach ([
            [fn () => $this->lettersWorkflow()->remind($this->cmSec, $hop), 'Only the sender of a hand-over can remind the recipient.'],
            [fn () => $this->lettersWorkflow()->remind($this->matSec, $batch), 'Only the sender of a transmittal can remind the recipient.'],
        ] as [$attempt, $message]) {
            try {
                $attempt();
                $this->fail('A non-sender must not remind.');
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $letters->pluck('id')->all(), $batch);

        foreach ([$hop, $batch] as $target) {
            try {
                $this->lettersWorkflow()->remind($this->hrSec, $target);
                $this->fail('Nothing is awaiting, so there is nobody to remind.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Nothing is waiting', $e->getMessage());
            }
        }

        $this->assertSame(0, RoutingHistory::query()->whereNotNull('reminded_at')->count());
    }

    public function test_a_recalled_hop_cannot_be_reminded(): void
    {
        [$letter, $hop] = $this->single();
        $this->lettersWorkflow()->recall($hop, $this->hrSec);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing is waiting');
        $this->lettersWorkflow()->remind($this->hrSec, $hop->fresh());
    }

    public function test_the_sent_tab_reminds_and_disables_remind_inside_the_cooldown(): void
    {
        [$letters, $batch] = $this->batch(3);

        $this->as($this->hrSec)
            ->call('setTab', 'sent')
            ->call('remindBatch', $batch->id)
            ->assertDispatched('toast', type: 'success', message: 'Reminder sent for 3 letters.')
            ->assertSee('you can remind again in 24 hours')
            ->assertSeeHtml('disabled');

        $this->travel(3)->hours();

        $this->as($this->hrSec)
            ->call('setTab', 'sent')
            ->assertSee('Reminded 3 hours ago')
            ->call('remindBatch', $batch->id)
            ->assertDispatched('toast', type: 'error', message: 'You already reminded Employee CM001 3 hours ago. You can remind again in 21 hours.');
    }

    public function test_the_sent_tab_reminds_a_single_dispatch_and_the_recipient_sees_the_reminder(): void
    {
        [$letter, $hop] = $this->single('Nudge me');

        $this->as($this->hrSec)
            ->call('setTab', 'sent')
            ->assertSee('Individual letters')
            ->assertSee('Nudge me')
            ->call('remindLine', $hop->id)
            ->assertDispatched('toast', type: 'success', message: 'Reminder sent for 1 letter.');

        $notice = LetterNotification::query()->where('secretariat_id', $this->cmSec->id)->where('title', 'like', 'Reminder%')->sole();

        $this->as($this->cmSec, Notifications::class)
            ->call('toggle')
            ->assertSee('Reminder: letter awaiting your confirmation')
            ->call('openNotification', $notice->id)
            ->assertRedirect(route('letters.active', ['letter' => $letter->id, 'prompt' => 1]));
    }

    public function test_the_recipient_of_a_batch_reminder_is_taken_to_the_incoming_tab(): void
    {
        [$letters, $batch] = $this->batch(2);
        $this->lettersWorkflow()->remind($this->hrSec, $batch);
        $notice = LetterNotification::query()->where('title', 'Reminder: letters awaiting your confirmation')->sole();

        $this->as($this->cmSec, Notifications::class)
            ->call('openNotification', $notice->id)
            ->assertRedirect(route('letters.transmittals', ['tab' => 'incoming', 'batch' => $batch->id]));
    }

    public function test_reminding_needs_letters_forward_and_the_senders_own_rows(): void
    {
        [$letters, $batch] = $this->batch(1);
        [$letter, $hop] = $this->single();
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);

        $this->as($viewer)->call('remindBatch', $batch->id)->assertForbidden();
        $this->as($viewer)->call('remindLine', $hop->id)->assertForbidden();

        $this->as($this->matSec)->call('remindBatch', $batch->id)
            ->assertDispatched('toast', type: 'error', message: 'That transmittal was not found.');
        $this->as($this->matSec)->call('remindLine', $hop->id)
            ->assertDispatched('toast', type: 'error', message: 'That hand-over was not found.');

        $this->assertSame(0, RoutingHistory::query()->whereNotNull('reminded_at')->count());
    }

    public function test_active_letters_queries_stay_flat_with_the_outgoing_hop_lookup(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ActiveLetters::class);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->single('One');
        $this->actingAs($this->letterUserOf($this->hrSec));
        $queries(); // warm-up
        $withOne = $queries();

        foreach (range(1, 5) as $i) {
            $this->single("More {$i}");
        }

        $this->assertSame($withOne, $queries());
    }
}
