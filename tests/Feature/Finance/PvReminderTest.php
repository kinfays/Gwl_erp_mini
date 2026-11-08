<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\ReminderSettings;
use App\Models\AuditLog;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinReminderState;
use App\Models\FinSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Finance\AuditReminderService;
use App\Services\Finance\AuditSettings;
use App\Services\Finance\FinanceReminderService;
use App\Services\Finance\FinanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Step D: TWO independent reminder sets. audit:remind (With Audit; Audit settings; Audit officers) and finance:remind (queried,
 * ticked and waiting, drafts; Finance settings; Finance officers), each changed only by its own manager, stored per location only
 * when different from the default, one digest notice per recipient per kind of wait carrying only counts.
 */
class PvReminderTest extends FinanceTestCase
{
    private function finRemind(): FinanceReminderService
    {
        return app(FinanceReminderService::class);
    }

    private function audRemind(): AuditReminderService
    {
        return app(AuditReminderService::class);
    }

    private function finSettings(): FinanceSettings
    {
        return app(FinanceSettings::class);
    }

    private function audSettings(): AuditSettings
    {
        return app(AuditSettings::class);
    }

    /** Voucher reminders of one user (the digest notices). */
    private function waitingNotices($user): array
    {
        return Notification::sent($user, GeneralDatabaseNotification::class)
            ->map(fn ($notice) => $notice->toArray($user))
            ->filter(fn (array $data) => ($data['type'] ?? '') === 'pv_waiting')
            ->values()->all();
    }

    private function sentAt(string $time, $user, string $no, array $extra = []): Pv
    {
        Carbon::setTestNow($time);

        return $this->sent($user, ['pv_no' => $no, 'pv_date' => '2026-10-01', ...$extra]);
    }

    private function west(): array
    {
        return ['region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id];
    }

    private function assertForbidden(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    // ------------------------------------------------------------------ audit:remind

    public function test_audit_officers_of_the_location_are_reminded_once_per_repeat_interval(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $finTwo = $this->finance('F2', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audManager = $this->auditManager('AM1', $this->sowutuom);
        $audOther = $this->audit('AU2', $this->kumasi);
        $audHo = $this->audit('AU3', $this->headOffice);

        $this->sentAt('2026-11-01 09:00', $fin, 'OLD-1');
        $this->sentAt('2026-11-07 08:00', $fin, 'FRESH-1');
        Carbon::setTestNow('2026-11-07 09:00:00');
        Notification::fake();

        $dry = $this->audRemind()->remind(dryRun: true);
        $this->assertSame(['waiting' => 2, 'overdue' => 1, 'reminded' => 1, 'notifications' => 0, 'skipped' => 0, 'disabled' => 0], $dry);
        $this->assertSame([], $this->waitingNotices($aud));
        $this->assertSame(0, FinReminderState::query()->count(), 'a dry run records nothing');

        $this->audRemind()->remind();
        $notices = $this->waitingNotices($aud);
        $this->assertCount(1, $notices);
        $this->assertSame('pv_waiting', $notices[0]['type']);
        $this->assertStringContainsString('1 voucher with Audit', $notices[0]['message']);
        $this->assertStringContainsString('6 days', $notices[0]['message']);
        $this->assertSame([], $this->waitingNotices($fin), 'Finance is not told that Audit is slow');
        $this->assertSame([], $this->waitingNotices($finTwo));
        $this->assertSame([], $this->waitingNotices($audManager), 'the Audit manager manages the settings but does not review, so is not a recipient');
        $this->assertSame([], $this->waitingNotices($audOther), 'another location');
        $this->assertSame([], $this->waitingNotices($audHo), 'Head Office keeps its own books');

        // The next day, and the day after: nobody is told again. Three days on (repeat 3) they are, and FRESH-1 has by then waited its 3 days too.
        foreach (['2026-11-08 09:00', '2026-11-09 09:00'] as $day) {
            Carbon::setTestNow($day);
            $summary = $this->audRemind()->remind();
            $this->assertSame(['reminded' => 0, 'skipped' => 1], ['reminded' => $summary['reminded'], 'skipped' => $summary['skipped']], $day);
        }

        $this->assertCount(1, $this->waitingNotices($aud));
        Carbon::setTestNow('2026-11-10 09:00');
        $this->audRemind()->remind();
        $notices = $this->waitingNotices($aud);
        $this->assertCount(2, $notices, 'one digest per run, not one per voucher');
        $this->assertStringContainsString('2 vouchers with Audit', $notices[1]['message']);
    }

    public function test_the_audit_command_does_not_touch_queried_ticked_or_draft_vouchers(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $this->draft($fin, ['pv_no' => 'D-1']);
        $queried = $this->sentAt('2026-10-01 09:00', $fin, 'Q-1');
        $ticked = $this->sentAt('2026-10-01 09:00', $fin, 'T-1');
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $queried, 'duplicate', 'Seen');
        $this->workflow()->auditAcknowledge($aud, $ticked);
        Carbon::setTestNow('2026-11-07 09:00');
        Notification::fake();

        $this->assertSame(0, $this->audRemind()->remind()['waiting']);
        $this->assertSame([], $this->waitingNotices($aud));
    }

    // ------------------------------------------------------------------ finance:remind

    public function test_a_queried_voucher_reminds_finance_and_a_new_query_starts_a_fresh_wait(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $this->actingAs($fin);
        $this->finSettings()->save($this->financeManager('FM1', $this->sowutuom), [FinanceSettings::REPEAT => 30], null);
        $pv = $this->sentAt('2026-10-20 09:00', $fin, 'Q-1');

        Carbon::setTestNow('2026-10-21 09:00');
        $this->actingAs($aud);
        $pv = $this->workflow()->auditQuery($aud, $pv, 'duplicate', 'Seen before');

        Carbon::setTestNow('2026-10-24 09:00'); // 3 days: under the limit of 5
        Notification::fake();
        $this->assertSame(0, $this->finRemind()->remind()['overdue']);

        Carbon::setTestNow('2026-10-27 09:00'); // 6 days
        $this->finRemind()->remind();
        $this->assertCount(1, $this->waitingNotices($fin));
        $this->assertStringContainsString('1 queried voucher', $this->waitingNotices($fin)[0]['message']);
        $this->assertStringContainsString('6 days', $this->waitingNotices($fin)[0]['message']);
        $this->assertSame([], $this->waitingNotices($aud));

        // Finance corrects it, Audit queries it AGAIN: a new wait, reminded afresh even though the repeat interval has not passed.
        Carbon::setTestNow('2026-10-28 09:00');
        $this->actingAs($fin);
        $pv = $this->workflow()->resubmit($fin, $pv, $pv->lock_version);
        Carbon::setTestNow('2026-10-28 10:00');
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $pv, 'duplicate', 'Still the same');

        Carbon::setTestNow('2026-11-03 11:00'); // 6 days after the second query; the first reminder was 7 days ago but the repeat interval is 30
        $this->finRemind()->remind();
        $this->assertCount(2, $this->waitingNotices($fin));
        $this->assertSame(1, FinReminderState::query()->count(), 'one state per voucher and kind, updated');
    }

    public function test_a_ticked_voucher_goes_to_those_who_can_acknowledge_and_a_draft_to_those_who_can_prepare(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $pv = $this->sentAt('2026-11-01 09:00', $fin, 'T-1');
        Carbon::setTestNow('2026-11-02 09:00');
        $this->actingAs($aud);
        $this->workflow()->auditAcknowledge($aud, $pv);
        Carbon::setTestNow('2026-10-25 09:00');
        $this->draft($fin, ['pv_no' => 'DRAFT-1', 'pv_date' => '2026-10-20']);

        Notification::fake();
        Carbon::setTestNow('2026-11-06 09:00'); // 4 days since the tick (limit 3); the draft is 12 days old (limit 7)
        $summary = $this->finRemind()->remind();

        $this->assertSame(2, $summary['reminded']);
        $notices = $this->waitingNotices($fin);
        $this->assertCount(2, $notices, 'one digest for the tick and one for the draft');
        $kinds = collect($notices)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['acknowledge', 'draft'], $kinds);
        $this->assertTrue(collect($notices)->contains(fn ($n) => str_contains($n['message'], 'ticked by Audit and waiting for Finance to acknowledge')));
        $this->assertTrue(collect($notices)->contains(fn ($n) => str_contains($n['message'], '1 draft voucher not yet sent to Audit. The oldest is 12 days old')));
        $this->assertSame([], $this->waitingNotices($manager), 'the Finance manager holds neither right, so is not a recipient');
        $this->assertSame([], $this->waitingNotices($aud));
    }

    public function test_those_who_can_prepare_and_those_who_can_acknowledge_are_told_about_their_own_kind_only(): void
    {
        $preparer = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $acknowledger = $this->finance('F2', $this->sowutuom);
        $pv = $this->sentAt('2026-11-01 09:00', $preparer, 'T-1');
        Carbon::setTestNow('2026-11-02 09:00');
        $this->actingAs($aud);
        $this->workflow()->auditAcknowledge($aud, $pv);

        // Take "prepare" away from one officer and "acknowledge" from the other (a custom arrangement of the roles).
        $role = Role::query()->create(['name' => 'ack_only', 'display_name' => 'Ack only', 'description' => 'x', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->where('name', 'finance.acknowledge')->value('id'));
        $ackOnly = $this->person('F3', $this->sowutuom, ['employee', 'ack_only']);
        $this->draft($preparer, ['pv_no' => 'DRAFT-1']);
        Carbon::setTestNow('2026-11-06 09:00');
        Notification::fake();

        $this->finRemind()->remind();

        $this->assertSame(['acknowledge'], collect($this->waitingNotices($ackOnly))->pluck('kind')->all(), 'only the acknowledgement, never a draft or a query');
        $this->assertContains('acknowledge', collect($this->waitingNotices($acknowledger))->pluck('kind')->all());
    }

    public function test_vouchers_due_together_arrive_as_one_digest_naming_the_pv_numbers_and_no_personal_data(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $this->sentAt('2026-11-01 09:00', $fin, 'PLAIN-1', ['amount' => '987654.32', 'details' => 'SECRET-DETAILS', 'remarks' => 'SECRET-REMARK']);
        $this->sentAt('2026-11-02 09:00', $fin, 'PLAIN-2');
        $this->sentAt('2026-11-03 09:00', $fin, 'PLAIN-3');
        Carbon::setTestNow('2026-11-07 09:00:00');
        Notification::fake();

        $this->audRemind()->remind();

        $notices = $this->waitingNotices($aud);
        $this->assertCount(1, $notices, 'one notice, not one per voucher');
        $this->assertSame(3, $notices[0]['count']);
        $this->assertSame(6, $notices[0]['longest_days']);
        $this->assertSame(['PLAIN-1', 'PLAIN-2', 'PLAIN-3'], $notices[0]['pv_numbers'], 'the longest waiting first');
        $this->assertStringContainsString('PV PLAIN-1, PLAIN-2, PLAIN-3.', $notices[0]['message']);

        $json = json_encode($notices);
        foreach (['987654', '987,654', 'SECRET-DETAILS', 'SECRET-REMARK', 'Kumasi'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, 'a reminder names the PV numbers, never the payee, amount or a comment');
        }
    }

    public function test_a_long_list_names_the_first_ten_and_counts_the_rest(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);

        foreach (range(1, 13) as $i) {
            $this->sentAt('2026-11-01 09:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $fin, 'LONG-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        Carbon::setTestNow('2026-11-07 09:00:00');
        Notification::fake();
        $this->audRemind()->remind();

        $notice = $this->waitingNotices($aud)[0];
        $this->assertSame(13, $notice['count']);
        $this->assertCount(10, $notice['pv_numbers']);
        $this->assertStringContainsString('and 3 more.', $notice['message']);
    }

    // ------------------------------------------------------------------ on / off, and the two sets are independent

    public function test_a_location_can_switch_its_own_set_off_without_touching_the_other_set_or_another_location(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audAsh = $this->audit('AU2', $this->kumasi);
        $pvW = $this->sentAt('2026-10-01 09:00', $fin, 'W-1');
        $this->sentAt('2026-10-01 09:00', $ash, 'K-1');
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $pvW, 'duplicate', 'Seen');
        Carbon::setTestNow('2026-11-07 09:00:00');

        // The Audit manager of Accra West switches Audit reminders off there.
        $this->audSettings()->save($this->auditManager('AM1', $this->sowutuom), [AuditSettings::ENABLED => false], null);
        Notification::fake();

        $audit = $this->audRemind()->remind();
        $this->assertSame(['overdue' => 1, 'reminded' => 1, 'disabled' => 0], ['overdue' => $audit['overdue'], 'reminded' => $audit['reminded'], 'disabled' => $audit['disabled']], 'only Ashanti\'s voucher is With Audit now');
        $this->assertSame([], $this->waitingNotices($aud), 'switched off at Accra West');
        $this->assertCount(1, $this->waitingNotices($audAsh), 'still on at Ashanti');

        // The Finance set of the same location is untouched by that switch.
        $this->finRemind()->remind();
        $this->assertCount(1, $this->waitingNotices($fin), 'the queried voucher is still reminded');

        // Now Finance's own switch.
        $this->finSettings()->save($this->financeManager('FM1', $this->sowutuom), [FinanceSettings::ENABLED => false], null);
        $summary = $this->finRemind()->remind(now: Carbon::parse('2026-11-30 09:00'));
        $this->assertSame(1, $summary['disabled']);
        $this->assertCount(1, $this->waitingNotices($fin), 'nothing new after the switch');
    }

    public function test_the_two_sets_have_their_own_repeat_intervals_and_limits(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $pv = $this->sentAt('2026-10-01 09:00', $fin, 'V-1');
        $this->actingAs($aud);
        $queried = $this->sentAt('2026-10-01 09:00', $fin, 'V-2');
        $this->workflow()->auditQuery($aud, $queried, 'other', 'x');

        // Audit repeats every 30 days, Finance every day (a limit of 1 day as well).
        $this->audSettings()->save($this->auditManager('AM1', $this->sowutuom), [AuditSettings::REPEAT => 30], null);
        $this->finSettings()->save($this->financeManager('FM1', $this->sowutuom), [FinanceSettings::REPEAT => 1], null);

        Notification::fake();
        foreach (['2026-11-07 09:00', '2026-11-08 09:00', '2026-11-09 09:00'] as $day) {
            Carbon::setTestNow($day);
            $this->audRemind()->remind();
            $this->finRemind()->remind();
        }

        $this->assertCount(1, $this->waitingNotices($aud), 'Audit: once in 30 days');
        $this->assertCount(3, $this->waitingNotices($fin), 'Finance: every day');

        // Values live under separate keys: one set never reads the other's.
        $this->assertEqualsCanonicalizing(['audit_repeat_days', 'finance_repeat_days'], FinSetting::query()->pluck('key')->all());
        $this->assertSame(30, $this->audSettings()->value($this->west(), AuditSettings::REPEAT));
        $this->assertSame(1, $this->finSettings()->value($this->west(), FinanceSettings::REPEAT));
    }

    public function test_each_location_has_its_own_limits(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audAsh = $this->audit('AU2', $this->kumasi);
        $this->sentAt('2026-11-01 09:00', $fin, 'W-1');
        $this->sentAt('2026-11-01 09:00', $ash, 'K-1');
        Carbon::setTestNow('2026-11-07 09:00:00');

        $this->audSettings()->save($this->auditManager('AM1', $this->sowutuom), [AuditSettings::WAIT => 10], null);
        Notification::fake();
        $this->audRemind()->remind();

        $this->assertSame([], $this->waitingNotices($aud), 'Accra West allows 10 days');
        $this->assertCount(1, $this->waitingNotices($audAsh), 'Ashanti still has the standard 3');
    }

    // ------------------------------------------------------------------ the commands

    public function test_both_commands_are_registered_and_scheduled_daily(): void
    {
        foreach (['finance:remind', 'audit:remind'] as $command) {
            $this->artisan($command, ['--dry-run' => true])->expectsOutputToContain('voucher(s) waiting')->assertExitCode(0);
        }

        foreach (['finance:remind', 'audit:remind'] as $command) {
            $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, $command));
            $this->assertCount(1, $events, $command);
            $this->assertSame('0 8 * * *', $events->first()->expression);
        }
    }

    // ------------------------------------------------------------------ settings: who changes what, and what is stored

    public function test_each_manager_changes_only_their_own_set_and_nobody_else_changes_either(): void
    {
        $finManager = $this->financeManager('FM1', $this->sowutuom);
        $audManager = $this->auditManager('AM1', $this->sowutuom);
        $officer = $this->finance('F1', $this->sowutuom);
        $auditor = $this->audit('AU1', $this->sowutuom);
        $this->actingAs($finManager);

        $this->assertSame(9, $this->finSettings()->save($finManager, [FinanceSettings::QUERIED_WAIT => 9], null)[FinanceSettings::QUERIED_WAIT]);
        $this->assertSame(8, $this->audSettings()->save($audManager, [AuditSettings::WAIT => 8], null)[AuditSettings::WAIT]);

        $this->assertForbidden(fn () => $this->audSettings()->save($finManager, [AuditSettings::WAIT => 2], null));
        $this->assertForbidden(fn () => $this->finSettings()->save($audManager, [FinanceSettings::QUERIED_WAIT => 2], null));
        $this->assertForbidden(fn () => $this->finSettings()->save($officer, [FinanceSettings::QUERIED_WAIT => 2], null));
        $this->assertForbidden(fn () => $this->audSettings()->save($auditor, [AuditSettings::WAIT => 2], null));
        $this->assertForbidden(fn () => $this->finSettings()->save($auditor, [FinanceSettings::QUERIED_WAIT => 2], null));

        // A key of the other set sent with a save is ignored, not stored.
        $this->finSettings()->save($finManager, [FinanceSettings::QUERIED_WAIT => 9, AuditSettings::WAIT => 1, 'unknown' => 4], null);
        $this->assertSame(8, $this->audSettings()->value($this->west(), AuditSettings::WAIT));

        // super_admin may do both, for the location they pick.
        $super = $this->superAdmin();
        $this->audSettings()->save($super, [AuditSettings::WAIT => 6], $this->kumasi->id);
        $this->finSettings()->save($super, [FinanceSettings::QUERIED_WAIT => 7], $this->kumasi->id);
        $this->assertEqualsCanonicalizing(['audit_wait_days', 'queried_wait_days'], FinSetting::query()->where('unit', 'r'.$this->kumasi->region_id)->pluck('key')->all());
    }

    public function test_saving_stores_only_what_differs_from_the_default_and_audits_keys_without_values(): void
    {
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $this->actingAs($manager);

        $saved = $this->finSettings()->save($manager, [FinanceSettings::QUERIED_WAIT => 7, FinanceSettings::ACKNOWLEDGE_WAIT => 3, FinanceSettings::ENABLED => true, FinanceSettings::DRAFT_WAIT => 12], null);

        $this->assertSame(7, $saved[FinanceSettings::QUERIED_WAIT]);
        $this->assertSame(1, $saved[FinanceSettings::ENABLED]);
        $this->assertEqualsCanonicalizing(['queried_wait_days', 'draft_wait_days'], FinSetting::query()->pluck('key')->all(), 'values equal to their default are no row at all');
        $this->assertSame('r'.$this->sowutuom->region_id, FinSetting::query()->value('unit'));

        // Back to the default removes the row; a switch stored as 0 is a real override.
        $this->finSettings()->save($manager, [FinanceSettings::QUERIED_WAIT => 5, FinanceSettings::ENABLED => false], null);
        $this->assertEqualsCanonicalizing(['draft_wait_days', 'finance_remind_enabled'], FinSetting::query()->pluck('key')->all());
        $this->assertSame(0, (int) FinSetting::query()->where('key', 'finance_remind_enabled')->value('value'));

        $row = AuditLog::query()->where('action', 'finance.settings_saved')->orderBy('id')->first();
        $this->assertSame('finance', $row->module);
        $this->assertSame(['draft_wait_days', 'queried_wait_days'], collect($row->metadata['changed'])->sort()->values()->all());
        $this->assertSame($this->sowutuom->region_id, $row->metadata['region_id']);
        $this->assertNull($row->old_values);
        $this->assertNull($row->new_values);
        $this->assertStringNotContainsString('12', json_encode($row->metadata), 'no values in the audit row');

        $audit = $this->auditManager('AM1', $this->sowutuom);
        $this->audSettings()->save($audit, [AuditSettings::WAIT => 20], null);
        $this->assertSame('audit', AuditLog::query()->where('action', 'audit.settings_saved')->value('module'));
    }

    public function test_settings_are_checked_and_belong_to_the_actors_location(): void
    {
        $fin = $this->financeManager('F1', $this->sowutuom);
        $ho = $this->financeManager('F3', $this->headOffice);
        $this->actingAs($fin);

        foreach ([0, 400, 'abc', 2.5, ''] as $bad) {
            try {
                $this->finSettings()->save($fin, [FinanceSettings::QUERIED_WAIT => $bad], null);
                $this->fail('Expected a validation error for '.var_export($bad, true));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(FinanceSettings::QUERIED_WAIT, $exception->errors());
            }
        }

        try {
            $this->finSettings()->save($fin, [FinanceSettings::ENABLED => 'maybe'], null);
            $this->fail('A switch is on or off.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(FinanceSettings::ENABLED, $exception->errors());
        }

        $this->finSettings()->save($fin, [FinanceSettings::QUERIED_WAIT => 9], null);
        $west = $this->west();
        $this->assertSame(9, $this->finSettings()->value($west, FinanceSettings::QUERIED_WAIT));
        $this->assertSame(5, $this->finSettings()->value(['region_id' => $this->kumasi->region_id, 'district_id' => $this->kumasi->id], FinanceSettings::QUERIED_WAIT));
        $this->assertSame(5, $this->finSettings()->value(['region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id], FinanceSettings::QUERIED_WAIT), 'Head Office is not part of its region');

        $this->finSettings()->save($ho, [FinanceSettings::QUERIED_WAIT => 4], null);
        $this->assertEqualsCanonicalizing(['ho', 'r'.$this->sowutuom->region_id], FinSetting::query()->pluck('unit')->all());
    }

    public function test_super_admin_must_name_the_location_whose_settings_they_change(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super);

        foreach ([$this->finSettings(), $this->audSettings()] as $settings) {
            try {
                $settings->save($super, [array_key_first($settings->definitions()) => 0], null);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('location', $exception->errors());
            }
        }
    }

    public function test_the_audit_wait_decides_overdue_on_the_reports_and_the_finance_set_does_not(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->sentAt('2026-11-01 09:00', $fin, 'W-1');
        Carbon::setTestNow('2026-11-07 09:00');
        $analytics = app(\App\Services\Finance\PvAnalyticsService::class);
        $overdue = fn () => $analytics->report($fin, \App\Services\Finance\FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-30')['waiting']['kinds']['audit']['overdue'];

        $this->assertSame(1, $overdue());
        $this->finSettings()->save($this->financeManager('FM1', $this->sowutuom), [FinanceSettings::QUERIED_WAIT => 30, FinanceSettings::ACKNOWLEDGE_WAIT => 30], null);
        $this->assertSame(1, $overdue(), 'the Finance set does not decide when Audit is late');
        $this->audSettings()->save($this->auditManager('AM1', $this->sowutuom), [AuditSettings::WAIT => 30], null);
        $this->assertSame(0, $overdue(), 'saving drops the cached figures at once');
    }

    // ------------------------------------------------------------------ the screens

    public function test_each_door_opens_only_its_own_settings_screen_and_the_module_cannot_be_changed_from_the_browser(): void
    {
        $this->withoutVite();
        $finManager = $this->financeManager('FM1', $this->sowutuom);
        $audManager = $this->auditManager('AM1', $this->sowutuom);
        $officer = $this->finance('F1', $this->sowutuom);
        $auditor = $this->audit('AU1', $this->sowutuom);

        $this->actingAs($finManager)->get(route('finance.reminder-settings'))->assertOk()->assertSee('Finance reminders')->assertSee('Remind about a draft after');
        $this->actingAs($finManager)->get(route('audit.reminder-settings'))->assertRedirect();   // no Audit door at all
        $this->actingAs($audManager)->get(route('audit.reminder-settings'))->assertOk()->assertSee('Audit reminders')->assertDontSee('Remind about a draft after');
        $this->actingAs($audManager)->get(route('finance.reminder-settings'))->assertRedirect();
        $this->actingAs($officer)->get(route('finance.reminder-settings'))->assertForbidden();
        $this->actingAs($auditor)->get(route('audit.reminder-settings'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('audit.reminder-settings'))->assertOk();

        // The module is locked: a tampered request cannot turn the Finance screen into the Audit one.
        try {
            Livewire::actingAs($finManager)->test(ReminderSettings::class, ['module' => 'finance'])->set('module', 'audit');
            $this->fail('The module is locked.');
        } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
            $this->assertTrue(true);
        }
        // A Finance manager forcing the Audit screen through its component is refused.
        Livewire::actingAs($finManager)->test(ReminderSettings::class, ['module' => 'audit'])->assertForbidden();
        Livewire::actingAs($audManager)->test(ReminderSettings::class, ['module' => 'finance'])->assertForbidden();

        // The menu shows each its own entry, and an officer none.
        $routes = fn ($user, $module) => collect(app(\App\Support\ErpNavigation::class)->build($user, $module)['sidebar'])->pluck('route')->filter()->all();
        $this->assertContains('finance.reminder-settings', $routes($finManager, 'finance'));
        $this->assertContains('audit.reminder-settings', $routes($audManager, 'audit'));
        $this->assertNotContains('finance.reminder-settings', $routes($officer, 'finance'));
        $this->assertNotContains('audit.reminder-settings', $routes($auditor, 'audit'));
    }

    public function test_the_settings_screen_saves_toggles_and_resets(): void
    {
        $this->withoutVite();
        $manager = $this->financeManager('FM1', $this->sowutuom);

        $page = Livewire::actingAs($manager)->test(ReminderSettings::class, ['module' => 'finance'])
            ->assertSet('values.queried_wait_days', '5')->assertSet('values.finance_remind_enabled', true)
            ->set('values.queried_wait_days', '8')->set('values.finance_remind_enabled', false)
            ->call('save')->assertHasNoErrors()->assertSee('This location changed it.');
        $this->assertEqualsCanonicalizing(['queried_wait_days', 'finance_remind_enabled'], FinSetting::query()->pluck('key')->all());

        $page->set('values.queried_wait_days', '0')->call('save')->assertHasErrors(['values.queried_wait_days']);
        $page->call('resetToDefaults')->assertHasNoErrors()->assertSet('values.queried_wait_days', '5')->assertSet('values.finance_remind_enabled', true);
        $this->assertSame(0, FinSetting::query()->count());

        $audit = Livewire::actingAs($this->auditManager('AM1', $this->sowutuom))->test(ReminderSettings::class, ['module' => 'audit'])
            ->assertSet('values.audit_wait_days', '3')->set('values.audit_wait_days', '12')->call('save')->assertHasNoErrors();
        $this->assertSame(['audit_wait_days'], FinSetting::query()->pluck('key')->all());
    }

    // ------------------------------------------------------------------ the migration

    public function test_the_migration_gives_both_sets_the_one_repeat_interval_a_location_had_changed(): void
    {
        DB::table('fin_settings')->insert(['unit' => 'ho', 'key' => 'remind_repeat_days', 'value' => 9, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fin_settings')->insert(['unit' => 'ho', 'key' => 'audit_wait_days', 'value' => 4, 'created_at' => now(), 'updated_at' => now()]);

        (require base_path('database/migrations/2026_11_14_000001_split_finance_audit_reminder_settings.php'))->up();
        (require base_path('database/migrations/2026_11_14_000001_split_finance_audit_reminder_settings.php'))->up(); // idempotent

        $rows = FinSetting::query()->where('unit', 'ho')->pluck('value', 'key')->all();
        $this->assertSame(['audit_repeat_days' => 9, 'audit_wait_days' => 4, 'finance_repeat_days' => 9], collect($rows)->sortKeys()->all());
        $this->assertSame(9, $this->audSettings()->value(['region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id], AuditSettings::REPEAT));
    }
}
