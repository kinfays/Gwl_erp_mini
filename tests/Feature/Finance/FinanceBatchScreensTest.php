<?php

namespace Tests\Feature\Finance;

use App\Livewire\Audit\VoucherShow as AuditVoucherShow;
use App\Livewire\Finance\BatchShow;
use App\Livewire\Finance\Batches;
use App\Livewire\Finance\VoucherShow;
use App\Livewire\Finance\Vouchers;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvBatch;
use App\Services\Finance\BatchWorkflowService;
use App\Support\ErpNavigation;
use Livewire\Livewire;

/** Step G: the Finance screens for batches (tick and create, list, detail) and the batch and officer on a voucher. */
class FinanceBatchScreensTest extends FinanceTestCase
{
    private function batches(): BatchWorkflowService
    {
        return app(BatchWorkflowService::class);
    }

    /** @return list<Pv> */
    private function drafts($user, int $count, string $prefix = 'S'): array
    {
        return array_map(fn (int $i) => $this->draft($user, ['pv_no' => "{$prefix}-{$i}", 'amount' => (string) (100 * $i).'.00']), range(1, $count));
    }

    // ------------------------------------------------------------------ the voucher list

    public function test_only_drafts_in_no_batch_can_be_ticked_and_the_others_say_why(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        [$free, $inBatch] = $this->drafts($fin, 2, 'L');
        $batch = $this->batches()->create($fin, [$inBatch->id]);
        $sent = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'L-SENT']);
        $voided = $this->workflow()->void($fin, $this->draft($fin, ['pv_no' => 'L-VOID']), 'Mistake');

        $html = Livewire::actingAs($fin)->test(Vouchers::class)->html();

        $this->assertDoesNotMatchRegularExpression('/id="pick-'.$free->id.'"[^>]*disabled/', $html, 'a free draft can be ticked');
        $this->assertMatchesRegularExpression('/id="pick-'.$inBatch->id.'"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/id="pick-'.$sent->id.'"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/id="pick-'.$voided->id.'"[^>]*disabled/', $html);

        Livewire::actingAs($fin)->test(Vouchers::class)
            ->assertSee('is already in batch '.$batch->batch_no)
            ->assertSee('is not a draft (it has been sent to Audit)')
            ->assertSee('is voided')
            ->assertSee('Cannot be batched');
    }

    public function test_select_page_ticks_every_eligible_voucher_and_create_batch_makes_the_batch(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        [$a, $b, $c] = $this->drafts($fin, 3, 'P');
        $this->sentAndAssigned($fin, $aud, ['pv_no' => 'P-SENT']);

        $component = Livewire::actingAs($fin)->test(Vouchers::class)
            ->call('selectPage')
            ->assertSet('selected', [(string) $c->id, (string) $b->id, (string) $a->id])
            ->assertSee('3 vouchers selected');

        $component->call('selectPage')->assertSet('selected', [], 'a second click unticks them');
        $component->call('selectPage');

        $component->call('openBatch')->assertSet('showBatch', true)->assertSee('GH¢ 600.00')
            ->set('batchTitle', 'Staff claims')->set('batchRemarks', 'For October')
            ->call('createBatch');

        $batch = FinPvBatch::query()->firstOrFail();
        $component->assertRedirect(route('finance.batches.show', $batch->id));
        $this->assertSame('Staff claims', $batch->title);
        $this->assertSame(3, Pv::query()->where('batch_id', $batch->id)->count());
    }

    public function test_a_tampered_selection_drops_out_and_nothing_is_created_for_it(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        [$mine] = $this->drafts($fin, 1, 'T');
        $theirs = $this->draft($ash, ['pv_no' => 'T-ASH']);
        $sent = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'T-SENT']);

        // Another location's voucher and a sent one are simply not part of the request.
        Livewire::actingAs($fin)->test(Vouchers::class)
            ->set('selected', [(string) $theirs->id, (string) $sent->id, '999999'])
            ->call('openBatch')->assertSet('showBatch', false)->call('createBatch');
        $this->assertSame(0, FinPvBatch::query()->count());

        Livewire::actingAs($fin)->test(Vouchers::class)
            ->set('selected', [(string) $mine->id, (string) $theirs->id])
            ->call('openBatch')->assertSet('showBatch', true)->assertSee('1 voucher')
            ->call('createBatch');
        $this->assertSame([$mine->id], Pv::query()->whereNotNull('batch_id')->pluck('id')->all());
        $this->assertNull($theirs->fresh()->batch_id);
    }

    public function test_the_finance_manager_sees_no_tick_boxes_and_cannot_create_a_batch(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        [$a] = $this->drafts($fin, 1, 'M');

        Livewire::actingAs($manager)->test(Vouchers::class)->assertSee('M-1')->assertDontSee('Select page')->assertDontSee('Create batch from selected');
        Livewire::actingAs($manager)->test(Vouchers::class)->set('selected', [(string) $a->id])->call('createBatch')->assertForbidden();
        Livewire::actingAs($manager)->test(Vouchers::class)->call('selectPage')->assertForbidden();
        $this->assertSame(0, FinPvBatch::query()->count());
    }

    // ------------------------------------------------------------------ Finance > Batches

    public function test_the_batches_list_shows_count_total_state_officers_and_date_sent_for_the_location_only(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $audTwo = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $financeManager = $this->financeManager('FM1', $this->sowutuom);
        $aud->update(['full_name' => 'Ama Officer']);
        $audTwo->update(['full_name' => 'Kofi Officer']);

        [$a, $b] = $this->drafts($fin, 2, 'B');
        $sent = $this->batches()->send($fin, $this->batches()->create($fin, [$a->id, $b->id], 'October claims'));
        $this->batches()->assignVouchers($manager, [$a->id], $aud->id);
        $this->batches()->assignVouchers($manager, [$b->id], $audTwo->id);
        [$c] = $this->drafts($fin, 1, 'C');
        $draft = $this->batches()->create($fin, [$c->id], 'Still drafting');
        $other = $this->batches()->create($ash, [$this->draft($ash, ['pv_no' => 'ASH-1'])->id]);

        foreach ([$fin, $financeManager] as $reader) {
            Livewire::actingAs($reader)->test(Batches::class)
                ->assertSee($sent->batch_no)->assertSee('October claims')
                ->assertSee('300.00')                       // 100 + 200
                ->assertSee('In progress (0 of 2 done)')
                ->assertSee('Ama Officer')->assertSee('Kofi Officer')
                ->assertSee($draft->batch_no)->assertSee('Draft')
                ->assertDontSee($other->batch_no);
        }

        Livewire::actingAs($fin)->test(Batches::class)->set('status', 'draft')->assertSee('Still drafting')->assertDontSee('October claims')
            ->set('status', '')->set('search', 'October')->assertSee('October claims')->assertDontSee('Still drafting');
        Livewire::actingAs($ash)->test(Batches::class)->assertSee($other->batch_no)->assertDontSee($sent->batch_no);
        Livewire::actingAs($this->person('E1', $this->sowutuom))->test(Batches::class)->assertForbidden();
    }

    // ------------------------------------------------------------------ the batch detail

    public function test_a_draft_batch_is_built_on_its_screen_and_sent_from_it(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a, $b, $c] = $this->drafts($fin, 3, 'D');
        $batch = $this->batches()->create($fin, [$a->id, $b->id], 'First');

        $component = Livewire::actingAs($fin)->test(BatchShow::class, ['batch' => $batch->id])
            ->assertSee('Batch '.$batch->batch_no)->assertSee('D-1')->assertSee('D-2')->assertDontSee('D-3')
            ->assertSee('Send to Audit')->assertSee('Cancel batch')->assertSee('Not sent to Audit yet');

        // Add: the picker offers free drafts of this location only.
        $component->call('toggleAdd')->assertSee('D-3')->set('addIds', [(string) $c->id])->call('addSelected')->assertHasNoErrors();
        $this->assertSame($batch->id, $c->fresh()->batch_id);
        $component->assertSet('version', 2);

        // Take one out.
        $component->set('removeIds', [(string) $a->id])->call('removeSelected');
        $this->assertNull($a->fresh()->batch_id);

        // Edit the title.
        $component->set('title', 'Renamed')->call('saveDetails');
        $this->assertSame('Renamed', $batch->fresh()->title);

        // Send: all or nothing.
        $component->call('openSend')->assertSee('Send this batch to Audit')->call('confirmSend');
        $this->assertSame(Pv::WITH_AUDIT, $b->fresh()->status);
        $this->assertSame(Pv::WITH_AUDIT, $c->fresh()->status);
        $this->assertSame(FinPvBatch::SENT, $batch->fresh()->status);

        $component->assertSee('Awaiting assignment')->assertDontSee('Send to Audit')->assertDontSee('Cancel batch')->assertDontSee('Add vouchers');
    }

    public function test_a_send_that_fails_lists_each_voucher_and_sends_nothing(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a, $b] = $this->drafts($fin, 2, 'X');
        $batch = $this->batches()->create($fin, [$a->id, $b->id]);
        \Illuminate\Support\Facades\DB::table('fin_pvs')->where('id', $b->id)->update(['details' => '']);

        Livewire::actingAs($fin)->test(BatchShow::class, ['batch' => $batch->id])
            ->call('openSend')->call('confirmSend')
            ->assertSee('The batch was not sent')->assertSee('PV X-2: The details are missing.')->assertDontSee('PV X-1:')
            ->assertSet('showSend', true);

        $this->assertSame(Pv::DRAFT, $a->fresh()->status);
        $this->assertSame(FinPvBatch::DRAFT, $batch->fresh()->status);
    }

    public function test_cancelling_needs_a_reason_and_releases_the_vouchers(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a] = $this->drafts($fin, 1, 'K');
        $batch = $this->batches()->create($fin, [$a->id]);

        Livewire::actingAs($fin)->test(BatchShow::class, ['batch' => $batch->id])
            ->call('openCancel')->set('cancelReason', ' ')->call('confirmCancel')->assertHasErrors(['cancel.reason'])
            ->set('cancelReason', 'Wrong month')->call('confirmCancel')->assertHasNoErrors()
            ->assertSee('This batch was cancelled')->assertSee('Wrong month')->assertDontSee('Send to Audit');

        $this->assertNull($a->fresh()->batch_id);
    }

    public function test_a_stale_screen_is_a_conflict_not_an_overwrite(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        [$a, $b] = $this->drafts($fin, 2, 'V');
        $batch = $this->batches()->create($fin, [$a->id]);

        $component = Livewire::actingAs($fin)->test(BatchShow::class, ['batch' => $batch->id]);
        $this->batches()->addVouchers($this->finance('F2', $this->sowutuom), $batch, [$b->id]);   // someone else changes it

        $component->call('openSend')->call('confirmSend')->assertHasErrors(['conflict']);
        $this->assertSame(FinPvBatch::DRAFT, $batch->fresh()->status);
    }

    public function test_finance_acknowledges_the_ticked_vouchers_of_a_sent_batch_in_one_go(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        [$a, $b, $c] = $this->drafts($fin, 3, 'A');
        $batch = $this->batches()->send($fin, $this->batches()->create($fin, [$a->id, $b->id, $c->id]));
        $this->batches()->assignBatch($manager, $batch, $aud->id);
        $this->actingAs($aud);
        $this->workflow()->auditAcknowledge($aud, $a);
        $this->workflow()->auditAcknowledge($aud, $b);

        Livewire::actingAs($fin)->test(BatchShow::class, ['batch' => $batch->id])
            ->assertSee('Acknowledge all (2)')
            ->call('acknowledgeAll')
            ->assertSee('2 vouchers acknowledged')
            ->assertSee('In progress (2 of 3 done)')
            ->assertDontSee('Acknowledge all (');

        $this->assertSame([Pv::COMPLETED, Pv::COMPLETED, Pv::WITH_AUDIT], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
    }

    public function test_the_finance_manager_only_reads_a_batch_and_another_location_gets_a_404(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        [$a] = $this->drafts($fin, 1, 'R');
        $batch = $this->batches()->create($fin, [$a->id]);

        $this->actingAs($manager)->get(route('finance.batches.show', $batch->id))->assertOk()->assertSee($batch->batch_no)
            ->assertDontSee('Send to Audit')->assertDontSee('Cancel batch')->assertDontSee('Add vouchers')->assertDontSee('Take selected out');
        Livewire::actingAs($manager)->test(BatchShow::class, ['batch' => $batch->id])->call('openSend')->assertForbidden();
        Livewire::actingAs($manager)->test(BatchShow::class, ['batch' => $batch->id])->call('acknowledgeAll')->assertForbidden();

        $this->actingAs($ash)->get(route('finance.batches.show', $batch->id))->assertNotFound();
        $this->actingAs($ash)->get(route('finance.batches'))->assertOk()->assertDontSee($batch->batch_no);
        $this->actingAs($this->audit('AU1', $this->sowutuom))->get(route('finance.batches'))->assertRedirect();   // no Finance module access
    }

    // ------------------------------------------------------------------ a voucher shows its batch and officer

    public function test_a_voucher_shows_its_batch_as_a_link_and_who_has_it(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $aud->update(['full_name' => 'Ama Officer']);
        [$a, $b] = $this->drafts($fin, 2, 'W');
        $batch = $this->batches()->send($fin, $this->batches()->create($fin, [$a->id, $b->id], 'Weekly'));
        $loose = $this->sent($fin, ['pv_no' => 'W-LOOSE']);

        Livewire::actingAs($fin)->test(VoucherShow::class, ['pv' => $a->id])
            ->assertSee($batch->batch_no)->assertSee('Weekly')->assertSee(route('finance.batches.show', $batch->id), false)
            ->assertSee('Awaiting assignment');
        Livewire::actingAs($fin)->test(VoucherShow::class, ['pv' => $loose->id])->assertDontSee('Batch')->assertSee('Awaiting assignment');

        $this->assignTo($a, $aud, 'First this one');
        Livewire::actingAs($fin)->test(VoucherShow::class, ['pv' => $a->id])
            ->assertSee('Ama Officer')->assertSee('assigned by')->assertDontSee('Awaiting assignment')
            ->assertSee('Assigned to an Audit officer')->assertSee('to Ama Officer')->assertSee('First this one');

        // The officer sees the same, with the batch as plain text (Audit has no batch screen of its own yet).
        Livewire::actingAs($aud)->test(AuditVoucherShow::class, ['pv' => $a->id])->assertSee($batch->batch_no)->assertSee('Ama Officer')
            ->assertDontSee(route('finance.batches.show', $batch->id), false);

        // A draft has no officer row at all.
        $draft = $this->draft($fin, ['pv_no' => 'W-DRAFT']);
        Livewire::actingAs($fin)->test(VoucherShow::class, ['pv' => $draft->id])->assertDontSee('Audit officer');
    }

    public function test_the_menu_has_the_batches_entry_for_finance_readers_only(): void
    {
        $navigation = app(ErpNavigation::class);
        $routes = fn ($user) => collect($navigation->build($user, 'finance')['sidebar'])->pluck('route')->filter()->all();

        $this->assertContains('finance.batches', $routes($this->finance('F1', $this->sowutuom)));
        $this->assertContains('finance.batches', $routes($this->financeManager('FM1', $this->sowutuom)));
        $this->assertNotContains('finance.batches', $routes($this->audit('AU1', $this->sowutuom)));
    }
}
