<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\CreditUnionRefund;
use App\Models\Permission;
use App\Services\CreditUnion\RefundService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Refunds extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $search = '';

    public bool $showForm = false;

    public array $form = [
        'member_id' => '',
        'account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
        'amount' => '',
        'reason' => '',
        'refunded_at' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardManageRefunds();

        $this->form['refunded_at'] = today()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        $this->guardManageRefunds();
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(RefundService $refunds): void
    {
        $this->guardManageRefunds();

        $validated = $this->validate([
            'form.member_id' => ['required', 'integer', 'exists:credit_union_members,id'],
            'form.account_type' => ['required', 'in:'.implode(',', CreditUnionLedgerEntry::ACCOUNT_TYPES)],
            'form.amount' => ['required', 'numeric', 'min:0.01'],
            'form.reason' => ['required', 'string', 'max:255'],
            'form.refunded_at' => ['required', 'date'],
        ]);

        $member = CreditUnionMember::query()->findOrFail($validated['form']['member_id']);

        // No approval step by design: manage_refunds is the only permission involved.
        $refunds->record($member, $validated['form'], auth()->id());

        $this->closeForm();
        $this->dispatch('toast', type: 'success', message: 'Refund recorded and credited to the member ledger.');
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->form = [
            'member_id' => '',
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
            'amount' => '',
            'reason' => '',
            'refunded_at' => today()->toDateString(),
        ];
    }

    protected function guardManageRefunds(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.manage_refunds'))) {
            abort(403);
        }
    }

    public function render()
    {
        return view('livewire.credit-union.refunds', [
            'refunds' => CreditUnionRefund::query()
                ->with(['member', 'recorder'])
                ->when($this->search !== '', function (Builder $query) {
                    $term = '%'.$this->search.'%';
                    $query->where('reason', 'like', $term)
                        ->orWhereHas('member', fn (Builder $member) => $member
                            ->where('full_name', 'like', $term)
                            ->orWhere('member_number', 'like', $term));
                })
                ->orderByDesc('refunded_at')
                ->orderByDesc('id')
                ->paginate(15),
            'members' => CreditUnionMember::query()->active()->orderBy('full_name')->get(['id', 'member_number', 'full_name']),
            'accountTypes' => CreditUnionLedgerEntry::ACCOUNT_TYPES,
        ]);
    }
}
