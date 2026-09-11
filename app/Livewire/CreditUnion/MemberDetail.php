<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\LedgerService;
use Livewire\Component;
use Livewire\WithPagination;

class MemberDetail extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public CreditUnionMember $member;

    public string $accountFilter = '';

    public array $form = [
        'account_type' => CreditUnionLedgerEntry::ACCOUNT_SAVINGS,
        'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
        'amount' => '',
        'transaction_date' => '',
        'source' => CreditUnionLedgerEntry::SOURCE_CASH,
        'reference_no' => '',
        'remarks' => '',
    ];

    public function mount(CreditUnionMember $member): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardManageMembers();

        $this->member = $member;
        $this->form['transaction_date'] = today()->toDateString();
    }

    public function updatedAccountFilter(): void
    {
        $this->resetPage();
    }

    public function postEntry(LedgerService $ledger): void
    {
        $this->guardManageMembers();

        $validated = $this->validate([
            'form.account_type' => ['required', 'in:'.implode(',', CreditUnionLedgerEntry::ACCOUNT_TYPES)],
            'form.entry_type' => ['required', 'in:'.implode(',', CreditUnionLedgerEntry::ENTRY_TYPES)],
            'form.amount' => ['required', 'numeric'],
            'form.transaction_date' => ['required', 'date'],
            'form.source' => ['required', 'in:'.implode(',', CreditUnionLedgerEntry::SOURCES)],
            'form.reference_no' => ['nullable', 'string', 'max:100'],
            'form.remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $ledger->post($this->member, $validated['form'], auth()->id());

        $this->member->refresh();
        $this->form['amount'] = '';
        $this->form['reference_no'] = '';
        $this->form['remarks'] = '';

        $this->dispatch('toast', type: 'success', message: 'Ledger entry posted.');
    }

    protected function guardManageMembers(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.manage_members'))) {
            abort(403);
        }
    }

    public function render()
    {
        $entries = $this->member->ledgerEntries()
            ->with('recorder')
            ->when($this->accountFilter !== '', fn ($query) => $query->where('account_type', $this->accountFilter))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.credit-union.member-detail', [
            'entries' => $entries,
            'balances' => app(LedgerService::class)->balances($this->member),
            'accountTypes' => CreditUnionLedgerEntry::ACCOUNT_TYPES,
            'entryTypes' => CreditUnionLedgerEntry::ENTRY_TYPES,
            'sources' => $this->member->allowedLedgerSources(),
        ]);
    }
}
