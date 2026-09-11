<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\Loans;
use App\Models\AuditLog;
use App\Models\CreditUnionLoan;
use App\Models\Permission;
use Livewire\Livewire;

class LoanApplicationTest extends CreditUnionTestCase
{
    public function test_a_loan_within_twice_savings_needs_no_guarantor(): void
    {
        $member = $this->memberWithBalance('600101', savings: 3000);
        $this->actingAs($this->officer());

        Livewire::test(Loans::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.principal_amount', '5000')
            ->set('form.term_months', '12')
            ->call('save')
            ->assertHasNoErrors();

        $loan = CreditUnionLoan::query()->where('member_id', $member->id)->firstOrFail();

        // 3000 savings x 2 = 6000 limit, so 5000 is inside it.
        $this->assertSame('3000.00', $loan->savings_balance_at_application);
        $this->assertSame('6000.00', $loan->no_guarantor_limit);
        $this->assertSame('0.00', $loan->guarantor_shortfall);
        $this->assertFalse($loan->requires_guarantor);
        $this->assertSame(CreditUnionLoan::STATUS_PENDING, $loan->status);
    }

    public function test_a_loan_above_twice_savings_computes_the_shortfall_and_awaits_a_guarantor(): void
    {
        $member = $this->memberWithBalance('600201', savings: 2000);
        $this->actingAs($this->officer());

        Livewire::test(Loans::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.principal_amount', '6000')
            ->set('form.term_months', '12')
            ->call('save')
            ->assertHasNoErrors();

        $loan = CreditUnionLoan::query()->where('member_id', $member->id)->firstOrFail();

        // 2000 x 2 = 4000 limit; the 2000 above it needs guarantor cover.
        $this->assertSame('4000.00', $loan->no_guarantor_limit);
        $this->assertSame('2000.00', $loan->guarantor_shortfall);
        $this->assertTrue($loan->requires_guarantor);
        $this->assertSame(CreditUnionLoan::STATUS_AWAITING_GUARANTOR, $loan->status);
    }

    public function test_straight_line_interest_and_installments_use_the_confirmed_fifteen_percent_rate(): void
    {
        $member = $this->memberWithBalance('600301', savings: 10000);
        $this->actingAs($this->officer());

        Livewire::test(Loans::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.principal_amount', '12000')
            ->set('form.term_months', '18')
            ->call('save')
            ->assertHasNoErrors();

        $loan = CreditUnionLoan::query()->where('member_id', $member->id)->firstOrFail();

        // 12000 x 15% x (18/12) = 2700 interest; 14700 repayable over 18 months.
        $this->assertSame('15.00', $loan->interest_rate);
        $this->assertSame('2700.00', $loan->interest_amount);
        $this->assertSame('14700.00', $loan->total_repayable);
        $this->assertSame('816.67', $loan->monthly_installment_amount);
        $this->assertSame('14700.00', $loan->outstanding_balance);
    }

    public function test_a_twelve_month_loan_carries_exactly_one_year_of_interest(): void
    {
        $member = $this->memberWithBalance('600401', savings: 10000);
        $this->actingAs($this->officer());

        Livewire::test(Loans::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.principal_amount', '4000')
            ->set('form.term_months', '12')
            ->call('save')
            ->assertHasNoErrors();

        $loan = CreditUnionLoan::query()->where('member_id', $member->id)->firstOrFail();

        $this->assertSame('600.00', $loan->interest_amount);
        $this->assertSame('4600.00', $loan->total_repayable);
        $this->assertSame('383.33', $loan->monthly_installment_amount);
    }

    public function test_a_member_with_an_outstanding_loan_cannot_raise_another(): void
    {
        $member = $this->memberWithBalance('600501', savings: 10000);
        CreditUnionLoan::factory()->forMember($member)->disbursed()->create();

        $this->actingAs($this->officer());

        Livewire::test(Loans::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.principal_amount', '1000')
            ->set('form.term_months', '12')
            ->call('save')
            ->assertHasErrors('form.member_id');

        $this->assertSame(1, CreditUnionLoan::query()->where('member_id', $member->id)->count());
    }

    public function test_loan_numbers_are_sequential(): void
    {
        $first = $this->memberWithBalance('600601', savings: 10000);
        $second = $this->memberWithBalance('600602', savings: 10000);
        $this->actingAs($this->officer());

        foreach ([$first, $second] as $member) {
            Livewire::test(Loans::class)
                ->call('openForm')
                ->set('form.member_id', $member->id)
                ->set('form.principal_amount', '1000')
                ->set('form.term_months', '12')
                ->call('save')
                ->assertHasNoErrors();
        }

        $numbers = CreditUnionLoan::query()->orderBy('id')->pluck('loan_number')->all();

        $this->assertSame('CUL-'.now()->year.'-0001', $numbers[0]);
        $this->assertSame('CUL-'.now()->year.'-0002', $numbers[1]);
    }

    public function test_the_application_is_audit_logged(): void
    {
        $member = $this->memberWithBalance('600701', savings: 2000);
        $this->actingAs($this->officer());

        Livewire::test(Loans::class)
            ->call('openForm')
            ->set('form.member_id', $member->id)
            ->set('form.principal_amount', '6000')
            ->set('form.term_months', '12')
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.loan_applied')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(CreditUnionLoan::class, $log->target_type);
        $this->assertEquals(6000.0, $log->metadata['principal_amount'] ?? null);
        $this->assertEquals(2000.0, $log->metadata['guarantor_shortfall'] ?? null);
        $this->assertTrue($log->metadata['requires_guarantor'] ?? false);
    }

    public function test_an_officer_can_load_the_loan_screens(): void
    {
        $member = $this->memberWithBalance('600801', savings: 5000);
        $loan = CreditUnionLoan::factory()->forMember($member)->create();

        $officer = $this->officer();

        $this->actingAs($officer)->get(route('credit-union.loans'))->assertOk()->assertSee('Loan Register');
        $this->actingAs($officer)->get(route('credit-union.loans.show', $loan))
            ->assertOk()
            ->assertSee($loan->loan_number);
    }

    public function test_a_user_with_neither_loan_permission_cannot_reach_the_screens(): void
    {
        $member = $this->memberWithBalance('600901', savings: 5000);
        $loan = CreditUnionLoan::factory()->forMember($member)->create();

        $employee = $this->employeeUser($this->createEmployee('600902', 'Plain Employee'));

        $this->actingAs($employee)->get(route('credit-union.loans'))->assertForbidden();
        $this->actingAs($employee)->get(route('credit-union.loans.show', $loan))->assertForbidden();
    }
}
