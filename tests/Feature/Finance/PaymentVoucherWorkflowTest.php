<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvEvent;
use App\Models\FinPvType;
use App\Services\Finance\PvConflictException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Every transition and each illegal one, separation of duties, the version check, PV numbers and the immutable trail. */
class PaymentVoucherWorkflowTest extends FinanceTestCase
{
    public function test_the_happy_path_draft_to_completed(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);

        $pv = $this->draft($fin);
        $this->assertSame(Pv::DRAFT, $pv->status);
        $this->assertNull($pv->submitted_at);

        $pv = $this->workflow()->submit($fin, $pv, $pv->lock_version);
        $this->assertSame(Pv::WITH_AUDIT, $pv->status);
        $this->assertSame(1, $pv->round);
        $this->assertNotNull($pv->submitted_at);

        // Nobody in Audit can work on it until the Audit manager gives it to an officer.
        $pv = $this->assignTo($pv, $aud);

        $this->actingAs($aud);
        $pv = $this->workflow()->auditAcknowledge($aud, $pv);
        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $pv->status);

        $this->actingAs($fin);
        $pv = $this->workflow()->financeAcknowledge($fin, $pv);
        $this->assertSame(Pv::COMPLETED, $pv->status);
        $this->assertNotNull($pv->completed_at);

        $this->assertSame(
            [FinPvEvent::SUBMITTED, FinPvEvent::ASSIGNED, FinPvEvent::AUDIT_ACKNOWLEDGED, FinPvEvent::FINANCE_ACKNOWLEDGED],
            $pv->events()->pluck('action')->all()
        );
        $this->assertSame([$fin->id, $this->managerFor($this->sowutuom)->id, $aud->id, $fin->id], $pv->events()->pluck('actor_user_id')->all());
    }

    public function test_a_query_needs_a_reason_code_and_a_comment_and_resubmitting_raises_the_round_with_a_diff(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $pv = $this->sentAndAssigned($fin, $aud);

        $this->actingAs($aud);
        $this->assertValidationFails('reason_code', fn () => $this->workflow()->auditQuery($aud, $pv, 'nonsense', 'Wrong amount'));
        $this->assertValidationFails('comment', fn () => $this->workflow()->auditQuery($aud, $pv, 'amount_mismatch', '  '));

        $pv = $this->workflow()->auditQuery($aud, $pv, 'amount_mismatch', 'The receipt says 1,200.00');
        $this->assertSame(Pv::QUERIED, $pv->status);
        $queried = $pv->events()->where('action', FinPvEvent::QUERIED)->firstOrFail();
        $this->assertSame('amount_mismatch', $queried->query_reason_code);
        $this->assertSame('The receipt says 1,200.00', $queried->comment);

        // Finance corrects the amount and the details, then resubmits (round 2).
        $this->actingAs($fin);
        $pv = $this->workflow()->update($fin, $pv, $this->pvData(['amount' => '1200.00', 'details' => 'Medical claim, corrected']), $pv->lock_version);
        $pv = $this->workflow()->resubmit($fin, $pv, $pv->lock_version, 'Amount corrected');

        $this->assertSame(Pv::WITH_AUDIT, $pv->status);
        $this->assertSame(2, $pv->round);

        $resubmitted = $pv->events()->where('action', FinPvEvent::RESUBMITTED)->firstOrFail();
        $this->assertSame(2, $resubmitted->round);
        $this->assertSame('Amount corrected', $resubmitted->comment);
        $changed = collect($resubmitted->changes)->keyBy('field');
        $this->assertSame(['Details', 'Amount (GH¢)'], $changed->keys()->all());
        $this->assertSame('1,250.50', $changed['Amount (GH¢)']['old']);
        $this->assertSame('1,200.00', $changed['Amount (GH¢)']['new']);

        // Audit ticks in the second round.
        $this->actingAs($aud);
        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $this->workflow()->auditAcknowledge($aud, $pv)->status);
    }

    public function test_illegal_transitions_are_refused(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $workflow = $this->workflow();

        $draft = $this->draft($fin);
        $this->assertValidationFails('status', fn () => $workflow->resubmit($fin, $draft, $draft->lock_version));
        $this->assertValidationFails('status', fn () => $workflow->financeAcknowledge($fin, $draft));

        $sent = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'PV-2']);
        $this->assertValidationFails('status', fn () => $workflow->submit($fin, $sent, $sent->lock_version));
        $this->assertValidationFails('status', fn () => $workflow->update($fin, $sent, $this->pvData(['pv_no' => 'PV-2']), $sent->lock_version));
        $this->assertValidationFails('status', fn () => $workflow->financeAcknowledge($fin, $sent));
        $this->assertValidationFails('status', fn () => $workflow->withdrawTick($aud, $sent, 'Mistake'));

        $this->actingAs($aud);
        $ticked = $workflow->auditAcknowledge($aud, $sent);
        $this->assertValidationFails('status', fn () => $workflow->auditAcknowledge($aud, $ticked));
        $this->assertValidationFails('status', fn () => $workflow->auditQuery($aud, $ticked, 'other', 'Too late'));
        $this->assertValidationFails('status', fn () => $workflow->update($fin, $ticked, $this->pvData(['pv_no' => 'PV-2']), $ticked->lock_version));
        // Audit acknowledged must be withdrawn before it can be voided.
        $this->assertValidationFails('reason', fn () => $workflow->void($fin, $ticked, 'Cancel it'));

        $this->actingAs($fin);
        $done = $workflow->financeAcknowledge($fin, $ticked);
        $this->assertValidationFails('reason', fn () => $workflow->void($fin, $done, 'Cancel it'));
        $this->assertValidationFails('status', fn () => $workflow->update($fin, $done, $this->pvData(['pv_no' => 'PV-2']), $done->lock_version));
        // Completed is final: Audit can no longer withdraw its tick either.
        $this->assertValidationFails('status', fn () => $workflow->withdrawTick($aud, $done, 'Too late'));
    }

    public function test_the_audit_tick_can_be_withdrawn_with_a_reason_only_while_finance_has_not_acknowledged(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $pv = $this->sentAndAssigned($fin, $aud);

        $this->actingAs($aud);
        $pv = $this->workflow()->auditAcknowledge($aud, $pv);

        $this->assertValidationFails('reason', fn () => $this->workflow()->withdrawTick($aud, $pv, ' '));

        $pv = $this->workflow()->withdrawTick($aud, $pv, 'Receipt not legible');
        $this->assertSame(Pv::WITH_AUDIT, $pv->status);
        $this->assertSame(1, $pv->round);

        $event = $pv->events()->where('action', FinPvEvent::TICK_WITHDRAWN)->firstOrFail();
        $this->assertSame('Receipt not legible', $event->comment);
        $this->assertSame($aud->id, $event->actor_user_id);

        // Audit can tick again, and the voucher can be voided while it is With Audit.
        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $this->workflow()->auditAcknowledge($aud, $pv)->status);
    }

    public function test_the_person_who_sent_a_voucher_cannot_tick_query_or_withdraw_even_with_both_roles(): void
    {
        $both = $this->both('B1', $this->sowutuom);
        $other = $this->audit('AU2', $this->sowutuom);
        $pv = $this->sent($both);

        // The manager cannot give a voucher to the person who sent it, and that person cannot even see it in Audit.
        try {
            $this->assignTo($pv, $both);
            $this->fail('The submitter must not be assignable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('officer', $exception->errors());
        }

        $pv = $this->assignTo($pv, $other);

        $this->actingAs($both);
        $this->assertNotFound(fn () => $this->workflow()->auditAcknowledge($both, $pv));
        $this->assertNotFound(fn () => $this->workflow()->auditQuery($both, $pv, 'other', 'Hmm'));

        $this->actingAs($other);
        $pv = $this->workflow()->auditAcknowledge($other, $pv);

        $this->actingAs($both);
        $this->assertNotFound(fn () => $this->workflow()->withdrawTick($both, $pv, 'I changed my mind'));

        // It is Finance's own step, so the submitter may acknowledge.
        $this->assertSame(Pv::COMPLETED, $this->workflow()->financeAcknowledge($both, $pv)->status);
    }

    public function test_an_officer_who_later_resubmits_the_voucher_can_no_longer_review_it(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $both = $this->both('B1', $this->sowutuom);
        $pv = $this->sentAndAssigned($fin, $both);

        // They are assigned, so they may query it...
        $this->actingAs($both);
        $pv = $this->workflow()->auditQuery($both, $pv, 'other', 'Please check');

        // ...but once they (as Finance) correct and resubmit it, they became a submitter in round 2 and the voucher stays theirs only on paper.
        $pv = $this->workflow()->resubmit($both, $pv, $pv->lock_version);
        $this->assertSame($both->id, $pv->assigned_to_user_id);
        $this->assertValidationFails('action', fn () => $this->workflow()->auditAcknowledge($both, $pv));
        $this->assertValidationFails('action', fn () => $this->workflow()->auditQuery($both, $pv, 'other', 'No'));
    }

    public function test_separation_of_duties_counts_any_round_and_super_admin_is_not_exempt(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $super = $this->superAdmin();
        $pv = $this->sentAndAssigned($fin, $aud);

        $this->actingAs($aud);
        $pv = $this->workflow()->auditQuery($aud, $pv, 'other', 'Please check');

        // A different Finance user (super_admin) corrects and resubmits: they became a submitter in round 2.
        $this->actingAs($super);
        $pv = $this->workflow()->resubmit($super, $pv, $pv->lock_version);

        $this->assertValidationFails('action', fn () => $this->workflow()->auditAcknowledge($super, $pv));
        $this->assertValidationFails('action', fn () => $this->workflow()->auditQuery($super, $pv, 'other', 'No'));
        $this->assertTrue($this->workflow()->hasSubmitted($fin, $pv));
        $this->assertTrue($this->workflow()->hasSubmitted($super, $pv));
        $this->assertFalse($this->workflow()->hasSubmitted($aud, $pv));
    }

    public function test_permissions_are_checked_before_anything_else(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $pv = $this->sent($fin);

        $this->assertForbidden(fn () => $this->workflow()->createDraft($aud, $this->pvData(['pv_no' => 'X-1'])));
        $this->assertForbidden(fn () => $this->workflow()->auditAcknowledge($fin, $pv));
        $this->assertForbidden(fn () => $this->workflow()->void($aud, $pv, 'No'));
        $this->assertForbidden(fn () => $this->workflow()->financeAcknowledge($aud, $pv));
    }

    public function test_a_stale_form_raises_a_conflict_and_the_version_rises_only_with_a_change_of_content(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $other = $this->finance('F2', $this->sowutuom);
        $pv = $this->draft($fin);
        $this->assertSame(1, $pv->lock_version);

        // Saving the same content does not move the version.
        $same = $this->workflow()->update($fin, $pv, $this->pvData(), 1);
        $this->assertSame(1, $same->lock_version);

        // A real change does.
        $changed = $this->workflow()->update($other, $pv, $this->pvData(['amount' => '999.00']), 1);
        $this->assertSame(2, $changed->lock_version);

        // The first user still holds version 1: nothing is overwritten.
        $this->expectException(PvConflictException::class);
        $this->workflow()->update($fin, $pv, $this->pvData(['amount' => '1.00']), 1);
    }

    public function test_sending_with_a_stale_version_is_a_conflict_too(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $pv = $this->draft($fin);
        $this->workflow()->update($fin, $pv, $this->pvData(['amount' => '999.00']), 1);

        $this->expectException(PvConflictException::class);
        $this->workflow()->submit($fin, $pv, 1);
        $this->assertSame(Pv::DRAFT, $pv->fresh()->status);
    }

    public function test_a_pv_number_is_unique_company_wide_including_voided_ones_and_normalised(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);

        $first = $this->draft($west, ['pv_no' => ' pv   0042 ']);
        $this->assertSame('pv 0042', $first->pv_no);
        $this->assertSame('PV 0042', $first->pv_no_key);

        // The same number, written differently, in the same location and in another one.
        $this->assertValidationFails('pv_no', fn () => $this->workflow()->createDraft($west, $this->pvData(['pv_no' => 'PV 0042'])));
        $this->actingAs($ash);

        try {
            $this->workflow()->createDraft($ash, $this->pvData(['pv_no' => 'Pv  0042']));
            $this->fail('A number used in another location must be refused.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['pv_no'][0];
            $this->assertSame('This PV number is already used.', $message);
            $this->assertStringNotContainsString('Accra', $message);
            $this->assertStringNotContainsString('PV 0042', $message);
        }

        // A voided number is never free again.
        $this->actingAs($west);
        $this->workflow()->void($west, $first, 'Entered twice');
        $this->actingAs($ash);
        $this->assertValidationFails('pv_no', fn () => $this->workflow()->createDraft($ash, $this->pvData(['pv_no' => 'PV 0042'])));
        $this->assertSame(1, Pv::query()->count());
    }

    public function test_editing_a_voucher_cannot_take_another_vouchers_number(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->draft($fin, ['pv_no' => 'PV-A']);
        $second = $this->draft($fin, ['pv_no' => 'PV-B']);

        $this->assertValidationFails('pv_no', fn () => $this->workflow()->update($fin, $second, $this->pvData(['pv_no' => 'pv-a']), $second->lock_version));
        // Keeping its own number (even re-cased) is fine.
        $this->assertSame('PV-b', $this->workflow()->update($fin, $second, $this->pvData(['pv_no' => 'PV-b']), $second->lock_version)->pv_no);
    }

    public function test_events_are_immutable(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $event = $this->sent($fin)->events()->firstOrFail();

        try {
            $event->update(['comment' => 'Edited']);
            $this->fail('An event must not be updated.');
        } catch (\LogicException) {
            $this->assertNull($event->fresh()->comment);
        }

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_the_fields_are_validated(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $w = $this->workflow();

        $this->assertValidationFails('pv_date', fn () => $w->createDraft($fin, $this->pvData(['pv_date' => '2026-11-08', 'pv_no' => 'N1'])));
        $this->assertValidationFails('amount', fn () => $w->createDraft($fin, $this->pvData(['amount' => '0', 'pv_no' => 'N2'])));
        $this->assertValidationFails('amount', fn () => $w->createDraft($fin, $this->pvData(['amount' => '12.345', 'pv_no' => 'N3'])));
        $this->assertValidationFails('amount', fn () => $w->createDraft($fin, $this->pvData(['amount' => '-5', 'pv_no' => 'N4'])));
        $this->assertValidationFails('amount', fn () => $w->createDraft($fin, $this->pvData(['amount' => '50000001', 'pv_no' => 'N5'])));
        $this->assertValidationFails('details', fn () => $w->createDraft($fin, $this->pvData(['details' => ' ', 'pv_no' => 'N6'])));
        $this->assertValidationFails('pv_no', fn () => $w->createDraft($fin, $this->pvData(['pv_no' => ' '])));

        // The type must belong to the chosen category.
        $stationery = FinPvType::query()->where('name', 'Stationery')->firstOrFail();
        $this->assertValidationFails('type_id', fn () => $w->createDraft($fin, $this->pvData(['type_id' => $stationery->id, 'pv_no' => 'N7'])));

        $this->assertSame(125050, $w->createDraft($fin, $this->pvData(['amount' => '1,250.50', 'pv_no' => 'OK']))->amount);
        $this->assertSame(0, Pv::query()->where('pv_no', '!=', 'OK')->count());
    }

    public function test_employee_and_retiree_payees_are_checked_against_the_picker_and_frozen_once_saved(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $current = $this->employee('CUR1', $this->sowutuom);
        $retired = $this->employee('RET1', $this->sowutuom);
        $retired->update(['is_active' => false, 'deactivation_reason' => 'retired']);
        $elsewhere = $this->employee('ELS1', $this->kumasi);

        $this->actingAs($fin);
        $w = $this->workflow();

        $this->assertValidationFails('payee_employee_id', fn () => $w->createDraft($fin, $this->pvData(['pv_no' => 'P1', 'payee_kind' => 'employee', 'payee_employee_id' => $retired->id])));
        $this->assertValidationFails('payee_employee_id', fn () => $w->createDraft($fin, $this->pvData(['pv_no' => 'P2', 'payee_kind' => 'retiree', 'payee_employee_id' => $current->id])));
        $this->assertValidationFails('payee_employee_id', fn () => $w->createDraft($fin, $this->pvData(['pv_no' => 'P3', 'payee_kind' => 'employee', 'payee_employee_id' => $elsewhere->id])));

        $pv = $w->createDraft($fin, $this->pvData(['pv_no' => 'P4', 'payee_kind' => 'employee', 'payee_employee_id' => $current->id]));
        $this->assertSame($current->full_name, $pv->payee_name);
        $this->assertSame($current->id, $pv->payee_employee_id);

        // The person leaves service later: the saved voucher keeps its payee and can still be edited and sent.
        $current->update(['is_active' => false, 'deactivation_reason' => 'resigned']);
        $pv = $w->update($fin, $pv, $this->pvData(['pv_no' => 'P4', 'payee_kind' => 'employee', 'payee_employee_id' => $current->id, 'amount' => '10.00']), $pv->lock_version);
        $this->assertSame($current->id, $pv->payee_employee_id);

        $retiree = $w->createDraft($fin, $this->pvData(['pv_no' => 'P5', 'payee_kind' => 'retiree', 'payee_employee_id' => $retired->id]));
        $this->assertSame(Pv::PAYEE_RETIREE, $retiree->payee_kind);
    }

    public function test_voiding_works_from_draft_queried_and_with_audit_and_keeps_the_voucher(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $w = $this->workflow();

        $this->actingAs($fin);
        $this->assertValidationFails('reason', fn () => $w->void($fin, $this->draft($fin, ['pv_no' => 'V0']), ' '));

        $draft = $w->void($fin, $this->draft($fin, ['pv_no' => 'V1']), 'Wrong payee');
        $sent = $w->void($fin, $this->sent($fin, ['pv_no' => 'V2']), 'Cancelled');
        $queried = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'V3']);
        $this->actingAs($aud);
        $queried = $w->auditQuery($aud, $queried, 'duplicate', 'Seen before');
        $this->actingAs($fin);
        $queried = $w->void($fin, $queried, 'Duplicate');

        foreach ([$draft, $sent, $queried] as $pv) {
            $this->assertSame(Pv::VOIDED, $pv->status);
            $this->assertNotNull($pv->voided_at);
            $this->assertSame($fin->id, $pv->voided_by);
            $this->assertSame(FinPvEvent::VOIDED, $pv->events()->reorder()->latest('id')->first()->action);
        }

        $this->assertSame(3, Pv::query()->where('status', Pv::VOIDED)->count());
        $this->assertValidationFails('status', fn () => $w->submit($fin, $draft->fresh(), $draft->lock_version));
    }

    public function test_every_change_writes_an_audit_log_row(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $pv = $this->sentAndAssigned($fin, $aud);
        $this->actingAs($aud);
        $this->workflow()->auditAcknowledge($aud, $pv);

        $actions = AuditLog::query()->orderBy('id')->pluck('action')->all();
        $this->assertSame(['finance.pv_created', 'finance.pv_submitted', 'audit.pv_assigned', 'audit.pv_acknowledged'], $actions);
    }

    private function assertValidationFails(string $field, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function assertNotFound(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a 404.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
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
}
