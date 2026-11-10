<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Finance\BatchWorkflowService;
use Illuminate\Support\Facades\Notification;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinSupplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Who is told what, and that neither a notice nor an audit row ever carries the payee, amount, a comment or a file name. */
class PvNoticesAndAuditRowsTest extends FinanceTestCase
{
    public function test_audit_managers_hear_of_submissions_the_officer_of_their_voucher_and_finance_of_the_answers(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $finTwo = $this->finance('F2', $this->odorkor);       // same location (Accra West without Head Office)
        $finHo = $this->finance('F3', $this->headOffice);     // Head Office keeps its own books
        $aud = $this->audit('AU1', $this->sowutuom);
        $audTwo = $this->audit('AU2', $this->odorkor);
        $audKumasi = $this->audit('AU3', $this->kumasi);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $managerKumasi = $this->auditManager('AM2', $this->kumasi);
        $managerHo = $this->auditManager('AM3', $this->headOffice);

        $pv = $this->sent($fin);

        // A new voucher is the MANAGERS' news (nobody in Audit can work on it before it is assigned).
        $this->assertSame([], $this->noticesFor($fin), 'the sender is not told');
        $this->assertCount(1, $this->noticesFor($manager));
        $this->assertSame([], $this->noticesFor($managerKumasi));
        $this->assertSame([], $this->noticesFor($managerHo));
        $this->assertSame([], $this->noticesFor($aud));
        $this->assertSame([], $this->noticesFor($audTwo));
        $this->assertSame([], $this->noticesFor($finTwo), 'Finance is not told about a submission');

        // Giving it to an officer tells that officer, and only that one.
        $this->actingAs($manager);
        $pv = $this->workflow()->assign($manager, $pv, $aud->id);
        $this->assertCount(1, $this->noticesFor($aud));
        $this->assertSame([], $this->noticesFor($audTwo));
        $this->assertCount(1, $this->noticesFor($manager), 'the manager is not told about their own assignment');

        $this->actingAs($aud);
        $pv = $this->workflow()->auditQuery($aud, $pv, 'amount_mismatch', 'SECRET-COMMENT-1');
        $this->assertCount(1, $this->noticesFor($fin));
        $this->assertCount(1, $this->noticesFor($finTwo));
        $this->assertSame([], $this->noticesFor($finHo));
        $this->assertCount(1, $this->noticesFor($aud), 'Audit is not told about its own query');

        // A corrected voucher goes back to the same officer, not to the managers or to another officer.
        $this->actingAs($fin);
        $pv = $this->workflow()->resubmit($fin, $pv, $pv->lock_version, 'SECRET-NOTE-2');
        $this->assertCount(2, $this->noticesFor($aud));
        $this->assertSame([], $this->noticesFor($audTwo));
        $this->assertSame([], $this->noticesFor($audKumasi));
        $this->assertCount(1, $this->noticesFor($manager));

        $this->actingAs($aud);
        $pv = $this->workflow()->auditAcknowledge($aud, $pv);
        $this->assertCount(2, $this->noticesFor($fin)); // the query and the tick
        $pv = $this->workflow()->withdrawTick($aud, $pv, 'SECRET-REASON-3');
        $this->assertCount(3, $this->noticesFor($fin)); // and the withdrawn tick
    }

    public function test_notices_and_audit_rows_carry_no_payee_amount_comment_supplier_or_file_name(): void
    {
        Storage::fake('local');
        $payee = $this->employee('PAYEE1', $this->sowutuom);
        $payee->update(['full_name' => 'Zebediah Quarcoo-Payee']);
        $supplier = FinSupplier::query()->create(['name' => 'Gold Coast Secret Traders', 'name_key' => 'GOLD COAST SECRET TRADERS', 'tin' => 'C0099988877', 'phone' => '0244998877']);

        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $this->actingAs($fin);

        $file = UploadedFile::fake()->createWithContent('secret-scan-name.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");
        $employeePv = $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'PV-E', 'payee_kind' => 'employee', 'payee_employee_id' => $payee->id, 'amount' => '7777.77']), [$file]);
        $supplierPv = $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'PV-S', 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id, 'amount' => '8888.88']));

        foreach ([$employeePv, $supplierPv] as $pv) {
            $this->actingAs($fin);
            $pv = $this->workflow()->submit($fin, $pv, $pv->lock_version);
            $pv = $this->assignTo($pv, $aud, 'SECRET-ASSIGN-NOTE');
            $this->actingAs($aud);
            $pv = $this->workflow()->auditQuery($aud, $pv, 'other', 'SECRET-COMMENT');
            $this->actingAs($fin);
            $pv = $this->workflow()->update($fin, $pv, $this->pvData(['pv_no' => $pv->pv_no, 'payee_kind' => $pv->payee_kind, 'payee_employee_id' => $pv->payee_employee_id, 'supplier_id' => $pv->supplier_id, 'amount' => '9999.99']), $pv->lock_version);
            $pv = $this->workflow()->resubmit($fin, $pv, $pv->lock_version, 'SECRET-NOTE');
            $this->actingAs($aud);
            $pv = $this->workflow()->auditAcknowledge($aud, $pv);
            $pv = $this->workflow()->withdrawTick($aud, $pv, 'SECRET-WITHDRAW');
        }

        // A batch: sent to Audit, then given to the officer. Its notices and audit rows are part of the scan.
        $this->actingAs($fin);
        $first = $this->draft($fin, ['pv_no' => 'PV-B1', 'amount' => '3333.33', 'details' => 'SECRET-BATCH-DETAILS']);
        $second = $this->draft($fin, ['pv_no' => 'PV-B2', 'amount' => '4444.44']);
        $batches = app(BatchWorkflowService::class);
        $batch = $batches->create($fin, [$first->id, $second->id], 'SECRET-TITLE', 'SECRET-BATCH-REMARK');
        $batches->send($fin, $batch);
        $batches->assignBatch($this->managerFor($this->sowutuom), $batch, $aud->id, 'SECRET-BATCH-NOTE');

        $voided = $this->draft($fin, ['pv_no' => 'PV-V']);
        $this->workflow()->void($fin, $voided, 'SECRET-VOID-REASON');

        // The reminders (both sets) and the settings that drive them are part of the scan.
        $this->draft($fin, ['pv_no' => 'PV-D', 'amount' => '6666.66', 'details' => 'SECRET-DRAFT']);
        \Illuminate\Support\Carbon::setTestNow('2026-12-20 09:00');
        app(\App\Services\Finance\FinanceSettings::class)->save($this->financeManager('FM1', $this->sowutuom), [\App\Services\Finance\FinanceSettings::QUERIED_WAIT => 9, \App\Services\Finance\FinanceSettings::ENABLED => true], null);
        app(\App\Services\Finance\AuditSettings::class)->save($this->auditManager('AM1', $this->sowutuom), [\App\Services\Finance\AuditSettings::WAIT => 4], null);
        $sentToAudit = app(\App\Services\Finance\AuditReminderService::class)->remind()['notifications'];
        $sentToFinance = app(\App\Services\Finance\FinanceReminderService::class)->remind()['notifications'];
        $this->assertGreaterThan(0, $sentToAudit);
        $this->assertGreaterThan(0, $sentToFinance);

        $notices =json_encode(User::query()->get()->flatMap(fn (User $user) => $this->noticesFor($user))->all());
        $audits = json_encode(DB::table('audit_logs')->get());

        $this->assertNotSame('[]', $notices);
        $this->assertStringContainsString('PV-E', $notices, 'the notice names the voucher');
        $this->assertStringContainsString('pv_waiting', $notices, 'the reminders are in the scan');
        $this->assertStringContainsString('batch_sent', $notices, 'the batch notices are in the scan');
        $this->assertStringContainsString('batch_assigned', $notices);
        $this->assertStringContainsString('pv_assigned', $notices);

        foreach (['Zebediah', 'Quarcoo', 'Gold Coast', 'C0099988877', '0244998877', '7777.77', '8888.88', '9999.99', '6666.66', '3333.33', '4444.44', '333333', '444444', '777777', '888888', '999999', '666666',
            'SECRET', 'secret-scan-name', 'Medical claim', 'Finance own note'] as $needle) {
            $this->assertStringNotContainsString($needle, $notices, "notices must not contain {$needle}");
            $this->assertStringNotContainsString($needle, $audits, "audit rows must not contain {$needle}");
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function noticesFor($user): array
    {
        // Other parts of the ERP (new accounts) also send general notices: only the voucher ones count here.
        return Notification::sent($user, GeneralDatabaseNotification::class)
            ->map(fn ($notice) => $notice->toArray($user))
            ->filter(fn (array $data) => str_starts_with((string) ($data['type'] ?? ''), 'pv') || str_starts_with((string) ($data['type'] ?? ''), 'batch_'))
            ->values()->all();
    }
}
