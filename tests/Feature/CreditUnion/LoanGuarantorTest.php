<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\LoanShow;
use App\Models\AuditLog;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionLoanGuarantor;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\LoanService;
use App\Services\CreditUnion\MemberEligibilityService;
use Livewire\Livewire;

class LoanGuarantorTest extends CreditUnionTestCase
{
    public function test_a_guarantor_who_is_mid_loan_is_rejected_with_has_active_loan(): void
    {
        [$loan] = $this->loanNeedingGuarantor('700101');

        // The candidate is carrying a disbursed loan of their own.
        $candidate = $this->memberWithBalance('700102', savings: 50000);
        CreditUnionLoan::factory()->forMember($candidate)->disbursed()->create();

        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('guarantorForm.member_id', $candidate->id)
            ->set('guarantorForm.guaranteed_amount', '2000')
            ->call('addGuarantor')
            ->assertHasNoErrors();

        $guarantor = $loan->guarantors()->where('guarantor_member_id', $candidate->id)->firstOrFail();

        $this->assertSame(CreditUnionLoanGuarantor::STATUS_DECLINED, $guarantor->status);
        $this->assertSame(MemberEligibilityService::REASON_HAS_ACTIVE_LOAN, $guarantor->disqualified_reason);
        $this->assertFalse($guarantor->was_in_good_standing);
        $this->assertSame(0.0, $loan->refresh()->acceptedGuaranteeTotal());
    }

    public function test_a_guarantor_whose_holdings_are_too_small_is_rejected_with_insufficient_balance(): void
    {
        [$loan] = $this->loanNeedingGuarantor('700201');

        // 500 savings + 100 shares = 600 combined, well under the 2000 being guaranteed.
        $candidate = $this->memberWithBalance('700202', savings: 500, shares: 100);

        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('guarantorForm.member_id', $candidate->id)
            ->set('guarantorForm.guaranteed_amount', '2000')
            ->call('addGuarantor')
            ->assertHasNoErrors();

        $guarantor = $loan->guarantors()->where('guarantor_member_id', $candidate->id)->firstOrFail();

        $this->assertSame(CreditUnionLoanGuarantor::STATUS_DECLINED, $guarantor->status);
        $this->assertSame(MemberEligibilityService::REASON_INSUFFICIENT_BALANCE, $guarantor->disqualified_reason);
        $this->assertSame('600.00', $guarantor->guarantor_asset_balance_at_guarantee);
    }

    public function test_an_eligible_guarantor_is_recorded_as_pending_with_their_balance_snapshot(): void
    {
        [$loan] = $this->loanNeedingGuarantor('700301');
        $candidate = $this->memberWithBalance('700302', savings: 2500, shares: 400);

        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('guarantorForm.member_id', $candidate->id)
            ->set('guarantorForm.guaranteed_amount', '2000')
            ->call('addGuarantor')
            ->assertHasNoErrors();

        $guarantor = $loan->guarantors()->where('guarantor_member_id', $candidate->id)->firstOrFail();

        $this->assertSame(CreditUnionLoanGuarantor::STATUS_PENDING, $guarantor->status);
        $this->assertNull($guarantor->disqualified_reason);
        $this->assertTrue($guarantor->was_in_good_standing);
        $this->assertSame('2900.00', $guarantor->guarantor_asset_balance_at_guarantee);
        $this->assertNotNull($guarantor->eligibility_checked_at);
    }

    public function test_two_guarantors_can_split_the_shortfall_between_them(): void
    {
        [$loan] = $this->loanNeedingGuarantor('700401');

        $first = $this->memberWithBalance('700402', savings: 1500);
        $second = $this->memberWithBalance('700403', savings: 1500);

        $this->actingAs($this->officer());

        $component = Livewire::test(LoanShow::class, ['loan' => $loan]);

        // 1200 + 800 = the full 2000 shortfall.
        $component
            ->set('guarantorForm.member_id', $first->id)
            ->set('guarantorForm.guaranteed_amount', '1200')
            ->call('addGuarantor')
            ->assertHasNoErrors();

        $component
            ->set('guarantorForm.member_id', $second->id)
            ->set('guarantorForm.guaranteed_amount', '800')
            ->call('addGuarantor')
            ->assertHasNoErrors();

        $firstRow = $loan->guarantors()->where('guarantor_member_id', $first->id)->firstOrFail();
        $secondRow = $loan->guarantors()->where('guarantor_member_id', $second->id)->firstOrFail();

        $this->assertSame(2000.0, $loan->refresh()->guaranteeShortfallRemaining());

        $component->call('acceptGuarantor', $firstRow->id)->assertHasNoErrors();
        $this->assertSame(800.0, $loan->refresh()->guaranteeShortfallRemaining());
        $this->assertFalse($loan->isFullyGuaranteed());

        $component->call('acceptGuarantor', $secondRow->id)->assertHasNoErrors();

        $loan->refresh()->load('guarantors');
        $this->assertSame(2000.0, $loan->acceptedGuaranteeTotal());
        $this->assertSame(0.0, $loan->guaranteeShortfallRemaining());
        $this->assertTrue($loan->isFullyGuaranteed());
    }

    public function test_a_declined_guarantor_does_not_count_toward_the_shortfall(): void
    {
        [$loan] = $this->loanNeedingGuarantor('700501');
        $candidate = $this->memberWithBalance('700502', savings: 5000);

        $this->actingAs($this->officer());

        $component = Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('guarantorForm.member_id', $candidate->id)
            ->set('guarantorForm.guaranteed_amount', '2000')
            ->call('addGuarantor')
            ->assertHasNoErrors();

        $guarantor = $loan->guarantors()->where('guarantor_member_id', $candidate->id)->firstOrFail();

        $component->call('declineGuarantor', $guarantor->id)->assertHasNoErrors();

        $this->assertSame(CreditUnionLoanGuarantor::STATUS_DECLINED, $guarantor->refresh()->status);
        $this->assertSame(0.0, $loan->refresh()->acceptedGuaranteeTotal());
        $this->assertFalse($loan->isFullyGuaranteed());
    }

    public function test_a_member_cannot_guarantee_their_own_loan(): void
    {
        [$loan, $borrower] = $this->loanNeedingGuarantor('700601');

        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('guarantorForm.member_id', $borrower->id)
            ->set('guarantorForm.guaranteed_amount', '2000')
            ->call('addGuarantor')
            ->assertHasErrors('guarantorForm.member_id');

        $this->assertSame(0, $loan->guarantors()->count());
    }

    public function test_the_same_member_cannot_be_proposed_twice_on_one_loan(): void
    {
        [$loan] = $this->loanNeedingGuarantor('700701');
        $candidate = $this->memberWithBalance('700702', savings: 5000);

        $this->actingAs($this->officer());

        $component = Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('guarantorForm.member_id', $candidate->id)
            ->set('guarantorForm.guaranteed_amount', '1000')
            ->call('addGuarantor')
            ->assertHasNoErrors();

        $component
            ->set('guarantorForm.member_id', $candidate->id)
            ->set('guarantorForm.guaranteed_amount', '1000')
            ->call('addGuarantor')
            ->assertHasErrors('guarantorForm.member_id');

        $this->assertSame(1, $loan->guarantors()->count());
    }

    public function test_a_loan_within_the_limit_rejects_guarantors_outright(): void
    {
        $member = $this->memberWithBalance('700801', savings: 5000);
        $loan = app(LoanService::class)->apply($member, ['principal_amount' => 2000, 'term_months' => 12]);
        $candidate = $this->memberWithBalance('700802', savings: 5000);

        $this->assertFalse($loan->requires_guarantor);
        $this->actingAs($this->officer());

        Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('guarantorForm.member_id', $candidate->id)
            ->set('guarantorForm.guaranteed_amount', '1000')
            ->call('addGuarantor')
            ->assertHasErrors('guarantorForm.member_id');
    }

    public function test_guarantor_activity_is_audit_logged(): void
    {
        [$loan] = $this->loanNeedingGuarantor('700901');
        $candidate = $this->memberWithBalance('700902', savings: 5000);

        $this->actingAs($this->officer());

        $component = Livewire::test(LoanShow::class, ['loan' => $loan])
            ->set('guarantorForm.member_id', $candidate->id)
            ->set('guarantorForm.guaranteed_amount', '2000')
            ->call('addGuarantor');

        $guarantor = $loan->guarantors()->where('guarantor_member_id', $candidate->id)->firstOrFail();
        $component->call('acceptGuarantor', $guarantor->id);

        foreach (['credit_union.loan_guarantor_proposed', 'credit_union.loan_guarantor_accepted'] as $action) {
            $this->assertTrue(
                AuditLog::query()
                    ->where('module', Permission::MODULE_CREDIT_UNION)
                    ->where('action', $action)
                    ->exists(),
                $action.' was not audit logged.'
            );
        }
    }

    /**
     * A 6000 loan against 2000 savings: a 4000 no-guarantor limit and a 2000 shortfall.
     *
     * @return array{0: CreditUnionLoan, 1: CreditUnionMember}
     */
    protected function loanNeedingGuarantor(string $staffId): array
    {
        $borrower = $this->memberWithBalance($staffId, savings: 2000);
        $loan = app(LoanService::class)->apply($borrower, [
            'principal_amount' => 6000,
            'term_months' => 12,
        ]);

        $this->assertTrue($loan->requires_guarantor);
        $this->assertSame('2000.00', $loan->guarantor_shortfall);

        return [$loan, $borrower];
    }
}
