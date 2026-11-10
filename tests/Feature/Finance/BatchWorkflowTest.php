<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FinBatchEvent;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvBatch;
use App\Models\FinPvEvent;
use App\Services\Finance\BatchSendException;
use App\Services\Finance\BatchWorkflowService;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvConflictException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Step F: a batch is a CONTAINER (build, send, cancel, track), never a unit of approval. Every transition and each illegal one,
 * the number, the cap, all-or-nothing sending, the computed state and the location rule.
 */
class BatchWorkflowTest extends FinanceTestCase
{
    private function batches(): BatchWorkflowService
    {
        return app(BatchWorkflowService::class);
    }

    private function scope(): FinanceScope
    {
        return app(FinanceScope::class);
    }

    /** @return list<Pv> */
    private function drafts($user, int $count, string $prefix = 'D'): array
    {
        return array_map(fn (int $i) => $this->draft($user, ['pv_no' => "{$prefix}-{$i}", 'amount' => (string) (100 + $i).'.00']), range(1, $count));
    }

    private function ids(array $vouchers): array
    {
        return array_map(fn (Pv $pv) => $pv->id, $vouchers);
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

    // ------------------------------------------------------------------ creating and numbering

    public function test_a_batch_is_built_from_selected_drafts_and_numbered_by_the_service_per_location_and_year(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ho = $this->finance('H1', $this->headOffice);
        [$a, $b] = $this->drafts($fin, 2);

        $batch = $this->batches()->create($fin, $this->ids([$a, $b]), '  October claims ', 'For the board');

        $this->assertMatchesRegularExpression('/^BT-[A-Z0-9]+-2026-0001$/', $batch->batch_no);
        $this->assertNotSame('BT-HO-2026-0001', $batch->batch_no);
        $this->assertSame('October claims', $batch->title);
        $this->assertSame(FinPvBatch::DRAFT, $batch->status);
        $this->assertSame(1, $batch->lock_version);
        $this->assertSame($this->sowutuom->region_id, $batch->region_id);
        $this->assertSame([$batch->id, $batch->id], Pv::query()->whereIn('id', $this->ids([$a, $b]))->pluck('batch_id')->all());
        $this->assertSame(
            [FinBatchEvent::CREATED, FinBatchEvent::PV_ADDED, FinBatchEvent::PV_ADDED],
            $batch->events()->pluck('action')->all()
        );
        $this->assertSame(1, AuditLog::query()->where('action', 'finance.batch_created')->count());

        // The counter is per location and per year; the number is never typed by hand.
        [$c] = $this->drafts($fin, 1, 'E');
        $this->assertStringEndsWith('-2026-0002', $this->batches()->create($fin, [$c->id])->batch_no);

        [$h] = $this->drafts($ho, 1, 'H');
        $this->assertSame('BT-HO-2026-0001', $this->batches()->create($ho, [$h->id])->batch_no, 'Head Office counts on its own');

        Carbon::setTestNow('2027-01-05 09:00:00');
        [$d] = $this->drafts($fin, 1, 'F');
        $this->assertMatchesRegularExpression('/^BT-[A-Z0-9]+-2027-0001$/', $this->batches()->create($fin, [$d->id])->batch_no, 'a new year starts again');
    }

    public function test_only_eligible_vouchers_can_be_put_in_a_batch_and_a_voucher_is_in_at_most_one(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        [$a, $b] = $this->drafts($fin, 2);
        $sent = $this->assignTo($this->sent($fin, ['pv_no' => 'SENT-1']), $aud);
        $elsewhere = $this->draft($ash, ['pv_no' => 'ASH-1']);
        $this->actingAs($fin);

        $first = $this->batches()->create($fin, [$a->id]);

        // Already in a batch: refused, naming that batch.
        $error = $this->assertValidation('vouchers', fn () => $this->batches()->create($fin, [$a->id, $b->id]));
        $this->assertStringContainsString($first->batch_no, $error->errors()['vouchers'][0]);
        $this->assertNull($b->fresh()->batch_id, 'nothing was added');

        // Only a Draft; only the creator's location (another location's voucher is a 404); at least one.
        $this->assertValidation('vouchers', fn () => $this->batches()->create($fin, [$sent->id]));
        $this->assertNotFound(fn () => $this->batches()->create($fin, [$elsewhere->id]));
        $this->assertNotFound(fn () => $this->batches()->create($fin, [999999]));
        $this->assertValidation('vouchers', fn () => $this->batches()->create($fin, []));

        // The cap.
        config(['gwl.fin_batch_max_vouchers' => 2]);
        [$x, $y, $z] = $this->drafts($fin, 3, 'CAP');
        $this->assertValidation('vouchers', fn () => $this->batches()->create($fin, $this->ids([$x, $y, $z])));
        $this->assertSame(2, $this->batches()->create($fin, $this->ids([$x, $y]))->vouchers()->count());
        $this->assertValidation('vouchers', fn () => $this->batches()->addVouchers($fin, $this->batches()->create($fin, [$z->id]), [$b->id, $a->fresh()->id]), );
    }

    public function test_the_permissions_decide_who_builds_batches(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        [$a] = $this->drafts($fin, 1);

        $this->assertForbidden(fn () => $this->batches()->create($manager, [$a->id]));
        $this->assertForbidden(fn () => $this->batches()->create($aud, [$a->id]));

        // super_admin must name the district whose books the batch goes into.
        $super = $this->superAdmin();
        $this->actingAs($super);
        $this->assertValidation('location', fn () => $this->batches()->create($super, [$a->id]));
        $batch = $this->batches()->create($super, [$a->id], null, null, $this->sowutuom->id);
        $this->assertSame($this->sowutuom->id, $batch->district_id);
    }

    // ------------------------------------------------------------------ the draft batch, send, cancel

    public function test_a_draft_batch_can_be_changed_sent_and_then_its_membership_is_frozen(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a, $b, $c, $d] = $this->drafts($fin, 4);
        $batches = $this->batches();

        $batch = $batches->create($fin, $this->ids([$a, $b]));
        $batch = $batches->addVouchers($fin, $batch, [$c->id], 1);
        $this->assertSame(2, $batch->lock_version);
        $batch = $batches->removeVouchers($fin, $batch, [$a->id]);
        $this->assertNull($a->fresh()->batch_id, 'taken out, still a draft and free');
        $batch = $batches->updateDetails($fin, $batch, 'Title', 'Remarks');
        $this->assertSame(4, $batch->lock_version);
        $this->assertSame([FinBatchEvent::CREATED, FinBatchEvent::PV_ADDED, FinBatchEvent::PV_ADDED, FinBatchEvent::PV_ADDED, FinBatchEvent::PV_REMOVED], $batch->events()->pluck('action')->all());

        // A stale screen is a conflict, never a silent overwrite.
        $this->expectException(PvConflictException::class);
        try {
            $batches->addVouchers($fin, $batch, [$d->id], 1);
        } finally {
            $this->assertNull($d->fresh()->batch_id);
        }
    }

    public function test_sending_a_batch_submits_every_voucher_on_its_own_and_freezes_the_batch(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a, $b, $c] = $this->drafts($fin, 3);
        $outsider = $this->draft($fin, ['pv_no' => 'LOOSE']);
        $batches = $this->batches();
        $batch = $batches->create($fin, $this->ids([$a, $b]));

        $sent = $batches->send($fin, $batch);

        $this->assertSame(FinPvBatch::SENT, $sent->status);
        $this->assertNotNull($sent->sent_at);
        $this->assertSame($fin->id, $sent->sent_by);

        foreach ([$a, $b] as $pv) {
            $pv = $pv->fresh();
            $this->assertSame(Pv::WITH_AUDIT, $pv->status);
            $this->assertSame(1, $pv->round);
            $this->assertNotNull($pv->submitted_at);
            $this->assertSame($batch->id, $pv->batch_id, 'it stays in the batch');
            $event = $pv->events()->where('action', FinPvEvent::SUBMITTED)->sole();
            $this->assertSame($batch->id, $event->batch_id, 'its own submitted event carries the batch id');
            $this->assertSame($fin->id, $event->actor_user_id);
        }

        $this->assertSame(Pv::DRAFT, $outsider->fresh()->status, 'a loose draft is untouched');
        $this->assertSame(FinBatchEvent::SENT, $sent->events()->reorder()->latest('id')->first()->action);
        $this->assertSame(2, AuditLog::query()->where('action', 'finance.pv_submitted')->count());

        // Membership is frozen, and every other change of a sent batch is refused.
        $this->assertValidation('batch', fn () => $batches->addVouchers($fin, $sent, [$c->id]));
        $this->assertValidation('batch', fn () => $batches->removeVouchers($fin, $sent, [$a->id]));
        $this->assertValidation('batch', fn () => $batches->updateDetails($fin, $sent, 'x', 'y'));
        $this->assertValidation('batch', fn () => $batches->send($fin, $sent));
        $this->assertValidation('batch', fn () => $batches->cancel($fin, $sent, 'Too late'));
        $this->assertSame($batch->id, $a->fresh()->batch_id);
    }

    public function test_a_voucher_in_a_draft_batch_can_only_be_sent_through_the_batch_and_cannot_be_voided(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a, $b] = $this->drafts($fin, 2);
        $batch = $this->batches()->create($fin, [$a->id]);

        $this->assertValidation('status', fn () => $this->workflow()->submit($fin, $a->fresh(), $a->lock_version));
        $this->assertValidation('reason', fn () => $this->workflow()->void($fin, $a->fresh(), 'Mistake'));
        $this->assertSame(Pv::DRAFT, $a->fresh()->status);

        // It can still be edited, and once it is taken out it is free again.
        $edited = $this->workflow()->update($fin, $a->fresh(), $this->pvData(['pv_no' => 'D-1', 'amount' => '55.00']), $a->lock_version);
        $this->assertSame(5500, $edited->amount);
        $this->batches()->removeVouchers($fin, $batch, [$a->id]);
        $this->assertSame(Pv::WITH_AUDIT, $this->workflow()->submit($fin, $a->fresh(), $edited->lock_version)->status);
        $this->assertSame(Pv::VOIDED, $this->workflow()->void($fin, $b->fresh(), 'Mistake')->status);
    }

    public function test_sending_is_all_or_nothing_and_lists_each_failing_voucher(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a, $b, $c] = $this->drafts($fin, 3);
        $batch = $this->batches()->create($fin, $this->ids([$a, $b, $c]));

        // Two vouchers are incomplete (a draft saved by an older version, say).
        DB::table('fin_pvs')->where('id', $b->id)->update(['details' => '']);
        DB::table('fin_pvs')->where('id', $c->id)->update(['amount' => 0]);

        try {
            $this->batches()->send($fin, $batch);
            $this->fail('An incomplete voucher must stop the whole batch.');
        } catch (BatchSendException $exception) {
            $this->assertSame([$b->id, $c->id], array_column($exception->failures, 'pv_id'));
            $this->assertSame(['The details are missing.'], $exception->failures[0]['reasons']);
            $this->assertSame(['The amount is missing.'], $exception->failures[1]['reasons']);
            $lines = implode(' ', $exception->errors()['batch']);
            $this->assertStringContainsString('PV D-2', $lines);
            $this->assertStringContainsString('PV D-3', $lines);
            $this->assertStringNotContainsString('PV D-1', $lines);
        }

        foreach ([$a, $b, $c] as $pv) {
            $this->assertSame(Pv::DRAFT, $pv->fresh()->status, 'nothing was sent');
        }

        $this->assertSame(0, FinPvEvent::query()->count());
        $this->assertSame(FinPvBatch::DRAFT, $batch->fresh()->status);
        $this->assertSame(0, FinBatchEvent::query()->where('action', FinBatchEvent::SENT)->count());

        // A voucher that was changed after the screen showed it is a conflict, not a surprise.
        DB::table('fin_pvs')->where('id', $b->id)->update(['details' => 'Fixed']);
        DB::table('fin_pvs')->where('id', $c->id)->update(['amount' => 100]);
        $this->assertThrows(fn () => $this->batches()->send($fin, $batch, null, [$a->id => 99]), PvConflictException::class);
        $this->assertSame(Pv::DRAFT, $a->fresh()->status);

        $this->assertSame(FinPvBatch::SENT, $this->batches()->send($fin, $batch, null, [$a->id => $a->lock_version])->status);
    }

    public function test_an_empty_batch_cannot_be_sent_and_a_draft_batch_is_cancelled_with_a_reason(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a, $b] = $this->drafts($fin, 2);
        $batches = $this->batches();
        $batch = $batches->create($fin, $this->ids([$a, $b]));

        $batches->removeVouchers($fin, $batch, $this->ids([$a, $b]));
        $this->assertValidation('batch', fn () => $batches->send($fin, $batch));

        $batches->addVouchers($fin, $batch, $this->ids([$a, $b]));
        $this->assertValidation('reason', fn () => $batches->cancel($fin, $batch, '  '));

        $cancelled = $batches->cancel($fin, $batch, 'Wrong month');

        $this->assertSame(FinPvBatch::CANCELLED, $cancelled->status);
        $this->assertSame('Wrong month', $cancelled->cancel_reason);
        $this->assertSame($fin->id, $cancelled->cancelled_by);
        $this->assertNull($a->fresh()->batch_id, 'released, nothing deleted');
        $this->assertNull($b->fresh()->batch_id);
        $this->assertSame(Pv::DRAFT, $a->fresh()->status);
        $this->assertSame(1, FinPvBatch::query()->count(), 'the batch itself stays');
        $this->assertSame(FinBatchEvent::CANCELLED, $cancelled->events()->reorder()->latest('id')->first()->action);

        // A cancelled batch is finished.
        $this->assertValidation('batch', fn () => $batches->addVouchers($fin, $cancelled, [$a->id]));
        $this->assertValidation('batch', fn () => $batches->send($fin, $cancelled));
        $this->assertValidation('batch', fn () => $batches->cancel($fin, $cancelled, 'Again'));

        // An empty batch can be cancelled too, and its vouchers go into a new batch.
        $empty = $batches->create($fin, [$a->id]);
        $batches->removeVouchers($fin, $empty, [$a->id]);
        $this->assertSame(FinPvBatch::CANCELLED, $batches->cancel($fin, $empty, 'Not needed')->status);
        $this->assertNotNull($batches->create($fin, [$a->id])->id);
    }

    // ------------------------------------------------------------------ the computed state

    public function test_the_state_is_computed_in_one_place_from_the_batch_and_its_vouchers(): void
    {
        $state = fn (string $status, array $members) => FinPvBatch::computeState($status, $members);
        $pv = fn (string $status, ?int $officer = null) => ['status' => $status, 'assigned_to_user_id' => $officer];

        $this->assertSame('draft', $state('draft', [$pv('draft')])['key']);
        $this->assertSame('cancelled', $state('cancelled', [])['key']);
        $this->assertSame('awaiting_assignment', $state('sent', [$pv('with_audit'), $pv('with_audit', 5)])['key'], 'one still has no officer');
        $this->assertSame('in_progress', $state('sent', [$pv('with_audit', 5), $pv('queried', 5)])['key']);
        $this->assertSame('In progress (1 of 3 done)', $state('sent', [$pv('with_audit', 5), $pv('completed', 5), $pv('with_audit', 6)])['label']);
        $this->assertSame('completed', $state('sent', [$pv('completed', 5), $pv('voided', 5)])['key'], 'voided counts as done');
        $this->assertSame('completed', $state('sent', [$pv('voided'), $pv('voided')])['key']);
        $this->assertSame('in_progress', $state('sent', [$pv('voided'), $pv('with_audit', 5)])['key'], 'a voided voucher needs no officer');
        $this->assertSame(['key' => 'awaiting_assignment', 'label' => 'Awaiting assignment', 'done' => 1, 'total' => 3, 'unassigned' => 1], $state('sent', [$pv('completed'), $pv('with_audit'), $pv('with_audit', 7)]));
    }

    public function test_the_state_follows_a_batch_through_its_life(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        [$a, $b, $c] = $this->drafts($fin, 3);
        $batch = $this->batches()->create($fin, $this->ids([$a, $b, $c]));
        $this->assertSame('draft', $batch->state()['key']);

        $batch = $this->batches()->send($fin, $batch);
        $this->assertSame('awaiting_assignment', $batch->fresh()->state()['key']);

        $this->batches()->assignBatch($this->managerFor($this->sowutuom), $batch, $aud->id);
        $this->assertSame('In progress (0 of 3 done)', $batch->fresh()->state()['label']);

        $this->actingAs($aud);
        $this->workflow()->auditAcknowledge($aud, $a);
        $this->actingAs($fin);
        $this->workflow()->financeAcknowledge($fin, $a);
        $this->assertSame('In progress (1 of 3 done)', $batch->fresh()->state()['label']);

        $this->workflow()->void($fin, $b, 'Duplicate');
        $this->assertSame('In progress (2 of 3 done)', $batch->fresh()->state()['label'], 'the voided voucher is shown as done');

        $this->actingAs($aud);
        $this->workflow()->auditAcknowledge($aud, $c);
        $this->actingAs($fin);
        $this->workflow()->financeAcknowledge($fin, $c);
        $this->assertSame('completed', $batch->fresh()->state()['key']);

        $this->assertSame('cancelled', $this->batches()->cancel($fin, $this->batches()->create($fin, [$this->draft($fin, ['pv_no' => 'LATER'])->id]), 'Nope')->state()['key']);
    }

    // ------------------------------------------------------------------ who reaches which batch

    public function test_another_location_cannot_reach_a_batch_and_the_finance_manager_only_reads(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $managerKumasi = $this->financeManager('FM2', $this->kumasi);
        [$a, $b] = $this->drafts($fin, 2);
        $batch = $this->batches()->create($fin, [$a->id]);

        $this->assertSame([$batch->id], $this->scope()->batches($fin)->pluck('id')->all());
        $this->assertSame([$batch->id], $this->scope()->batches($manager)->pluck('id')->all(), 'the Finance manager reads every batch of the location');
        $this->assertSame([], $this->scope()->batches($ash)->pluck('id')->all());
        $this->assertSame([], $this->scope()->batches($managerKumasi)->pluck('id')->all());
        $this->assertNotFound(fn () => $this->scope()->findBatch($ash, $batch->id));

        // Writes by another location are 404; by the Finance manager they are 403 (no right to write at all).
        $this->actingAs($ash);
        $this->assertNotFound(fn () => $this->batches()->addVouchers($ash, $batch, [$b->id]));
        $this->assertNotFound(fn () => $this->batches()->send($ash, $batch));
        $this->assertNotFound(fn () => $this->batches()->cancel($ash, $batch, 'Mine now'));
        $this->assertNotFound(fn () => $this->batches()->removeVouchers($ash, $batch, [$a->id]));

        foreach ([
            fn () => $this->batches()->addVouchers($manager, $batch, [$b->id]),
            fn () => $this->batches()->removeVouchers($manager, $batch, [$a->id]),
            fn () => $this->batches()->send($manager, $batch),
            fn () => $this->batches()->cancel($manager, $batch, 'No'),
            fn () => $this->batches()->financeAcknowledgeAll($manager, $batch),
        ] as $call) {
            $this->assertForbidden($call);
        }

        $this->assertSame(FinPvBatch::DRAFT, $batch->fresh()->status);
        $this->assertSame(1, Pv::query()->where('batch_id', $batch->id)->count());
    }

    public function test_the_audit_side_never_sees_a_draft_or_cancelled_batch_and_officers_only_batches_with_their_vouchers(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audTwo = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        [$a, $b, $c, $d] = $this->drafts($fin, 4);

        $draftBatch = $this->batches()->create($fin, [$a->id]);
        $sentBatch = $this->batches()->send($fin, $this->batches()->create($fin, [$b->id, $c->id]));
        $cancelled = $this->batches()->cancel($fin, $this->batches()->create($fin, [$d->id]), 'No');

        $audit = fn ($user) => $this->scope()->batches($user, FinanceScope::AS_AUDIT)->pluck('id')->all();

        $this->assertSame([$sentBatch->id], $audit($manager), 'the manager sees every SENT batch of the location');
        $this->assertSame([], $audit($aud), 'an officer sees a batch only once one of its vouchers is theirs');

        $this->batches()->assignVouchers($manager, [$b->id], $aud->id);
        $this->assertSame([$sentBatch->id], $audit($aud));
        $this->assertSame([], $audit($audTwo));
        $this->assertSame([], $audit($fin), 'Finance has no Audit door');
        $this->assertNotContains($draftBatch->id, $audit($manager));
        $this->assertNotContains($cancelled->id, $audit($manager));
        $this->assertSame([], $this->scope()->batches($this->audit('AU9', $this->kumasi), FinanceScope::AS_AUDIT)->pluck('id')->all());
    }
}
