<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\Receipts;
use App\Models\AuditLog;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionLoanRepayment;
use App\Models\CreditUnionManualReceipt;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\LoanService;
use Livewire\Livewire;

class ManualReceiptTest extends CreditUnionTestCase
{
    public function test_a_banked_savings_receipt_posts_to_the_member_ledger(): void
    {
        $member = $this->memberWithBalance('C00101', savings: 300);
        $this->actingAs($this->officer());

        Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_SAVINGS)
            ->set('form.method', CreditUnionManualReceipt::METHOD_CASH)
            ->set('form.amount', '250')
            ->set('form.banked', true)
            ->call('save')
            ->assertHasNoErrors();

        $receipt = CreditUnionManualReceipt::query()->where('member_id', $member->id)->firstOrFail();

        $this->assertSame('250.00', $receipt->amount);
        $this->assertTrue($receipt->banked);
        $this->assertSame(550.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));

        $entry = $member->ledgerEntries()->latest('id')->firstOrFail();
        $this->assertSame(CreditUnionLedgerEntry::SOURCE_CASH, $entry->source);
        $this->assertSame(CreditUnionLedgerEntry::ENTRY_CONTRIBUTION, $entry->entry_type);
    }

    public function test_an_unbanked_receipt_waits_for_banking_before_it_reaches_the_ledger(): void
    {
        $member = $this->memberWithBalance('C00201', savings: 300);
        $this->actingAs($this->officer());

        $component = Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_SAVINGS)
            ->set('form.amount', '250')
            ->set('form.banked', false)
            ->call('save')
            ->assertHasNoErrors();

        $receipt = CreditUnionManualReceipt::query()->where('member_id', $member->id)->firstOrFail();
        $this->assertSame(300.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));

        $component->call('markBanked', $receipt->id)->assertHasNoErrors();

        $this->assertTrue($receipt->refresh()->banked);
        $this->assertSame(550.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_a_cheque_receipt_posts_with_the_cheque_source_and_reference(): void
    {
        $member = $this->memberWithBalance('C00301', savings: 300, shares: 200);
        $this->actingAs($this->officer());

        Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_SHARES)
            ->set('form.method', CreditUnionManualReceipt::METHOD_CHEQUE)
            ->set('form.cheque_no', 'GCB 771902')
            ->set('form.amount', '100')
            ->set('form.banked', true)
            ->call('save')
            ->assertHasNoErrors();

        $entry = $member->ledgerEntries()->latest('id')->firstOrFail();

        $this->assertSame(CreditUnionLedgerEntry::ACCOUNT_SHARES, $entry->account_type);
        $this->assertSame(CreditUnionLedgerEntry::SOURCE_CHEQUE, $entry->source);
        $this->assertSame('GCB 771902', $entry->reference_no);
        $this->assertSame(300.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SHARES));
    }

    public function test_a_loan_repayment_receipt_reduces_the_outstanding_loan_balance(): void
    {
        $member = $this->memberWithBalance('C00401', savings: 5000);
        $loan = $this->disbursedLoan($member);

        $this->actingAs($this->officer());

        Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_LOAN_REPAYMENT)
            ->set('form.method', CreditUnionManualReceipt::METHOD_CASH)
            ->set('form.amount', '500')
            ->call('save')
            ->assertHasNoErrors();

        $loan->refresh();
        $repayment = $loan->repayments()->latest('id')->firstOrFail();

        // 2300 repayable less the 500 just received.
        $this->assertSame('1800.00', $loan->outstanding_balance);
        $this->assertSame(CreditUnionLoan::STATUS_ACTIVE, $loan->status);
        $this->assertSame('500.00', $repayment->amount);
        $this->assertSame(CreditUnionLoanRepayment::SOURCE_CASH, $repayment->source);

        // A loan repayment is not a shares/savings contribution.
        $this->assertSame(5000.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_a_loan_repayment_receipt_without_an_outstanding_loan_is_rejected(): void
    {
        $member = $this->memberWithBalance('C00501', savings: 5000);
        $this->actingAs($this->officer());

        Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_LOAN_REPAYMENT)
            ->set('form.amount', '500')
            ->call('save')
            ->assertHasErrors('form.purpose');

        $this->assertSame(0, CreditUnionManualReceipt::query()->count());
    }

    public function test_a_membership_form_fee_receipt_creates_no_ledger_entry(): void
    {
        $member = $this->memberWithBalance('C00601', savings: 300);
        $ledgerCountBefore = $member->ledgerEntries()->count();

        $this->actingAs($this->officer());

        Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_MEMBERSHIP_FORM_FEE)
            ->set('form.amount', '20')
            ->set('form.banked', true)
            ->call('save')
            ->assertHasNoErrors();

        $receipt = CreditUnionManualReceipt::query()->where('member_id', $member->id)->firstOrFail();

        // The form fee is an admin charge, not a member asset - same rule as at registration.
        $this->assertTrue($receipt->isMembershipFormFee());
        $this->assertSame('20.00', $receipt->amount);
        $this->assertSame($ledgerCountBefore, $member->fresh()->ledgerEntries()->count());
        $this->assertSame(300.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_an_associate_members_cash_receipt_works_normally(): void
    {
        // Cash over the counter is an associate member's only contribution route.
        $associate = $this->associateMemberWithBalance('P0051', savings: 400);
        $this->actingAs($this->officer());

        Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $associate->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_SAVINGS)
            ->set('form.method', CreditUnionManualReceipt::METHOD_CASH)
            ->set('form.amount', '150')
            ->set('form.banked', true)
            ->call('save')
            ->assertHasNoErrors();

        $entry = $associate->ledgerEntries()->latest('id')->firstOrFail();

        $this->assertSame(CreditUnionLedgerEntry::SOURCE_CASH, $entry->source);
        $this->assertSame(550.0, $associate->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_an_associate_member_can_repay_a_loan_in_cash(): void
    {
        $associate = $this->associateMemberWithBalance('P0052', savings: 5000);
        $loan = $this->disbursedLoan($associate);

        $this->actingAs($this->officer());

        Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $associate->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_LOAN_REPAYMENT)
            ->set('form.method', CreditUnionManualReceipt::METHOD_CASH)
            ->set('form.amount', '300')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2000.00', $loan->refresh()->outstanding_balance);
        $this->assertSame(CreditUnionLoanRepayment::SOURCE_CASH, $loan->repayments()->latest('id')->firstOrFail()->source);
    }

    public function test_a_receipt_is_audit_logged(): void
    {
        $member = $this->memberWithBalance('C00701', savings: 300);
        $this->actingAs($this->officer());

        Livewire::test(Receipts::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.purpose', CreditUnionManualReceipt::PURPOSE_SAVINGS)
            ->set('form.amount', '90')
            ->set('form.banked', true)
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.manual_receipt_recorded')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CreditUnionManualReceipt::class, $log->target_type);
        $this->assertEquals(90.0, $log->metadata['amount'] ?? null);
        $this->assertTrue($log->metadata['ledgered'] ?? false);
    }

    public function test_a_user_without_the_receipts_permission_cannot_reach_the_screen(): void
    {
        $this->actingAs($this->committeeMember());

        $this->get(route('credit-union.receipts'))->assertForbidden();
    }

    public function test_an_officer_can_load_the_receipts_screen(): void
    {
        $this->actingAs($this->officer());

        $this->get(route('credit-union.receipts'))->assertOk()->assertSee('Receipt Register');
    }

    /**
     * A disbursed 2000/12-month loan: 2300 repayable at the confirmed 15% rate.
     */
    protected function disbursedLoan(CreditUnionMember $member): CreditUnionLoan
    {
        $loans = app(LoanService::class);

        $loan = $loans->apply($member, [
            'principal_amount' => 2000,
            'term_months' => 12,
        ], $this->officer('LN'.$member->member_number)->id);

        $loans->approve($loan, $this->committeeMember('LC'.$member->member_number));

        return $loans->disburse($loan->refresh());
    }
}
