<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FinBatchEvent;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Finance\BatchWorkflowService;
use App\Services\Finance\FinanceScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Step F: nobody in Audit works on a voucher until the Audit manager assigns it to an officer; tick, query and withdraw belong to that
 * officer alone; an officer reads what is theirs; the manager's assignment queue shows minimal columns.
 */
class AuditAssignmentTest extends FinanceTestCase
{
    private function batches(): BatchWorkflowService
    {
        return app(BatchWorkflowService::class);
    }

    private function scope(): FinanceScope
    {
        return app(FinanceScope::class);
    }

    private function assertValidation(string $field, callable $call): ValidationException
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return $exception;
        }

        $this->fail("Expected a validation error on {$field}.");
    }

    private function assertNotFound(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a 404.');
        } catch (NotFoundHttpException|ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
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

    // ------------------------------------------------------------------ who may assign

    public function test_only_the_audit_manager_assigns_and_only_inside_their_location(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $financeManager = $this->financeManager('FM1', $this->sowutuom);
        $managerKumasi = $this->auditManager('AM2', $this->kumasi);
        $pv = $this->sent($fin);

        foreach ([$aud, $fin, $financeManager] as $nobody) {
            $this->assertForbidden(fn () => $this->workflow()->assign($nobody, $pv, $aud->id));
        }

        $this->assertNotFound(fn () => $this->workflow()->assign($managerKumasi, $pv, $aud->id));
        $this->assertNull($pv->fresh()->assigned_to_user_id);

        $this->assertSame($aud->id, $this->workflow()->assign($manager, $pv, $aud->id)->assigned_to_user_id);

        // super_admin holds the right too (they choose the location on the screen), but is never an officer.
        $super = $this->superAdmin();
        $audTwo = $this->audit('AU2', $this->odorkor);
        $this->assertSame($audTwo->id, $this->workflow()->assign($super, $pv, $audTwo->id)->assigned_to_user_id);
        $this->assertNotContains($super->id, $this->scope()->assignableOfficers(['region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id])->pluck('users.id')->all());

        // A draft is not in the queue at all.
        $this->assertNotFound(fn () => $this->workflow()->assign($manager, $this->draft($fin, ['pv_no' => 'DRAFT-X']), $aud->id));
    }

    public function test_assigning_and_reassigning_write_events_with_who_from_to_and_a_note_and_a_repeat_changes_nothing(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audTwo = $this->audit('AU2', $this->odorkor);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $pv = $this->sent($fin);
        $this->assertNull($pv->assigned_to_user_id);

        $pv = $this->workflow()->assign($manager, $pv, $aud->id, 'Start with this one');
        $this->assertSame([$aud->id, $manager->id], [$pv->assigned_to_user_id, $pv->assigned_by_user_id]);
        $this->assertNotNull($pv->assigned_at);

        $assigned = $pv->events()->where('action', FinPvEvent::ASSIGNED)->sole();
        $this->assertSame([$manager->id, null, $aud->id, 'Start with this one', 1], [$assigned->actor_user_id, $assigned->from_user_id, $assigned->to_user_id, $assigned->comment, $assigned->round]);

        $pv = $this->workflow()->assign($manager, $pv, $audTwo->id);
        $reassigned = $pv->events()->where('action', FinPvEvent::REASSIGNED)->sole();
        $this->assertSame([$manager->id, $aud->id, $audTwo->id], [$reassigned->actor_user_id, $reassigned->from_user_id, $reassigned->to_user_id]);

        // The same officer again: nothing is written.
        $before = FinPvEvent::query()->count();
        $this->workflow()->assign($manager, $pv, $audTwo->id);
        $this->assertSame($before, FinPvEvent::query()->count());

        $this->assertSame(['audit.pv_assigned', 'audit.pv_reassigned'], AuditLog::query()->where('action', 'like', 'audit.pv_%assigned')->orderBy('id')->pluck('action')->all());
        $row = json_encode(AuditLog::query()->where('action', 'audit.pv_reassigned')->first()->metadata ?? AuditLog::query()->where('action', 'audit.pv_reassigned')->first()->toArray());
        $this->assertStringNotContainsString('Start with this one', $row, 'the note stays on the timeline, never in the audit row');

        // An assignment is not review work: it never makes the voucher "worked on" and never changes the marks.
        $this->assertFalse($this->scope()->containsPv($manager, $pv, FinanceScope::AS_AUDIT));
        $this->assertSame('waiting', $pv->fresh(['events'])->marks()['audit']['state']);
    }

    public function test_only_a_voucher_with_audit_can_be_assigned_or_reassigned(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audTwo = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);

        $queried = $this->workflow()->auditQuery($aud, $this->sentAndAssigned($fin, $aud, ['pv_no' => 'Q']), 'other', 'x');
        $ticked = $this->workflow()->auditAcknowledge($aud, $this->sentAndAssigned($fin, $aud, ['pv_no' => 'T']));
        $voided = $this->workflow()->void($fin, $this->sent($fin, ['pv_no' => 'V']), 'Mistake');

        foreach ([$queried, $ticked, $voided] as $pv) {
            $this->assertValidation('status', fn () => $this->workflow()->assign($manager, $pv, $audTwo->id));
        }

        // Back With Audit (a corrected query), it can be moved again.
        $this->actingAs($fin);
        $back = $this->workflow()->resubmit($fin, $queried, $queried->lock_version);
        $this->assertSame($audTwo->id, $this->workflow()->assign($manager, $back, $audTwo->id)->assigned_to_user_id);
    }

    public function test_the_officer_must_be_an_active_audit_officer_of_the_location_who_did_not_send_the_voucher(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $both = $this->both('B1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $kumasiOfficer = $this->audit('AU9', $this->kumasi);
        $financeOnly = $this->finance('F2', $this->sowutuom);
        $inactive = $this->audit('AU8', $this->sowutuom);
        $inactive->update(['is_active' => false]);
        $other = $this->audit('AU1', $this->sowutuom);
        $mine = $this->sent($both, ['pv_no' => 'MINE']);
        $pv = $this->sent($fin, ['pv_no' => 'THEIRS']);

        foreach ([$kumasiOfficer, $financeOnly, $inactive, $manager] as $wrong) {
            $this->assertValidation('officer', fn () => $this->workflow()->assign($manager, $pv, $wrong->id));
        }

        $this->assertValidation('officer', fn () => $this->workflow()->assign($manager, $pv, 999999));
        $this->assertValidation('officer', fn () => $this->workflow()->assign($manager, $mine, $both->id), );
        $this->assertNull($mine->fresh()->assigned_to_user_id);

        // The person who sent it is fine for another voucher, and the other officer for this one.
        $this->assertSame($both->id, $this->workflow()->assign($manager, $pv, $both->id)->assigned_to_user_id);
        $this->assertSame($other->id, $this->workflow()->assign($manager, $mine, $other->id)->assigned_to_user_id);

        // Having been assigned does not survive becoming the submitter: the old officer cannot be given it back.
        $this->actingAs($both);
        $this->workflow()->auditQuery($both, $pv->fresh(), 'other', 'x');
        $this->workflow()->resubmit($both, $pv->fresh(), $pv->fresh()->lock_version);
        $this->assertValidation('officer', fn () => $this->workflow()->assign($manager, $pv->fresh(), $both->id));
    }

    public function test_the_picker_offers_eligible_officers_with_their_workload_and_leaves_out_every_submitter(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $both = $this->both('B1', $this->sowutuom);
        $busy = $this->audit('AU1', $this->sowutuom);
        $free = $this->audit('AU2', $this->odorkor);
        $this->audit('AU9', $this->kumasi);
        $this->finance('F2', $this->sowutuom);
        $inactive = $this->audit('AU8', $this->sowutuom);
        $inactive->update(['is_active' => false]);
        $manager = $this->auditManager('AM1', $this->sowutuom);

        $this->sentAndAssigned($fin, $busy, ['pv_no' => 'OPEN-1']);
        $this->sentAndAssigned($fin, $busy, ['pv_no' => 'OPEN-2']);
        $byFin = $this->sent($fin, ['pv_no' => 'BY-FIN']);
        $byBoth = $this->sent($both, ['pv_no' => 'BY-BOTH']);

        $choices = $this->batches()->officerChoices($manager, [$byFin->id]);
        $this->assertEqualsCanonicalizing([$busy->id, $free->id, $both->id], $choices->pluck('value')->all(), 'only this location\'s active officers; the finance-only user, Kumasi and the inactive one are out');
        $this->assertSame(2, $choices->firstWhere('value', $busy->id)['open']);
        $this->assertStringContainsString('2 open', $choices->firstWhere('value', $busy->id)['description']);
        $this->assertSame(0, $choices->firstWhere('value', $free->id)['open']);

        // For a group, everyone who sent ANY of the vouchers is out.
        $group = $this->batches()->officerChoices($manager, [$byFin->id, $byBoth->id]);
        $this->assertEqualsCanonicalizing([$busy->id, $free->id], $group->pluck('value')->all());

        // Only the manager asks; a voucher outside the manager's queue is a 404.
        $this->assertForbidden(fn () => $this->batches()->officerChoices($busy, [$byFin->id]));
        $this->assertNotFound(fn () => $this->batches()->officerChoices($this->auditManager('AM2', $this->kumasi), [$byFin->id]));
        $this->assertNotFound(fn () => $this->batches()->officerChoices($manager, [$this->draft($fin, ['pv_no' => 'DRAFT-Y'])->id]));
    }

    // ------------------------------------------------------------------ batches and splitting

    public function test_a_whole_batch_is_assigned_and_can_be_split_across_several_officers(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audTwo = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $vouchers = array_map(fn (int $i) => $this->draft($fin, ['pv_no' => "B-{$i}"]), [1, 2, 3, 4]);
        $batch = $this->batches()->send($fin, $this->batches()->create($fin, array_map(fn (Pv $pv) => $pv->id, $vouchers)));

        $this->assertSame('awaiting_assignment', $batch->fresh()->state()['key']);

        $result = $this->batches()->assignBatch($manager, $batch, $aud->id, 'The whole month');
        $this->assertSame(['assigned' => 4, 'unchanged' => 0, 'skipped' => 0], $result);
        $this->assertSame([$aud->id], Pv::query()->where('batch_id', $batch->id)->pluck('assigned_to_user_id')->unique()->all(), 'every voucher inherits the officer');
        $this->assertSame(4, FinPvEvent::query()->where('action', FinPvEvent::ASSIGNED)->count(), 'one event per voucher, each with the batch id');
        $this->assertSame([$batch->id], FinPvEvent::query()->where('action', FinPvEvent::ASSIGNED)->pluck('batch_id')->unique()->all());
        $this->assertSame([FinBatchEvent::ASSIGNED], $batch->events()->where('action', FinBatchEvent::ASSIGNED)->pluck('action')->all());
        $this->assertSame('In progress (0 of 4 done)', $batch->fresh()->state()['label']);

        // Split: two of them go to the other officer.
        $result = $this->batches()->assignVouchers($manager, [$vouchers[2]->id, $vouchers[3]->id], $audTwo->id);
        $this->assertSame(['assigned' => 2, 'unchanged' => 0], $result);
        $this->assertSame([$aud->id, $aud->id, $audTwo->id, $audTwo->id], Pv::query()->where('batch_id', $batch->id)->orderBy('id')->pluck('assigned_to_user_id')->all());
        $this->assertSame(2, FinPvEvent::query()->where('action', FinPvEvent::REASSIGNED)->count());

        // The batch back to the first officer: the two that are theirs already are left alone.
        $result = $this->batches()->assignBatch($manager, $batch, $aud->id);
        $this->assertSame(['assigned' => 2, 'unchanged' => 2, 'skipped' => 0], $result);
        $this->assertSame(1, $batch->events()->where('action', FinBatchEvent::REASSIGNED)->count());

        // A voucher that has already moved on (queried) is not part of an assignment.
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $vouchers[0], 'other', 'x');
        $result = $this->batches()->assignBatch($manager, $batch, $audTwo->id);
        $this->assertSame(['assigned' => 3, 'unchanged' => 0, 'skipped' => 1], $result);
    }

    public function test_a_batch_assignment_is_all_or_nothing_and_needs_a_sent_batch_of_the_managers_location(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $both = $this->both('B1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $managerKumasi = $this->auditManager('AM2', $this->kumasi);
        [$a] = [$this->draft($fin, ['pv_no' => 'A'])];
        $mine = $this->draft($both, ['pv_no' => 'MINE']);
        $draftBatch = $this->batches()->create($fin, [$a->id]);
        $this->actingAs($both);
        $sent = $this->batches()->send($both, $this->batches()->create($both, [$mine->id]));

        $this->assertNotFound(fn () => $this->batches()->assignBatch($manager, $draftBatch, $aud->id), 'a draft batch is not Audit\'s');
        $this->assertNotFound(fn () => $this->batches()->assignBatch($managerKumasi, $sent, $aud->id));
        $this->assertForbidden(fn () => $this->batches()->assignBatch($aud, $sent, $aud->id));
        $this->assertValidation('officer', fn () => $this->batches()->assignBatch($manager, $sent, $both->id));
        $this->assertNull($mine->fresh()->assigned_to_user_id);
        $this->assertSame(0, FinPvEvent::query()->whereIn('action', FinPvEvent::ASSIGNING)->count());
    }

    // ------------------------------------------------------------------ what an officer can do and see

    public function test_an_officer_cannot_see_or_act_on_a_voucher_that_is_unassigned_or_someone_elses_and_super_admin_cannot_act_either(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audTwo = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $super = $this->superAdmin();

        $unassigned = $this->sent($fin, ['pv_no' => 'NOBODY']);
        $theirs = $this->sentAndAssigned($fin, $audTwo, ['pv_no' => 'THEIRS']);

        // Unassigned: invisible to every officer, so it is a 404, not a refusal.
        $this->assertFalse($this->scope()->containsPv($aud, $unassigned, FinanceScope::AS_AUDIT));
        $this->assertNotFound(fn () => $this->workflow()->auditAcknowledge($aud, $unassigned));
        $this->assertNotFound(fn () => $this->workflow()->auditQuery($aud, $unassigned, 'other', 'x'));
        $this->assertNotFound(fn () => $this->workflow()->auditAcknowledge($audTwo, $unassigned));

        // Someone else's: invisible too.
        $this->assertNotFound(fn () => $this->workflow()->auditAcknowledge($aud, $theirs));
        $this->assertNotFound(fn () => $this->workflow()->auditQuery($aud, $theirs, 'other', 'x'));

        // super_admin reads everything but is not exempt: nothing is ever assigned to them.
        $this->assertValidation('action', fn () => $this->workflow()->auditAcknowledge($super, $unassigned));
        $this->assertValidation('action', fn () => $this->workflow()->auditAcknowledge($super, $theirs));
        $this->assertValidation('action', fn () => $this->workflow()->auditQuery($super, $theirs, 'other', 'x'));

        // The manager reviews nothing.
        $this->assertForbidden(fn () => $this->workflow()->auditAcknowledge($manager, $theirs));

        foreach ([$unassigned, $theirs] as $pv) {
            $this->assertSame(Pv::WITH_AUDIT, $pv->fresh()->status);
        }

        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $this->workflow()->auditAcknowledge($audTwo, $theirs)->status);
    }

    public function test_after_a_reassignment_the_old_officer_keeps_reading_what_they_did_but_can_no_longer_act(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $first = $this->audit('AU1', $this->sowutuom);
        $second = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);

        $worked = $this->sentAndAssigned($fin, $first, ['pv_no' => 'WORKED']);
        $idle = $this->sentAndAssigned($fin, $first, ['pv_no' => 'IDLE']);

        $this->actingAs($first);
        $worked = $this->workflow()->auditQuery($first, $worked, 'other', 'Please check');
        $this->actingAs($fin);
        $worked = $this->workflow()->resubmit($fin, $worked, $worked->lock_version);

        $this->assertEqualsCanonicalizing(['WORKED', 'IDLE'], $this->scope()->pvs($first, FinanceScope::AS_AUDIT)->pluck('pv_no')->all());

        $this->workflow()->assign($manager, $worked, $second->id);
        $this->workflow()->assign($manager, $idle, $second->id);

        // They keep reading the one they queried (it is their own work), and lose the one they never touched.
        $this->assertSame(['WORKED'], $this->scope()->pvs($first, FinanceScope::AS_AUDIT)->pluck('pv_no')->all());
        $error = $this->assertValidation('action', fn () => $this->workflow()->auditAcknowledge($first, $worked));
        $this->assertStringContainsString('another Audit officer', $error->errors()['action'][0]);
        $this->assertNotFound(fn () => $this->workflow()->auditAcknowledge($first, $idle));
        $this->assertEqualsCanonicalizing(['WORKED', 'IDLE'], $this->scope()->pvs($second, FinanceScope::AS_AUDIT)->pluck('pv_no')->all());
        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $this->workflow()->auditAcknowledge($second, $worked)->status);
    }

    public function test_a_corrected_voucher_stays_with_the_same_officer_in_the_next_round_and_the_manager_may_move_it(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audTwo = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $pv = $this->sentAndAssigned($fin, $aud);

        $this->actingAs($aud);
        $pv = $this->workflow()->auditQuery($aud, $pv, 'other', 'Check');
        $this->actingAs($fin);
        $before = FinPvEvent::query()->whereIn('action', FinPvEvent::ASSIGNING)->count();
        $pv = $this->workflow()->resubmit($fin, $pv, $pv->lock_version);

        $this->assertSame(2, $pv->round);
        $this->assertSame($aud->id, $pv->assigned_to_user_id, 'the same officer');
        $this->assertSame($before, FinPvEvent::query()->whereIn('action', FinPvEvent::ASSIGNING)->count(), 'no new assignment');
        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $this->workflow()->auditAcknowledge($aud, $pv)->status);

        // And the manager can still move one that is With Audit again.
        $other = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'OTHER']);
        $this->actingAs($aud);
        $other = $this->workflow()->auditQuery($aud, $other, 'other', 'Check');
        $this->actingAs($fin);
        $other = $this->workflow()->resubmit($fin, $other, $other->lock_version);
        $this->assertSame($audTwo->id, $this->workflow()->assign($manager, $other, $audTwo->id)->assigned_to_user_id);
    }

    // ------------------------------------------------------------------ the manager's queue

    public function test_the_assignment_queue_lists_every_sent_voucher_with_minimal_columns_and_never_a_draft(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $managerKumasi = $this->auditManager('AM2', $this->kumasi);
        $this->draft($fin, ['pv_no' => 'DRAFT-1']);
        $awaiting = $this->sent($fin, ['pv_no' => 'AWAITING']);
        $assigned = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'ASSIGNED']);
        $this->sent($this->finance('F9', $this->kumasi), ['pv_no' => 'KUMASI']);

        $rows = $this->scope()->assignmentQueue($manager)->orderBy('fin_pvs.id')->get();

        $this->assertSame(['AWAITING', 'ASSIGNED'], $rows->pluck('pv_no')->all(), 'sent vouchers of the location, drafts and other locations never; unassigned (legacy) ones included');
        $this->assertNull($rows[0]->assigned_to_user_id);
        $this->assertSame($aud->id, $rows[1]->assigned_to_user_id);

        // Only the reference, where it is, how much, when and who: no payee, details, remarks or creator.
        $expected = array_map(fn (string $column) => str_replace('fin_pvs.', '', $column), FinanceScope::QUEUE_COLUMNS);
        $this->assertEqualsCanonicalizing($expected, array_keys($rows[0]->getAttributes()));
        foreach (['payee_name', 'payee_kind', 'details', 'remarks', 'created_by', 'supplier_id', 'submitted_snapshot'] as $secret) {
            $this->assertArrayNotHasKey($secret, $rows[0]->getAttributes());
        }

        // Nobody else gets the queue.
        foreach ([$aud, $fin, $managerKumasi] as $other) {
            $this->assertSame($other === $managerKumasi ? ['KUMASI'] : [], $this->scope()->assignmentQueue($other)->pluck('pv_no')->all());
        }

        $this->assertSame(['AWAITING', 'ASSIGNED'], $this->scope()->assignmentQueue($this->superAdmin())->orderBy('fin_pvs.id')->where('fin_pvs.region_id', $this->sowutuom->region_id)->pluck('pv_no')->all());

        // The queue is a list, not a door: opening the voucher is still decided by pvs().
        $this->assertFalse($this->scope()->containsPv($manager, $awaiting, FinanceScope::AS_AUDIT));
        $this->assertFalse($this->scope()->containsPv($manager, $assigned, FinanceScope::AS_AUDIT));
    }

    public function test_the_manager_cannot_open_a_voucher_before_it_has_an_audit_action_and_can_afterwards(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $pv = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'LOOK-1']);

        $this->actingAs($manager)->get(route('audit.pvs.show', $pv->id))->assertNotFound();

        // Giving it to someone else, or to nobody, changes nothing about that; an Audit action does.
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $pv, 'other', 'Check');

        $this->actingAs($manager)->get(route('audit.pvs.show', $pv->id))->assertOk();
        $this->assertContains($pv->id, $this->scope()->pvs($manager, FinanceScope::AS_AUDIT)->pluck('id')->all());
    }

    public function test_existing_sent_vouchers_show_as_awaiting_assignment_and_the_migration_adds_the_permission_once(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $legacy = $this->sent($fin, ['pv_no' => 'LEGACY']);
        DB::table('fin_pvs')->where('id', $legacy->id)->update(['assigned_to_user_id' => null, 'assigned_by_user_id' => null, 'assigned_at' => null, 'batch_id' => null]);

        $this->assertSame([], $this->scope()->pvs($aud, FinanceScope::AS_AUDIT)->pluck('pv_no')->all(), 'invisible to officers until assigned');
        $this->assertSame(['LEGACY'], $this->scope()->assignmentQueue($manager)->pluck('pv_no')->all());

        // The permission: rebuild a database migrated before this change and run the migration twice.
        $permission = Permission::query()->where('name', 'audit.assign')->firstOrFail();
        DB::table('role_permissions')->where('permission_id', $permission->id)->delete();
        $permission->delete();

        $migration = require base_path('database/migrations/2026_11_15_000001_create_finance_batches_and_assignment.php');
        $migration->up();
        $migration->up();

        $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', 'audit.assign'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['audit_manager', 'super_admin'], $holders);
        $this->assertSame(1, Permission::query()->where('name', 'audit.assign')->count());
    }

    // ------------------------------------------------------------------ bulk acknowledgement

    public function test_acknowledge_all_remaining_ticks_the_officers_own_vouchers_one_event_each_and_skips_and_reports_the_rest(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $one = $this->audit('AU1', $this->sowutuom);
        $two = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $vouchers = array_map(fn (int $i) => $this->draft($fin, ['pv_no' => "R-{$i}"]), [1, 2, 3, 4, 5]);
        $batch = $this->batches()->send($fin, $this->batches()->create($fin, array_map(fn (Pv $pv) => $pv->id, $vouchers)));
        [$r1, $r2, $r3, $r4, $r5] = $vouchers;

        $this->batches()->assignVouchers($manager, [$r1->id, $r2->id, $r4->id, $r5->id], $one->id);
        $this->batches()->assignVouchers($manager, [$r3->id], $two->id);

        // R-4: the first officer queried it, Finance corrected it, and the manager then gave it to the second officer.
        $this->actingAs($one);
        $this->workflow()->auditQuery($one, $r4, 'other', 'Check');
        $this->actingAs($fin);
        $this->workflow()->resubmit($fin, $r4->fresh(), $r4->fresh()->lock_version);
        $this->workflow()->assign($manager, $r4->fresh(), $two->id);
        // R-5: already ticked by them, so nothing "remains" for it.
        $this->actingAs($one);
        $this->workflow()->auditAcknowledge($one, $r5);

        $result = $this->batches()->acknowledgeRemaining($one, $batch);

        $this->assertSame(2, $result['acknowledged'], 'R-1 and R-2');
        $this->assertSame([['pv_no' => 'R-4', 'reason' => 'This voucher is assigned to another Audit officer.']], $result['skipped'], 'visible to them, but no longer theirs');
        $this->assertSame(1, $result['not_yours'], 'R-3 belongs to someone else and is not even named');
        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $r1->fresh()->status);
        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $r2->fresh()->status);
        $this->assertSame(Pv::WITH_AUDIT, $r3->fresh()->status);
        $this->assertSame(Pv::WITH_AUDIT, $r4->fresh()->status);
        $this->assertSame(1, FinPvEvent::query()->where('pv_id', $r1->id)->where('action', FinPvEvent::AUDIT_ACKNOWLEDGED)->count(), 'one event per voucher');
        $this->assertSame(1, FinPvEvent::query()->where('pv_id', $r2->id)->where('action', FinPvEvent::AUDIT_ACKNOWLEDGED)->count());

        // Not an officer, not their batch: refused or invisible.
        $this->assertForbidden(fn () => $this->batches()->acknowledgeRemaining($manager, $batch));
        $this->assertNotFound(fn () => $this->batches()->acknowledgeRemaining($this->audit('AU7', $this->sowutuom), $batch));
        $this->assertSame(1, AuditLog::query()->where('action', 'audit.batch_bulk_acknowledged')->count());
    }

    public function test_finance_acknowledges_all_the_ticked_vouchers_of_a_batch_one_event_each(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $financeManager = $this->financeManager('FM1', $this->sowutuom);
        $vouchers = array_map(fn (int $i) => $this->draft($fin, ['pv_no' => "F-{$i}"]), [1, 2, 3]);
        $batch = $this->batches()->send($fin, $this->batches()->create($fin, array_map(fn (Pv $pv) => $pv->id, $vouchers)));
        $this->batches()->assignBatch($manager, $batch, $aud->id);

        $this->actingAs($aud);
        $this->workflow()->auditAcknowledge($aud, $vouchers[0]);
        $this->workflow()->auditAcknowledge($aud, $vouchers[1]);

        $this->actingAs($fin);
        $result = $this->batches()->financeAcknowledgeAll($fin, $batch);

        $this->assertSame(['acknowledged' => 2, 'skipped' => []], $result);
        $this->assertSame([Pv::COMPLETED, Pv::COMPLETED, Pv::WITH_AUDIT], array_map(fn (Pv $pv) => $pv->fresh()->status, $vouchers));
        $this->assertSame(2, FinPvEvent::query()->where('action', FinPvEvent::FINANCE_ACKNOWLEDGED)->count());
        $this->assertSame(['acknowledged' => 0, 'skipped' => []], $this->batches()->financeAcknowledgeAll($fin, $batch), 'nothing left to acknowledge');

        $this->assertForbidden(fn () => $this->batches()->financeAcknowledgeAll($financeManager, $batch));
        $this->assertNotFound(fn () => $this->batches()->financeAcknowledgeAll($this->finance('A1', $this->kumasi), $batch));
    }
}
