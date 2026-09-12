<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\Refunds;
use App\Models\AuditLog;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionRefund;
use App\Models\Permission;
use Livewire\Livewire;

class RefundTest extends CreditUnionTestCase
{
    public function test_a_refund_credits_the_members_ledger_with_no_approval_step(): void
    {
        $member = $this->memberWithBalance('B00101', savings: 500);
        $this->actingAs($this->officer());

        Livewire::test(Refunds::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.account_type', CreditUnionLedgerEntry::ACCOUNT_SAVINGS)
            ->set('form.amount', '120')
            ->set('form.reason', 'July deduction taken twice')
            ->call('save')
            ->assertHasNoErrors();

        $refund = CreditUnionRefund::query()->where('member_id', $member->id)->firstOrFail();
        $entry = $refund->ledgerEntries()->firstOrFail();

        // Recorded and credited in one step - there is no approve_refunds permission.
        $this->assertSame('120.00', $refund->amount);
        $this->assertSame('July deduction taken twice', $refund->reason);
        $this->assertSame(CreditUnionLedgerEntry::ENTRY_REFUND, $entry->entry_type);
        $this->assertSame($refund->id, $entry->refund_id);
        $this->assertSame('620.00', $entry->balance_after);
        $this->assertSame(620.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_a_refund_can_be_credited_to_the_shares_account(): void
    {
        $member = $this->memberWithBalance('B00201', savings: 500, shares: 200);
        $this->actingAs($this->officer());

        Livewire::test(Refunds::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.account_type', CreditUnionLedgerEntry::ACCOUNT_SHARES)
            ->set('form.amount', '50')
            ->set('form.reason', 'Shares over-deducted in June')
            ->call('save')
            ->assertHasNoErrors();

        $member = $member->fresh();

        $this->assertSame(250.0, $member->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SHARES));
        $this->assertSame(500.0, $member->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_a_refund_requires_a_reason_and_a_positive_amount(): void
    {
        $member = $this->memberWithBalance('B00301', savings: 500);
        $this->actingAs($this->officer());

        Livewire::test(Refunds::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.amount', '0')
            ->set('form.reason', '')
            ->call('save')
            ->assertHasErrors(['form.amount', 'form.reason']);

        $this->assertSame(0, CreditUnionRefund::query()->count());
    }

    public function test_a_refund_is_audit_logged(): void
    {
        $member = $this->memberWithBalance('B00401', savings: 500);
        $this->actingAs($this->officer());

        Livewire::test(Refunds::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.amount', '75')
            ->set('form.reason', 'Duplicate cheque banked')
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.refund_recorded')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CreditUnionRefund::class, $log->target_type);
        $this->assertEquals(75.0, $log->metadata['amount'] ?? null);
        $this->assertSame('Duplicate cheque banked', $log->metadata['reason'] ?? null);
        $this->assertNotNull($log->metadata['ledger_entry_id'] ?? null);
    }

    public function test_an_associate_member_can_be_refunded(): void
    {
        $associate = $this->associateMemberWithBalance('P0041', savings: 400);
        $this->actingAs($this->officer());

        Livewire::test(Refunds::class)
            ->call('openForm')
            ->set('form.member_id', $associate->id)
            ->set('form.amount', '60')
            ->set('form.reason', 'Cash receipt posted twice')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(460.0, $associate->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_a_user_without_the_refunds_permission_cannot_reach_the_screen(): void
    {
        $this->actingAs($this->committeeMember());

        $this->get(route('credit-union.refunds'))->assertForbidden();
    }

    public function test_an_officer_can_load_the_refunds_screen(): void
    {
        $this->actingAs($this->officer());

        $this->get(route('credit-union.refunds'))->assertOk()->assertSee('Refund Register');
    }
}
