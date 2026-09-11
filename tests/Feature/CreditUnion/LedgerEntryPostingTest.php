<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\MemberDetail;
use App\Models\AuditLog;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\LedgerService;
use Livewire\Livewire;

class LedgerEntryPostingTest extends CreditUnionTestCase
{
    public function test_consecutive_deposits_maintain_the_running_balance(): void
    {
        $member = CreditUnionMember::factory()->shareIssued()->create();
        $this->actingAs($this->officer());

        $component = Livewire::test(MemberDetail::class, ['member' => $member])
            ->set('form.account_type', CreditUnionLedgerEntry::ACCOUNT_SAVINGS)
            ->set('form.entry_type', CreditUnionLedgerEntry::ENTRY_CONTRIBUTION)
            ->set('form.source', CreditUnionLedgerEntry::SOURCE_CASH)
            ->set('form.amount', '500')
            ->call('postEntry')
            ->assertHasNoErrors();

        $component
            ->set('form.amount', '250.50')
            ->call('postEntry')
            ->assertHasNoErrors();

        $entries = $member->ledgerEntries()->orderBy('id')->get();

        $this->assertCount(2, $entries);
        $this->assertSame('500.00', $entries[0]->balance_after);
        $this->assertSame('750.50', $entries[1]->balance_after);
        $this->assertSame(750.50, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_shares_and_savings_balances_are_tracked_separately(): void
    {
        $member = CreditUnionMember::factory()->shareIssued()->create();
        $ledger = app(LedgerService::class);

        $ledger->post($member, [
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SHARES,
            'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
            'amount' => 200,
            'source' => CreditUnionLedgerEntry::SOURCE_CASH,
        ]);

        $ledger->post($member, [
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
            'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
            'amount' => 80,
            'source' => CreditUnionLedgerEntry::SOURCE_CASH,
        ]);

        $balances = $ledger->balances($member->fresh());

        $this->assertSame(200.0, $balances['shares']);
        $this->assertSame(80.0, $balances['savings']);
        $this->assertSame(280.0, $balances['total']);
    }

    public function test_a_withdrawal_debits_the_account(): void
    {
        $member = CreditUnionMember::factory()->shareIssued()->create();
        $this->actingAs($this->officer());

        Livewire::test(MemberDetail::class, ['member' => $member])
            ->set('form.account_type', CreditUnionLedgerEntry::ACCOUNT_SAVINGS)
            ->set('form.source', CreditUnionLedgerEntry::SOURCE_CASH)
            ->set('form.amount', '400')
            ->call('postEntry')
            ->set('form.entry_type', CreditUnionLedgerEntry::ENTRY_WITHDRAWAL)
            ->set('form.amount', '150')
            ->call('postEntry')
            ->assertHasNoErrors();

        $withdrawal = $member->ledgerEntries()->latest('id')->firstOrFail();

        $this->assertSame(CreditUnionLedgerEntry::ENTRY_WITHDRAWAL, $withdrawal->entry_type);
        $this->assertSame('250.00', $withdrawal->balance_after);
        $this->assertSame(-150.0, $withdrawal->signedAmount());
    }

    public function test_a_withdrawal_cannot_take_the_balance_below_zero(): void
    {
        $member = CreditUnionMember::factory()->shareIssued()->create();
        $this->actingAs($this->officer());

        Livewire::test(MemberDetail::class, ['member' => $member])
            ->set('form.account_type', CreditUnionLedgerEntry::ACCOUNT_SAVINGS)
            ->set('form.source', CreditUnionLedgerEntry::SOURCE_CASH)
            ->set('form.amount', '100')
            ->call('postEntry')
            ->set('form.entry_type', CreditUnionLedgerEntry::ENTRY_WITHDRAWAL)
            ->set('form.amount', '250')
            ->call('postEntry')
            ->assertHasErrors('form.amount');

        $this->assertSame(1, $member->ledgerEntries()->count());
        $this->assertSame(100.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
    }

    public function test_associate_members_cannot_post_payroll_deduction_entries(): void
    {
        $member = CreditUnionMember::factory()->associate()->shareIssued()->create();
        $this->actingAs($this->officer());

        Livewire::test(MemberDetail::class, ['member' => $member])
            ->set('form.account_type', CreditUnionLedgerEntry::ACCOUNT_SAVINGS)
            ->set('form.source', CreditUnionLedgerEntry::SOURCE_PAYROLL_DEDUCTION)
            ->set('form.amount', '300')
            ->call('postEntry')
            ->assertHasErrors('form.source');

        $this->assertSame(0, $member->ledgerEntries()->count());
    }

    public function test_the_source_options_offered_for_an_associate_exclude_payroll(): void
    {
        $associate = CreditUnionMember::factory()->associate()->shareIssued()->create();
        $staff = CreditUnionMember::factory()->shareIssued()->create();
        $this->actingAs($this->officer());

        $associateSources = Livewire::test(MemberDetail::class, ['member' => $associate])->viewData('sources');
        $staffSources = Livewire::test(MemberDetail::class, ['member' => $staff])->viewData('sources');

        $this->assertNotContains(CreditUnionLedgerEntry::SOURCE_PAYROLL_DEDUCTION, $associateSources);
        $this->assertContains(CreditUnionLedgerEntry::SOURCE_PAYROLL_DEDUCTION, $staffSources);
    }

    public function test_entries_cannot_be_posted_for_a_member_who_is_not_active(): void
    {
        $member = CreditUnionMember::factory()->pending()->create();
        $this->actingAs($this->officer());

        Livewire::test(MemberDetail::class, ['member' => $member])
            ->set('form.account_type', CreditUnionLedgerEntry::ACCOUNT_SAVINGS)
            ->set('form.source', CreditUnionLedgerEntry::SOURCE_CASH)
            ->set('form.amount', '100')
            ->call('postEntry')
            ->assertHasErrors('form.amount');

        $this->assertSame(0, $member->ledgerEntries()->count());
    }

    public function test_every_ledger_post_is_audit_logged(): void
    {
        $member = CreditUnionMember::factory()->shareIssued()->create();
        $this->actingAs($this->officer());

        Livewire::test(MemberDetail::class, ['member' => $member])
            ->set('form.account_type', CreditUnionLedgerEntry::ACCOUNT_SHARES)
            ->set('form.source', CreditUnionLedgerEntry::SOURCE_CHEQUE)
            ->set('form.amount', '75')
            ->set('form.reference_no', 'CHQ-8891')
            ->call('postEntry')
            ->assertHasNoErrors();

        $entry = $member->ledgerEntries()->latest('id')->firstOrFail();
        $this->assertSame('CHQ-8891', $entry->reference_no);

        $log = AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.ledger_entry_posted')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CreditUnionLedgerEntry::class, $log->target_type);
        $this->assertSame($member->member_number, $log->metadata['member_number'] ?? null);
        $this->assertEquals(75.0, $log->metadata['amount'] ?? null);
    }

    public function test_the_pdf_statement_is_downloadable_for_a_member(): void
    {
        $member = CreditUnionMember::factory()->shareIssued()->create();
        CreditUnionLedgerEntry::factory()->for($member, 'member')->create([
            'amount' => 120,
            'balance_after' => 120,
        ]);

        $response = $this->actingAs($this->officer())
            ->get(route('credit-union.members.statement.pdf', $member));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
