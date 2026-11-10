<?php

namespace Tests\Feature\Finance;

use App\Livewire\Audit\Queue;
use App\Livewire\Finance\BatchShow;
use App\Livewire\Finance\VoucherShow;
use App\Livewire\Finance\Vouchers;
use App\Models\AuditLog;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvBatch;
use App\Services\Finance\BatchWorkflowService;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvPrintService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Step I: the PDF of a voucher, a batch and a selection; what each team may print; the print count and the audit row. */
class PvPrintTest extends FinanceTestCase
{
    private function prints(): PvPrintService
    {
        return app(PvPrintService::class);
    }

    private function batches(): BatchWorkflowService
    {
        return app(BatchWorkflowService::class);
    }

    private function assertPdf($response): void
    {
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
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

    /** @return array{0: FinPvBatch, 1: list<Pv>} */
    private function sentBatch($fin, string $prefix, int $count = 3, ?string $title = null): array
    {
        $vouchers = array_map(fn (int $i) => $this->draft($fin, ['pv_no' => "{$prefix}-{$i}", 'amount' => (string) (100 * $i).'.50']), range(1, $count));

        return [$this->batches()->send($fin, $this->batches()->create($fin, array_map(fn (Pv $pv) => $pv->id, $vouchers), $title)), $vouchers];
    }

    // ------------------------------------------------------------------ the words

    public function test_the_amount_in_words_builds_on_the_leave_letters_number_to_words(): void
    {
        $words = fn (int $pesewas) => $this->prints()->amountInWords($pesewas);

        $this->assertSame('Fifty pesewas only', $words(50));
        $this->assertSame('One Ghana cedi only', $words(100));
        $this->assertSame('One thousand two hundred and fifty Ghana cedis and fifty pesewas only', $words(125050));
        $this->assertSame('Twelve million three hundred and forty-five thousand six hundred and seventy-eight Ghana cedis and ninety pesewas only', $words(1234567890));
        $this->assertSame('One thousand and five Ghana cedis and one pesewa only', $words(100501));
        $this->assertSame('Fifty million Ghana cedis only', $words(5000000000));
    }

    // ------------------------------------------------------------------ one voucher

    public function test_a_voucher_prints_with_its_copy_label_wording_timeline_and_footer(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $aud->update(['full_name' => 'Ama Officer']);
        [$batch, $m] = $this->sentBatch($fin, 'W', 1, 'October claims');
        $this->assignTo($m[0], $aud, 'Start here');
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $m[0], 'amount_mismatch', 'The receipt says less');

        $doc = $this->prints()->vouchers($fin, FinanceScope::AS_FINANCE, [$m[0]->id]);
        $page = $doc['pages'][0];

        $this->assertSame('Finance copy', $doc['copy']);
        $this->assertSame('Finance copy', $page['copy']);
        $this->assertNull($page['mark']);
        $rows = collect($page['rows'])->mapWithKeys(fn ($row) => [$row[0] => $row[1]]);
        $this->assertSame('W-1', $rows['Voucher no.']);
        $this->assertSame($batch->batch_no.' (October claims)', $rows['Batch']);
        $this->assertSame('Ama Officer', $rows['Audit officer']);
        $this->assertSame('Queried', $rows['Status']);
        $this->assertStringContainsString('(District)', $rows['Payee']);
        $this->assertSame('100.50', $page['amount']);
        $this->assertSame('One hundred Ghana cedis and fifty pesewas only', $page['words']);
        $this->assertSame('Medical claim for the third quarter', $page['details']);
        $this->assertSame('Finance own note', $page['remarks']);

        $lines = collect($page['timeline'][0]['lines']);
        $this->assertSame('Round 1', $page['timeline'][0]['round']);
        $this->assertSame(['Sent to Audit', 'Assigned to an Audit officer to Ama Officer', 'Queried by Audit'], $lines->pluck('what')->all());
        $this->assertSame('Reason: Amount does not match', $lines[2]['reason']);
        $this->assertSame('The receipt says less', $lines[2]['comment']);
        $this->assertSame('Start here', $lines[1]['comment']);

        $this->assertStringStartsWith('Printed by '.$fin->full_name.' on 07 Nov 2026 at 09:00', $doc['footer']['left']);
        $this->assertSame('Voucher printed 1 time', $doc['footer']['right']);

        // Audit's copy of the same voucher.
        $this->assertSame('Audit copy', $this->prints()->vouchers($aud, FinanceScope::AS_AUDIT, [$m[0]->id])['copy']);

        // And it really is a PDF; printing twice counts twice.
        $this->assertPdf($this->actingAs($fin)->get(route('finance.pvs.print', $m[0]->id)));
        $this->assertPdf($this->actingAs($fin)->get(route('finance.pvs.print', $m[0]->id)));
        $this->assertSame(2, $m[0]->fresh()->print_count);
        $this->assertSame('Voucher printed 3 times', $this->prints()->vouchers($fin, FinanceScope::AS_FINANCE, [$m[0]->id])['footer']['right'], 'counting the print being made');
    }

    public function test_a_draft_a_voided_and_every_other_state_print_and_a_draft_carries_the_mark(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $draft = $this->draft($fin, ['pv_no' => 'S-DRAFT']);
        $voided = $this->workflow()->void($fin, $this->draft($fin, ['pv_no' => 'S-VOID']), 'Mistake');
        $withAudit = $this->sent($fin, ['pv_no' => 'S-WA']);
        $queried = $this->workflow()->auditQuery($aud, $this->sentAndAssigned($fin, $aud, ['pv_no' => 'S-Q']), 'other', 'x');
        $ticked = $this->workflow()->auditAcknowledge($aud, $this->sentAndAssigned($fin, $aud, ['pv_no' => 'S-T']));
        $done = $this->workflow()->financeAcknowledge($fin, $this->workflow()->auditAcknowledge($aud, $this->sentAndAssigned($fin, $aud, ['pv_no' => 'S-C'])));

        $this->assertSame('DRAFT - not yet sent to Audit', $this->prints()->vouchers($fin, FinanceScope::AS_FINANCE, [$draft->id])['pages'][0]['mark']);
        $this->assertSame('VOIDED', $this->prints()->vouchers($fin, FinanceScope::AS_FINANCE, [$voided->id])['pages'][0]['mark']);
        $this->assertNull($this->prints()->vouchers($fin, FinanceScope::AS_FINANCE, [$done->id])['pages'][0]['mark']);

        foreach ([$draft, $voided, $withAudit, $queried, $ticked, $done] as $pv) {
            $this->assertPdf($this->actingAs($fin)->get(route('finance.pvs.print', $pv->id)));
        }
    }

    // ------------------------------------------------------------------ who may print what

    public function test_each_team_prints_only_what_it_can_see_and_everything_else_is_a_404(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $financeManager = $this->financeManager('FM1', $this->sowutuom);
        $one = $this->audit('AU1', $this->sowutuom);
        $two = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $draft = $this->draft($fin, ['pv_no' => 'P-DRAFT']);
        $mine = $this->sentAndAssigned($fin, $one, ['pv_no' => 'P-MINE']);
        $unassigned = $this->sent($fin, ['pv_no' => 'P-NOBODY']);
        $worked = $this->workflow()->auditQuery($one, $this->sentAndAssigned($fin, $one, ['pv_no' => 'P-WORKED']), 'other', 'x');
        $foreign = $this->sent($ash, ['pv_no' => 'P-ASH']);

        // Finance: its location, drafts included; the Finance manager reads the same; another location's is a 404.
        $this->assertPdf($this->actingAs($fin)->get(route('finance.pvs.print', $draft->id)));
        $this->assertPdf($this->actingAs($financeManager)->get(route('finance.pvs.print', $draft->id)));
        $this->actingAs($fin)->get(route('finance.pvs.print', $foreign->id))->assertNotFound();
        $this->actingAs($ash)->get(route('finance.pvs.print', $mine->id))->assertNotFound();

        // An officer: their own and what they worked on, never an unassigned or another officer's voucher, never a draft.
        $this->assertPdf($this->actingAs($one)->get(route('audit.pvs.print', $mine->id)));
        $this->actingAs($one)->get(route('audit.pvs.print', $unassigned->id))->assertNotFound();
        $this->actingAs($two)->get(route('audit.pvs.print', $mine->id))->assertNotFound();
        $this->actingAs($one)->get(route('audit.pvs.print', $draft->id))->assertNotFound();

        // The manager: only what an officer has worked on.
        $this->actingAs($manager)->get(route('audit.pvs.print', $mine->id))->assertNotFound();
        $this->actingAs($manager)->get(route('audit.pvs.print', $unassigned->id))->assertNotFound();
        $this->assertPdf($this->actingAs($manager)->get(route('audit.pvs.print', $worked->id)));
        $this->assertSame(1, $mine->fresh()->print_count, 'only the officer\'s own print counted; the manager\'s 404s counted nothing');

        // The doors: Audit has none into Finance and the other way round; an ordinary employee has neither.
        $this->actingAs($one)->get(route('finance.pvs.print', $mine->id))->assertRedirect();
        $this->actingAs($fin)->get(route('audit.pvs.print', $mine->id))->assertRedirect();
        $this->actingAs($this->person('E1', $this->sowutuom))->get(route('finance.pvs.print', $mine->id))->assertRedirect();

        // super_admin prints through either door.
        $this->assertPdf($this->actingAs($this->superAdmin())->get(route('audit.pvs.print', $unassigned->id)));
    }

    // ------------------------------------------------------------------ the record of a print

    public function test_a_print_counts_and_writes_one_audit_row_with_ids_and_counts_only(): void
    {
        $this->withoutVite();
        $payee = $this->employee('PAYEE1', $this->sowutuom);
        $payee->update(['full_name' => 'Zebediah Quarcoo-Payee']);
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $pv = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'REC-1', 'payee_kind' => 'employee', 'payee_employee_id' => $payee->id, 'amount' => '7777.77', 'details' => 'SECRET-DETAILS']);
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $pv, 'other', 'SECRET-COMMENT');
        AuditLog::query()->delete();

        $this->actingAs($fin)->get(route('finance.pvs.print', $pv->id))->assertOk();
        $this->actingAs($aud)->get(route('audit.pvs.print', $pv->id))->assertOk();

        $this->assertSame(2, $pv->fresh()->print_count);
        $this->assertSame(['finance.pv_printed', 'audit.pv_printed'], AuditLog::query()->orderBy('id')->pluck('action')->all());

        $json = json_encode(AuditLog::query()->get());
        foreach (['Zebediah', 'Quarcoo', '7777', 'SECRET', 'REC-1', 'Medical claim', 'Finance own note'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "the audit row must not contain {$needle}");
        }

        $this->assertStringContainsString('"copy":"Finance copy"', $json);
        $this->assertStringContainsString('"vouchers":1', $json);
        $this->assertSame($pv->id, AuditLog::query()->orderBy('id')->first()->entity_id ?? $pv->id);

        // A print that fails (over the limit) counts nothing and writes nothing.
        config(['gwl.fin_print_max_vouchers' => 1]);
        [$batch] = $this->sentBatch($fin, 'LIM', 2);
        AuditLog::query()->delete();
        $before = $batch->fresh()->print_count;
        $this->actingAs($fin)->get(route('finance.batches.print', $batch->id))->assertRedirect()->assertSessionHas('error');
        $this->assertSame($before, $batch->fresh()->print_count);
        $this->assertSame(0, AuditLog::query()->count());
    }

    // ------------------------------------------------------------------ a batch

    public function test_a_batch_prints_a_cover_sheet_and_a_table_and_optionally_each_voucher_on_its_own_page(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $one = $this->audit('AU1', $this->sowutuom);
        $two = $this->audit('AU2', $this->sowutuom);
        $one->update(['full_name' => 'Ama Officer']);
        $two->update(['full_name' => 'Kofi Officer']);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        [$batch, $m] = $this->sentBatch($fin, 'B', 3, 'October claims');                // 100.50 + 200.50 + 300.50 = 601.50
        $this->batches()->assignVouchers($manager, [$m[0]->id, $m[1]->id], $one->id);
        $this->batches()->assignVouchers($manager, [$m[2]->id], $two->id);
        $this->actingAs($one);
        $this->workflow()->auditAcknowledge($one, $m[0]);

        $doc = $this->prints()->batch($fin, FinanceScope::AS_FINANCE, $batch->id);
        $facts = collect($doc['cover']['facts'])->mapWithKeys(fn ($row) => [$row[0] => $row[1]]);

        $this->assertSame('Finance copy', $doc['copy']);
        $this->assertSame($batch->batch_no, $facts['Batch number']);
        $this->assertSame('October claims', $facts['Title']);
        $this->assertSame('07 Nov 2026 09:00', $facts['Date sent']);
        $this->assertSame('Ama Officer, Kofi Officer', $facts['Assigned to']);
        $this->assertSame('3', $facts['Vouchers']);
        $this->assertSame('601.50', $facts['Total (GH¢)']);
        $this->assertSame('In progress (0 of 3 done)', $facts['State']);
        $this->assertSame(['With Audit: 2', 'Audit acknowledged: 1'], $doc['cover']['status_counts']);

        $table = $doc['table'];
        $this->assertSame(['B-1', 'B-2', 'B-3'], array_column($table['rows'], 0));
        $this->assertSame('601.50', $table['total']);
        $this->assertStringStartsWith('Acknowledged', $table['rows'][0][5], 'the Audit mark and who');
        $this->assertStringContainsString('Ama Officer', $table['rows'][0][5]);
        $this->assertSame([], $doc['pages'], 'no voucher pages unless asked');
        $this->assertSame([], $doc['pv_ids']);

        $withPages = $this->prints()->batch($fin, FinanceScope::AS_FINANCE, $batch->id, true);
        $this->assertCount(3, $withPages['pages']);
        $this->assertSame('Each voucher follows on its own page.', $withPages['cover']['own_pages_note']);

        // The PDFs, the batch count, and the voucher counts only for vouchers that got their own page.
        $this->assertPdf($this->actingAs($fin)->get(route('finance.batches.print', $batch->id)));
        $this->assertSame([1, 0, 0, 0], [$batch->fresh()->print_count, $m[0]->fresh()->print_count, $m[1]->fresh()->print_count, $m[2]->fresh()->print_count]);
        $this->assertPdf($this->actingAs($fin)->get(route('finance.batches.print', [$batch->id, 'pages' => 1])));
        $this->assertSame([2, 1, 1, 1], [$batch->fresh()->print_count, $m[0]->fresh()->print_count, $m[1]->fresh()->print_count, $m[2]->fresh()->print_count]);
        $this->assertSame(['finance.batch_printed', 'finance.batch_printed'], AuditLog::query()->where('action', 'like', '%batch_printed')->pluck('action')->all());
    }

    public function test_the_manager_and_the_officer_print_only_what_they_may_of_a_batch(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $one = $this->audit('AU1', $this->sowutuom);
        $two = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $managerKumasi = $this->auditManager('AM2', $this->kumasi);
        $payee = $this->employee('PAYEE2', $this->sowutuom);
        $payee->update(['full_name' => 'Zebediah Secretpayee']);
        $a = $this->draft($fin, ['pv_no' => 'M-1', 'amount' => '100.00', 'payee_kind' => 'employee', 'payee_employee_id' => $payee->id]);
        $b = $this->draft($fin, ['pv_no' => 'M-2', 'amount' => '200.00', 'payee_kind' => 'employee', 'payee_employee_id' => $payee->id]);
        $batch = $this->batches()->send($fin, $this->batches()->create($fin, [$a->id, $b->id]));
        $this->batches()->assignVouchers($manager, [$a->id], $one->id);
        $this->batches()->assignVouchers($manager, [$b->id], $two->id);
        $this->actingAs($one);
        $this->workflow()->auditQuery($one, $a, 'other', 'Check');

        // The manager: both vouchers in the table, the payee only where an officer has acted, own pages only for those.
        $doc = $this->prints()->batch($manager, FinanceScope::AS_AUDIT, $batch->id, true);
        $this->assertSame('Audit copy', $doc['copy']);
        $this->assertSame(['M-1', 'M-2'], array_column($doc['table']['rows'], 0));
        $this->assertStringContainsString('Zebediah', $doc['table']['rows'][0][2]);
        $this->assertSame('(opens after an officer has acted)', $doc['table']['rows'][1][2]);
        $this->assertStringNotContainsString('Zebediah', json_encode($doc['table']['rows'][1]));
        $this->assertCount(1, $doc['pages']);
        $this->assertContains('1 voucher is not printed on their own page: nobody in Audit has acted on them yet.', $doc['cover']['notes']);
        $this->assertSame([$a->id], $doc['pv_ids']);

        // An officer: only their own vouchers, no other officer's name, no totals of vouchers that are not theirs.
        $mine = $this->prints()->batch($two, FinanceScope::AS_AUDIT, $batch->id, true);
        $facts = collect($mine['cover']['facts'])->mapWithKeys(fn ($row) => [$row[0] => $row[1]]);
        $this->assertSame(['M-2'], array_column($mine['table']['rows'], 0));
        $this->assertSame('1', $facts['Your vouchers']);
        $this->assertSame('200.00', $facts['Total (GH¢)']);
        $this->assertArrayNotHasKey('Assigned to', $facts);
        $this->assertStringNotContainsString($one->full_name, json_encode($mine));
        $this->assertContains('Only the vouchers given to you are listed.', $mine['cover']['notes']);

        $this->assertPdf($this->actingAs($manager)->get(route('audit.batches.print', [$batch->id, 'pages' => 1])));
        $this->assertPdf($this->actingAs($two)->get(route('audit.batches.print', $batch->id)));
        $this->actingAs($this->audit('AU3', $this->sowutuom))->get(route('audit.batches.print', $batch->id))->assertNotFound();
        $this->actingAs($managerKumasi)->get(route('audit.batches.print', $batch->id))->assertNotFound();
        $this->actingAs($this->finance('A1', $this->kumasi))->get(route('finance.batches.print', $batch->id))->assertNotFound();
    }

    // ------------------------------------------------------------------ print selected

    public function test_print_selected_hands_the_ids_over_once_inside_the_users_reach(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $a = $this->draft($fin, ['pv_no' => 'SEL-1']);
        $b = $this->draft($fin, ['pv_no' => 'SEL-2']);
        $foreign = $this->draft($ash, ['pv_no' => 'SEL-ASH']);

        $component = Livewire::actingAs($fin)->test(Vouchers::class)
            ->assertSee('Print')
            ->set('printIds', [(string) $a->id, (string) $b->id, (string) $foreign->id, '99999'])
            ->call('preparePrint')->assertHasNoErrors();

        $url = $component->get('printUrl');
        $this->assertNotNull($url);
        $this->assertSame(2, $component->get('printCount'), 'the other location\'s voucher and the bogus id dropped out');
        $component->assertSee('Open the PDF');

        $this->assertPdf($this->actingAs($fin)->get($url));
        $this->assertSame([1, 1, 0], [$a->fresh()->print_count, $b->fresh()->print_count, $foreign->fresh()->print_count]);
        $this->assertSame(1, AuditLog::query()->where('action', 'finance.pvs_printed')->count());

        // One use only, and only by the person it was made for.
        $this->actingAs($fin)->get($url)->assertNotFound();
        $other = Livewire::actingAs($fin)->test(Vouchers::class)->set('printIds', [(string) $a->id])->call('preparePrint')->get('printUrl');
        $this->actingAs($this->finance('F2', $this->sowutuom))->get($other)->assertNotFound();

        // Nothing ticked, and too many ticked.
        Livewire::actingAs($fin)->test(Vouchers::class)->call('preparePrint')->assertHasErrors(['print']);
        config(['gwl.fin_print_max_vouchers' => 1]);
        Livewire::actingAs($fin)->test(Vouchers::class)->set('printIds', [(string) $a->id, (string) $b->id])->call('preparePrint')->assertHasErrors(['print']);
    }

    public function test_an_officer_prints_a_selection_from_my_work_and_cannot_tick_what_is_not_theirs(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $mine = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'OFF-1']);
        $theirs = $this->sent($fin, ['pv_no' => 'OFF-2']);

        $component = Livewire::actingAs($aud)->test(Queue::class)
            ->set('printIds', [(string) $mine->id, (string) $theirs->id])->call('preparePrint');

        $this->assertSame(1, $component->get('printCount'));
        $this->assertPdf($this->actingAs($aud)->get($component->get('printUrl')));
        $this->assertSame([1, 0], [$mine->fresh()->print_count, $theirs->fresh()->print_count]);
        $this->assertSame(1, AuditLog::query()->where('action', 'audit.pv_printed')->count());

        // A Finance token does not open Audit's door.
        $financeUrl = Livewire::actingAs($fin)->test(Vouchers::class)->set('printIds', [(string) $mine->id])->call('preparePrint')->get('printUrl');
        $this->actingAs($aud)->get(str_replace('/finance/', '/audit/', $financeUrl))->assertNotFound();
    }

    // ------------------------------------------------------------------ the buttons

    public function test_the_print_buttons_are_on_the_voucher_batch_and_list_screens(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        [$batch, $m] = $this->sentBatch($fin, 'BT', 2);
        $this->batches()->assignBatch($this->managerFor($this->sowutuom), $batch, $aud->id);

        Livewire::actingAs($fin)->test(VoucherShow::class, ['pv' => $m[0]->id])->assertSee(route('finance.pvs.print', $m[0]->id), false);
        Livewire::actingAs($fin)->test(BatchShow::class, ['batch' => $batch->id])
            ->assertSee(route('finance.batches.print', $batch->id), false)->assertSee(route('finance.batches.print', [$batch->id, 'pages' => 1]), false)->assertSee('Print with each voucher');
        Livewire::actingAs($fin)->test(\App\Livewire\Finance\Batches::class)->assertSee(route('finance.batches.print', $batch->id), false);
        Livewire::actingAs($fin)->test(Vouchers::class)->assertSee('Tick voucher BT-1 for printing');
        Livewire::actingAs($aud)->test(\App\Livewire\Audit\VoucherShow::class, ['pv' => $m[0]->id])->assertSee(route('audit.pvs.print', $m[0]->id), false);
        Livewire::actingAs($aud)->test(\App\Livewire\Audit\BatchShow::class, ['batch' => $batch->id])->assertSee(route('audit.batches.print', [$batch->id, 'pages' => 1]), false);
        Livewire::actingAs($aud)->test(Queue::class)->assertSee('Tick voucher BT-1 for printing');
    }

    public function test_all_the_wording_lives_in_the_print_service_not_in_the_view(): void
    {
        $view = file_get_contents(resource_path('views/finance/print/document.blade.php'));
        $visible = preg_replace(['/@class\(\[.*?\]\)/s', '/<style.*?<\/style>/s', '/\{\{.*?\}\}/s', '/^\s*@.*$/m', '/<[^>]+>/', '/@\w+.*$/m'], '', $view);

        // With the tags, the echoes and the directives removed, nothing but punctuation is left: no word is written in the layout.
        $this->assertSame('', trim(preg_replace('/[\s:\-]+/', ' ', (string) $visible)));
    }
}
