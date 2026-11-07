<?php

namespace Tests\Feature\Finance;

use App\Livewire\Audit\Queue;
use App\Livewire\Audit\VoucherShow as AuditVoucherShow;
use App\Livewire\Finance\VoucherShow;
use App\Models\Employee;
use App\Models\FinPaymentVoucher as Pv;
use App\Services\Finance\PayeeStatusService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** The "payee left service" flag: ONE test in PayeeStatusService, shown on the detail pages and the Audit queue. */
class PayeeStatusTest extends FinanceTestCase
{
    private function payeeStatus(): PayeeStatusService
    {
        return app(PayeeStatusService::class);
    }

    private function paidTo(Employee $employee, $by, string $no, string $kind = 'employee', string $date = '2026-11-02'): Pv
    {
        return $this->sent($by, ['pv_no' => $no, 'payee_kind' => $kind, 'payee_employee_id' => $employee->id, 'pv_date' => $date]);
    }

    private function leaves(Employee $employee, string $reason, ?string $on): void
    {
        $employee->forceFill(['is_active' => false, 'deactivation_reason' => $reason, 'deactivated_at' => $on])->save();
    }

    public function test_an_employee_who_left_on_or_before_the_pv_date_is_flagged(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $payee = $this->employee('P1', $this->sowutuom);
        $pv = $this->paidTo($payee, $fin, 'LEFT-1', date: '2026-11-02');

        $this->assertNull($this->payeeStatus()->warningFor($pv), 'still in service: nothing to flag');

        $this->leaves($payee, 'resigned', '2026-11-02'); // the very day of the voucher counts
        $warning = $this->payeeStatus()->warningFor($pv->fresh());

        $this->assertSame(PayeeStatusService::LEFT_BEFORE_PV, $warning['code']);
        $this->assertSame('2026-11-02', $warning['left_on']->toDateString());
        $this->assertStringContainsString('left service on 02 Nov 2026', $warning['message']);
        $this->assertStringContainsString('Resignation', $warning['message']);
    }

    public function test_an_employee_who_left_after_the_pv_date_gets_the_softer_has_since_left_note(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $payee = $this->employee('P1', $this->sowutuom);
        $pv = $this->paidTo($payee, $fin, 'LEFT-2', date: '2026-10-15');

        $this->leaves($payee, 'contract_ended', '2026-11-05');

        $warning = $this->payeeStatus()->warningFor($pv->fresh());
        $this->assertSame(PayeeStatusService::LEFT_SINCE, $warning['code']);
        $this->assertStringContainsString('has since left service', $warning['message']);

        // An older record with no exit date cannot be compared with the PV date: it is the same soft note.
        DB::table('employees')->where('id', $payee->id)->update(['deactivated_at' => null]);
        $this->assertSame(PayeeStatusService::LEFT_SINCE, $this->payeeStatus()->warningFor($pv->fresh())['code']);
    }

    public function test_a_transfer_a_retiree_a_district_a_supplier_and_a_returning_employee_are_never_flagged(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $moved = $this->employee('P1', $this->sowutuom);
        $pvMoved = $this->paidTo($moved, $fin, 'T-1');
        $this->leaves($moved, Employee::EXIT_REASON_TRANSFER, '2026-10-01');
        $this->assertNull($this->payeeStatus()->warningFor($pvMoved->fresh()), 'a transfer is a move, not leaving service');

        $gone = $this->employee('P2', $this->sowutuom);
        $this->leaves($gone, 'retired', '2026-09-01');
        $retiree = $this->paidTo($gone, $fin, 'R-1', 'retiree');
        $this->assertNull($this->payeeStatus()->warningFor($retiree), 'a retiree is MEANT to have left');

        $this->assertNull($this->payeeStatus()->warningFor($this->sent($fin, ['pv_no' => 'D-1'])), 'a district payee has no staff record');

        $back = $this->employee('P3', $this->sowutuom);
        $pvBack = $this->paidTo($back, $fin, 'B-1');
        $this->leaves($back, 'resigned', '2026-10-01');
        $back->forceFill(['is_active' => true])->save();
        $this->assertNull($this->payeeStatus()->warningFor($pvBack->fresh()), 'reactivated: in service again');
    }

    public function test_a_page_of_vouchers_is_checked_with_one_staff_query_and_agrees_with_the_single_test(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $vouchers = [];

        foreach (['A' => '2026-10-01', 'B' => '2026-11-06', 'C' => null] as $key => $leftOn) {
            $payee = $this->employee('PG'.$key, $this->sowutuom);
            $vouchers[$key] = $this->paidTo($payee, $fin, 'PAGE-'.$key);

            if ($leftOn) {
                $this->leaves($payee, 'resigned', $leftOn);
            }
        }

        $vouchers = collect($vouchers)->map->fresh();
        DB::enableQueryLog();
        $flags = $this->payeeStatus()->warningsFor($vouchers);
        $staffQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], '"employees"') || str_contains($query['query'], '`employees`'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $staffQueries);
        $this->assertSame([$vouchers['A']->id, $vouchers['B']->id], array_keys($flags));
        $this->assertEquals($this->payeeStatus()->warningFor($vouchers["A"]), $flags[$vouchers["A"]->id]);
        $this->assertSame([], $this->payeeStatus()->warningsFor([]));
    }

    public function test_the_detail_pages_and_the_audit_queue_show_the_flag_with_words_not_only_colour(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $gone = $this->employee('P1', $this->sowutuom);
        $here = $this->employee('P2', $this->sowutuom);
        $flagged = $this->paidTo($gone, $fin, 'FLAGGED-1');
        $clean = $this->paidTo($here, $fin, 'CLEAN-1');
        $this->leaves($gone, 'resigned', '2026-10-20');

        Livewire::actingAs($aud)->test(AuditVoucherShow::class, ['pv' => $flagged->id])
            ->assertSee('Payee has left service')->assertSee('left service on 20 Oct 2026');
        Livewire::actingAs($aud)->test(AuditVoucherShow::class, ['pv' => $clean->id])->assertDontSee('Payee has left service');
        Livewire::actingAs($fin)->test(VoucherShow::class, ['pv' => $flagged->id])->assertSee('Payee has left service');

        Livewire::actingAs($aud)->test(Queue::class)->assertSee('Left service')->assertSee('FLAGGED-1')->assertSee('CLEAN-1');
        $html = Livewire::actingAs($aud)->test(Queue::class)->html();
        $this->assertSame(1, substr_count($html, 'Left service'), 'only the flagged voucher carries the flag');
    }
}
