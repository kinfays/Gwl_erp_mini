<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionWithdrawalRequest;
use App\Models\Permission;
use App\Models\User;
use App\Services\CreditUnion\LedgerService;
use App\Services\CreditUnion\WithdrawalService;
use Livewire\Component;

class WithdrawalShow extends Component
{
    use EnforcesModuleAccess;

    public CreditUnionWithdrawalRequest $withdrawal;

    public bool $showReject = false;

    public string $rejectionReason = '';

    public array $paymentForm = [
        'payment_method' => CreditUnionWithdrawalRequest::METHOD_CASH,
        'payment_reference' => '',
        'paid_at' => '',
    ];

    public function mount(CreditUnionWithdrawalRequest $withdrawal): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardCanView();

        $this->withdrawal = $withdrawal;
        $this->paymentForm['paid_at'] = today()->toDateString();
    }

    public function approve(WithdrawalService $withdrawals): void
    {
        $approver = $this->guardCanDecide();

        $withdrawals->approve($this->withdrawal, $approver);

        $this->withdrawal->refresh();
        $this->dispatch('toast', type: 'success', message: 'Withdrawal approved.');
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

    public function reject(WithdrawalService $withdrawals): void
    {
        $approver = $this->guardCanDecide();

        $this->validate(['rejectionReason' => ['required', 'string', 'max:2000']]);

        $withdrawals->reject($this->withdrawal, $approver, $this->rejectionReason);

        $this->cancelReject();
        $this->withdrawal->refresh();
        $this->dispatch('toast', type: 'success', message: 'Withdrawal rejected.');
    }

    public function markPaid(WithdrawalService $withdrawals): void
    {
        $this->guardManageWithdrawals();

        $validated = $this->validate([
            'paymentForm.payment_method' => ['required', 'in:'.implode(',', CreditUnionWithdrawalRequest::PAYMENT_METHODS)],
            'paymentForm.payment_reference' => ['nullable', 'string', 'max:100'],
            'paymentForm.paid_at' => ['required', 'date'],
        ]);

        $withdrawals->markPaid($this->withdrawal, $validated['paymentForm'], auth()->id());

        $this->withdrawal->refresh();
        $this->dispatch('toast', type: 'success', message: 'Withdrawal paid and posted to the member ledger.');
    }

    protected function guardCanView(): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(403);
        }

        $allowed = $user->hasRoles('super_admin')
            || $user->hasPermission('credit_union.manage_withdrawals')
            || $user->hasPermission('credit_union.approve_withdrawals');

        if (! $allowed) {
            abort(403);
        }
    }

    protected function guardManageWithdrawals(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.manage_withdrawals'))) {
            abort(403);
        }
    }

    /**
     * Deciding a withdrawal is committee business specifically, and never on your own
     * request - the same self-approval rule WithdrawalService enforces again on write.
     */
    protected function guardCanDecide(): User
    {
        $user = auth()->user();

        if (! $user) {
            abort(403);
        }

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.approve_withdrawals')) {
            abort(403, 'Withdrawal approval requires the credit union committee permission.');
        }

        if (app(WithdrawalService::class)->isSelfApproval($this->withdrawal, $user)) {
            abort(403, 'You cannot decide on your own withdrawal request.');
        }

        return $user;
    }

    public function render(LedgerService $ledger)
    {
        $this->withdrawal->loadMissing(['member', 'requester', 'decider', 'ledgerEntries']);

        $user = auth()->user();
        $canDecide = $user && ($user->hasRoles('super_admin') || $user->hasPermission('credit_union.approve_withdrawals'));
        $isOwnRequest = $user && app(WithdrawalService::class)->isSelfApproval($this->withdrawal, $user);

        return view('livewire.credit-union.withdrawal-show', [
            'balances' => $ledger->balances($this->withdrawal->member),
            'paymentMethods' => CreditUnionWithdrawalRequest::paymentMethodsFor($this->withdrawal->member),
            'canDecide' => $canDecide && ! $isOwnRequest,
            'isOwnRequest' => $isOwnRequest,
        ]);
    }
}
