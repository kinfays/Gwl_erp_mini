<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionMember;
use App\Models\CreditUnionWithdrawalRequest;
use App\Models\Permission;
use App\Services\CreditUnion\LedgerService;
use App\Services\CreditUnion\WithdrawalService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Withdrawals extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showForm = false;

    public array $form = [
        'member_id' => '',
        'savings_amount' => '',
        'shares_amount' => '',
        'reason' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardCanView();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        $this->guardManageWithdrawals();
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(WithdrawalService $withdrawals): void
    {
        $this->guardManageWithdrawals();

        $validated = $this->validate([
            'form.member_id' => ['required', 'integer', 'exists:credit_union_members,id'],
            'form.savings_amount' => ['nullable', 'numeric', 'min:0'],
            'form.shares_amount' => ['nullable', 'numeric', 'min:0'],
            'form.reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $member = CreditUnionMember::query()->findOrFail($validated['form']['member_id']);
        $withdrawal = $withdrawals->request($member, $validated['form'], auth()->id());

        $this->closeForm();
        $this->dispatch('toast', type: 'success', message: 'Withdrawal request raised for '.$member->member_number.'.');

        $this->redirectRoute('credit-union.withdrawals.show', $withdrawal, navigate: false);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->form = [
            'member_id' => '',
            'savings_amount' => '',
            'shares_amount' => '',
            'reason' => '',
        ];
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

    public function render(LedgerService $ledger)
    {
        $selectedMember = $this->form['member_id'] !== ''
            ? CreditUnionMember::query()->find($this->form['member_id'])
            : null;

        return view('livewire.credit-union.withdrawals', [
            'withdrawals' => CreditUnionWithdrawalRequest::query()
                ->with('member')
                ->when($this->search !== '', function (Builder $query) {
                    $term = '%'.$this->search.'%';
                    $query->whereHas('member', fn (Builder $member) => $member
                        ->where('full_name', 'like', $term)
                        ->orWhere('member_number', 'like', $term));
                })
                ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', $this->statusFilter))
                ->orderByDesc('id')
                ->paginate(15),
            'members' => CreditUnionMember::query()->active()->orderBy('full_name')->get(['id', 'member_number', 'full_name']),
            'statuses' => CreditUnionWithdrawalRequest::STATUSES,
            'selectedMember' => $selectedMember,
            'selectedBalances' => $selectedMember ? $ledger->balances($selectedMember) : null,
        ]);
    }
}
