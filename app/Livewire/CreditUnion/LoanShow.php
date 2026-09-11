<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionLoanGuarantor;
use App\Models\CreditUnionLoanRepayment;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Models\User;
use App\Services\CreditUnion\LoanService;
use App\Services\CreditUnion\MemberEligibilityService;
use Livewire\Component;

class LoanShow extends Component
{
    use EnforcesModuleAccess;

    public CreditUnionLoan $loan;

    public string $rejectionReason = '';

    public bool $showReject = false;

    public array $guarantorForm = [
        'member_id' => '',
        'guaranteed_amount' => '',
    ];

    public array $disbursementForm = [
        'disbursed_at' => '',
        'disbursement_reference' => '',
    ];

    public array $repaymentForm = [
        'amount' => '',
        'repayment_date' => '',
        'source' => CreditUnionLoanRepayment::SOURCE_CASH,
        'reference_no' => '',
    ];

    public function mount(CreditUnionLoan $loan): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardCanView();

        $this->loan = $loan;
        $this->disbursementForm['disbursed_at'] = today()->toDateString();
        $this->repaymentForm['repayment_date'] = today()->toDateString();
    }

    public function addGuarantor(LoanService $loans): void
    {
        $this->guardManageLoans();

        $validated = $this->validate([
            'guarantorForm.member_id' => ['required', 'integer', 'exists:credit_union_members,id'],
            'guarantorForm.guaranteed_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $candidate = CreditUnionMember::query()->findOrFail($validated['guarantorForm']['member_id']);

        $guarantor = $loans->addGuarantor(
            $this->loan,
            $candidate,
            (float) $validated['guarantorForm']['guaranteed_amount'],
            auth()->id()
        );

        $this->guarantorForm = ['member_id' => '', 'guaranteed_amount' => ''];
        $this->loan->refresh();

        if ($guarantor->wasDisqualified()) {
            $this->dispatch('toast', type: 'error', message: app(MemberEligibilityService::class)->describeReason($guarantor->disqualified_reason));

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Guarantor request recorded.');
    }

    public function acceptGuarantor(int $guarantorId, LoanService $loans): void
    {
        $this->guardManageLoans();

        $loans->acceptGuarantor($this->guarantorOrFail($guarantorId), auth()->id());

        $this->loan->refresh();
        $this->dispatch('toast', type: 'success', message: 'Guarantor accepted.');
    }

    public function declineGuarantor(int $guarantorId, LoanService $loans): void
    {
        $this->guardManageLoans();

        $loans->declineGuarantor($this->guarantorOrFail($guarantorId), auth()->id());

        $this->loan->refresh();
        $this->dispatch('toast', type: 'success', message: 'Guarantor declined.');
    }

    public function approve(LoanService $loans): void
    {
        $approver = $this->guardCanDecide();

        $loans->approve($this->loan, $approver);

        $this->loan->refresh();
        $this->dispatch('toast', type: 'success', message: 'Loan approved.');
    }

    public function startReject(): void
    {
        $this->guardCanDecide();
        $this->showReject = true;
        $this->rejectionReason = '';
    }

    public function cancelReject(): void
    {
        $this->showReject = false;
        $this->rejectionReason = '';
    }

    public function reject(LoanService $loans): void
    {
        $approver = $this->guardCanDecide();

        $this->validate(['rejectionReason' => ['required', 'string', 'max:2000']]);

        $loans->reject($this->loan, $approver, $this->rejectionReason);

        $this->cancelReject();
        $this->loan->refresh();
        $this->dispatch('toast', type: 'success', message: 'Loan rejected.');
    }

    public function disburse(LoanService $loans): void
    {
        $this->guardManageLoans();

        $validated = $this->validate([
            'disbursementForm.disbursed_at' => ['required', 'date'],
            'disbursementForm.disbursement_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $loans->disburse($this->loan, $validated['disbursementForm'], auth()->id());

        $this->loan->refresh();
        $this->dispatch('toast', type: 'success', message: 'Loan disbursed.');
    }

    public function recordRepayment(LoanService $loans): void
    {
        $this->guardManageLoans();

        $validated = $this->validate([
            'repaymentForm.amount' => ['required', 'numeric', 'min:0.01'],
            'repaymentForm.repayment_date' => ['required', 'date'],
            'repaymentForm.source' => ['required', 'in:'.implode(',', CreditUnionLoanRepayment::SOURCES)],
            'repaymentForm.reference_no' => ['nullable', 'string', 'max:100'],
        ]);

        $loans->recordRepayment($this->loan, $validated['repaymentForm'], auth()->id());

        $this->loan->refresh();
        $this->repaymentForm['amount'] = '';
        $this->repaymentForm['reference_no'] = '';

        $this->dispatch('toast', type: 'success', message: 'Repayment recorded.');
    }

    protected function guarantorOrFail(int $guarantorId): CreditUnionLoanGuarantor
    {
        return $this->loan->guarantors()->whereKey($guarantorId)->firstOrFail();
    }

    protected function guardCanView(): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(403);
        }

        $allowed = $user->hasRoles('super_admin')
            || $user->hasPermission('credit_union.manage_loans')
            || $user->hasPermission('credit_union.approve_loans');

        if (! $allowed) {
            abort(403);
        }
    }

    protected function guardManageLoans(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.manage_loans'))) {
            abort(403);
        }
    }

    /**
     * Deciding a loan is committee business specifically, and never on your own
     * application - the same self-approval rule LoanService enforces again on write.
     */
    protected function guardCanDecide(): User
    {
        $user = auth()->user();

        if (! $user) {
            abort(403);
        }

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.approve_loans')) {
            abort(403, 'Loan approval requires the credit union committee permission.');
        }

        if (app(LoanService::class)->isSelfApproval($this->loan, $user)) {
            abort(403, 'You cannot decide on your own loan application.');
        }

        return $user;
    }

    public function render()
    {
        $this->loan->loadMissing(['member', 'guarantors.guarantor', 'repayments.recorder', 'applicant', 'approver']);

        $user = auth()->user();
        $canDecide = $user && ($user->hasRoles('super_admin') || $user->hasPermission('credit_union.approve_loans'));
        $isOwnApplication = $user && app(LoanService::class)->isSelfApproval($this->loan, $user);

        return view('livewire.credit-union.loan-show', [
            'guarantorCandidates' => CreditUnionMember::query()
                ->active()
                ->where('id', '!=', $this->loan->member_id)
                ->orderBy('full_name')
                ->get(['id', 'member_number', 'full_name']),
            'repaymentSources' => CreditUnionLoanRepayment::sourcesFor($this->loan->member),
            'acceptedTotal' => $this->loan->acceptedGuaranteeTotal(),
            'shortfallRemaining' => $this->loan->guaranteeShortfallRemaining(),
            'canDecide' => $canDecide && ! $isOwnApplication,
            'isOwnApplication' => $isOwnApplication,
        ]);
    }
}
