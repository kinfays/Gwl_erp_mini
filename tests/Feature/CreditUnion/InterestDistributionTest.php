<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\InterestDistributions;
use App\Livewire\CreditUnion\InterestDistributionShow;
use App\Models\AuditLog;
use App\Models\CreditUnionInterestDistribution;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\InterestDistributionService;
use Livewire\Livewire;

class InterestDistributionTest extends CreditUnionTestCase
{
    /** The fiscal year the runs in this test are computed against. */
    protected string $periodStart;

    protected string $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodEnd = today()->toDateString();
        $this->periodStart = today()->subYear()->addDay()->toDateString();
    }

    public function test_the_pool_sums_only_interest_on_loans_disbursed_within_the_period(): void
    {
        $member = $this->memberWithBalance('D00101', savings: 1000);

        $this->loanWithInterest($member, 500.25, today()->subMonths(3));
        $this->loanWithInterest($member, 1000.50, today()->subMonths(8));

        // Outside the window on both sides.
        $this->loanWithInterest($member, 9999.99, today()->subYears(2));
        $this->loanWithInterest($member, 8888.88, today()->addMonth());

        $pool = app(InterestDistributionService::class)->interestPoolFor($this->periodStart, $this->periodEnd);

        $this->assertSame(1500.75, $pool);
    }

    public function test_a_computed_run_allocates_proportionally_across_members(): void
    {
        // Combined holdings of 900, 1500 and 2600 against a 1500.75 pool.
        $first = $this->memberWithBalance('D00201', savings: 600, shares: 300);
        $second = $this->memberWithBalance('D00202', savings: 1500);
        $third = $this->memberWithBalance('D00203', savings: 2600);

        $this->poolOf1500_75($first);

        $distribution = $this->computeRun();

        $this->assertSame(CreditUnionInterestDistribution::STATUS_COMPUTED, $distribution->status);
        $this->assertSame('1500.75', $distribution->total_interest_pool);
        $this->assertSame(3, $distribution->lines()->count());

        $lines = $distribution->lines()->get()->keyBy('member_id');

        $this->assertSame('900.00', $lines[$first->id]->asset_balance_at_computation);
        $this->assertSame('1500.00', $lines[$second->id]->asset_balance_at_computation);
        $this->assertSame('2600.00', $lines[$third->id]->asset_balance_at_computation);

        // 900/5000 = 18%, 1500/5000 = 30%, 2600/5000 = 52%.
        $this->assertSame('18.0000', $lines[$first->id]->share_of_pool_percent);
        $this->assertSame('30.0000', $lines[$second->id]->share_of_pool_percent);
        $this->assertSame('52.0000', $lines[$third->id]->share_of_pool_percent);

        $this->assertSame('270.14', $lines[$first->id]->amount);
        $this->assertSame('450.23', $lines[$second->id]->amount);
    }

    public function test_rounding_remainder_is_absorbed_so_the_lines_sum_to_the_pool_exactly(): void
    {
        $first = $this->memberWithBalance('D00301', savings: 600, shares: 300);
        $second = $this->memberWithBalance('D00302', savings: 1500);
        $third = $this->memberWithBalance('D00303', savings: 2600);

        $this->poolOf1500_75($first);

        $distribution = $this->computeRun();
        $lines = $distribution->lines()->get()->keyBy('member_id');

        // Naive per-line rounding gives 270.14 + 450.23 + 780.39 = 1500.76, a cent over
        // the pool. The largest line absorbs the difference.
        $naiveSum = round(270.14 + 450.23 + 780.39, 2);
        $this->assertSame(1500.76, $naiveSum);
        $this->assertSame('780.38', $lines[$third->id]->amount);

        $this->assertSame(1500.75, $distribution->lineTotal());
        $this->assertSame((float) $distribution->total_interest_pool, $distribution->lineTotal());
    }

    public function test_a_member_holding_nothing_gets_no_line_rather_than_a_divide_by_zero(): void
    {
        $holder = $this->memberWithBalance('D00401', savings: 1000);
        $empty = CreditUnionMember::factory()->create([
            'member_number' => 'D00402',
            'staff_id' => 'D00402',
            'full_name' => 'Empty Holdings',
        ]);

        $this->poolOf1500_75($holder);

        $distribution = $this->computeRun();

        $this->assertSame(0.0, $empty->assetBalanceAsOf($this->periodEnd));
        $this->assertSame(1, $distribution->lines()->count());
        $this->assertSame(0, $distribution->lines()->where('member_id', $empty->id)->count());
        $this->assertSame('1500.75', $distribution->lines()->firstOrFail()->amount);
    }

    public function test_balances_are_snapshotted_as_at_the_period_end_date(): void
    {
        $member = $this->memberWithBalance('D00501', savings: 1000);

        // Paid in after the year end - must not count toward this run.
        $member->ledgerEntries()->create([
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
            'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
            'amount' => 5000,
            'balance_after' => 6000,
            'transaction_date' => today()->addMonth()->toDateString(),
            'source' => CreditUnionLedgerEntry::SOURCE_CASH,
        ]);

        $this->assertSame(6000.0, $member->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
        $this->assertSame(1000.0, $member->fresh()->assetBalanceAsOf($this->periodEnd));

        $this->poolOf1500_75($member);
        $distribution = $this->computeRun();

        $this->assertSame('1000.00', $distribution->lines()->firstOrFail()->asset_balance_at_computation);
    }

    public function test_inactive_members_are_excluded_from_the_run(): void
    {
        $active = $this->memberWithBalance('D00601', savings: 1000);
        $exited = $this->memberWithBalance('D00602', savings: 4000, overrides: [
            'status' => CreditUnionMember::STATUS_EXITED,
        ]);

        $this->poolOf1500_75($active);

        $distribution = $this->computeRun();

        $this->assertSame(1, $distribution->lines()->count());
        $this->assertSame(0, $distribution->lines()->where('member_id', $exited->id)->count());
    }

    public function test_posting_credits_every_member_with_an_interest_ledger_entry(): void
    {
        $first = $this->memberWithBalance('D00701', savings: 600, shares: 300);
        $second = $this->memberWithBalance('D00702', savings: 1500);
        $third = $this->memberWithBalance('D00703', savings: 2600);

        $this->poolOf1500_75($first);

        $distribution = $this->computeRun();

        $this->actingAs($this->committeeMember());
        Livewire::test(InterestDistributionShow::class, ['distribution' => $distribution])
            ->call('approve')
            ->assertHasNoErrors();

        Livewire::test(InterestDistributionShow::class, ['distribution' => $distribution->refresh()])
            ->call('post')
            ->assertHasNoErrors();

        $distribution->refresh();
        $this->assertSame(CreditUnionInterestDistribution::STATUS_POSTED, $distribution->status);
        $this->assertNotNull($distribution->posted_at);

        foreach ($distribution->lines()->with('member')->get() as $line) {
            $entry = $line->ledgerEntry;

            $this->assertNotNull($entry, 'Line for member '.$line->member_id.' was not posted.');
            $this->assertSame(CreditUnionLedgerEntry::ENTRY_INTEREST, $entry->entry_type);
            $this->assertSame(CreditUnionLedgerEntry::ACCOUNT_SAVINGS, $entry->account_type);
            $this->assertSame($this->periodEnd, $entry->transaction_date->toDateString());
        }

        // 600 savings + 270.14 interest; 1500 + 450.23; 2600 + 780.38.
        $this->assertSame(870.14, $first->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
        $this->assertSame(1950.23, $second->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));
        $this->assertSame(3380.38, $third->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SAVINGS));

        // Shares are untouched: distributions are credited to savings.
        $this->assertSame(300.0, $first->fresh()->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SHARES));
    }

    public function test_a_computed_run_cannot_be_posted_before_it_is_approved(): void
    {
        $member = $this->memberWithBalance('D00801', savings: 1000);
        $this->poolOf1500_75($member);

        $distribution = $this->computeRun();
        $this->assertSame(CreditUnionInterestDistribution::STATUS_COMPUTED, $distribution->status);

        $this->actingAs($this->committeeMember());

        Livewire::test(InterestDistributionShow::class, ['distribution' => $distribution])
            ->call('post')
            ->assertHasErrors('distribution');

        $distribution->refresh();
        $this->assertSame(CreditUnionInterestDistribution::STATUS_COMPUTED, $distribution->status);
        $this->assertSame(0, $member->fresh()->ledgerEntries()->where('entry_type', CreditUnionLedgerEntry::ENTRY_INTEREST)->count());
    }

    public function test_a_draft_run_cannot_be_approved_and_a_posted_run_cannot_be_reposted(): void
    {
        $member = $this->memberWithBalance('D00901', savings: 1000);
        $this->poolOf1500_75($member);

        $draft = CreditUnionInterestDistribution::factory()->create();
        $this->actingAs($this->committeeMember());

        Livewire::test(InterestDistributionShow::class, ['distribution' => $draft])
            ->call('approve')
            ->assertHasErrors('distribution');

        $distribution = $this->computeRun();
        $distributions = app(InterestDistributionService::class);
        $distributions->approve($distribution, $this->committeeMember());
        $distributions->post($distribution->refresh());

        $ledgerCount = $member->fresh()->ledgerEntries()->count();

        // computeRun() acts as the officer, so switch back before a committee action.
        $this->actingAs($this->committeeMember());

        Livewire::test(InterestDistributionShow::class, ['distribution' => $distribution->refresh()])
            ->call('post')
            ->assertHasErrors('distribution');

        $this->assertSame($ledgerCount, $member->fresh()->ledgerEntries()->count());
    }

    public function test_computing_a_period_with_no_loan_interest_is_refused(): void
    {
        $this->memberWithBalance('D01001', savings: 1000);
        $this->actingAs($this->officer());

        Livewire::test(InterestDistributions::class)
            ->call('openForm')
            ->set('form.period_label', '2025/2026')
            ->set('form.period_end_date', $this->periodEnd)
            ->call('compute')
            ->assertHasErrors('form.period_end_date');

        $this->assertSame(0, CreditUnionInterestDistribution::query()->count());
    }

    public function test_an_officer_without_the_approve_permission_cannot_approve_or_post(): void
    {
        $member = $this->memberWithBalance('D01101', savings: 1000);
        $this->poolOf1500_75($member);

        $distribution = $this->computeRun();
        $officer = $this->officer();

        $this->assertFalse($officer->hasPermission('credit_union.approve_interest_distribution'));
        $this->actingAs($officer);

        Livewire::test(InterestDistributionShow::class, ['distribution' => $distribution])
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(CreditUnionInterestDistribution::STATUS_COMPUTED, $distribution->refresh()->status);
    }

    public function test_any_committee_member_may_approve_a_run_they_computed_themselves(): void
    {
        $member = $this->memberWithBalance('D01201', savings: 1000);
        $this->poolOf1500_75($member);

        // A union-wide batch has no applicant, so no self-approval guard applies here.
        $committee = $this->committeeMember('D01202');
        $committee->roles()->syncWithoutDetaching(
            \App\Models\Role::query()->where('name', 'credit_union_officer')->firstOrFail()
        );
        $committee = $committee->fresh();

        $distribution = app(InterestDistributionService::class)->compute(
            '2025/2026',
            $this->periodEnd,
            $this->periodStart,
            $committee->id
        );

        $this->actingAs($committee);

        Livewire::test(InterestDistributionShow::class, ['distribution' => $distribution])
            ->call('approve')
            ->assertHasNoErrors();

        $this->assertSame(CreditUnionInterestDistribution::STATUS_APPROVED, $distribution->refresh()->status);
        $this->assertSame($committee->id, $distribution->computed_by);
        $this->assertSame($committee->id, $distribution->approved_by);
    }

    public function test_the_distribution_lifecycle_is_audit_logged(): void
    {
        $member = $this->memberWithBalance('D01301', savings: 1000);
        $this->poolOf1500_75($member);

        $distribution = $this->computeRun();
        $distributions = app(InterestDistributionService::class);
        $distributions->approve($distribution, $this->committeeMember());
        $distributions->post($distribution->refresh(), $this->officer()->id);

        foreach ([
            'credit_union.interest_distribution_computed',
            'credit_union.interest_distribution_approved',
            'credit_union.interest_distribution_posted',
        ] as $action) {
            $this->assertTrue(
                AuditLog::query()
                    ->where('module', Permission::MODULE_CREDIT_UNION)
                    ->where('action', $action)
                    ->exists(),
                $action.' was not audit logged.'
            );
        }

        $log = AuditLog::query()
            ->where('action', 'credit_union.interest_distribution_posted')
            ->latest('id')
            ->firstOrFail();

        $this->assertEquals(1500.75, $log->metadata['amount_posted'] ?? null);
    }

    public function test_an_officer_can_load_the_interest_distribution_screens(): void
    {
        $member = $this->memberWithBalance('D01401', savings: 1000);
        $this->poolOf1500_75($member);

        $distribution = $this->computeRun();
        $officer = $this->officer();

        $this->actingAs($officer)->get(route('credit-union.interest-distributions'))
            ->assertOk()
            ->assertSee('Distribution Runs');

        $this->actingAs($officer)->get(route('credit-union.interest-distributions.show', $distribution))
            ->assertOk()
            ->assertSee('Member Allocations');
    }

    public function test_a_user_with_neither_distribution_permission_cannot_reach_the_screens(): void
    {
        $employee = $this->employeeUser($this->createEmployee('D01501', 'Plain Employee'));

        $this->actingAs($employee)->get(route('credit-union.interest-distributions'))->assertForbidden();
    }

    /**
     * Runs the compute step through the Livewire screen as an officer.
     */
    protected function computeRun(string $periodLabel = '2025/2026'): CreditUnionInterestDistribution
    {
        $this->actingAs($this->officer());

        Livewire::test(InterestDistributions::class)
            ->call('openForm')
            ->set('form.period_label', $periodLabel)
            ->set('form.period_start_date', $this->periodStart)
            ->set('form.period_end_date', $this->periodEnd)
            ->call('compute')
            ->assertHasNoErrors();

        return CreditUnionInterestDistribution::query()->latest('id')->firstOrFail();
    }

    /**
     * Two in-period loans whose interest adds up to 1500.75 - a pool that provokes the
     * per-line rounding drift this phase has to absorb.
     */
    protected function poolOf1500_75(CreditUnionMember $member): void
    {
        $this->loanWithInterest($member, 500.25, today()->subMonths(3));
        $this->loanWithInterest($member, 1000.50, today()->subMonths(8));
    }

    protected function loanWithInterest(CreditUnionMember $member, float $interest, $disbursedAt): CreditUnionLoan
    {
        return CreditUnionLoan::factory()->forMember($member)->create([
            'interest_amount' => $interest,
            'disbursed_at' => $disbursedAt,
            'status' => CreditUnionLoan::STATUS_COMPLETED,
        ]);
    }
}
