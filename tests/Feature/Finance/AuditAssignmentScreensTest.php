<?php

namespace Tests\Feature\Finance;

use App\Livewire\Audit\Assignments;
use App\Livewire\Audit\BatchShow;
use App\Livewire\Audit\Batches;
use App\Livewire\Audit\Queue;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvEvent;
use App\Services\Finance\BatchWorkflowService;
use Livewire\Livewire;

/** Step H: Audit > Assignments (the manager), Audit > Batches and the batch page, My work (the officer). */
class AuditAssignmentScreensTest extends FinanceTestCase
{
    private function batches(): BatchWorkflowService
    {
        return app(BatchWorkflowService::class);
    }

    /** A sent batch of drafts with distinctive payees and amounts. @return array{0: \App\Models\FinPvBatch, 1: list<Pv>} */
    private function sentBatch($fin, string $prefix, int $count = 3, ?string $title = null): array
    {
        $vouchers = array_map(fn (int $i) => $this->draft($fin, ['pv_no' => "{$prefix}-{$i}", 'amount' => (string) (100 * $i).'.00']), range(1, $count));
        $batch = $this->batches()->send($fin, $this->batches()->create($fin, array_map(fn (Pv $pv) => $pv->id, $vouchers), $title));

        return [$batch, $vouchers];
    }

    // ------------------------------------------------------------------ Assignments

    public function test_the_manager_sees_a_minimal_queue_and_nobody_else_reaches_it(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $payee = $this->employee('PAYEE1', $this->sowutuom);
        $payee->update(['full_name' => 'Zebediah Secretpayee']);
        $this->draft($fin, ['pv_no' => 'DRAFT-1']);
        $single = $this->sent($fin, ['pv_no' => 'SINGLE-1', 'payee_kind' => 'employee', 'payee_employee_id' => $payee->id, 'details' => 'SECRET-DETAILS']);
        [$batch] = $this->sentBatch($fin, 'BA', 2, 'October claims');
        $this->sent($ash, ['pv_no' => 'KUMASI-1']);

        Livewire::actingAs($manager)->test(Assignments::class)
            ->assertSee('SINGLE-1')->assertSee($batch->batch_no)->assertSee('October claims')->assertSee('Awaiting assignment')
            ->assertDontSee('DRAFT-1')->assertDontSee('KUMASI-1')
            ->assertDontSee('Zebediah')->assertDontSee('SECRET-DETAILS');

        $this->actingAs($manager)->get(route('audit.assignments'))->assertOk()->assertDontSee('Zebediah');
        $this->actingAs($aud)->get(route('audit.assignments'))->assertForbidden();
        $this->actingAs($fin)->get(route('audit.assignments'))->assertRedirect();   // no Audit module access
        Livewire::actingAs($aud)->test(Assignments::class)->assertForbidden();
        Livewire::actingAs($this->auditManager('AM2', $this->kumasi))->test(Assignments::class)->assertSee('KUMASI-1')->assertDontSee('SINGLE-1')->assertDontSee($batch->batch_no);

        // The worklist hides what already has an officer, "everything" shows it with who gave it to whom.
        $this->workflow()->assign($manager, $single, $aud->id);
        Livewire::actingAs($manager)->test(Assignments::class)->assertDontSee('SINGLE-1')
            ->set('show', 'all')->assertSee('SINGLE-1')->assertSee($aud->full_name)->assertSee('by '.$manager->full_name);
    }

    public function test_the_picker_offers_only_eligible_officers_with_their_workload_and_assigning_works_for_vouchers_and_batches(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $both = $this->both('B1', $this->sowutuom);
        $busy = $this->audit('AU1', $this->sowutuom);
        $free = $this->audit('AU2', $this->odorkor);
        $this->audit('AU9', $this->kumasi);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $this->sentAndAssigned($fin, $busy, ['pv_no' => 'OPEN-1']);
        $loose = $this->sent($fin, ['pv_no' => 'LOOSE-1']);
        $byBoth = $this->sent($both, ['pv_no' => 'BY-BOTH']);
        [$batch, $members] = $this->sentBatch($fin, 'GR');

        $component = Livewire::actingAs($manager)->test(Assignments::class)
            ->set('selectedPvs', [(string) $loose->id])->set('selectedBatches', [(string) $batch->id]);

        $options = collect($component->viewData('officerOptions'));
        $this->assertEqualsCanonicalizing([$busy->id, $free->id, $both->id], $options->pluck('value')->all());
        $this->assertSame(1, $options->firstWhere('value', $busy->id)['open']);
        $this->assertSame(4, $component->viewData('pickedCount'), 'the loose voucher and the three of the batch');

        // Someone who sent one of the picked vouchers is out.
        $component->set('selectedPvs', [(string) $loose->id, (string) $byBoth->id]);
        $this->assertNotContains($both->id, collect($component->viewData('officerOptions'))->pluck('value')->all());
        $component->set('officerId', (string) $both->id)->call('assign')->assertHasErrors(['assign.officer']);
        $this->assertNull($byBoth->fresh()->assigned_to_user_id);

        // The assignment itself: one transaction for the voucher and the batch.
        $component->set('selectedPvs', [(string) $loose->id])->set('officerId', (string) $free->id)->set('note', 'Start here')->call('assign')->assertHasNoErrors();
        $this->assertSame($free->id, $loose->fresh()->assigned_to_user_id);
        $this->assertSame([$free->id], Pv::query()->where('batch_id', $batch->id)->pluck('assigned_to_user_id')->unique()->all());
        $this->assertSame(4, FinPvEvent::query()->where('action', FinPvEvent::ASSIGNED)->where('to_user_id', $free->id)->count() - 0);
        $component->assertSet('selectedPvs', [])->assertSet('selectedBatches', [])->assertSet('officerId', '');

        // Reassign from the same screen.
        Livewire::actingAs($manager)->test(Assignments::class)->set('show', 'all')
            ->set('selectedPvs', [(string) $loose->id])->set('officerId', (string) $busy->id)->call('assign')->assertHasNoErrors();
        $this->assertSame($busy->id, $loose->fresh()->assigned_to_user_id);
        $this->assertSame(1, FinPvEvent::query()->where('action', FinPvEvent::REASSIGNED)->count());
    }

    public function test_a_tampered_selection_and_a_voucher_that_is_not_with_audit_are_never_assigned(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $draft = $this->draft($fin, ['pv_no' => 'D-1']);
        $foreign = $this->sent($ash, ['pv_no' => 'K-1']);
        $queried = $this->workflow()->auditQuery($aud, $this->sentAndAssigned($fin, $aud, ['pv_no' => 'Q-1']), 'other', 'x');

        $component = Livewire::actingAs($manager)->test(Assignments::class)->set('show', 'all')
            ->set('selectedPvs', [(string) $draft->id, (string) $foreign->id, (string) $queried->id, '99999'])
            ->set('officerId', (string) $aud->id)->call('assign')->assertHasErrors(['assign.vouchers']);

        $this->assertNull($draft->fresh()->assigned_to_user_id);
        $this->assertNull($foreign->fresh()->assigned_to_user_id);
        $this->assertSame(0, FinPvEvent::query()->where('action', FinPvEvent::REASSIGNED)->count());
        $this->assertSame(0, $component->viewData('pickedCount'));
    }

    public function test_super_admin_picks_the_location_before_there_is_a_queue(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->sent($fin, ['pv_no' => 'WEST-1']);
        $super = $this->superAdmin();

        Livewire::actingAs($super)->test(Assignments::class)->assertSee('Choose a location above')->assertDontSee('WEST-1')
            ->set('locationId', (string) $this->sowutuom->id)->assertSee('WEST-1');
    }

    // ------------------------------------------------------------------ batches

    public function test_an_officer_reads_batches_by_their_own_vouchers_and_the_manager_by_all_of_them(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $one = $this->audit('AU1', $this->sowutuom);
        $two = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        [$batch, $m] = $this->sentBatch($fin, 'SP', 3, 'Split batch');                 // 100 + 200 + 300
        $this->batches()->assignVouchers($manager, [$m[0]->id], $one->id);
        $this->batches()->assignVouchers($manager, [$m[1]->id, $m[2]->id], $two->id);
        [$other] = $this->sentBatch($fin, 'OT', 1, 'Not yours');

        // The manager counts every voucher; the first officer only their own (100), the second theirs (500).
        $managerView = Livewire::actingAs($manager)->test(Batches::class)->assertSee($batch->batch_no)->assertSee($other->batch_no)->assertSee('600.00');
        $oneView = Livewire::actingAs($one)->test(Batches::class)->assertSee($batch->batch_no)->assertSee('Your vouchers')->assertDontSee($other->batch_no)->assertDontSee('600.00')->assertDontSee($two->full_name);
        $this->assertSame(1, $oneView->viewData('summaries')[$batch->id]['count']);
        $this->assertSame(2, Livewire::actingAs($two)->test(Batches::class)->viewData('summaries')[$batch->id]['count']);
        $this->assertSame(3, $managerView->viewData('summaries')[$batch->id]['count']);
        Livewire::actingAs($this->audit('AU3', $this->sowutuom))->test(Batches::class)->assertDontSee($batch->batch_no);
        Livewire::actingAs($this->auditManager('AM2', $this->kumasi))->test(Batches::class)->assertDontSee($batch->batch_no);
    }

    public function test_the_manager_sees_minimal_rows_in_a_batch_until_an_officer_acts_and_assigns_it_whole_or_split(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $one = $this->audit('AU1', $this->sowutuom);
        $two = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $payee = $this->employee('PAYEE2', $this->sowutuom);
        $payee->update(['full_name' => 'Zebediah Secretpayee']);
        $a = $this->draft($fin, ['pv_no' => 'M-1', 'payee_kind' => 'employee', 'payee_employee_id' => $payee->id]);
        $b = $this->draft($fin, ['pv_no' => 'M-2', 'payee_kind' => 'employee', 'payee_employee_id' => $payee->id]);
        $batch = $this->batches()->send($fin, $this->batches()->create($fin, [$a->id, $b->id]));

        $component = Livewire::actingAs($manager)->test(BatchShow::class, ['batch' => $batch->id])
            ->assertSee('M-1')->assertSee('M-2')->assertDontSee('Zebediah')->assertSee('Opens after an officer has acted on it')->assertSee('Awaiting assignment');

        // Split: one voucher to each officer; the batch is then in progress.
        $component->set('selected', [(string) $a->id])->call('assignTicked')->assertHasErrors(['assign.officer']);
        $component->set('selected', [(string) $a->id])->set('officerId', (string) $one->id)->call('assignTicked')->assertHasNoErrors();
        $component->set('selected', [(string) $b->id])->set('officerId', (string) $two->id)->call('assignTicked')->assertHasNoErrors();
        $this->assertSame([$one->id, $two->id], [$a->fresh()->assigned_to_user_id, $b->fresh()->assigned_to_user_id]);
        $this->assertSame('in_progress', $component->viewData('state')['key']);

        // Still nothing opens for the manager; once an officer has acted, that voucher does (and shows who did).
        $this->assertSame([], $component->viewData('openable')->keys()->all());
        $this->actingAs($one);
        $this->workflow()->auditQuery($one, $a, 'other', 'Check');
        $this->actingAs($manager);
        $component->call('$refresh')->assertSee('Zebediah')->assertSee(route('audit.pvs.show', $a->id), false)->assertDontSee(route('audit.pvs.show', $b->id), false);

        // The whole batch to one officer: only what is still With Audit moves.
        $component->set('officerId', (string) $one->id)->call('assignWhole')->assertHasNoErrors();
        $this->assertSame($one->id, $a->fresh()->assigned_to_user_id, 'a queried voucher is not part of an assignment');
        $this->assertSame($one->id, $b->fresh()->assigned_to_user_id, 'the one still With Audit moved');
        $component->assertSee('History')->assertSee('Reassigned to another Audit officer');

        $this->actingAs($manager)->get(route('audit.batches.show', $batch->id))->assertOk()->assertSee($batch->batch_no);
        $this->actingAs($this->auditManager('AM2', $this->kumasi))->get(route('audit.batches.show', $batch->id))->assertNotFound();
    }

    public function test_an_officer_sees_only_their_vouchers_of_a_batch_and_acknowledges_all_remaining(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $one = $this->audit('AU1', $this->sowutuom);
        $two = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        [$batch, $m] = $this->sentBatch($fin, 'RM', 4);
        $this->batches()->assignVouchers($manager, [$m[0]->id, $m[1]->id, $m[2]->id], $one->id);
        $this->batches()->assignVouchers($manager, [$m[3]->id], $two->id);

        $component = Livewire::actingAs($one)->test(BatchShow::class, ['batch' => $batch->id])
            ->assertSee('RM-1')->assertSee('RM-3')->assertDontSee('RM-4')
            ->assertSee('Acknowledge all remaining (3)')
            ->assertDontSee('Assign the whole batch')->assertDontSee('History');

        $component->call('acknowledgeRemaining')->assertSee('3 vouchers acknowledged')->assertSee('A query is always one voucher at a time')
            ->assertSee('1 more voucher in this batch is with other officers')->assertDontSee('Acknowledge all remaining (');

        foreach ([$m[0], $m[1], $m[2]] as $pv) {
            $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $pv->fresh()->status);
        }

        $this->assertSame(Pv::WITH_AUDIT, $m[3]->fresh()->status);
        Livewire::actingAs($two)->test(BatchShow::class, ['batch' => $batch->id])->assertDontSee('RM-1')->assertSee('RM-4');
        Livewire::actingAs($this->audit('AU3', $this->sowutuom))->test(BatchShow::class, ['batch' => $batch->id])->assertNotFound();
        Livewire::actingAs($manager)->test(BatchShow::class, ['batch' => $batch->id])->call('acknowledgeRemaining')->assertForbidden();
    }

    // ------------------------------------------------------------------ My work, navigation

    public function test_my_work_lists_the_officers_vouchers_with_their_batch_and_the_manager_lands_on_assignments(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        [$batch, $m] = $this->sentBatch($fin, 'MW', 2);
        $this->batches()->assignBatch($manager, $batch, $aud->id);
        $this->sent($fin, ['pv_no' => 'NOT-MINE']);

        Livewire::actingAs($aud)->test(Queue::class)->assertSee('My work')->assertSee('MW-1')->assertSee('MW-2')->assertSee($batch->batch_no)
            ->assertSee(route('audit.batches.show', $batch->id), false)->assertDontSee('NOT-MINE');
        Livewire::actingAs($manager)->test(Queue::class)->assertSee('Reviewed vouchers')->assertDontSee('MW-1');

        $navigation = app(\App\Support\ErpNavigation::class);
        $tab = fn ($user) => collect($navigation->build($user, 'audit')['modules'])->firstWhere('slug', 'audit')['route'];
        $this->assertSame(route('audit.assignments'), $tab($manager));
        $this->assertSame(route('audit.home'), $tab($aud));

        $this->actingAs($manager);
        $this->assertSame(route('audit.assignments'), collect(dashboardModules())->firstWhere('slug', 'audit')['route']);
    }
}
