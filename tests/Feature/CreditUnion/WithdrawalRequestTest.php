<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\Withdrawals;
use App\Livewire\CreditUnion\WithdrawalShow;
use App\Models\AuditLog;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionWithdrawalRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\CreditUnion\WithdrawalService;
use Livewire\Livewire;

class WithdrawalRequestTest extends CreditUnionTestCase
{
    public function test_a_request_within_the_members_balance_is_raised_as_pending(): void
    {
        $member = $this->memberWithBalance('A00101', savings: 1000, shares: 400);
        $this->actingAs($this->officer());

        Livewire::test(Withdrawals::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.savings_amount', '600')
            ->set('form.shares_amount', '100')
            ->set('form.reason', 'School fees')
            ->call('save')
            ->assertHasNoErrors();

        $withdrawal = CreditUnionWithdrawalRequest::query()->where('member_id', $member->id)->firstOrFail();

        $this->assertSame(CreditUnionWithdrawalRequest::STATUS_PENDING, $withdrawal->status);
        $this->assertSame('600.00', $withdrawal->savings_amount);
        $this->assertSame('100.00', $withdrawal->shares_amount);
        $this->assertSame(700.0, $withdrawal->totalAmount());
        $this->assertNotNull($withdrawal->requested_by);
    }

    public function test_a_request_exceeding_the_members_savings_balance_is_rejected(): void
    {
        // The control Excel could never enforce: the ledger is the authority.
        $member = $this->memberWithBalance('A00201', savings: 500);
        $this->actingAs($this->officer());

        Livewire::test(Withdrawals::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.savings_amount', '900')
            ->call('save')
            ->assertHasErrors('form.savings_amount');

        $this->assertSame(0, CreditUnionWithdrawalRequest::query()->count());
    }

    public function test_a_request_exceeding_the_members_shares_balance_is_rejected(): void
    {
        // 200 of shares comes from the initial share purchase at registration.
        $member = $this->memberWithBalance('A00301', savings: 5000, shares: 200);
        $this->actingAs($this->officer());

        Livewire::test(Withdrawals::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.shares_amount', '350')
            ->call('save')
            ->assertHasErrors('form.shares_amount');

        $this->assertSame(0, CreditUnionWithdrawalRequest::query()->count());
    }

    public function test_a_committee_member_cannot_approve_their_own_withdrawal_request(): void
    {
        $requester = $this->committeeMember('A00401');
        $member = $this->memberWithBalance('A00402', savings: 1000);

        $withdrawal = app(WithdrawalService::class)->request($member, [
            'savings_amount' => 400,
        ], $requester->id);

        $this->actingAs($requester);

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('approve')
            ->assertForbidden();

        $withdrawal->refresh();
        $this->assertSame(CreditUnionWithdrawalRequest::STATUS_PENDING, $withdrawal->status);
        $this->assertNull($withdrawal->decided_at);
    }

    public function test_a_committee_member_cannot_approve_a_withdrawal_against_their_own_membership(): void
    {
        $employee = $this->createEmployee('A00501', 'Committee Saver');
        $user = $this->employeeUser($employee);
        $user->roles()->syncWithoutDetaching(Role::query()->where('name', 'credit_union_committee')->firstOrFail());

        $member = $this->memberWithBalance('A00501', savings: 1000, overrides: ['employee_id' => $employee->id]);
        $withdrawal = app(WithdrawalService::class)->request($member, ['savings_amount' => 400], $this->officer()->id);

        $this->actingAs($user->fresh());

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(CreditUnionWithdrawalRequest::STATUS_PENDING, $withdrawal->refresh()->status);
    }

    public function test_an_officer_without_the_approve_permission_cannot_approve(): void
    {
        $member = $this->memberWithBalance('A00601', savings: 1000);
        $officer = $this->officer();
        $withdrawal = app(WithdrawalService::class)->request($member, ['savings_amount' => 400], $officer->id);

        $this->assertFalse($officer->hasPermission('credit_union.approve_withdrawals'));
        $this->actingAs($officer);

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(CreditUnionWithdrawalRequest::STATUS_PENDING, $withdrawal->refresh()->status);
    }

    public function test_paying_an_approved_withdrawal_debits_the_member_ledger(): void
    {
        $member = $this->memberWithBalance('A00701', savings: 1000, shares: 400);
        $withdrawal = $this->approvedWithdrawal($member, savings: 600, shares: 100);

        $this->actingAs($this->officer());

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->set('paymentForm.payment_method', CreditUnionWithdrawalRequest::METHOD_CASH)
            ->set('paymentForm.payment_reference', 'PV-0091')
            ->call('markPaid')
            ->assertHasNoErrors();

        $withdrawal->refresh();
        $entries = $withdrawal->ledgerEntries()->get();

        $this->assertSame(CreditUnionWithdrawalRequest::STATUS_PAID, $withdrawal->status);
        $this->assertNotNull($withdrawal->paid_at);
        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertSame(CreditUnionLedgerEntry::ENTRY_WITHDRAWAL, $entry->entry_type);
            $this->assertSame(CreditUnionLedgerEntry::SOURCE_CASH, $entry->source);
            $this->assertSame($withdrawal->id, $entry->withdrawal_id);
        }

        // 1000 - 600 savings, 400 - 100 shares.
        $this->assertSame(400.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
        $this->assertSame(300.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SHARES));
    }

    public function test_a_staff_member_can_be_paid_by_bank_transfer(): void
    {
        $member = $this->memberWithBalance('A00801', savings: 1000);
        $withdrawal = $this->approvedWithdrawal($member, savings: 400);

        $this->actingAs($this->officer());

        $methods = Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])->viewData('paymentMethods');
        $this->assertContains(CreditUnionWithdrawalRequest::METHOD_BANK_TRANSFER, $methods);

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->set('paymentForm.payment_method', CreditUnionWithdrawalRequest::METHOD_BANK_TRANSFER)
            ->call('markPaid')
            ->assertHasNoErrors();

        $this->assertSame(
            CreditUnionLedgerEntry::SOURCE_BANK_TRANSFER,
            $withdrawal->refresh()->ledgerEntries()->firstOrFail()->source
        );
    }

    public function test_an_associate_member_cannot_be_paid_by_bank_transfer(): void
    {
        $associate = $this->associateMemberWithBalance('P0031', savings: 1000);
        $withdrawal = $this->approvedWithdrawal($associate, savings: 400);

        $this->actingAs($this->officer());

        $methods = Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])->viewData('paymentMethods');
        $this->assertNotContains(CreditUnionWithdrawalRequest::METHOD_BANK_TRANSFER, $methods);

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->set('paymentForm.payment_method', CreditUnionWithdrawalRequest::METHOD_BANK_TRANSFER)
            ->call('markPaid')
            ->assertHasErrors('paymentForm.payment_method');

        $this->assertSame(CreditUnionWithdrawalRequest::STATUS_APPROVED, $withdrawal->refresh()->status);
    }

    public function test_approval_re_checks_the_balance_in_case_it_moved(): void
    {
        $member = $this->memberWithBalance('A00901', savings: 1000);
        $withdrawal = app(WithdrawalService::class)->request($member, ['savings_amount' => 900], $this->officer()->id);

        // The member withdraws elsewhere before the committee gets to this request.
        $member->ledgerEntries()->create([
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
            'entry_type' => CreditUnionLedgerEntry::ENTRY_WITHDRAWAL,
            'amount' => 800,
            'balance_after' => 200,
            'transaction_date' => today()->toDateString(),
            'source' => CreditUnionLedgerEntry::SOURCE_CASH,
        ]);

        $this->actingAs($this->committeeMember());

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('approve')
            ->assertHasErrors('form.savings_amount');

        $this->assertSame(CreditUnionWithdrawalRequest::STATUS_PENDING, $withdrawal->refresh()->status);
    }

    public function test_a_rejected_withdrawal_records_the_reason_and_posts_nothing(): void
    {
        $member = $this->memberWithBalance('A01001', savings: 1000);
        $withdrawal = app(WithdrawalService::class)->request($member, ['savings_amount' => 400], $this->officer()->id);

        $this->actingAs($this->committeeMember());

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('startReject')
            ->set('rejectionReason', 'Member has an active loan against these savings.')
            ->call('reject')
            ->assertHasNoErrors();

        $withdrawal->refresh();

        $this->assertSame(CreditUnionWithdrawalRequest::STATUS_REJECTED, $withdrawal->status);
        $this->assertSame('Member has an active loan against these savings.', $withdrawal->rejection_reason);
        $this->assertSame(0, $withdrawal->ledgerEntries()->count());
        $this->assertSame(1000.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_an_unapproved_withdrawal_cannot_be_paid(): void
    {
        $member = $this->memberWithBalance('A01101', savings: 1000);
        $withdrawal = app(WithdrawalService::class)->request($member, ['savings_amount' => 400], $this->officer()->id);

        $this->actingAs($this->officer());

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->call('markPaid')
            ->assertHasErrors('paymentForm.payment_method');

        $this->assertSame(0, $withdrawal->refresh()->ledgerEntries()->count());
    }

    public function test_the_withdrawal_lifecycle_is_audit_logged(): void
    {
        $member = $this->memberWithBalance('A01201', savings: 1000);
        $withdrawal = $this->approvedWithdrawal($member, savings: 400);

        $this->actingAs($this->officer());

        Livewire::test(WithdrawalShow::class, ['withdrawal' => $withdrawal])
            ->set('paymentForm.payment_method', CreditUnionWithdrawalRequest::METHOD_CHEQUE)
            ->call('markPaid')
            ->assertHasNoErrors();

        foreach ([
            'credit_union.withdrawal_requested',
            'credit_union.withdrawal_approved',
            'credit_union.withdrawal_paid',
        ] as $action) {
            $this->assertTrue(
                AuditLog::query()
                    ->where('module', Permission::MODULE_CREDIT_UNION)
                    ->where('action', $action)
                    ->exists(),
                $action.' was not audit logged.'
            );
        }
    }

    public function test_an_officer_can_load_the_withdrawal_screens(): void
    {
        $member = $this->memberWithBalance('A01301', savings: 1000);
        $withdrawal = app(WithdrawalService::class)->request($member, ['savings_amount' => 400], $this->officer()->id);

        $officer = $this->officer('A01302');

        $this->actingAs($officer)->get(route('credit-union.withdrawals'))->assertOk()->assertSee('Withdrawal Requests');
        $this->actingAs($officer)->get(route('credit-union.withdrawals.show', $withdrawal))
            ->assertOk()
            ->assertSee('Request Details');
    }

    protected function approvedWithdrawal($member, float $savings, float $shares = 0): CreditUnionWithdrawalRequest
    {
        $withdrawals = app(WithdrawalService::class);

        $withdrawal = $withdrawals->request($member, [
            'savings_amount' => $savings,
            'shares_amount' => $shares,
        ], $this->officer('REQ'.$member->member_number)->id);

        return $withdrawals->approve($withdrawal, $this->committeeMember('APR'.$member->member_number));
    }
}
