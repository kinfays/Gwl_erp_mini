<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionManualReceipt;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\ManualReceiptService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Receipts extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $search = '';

    public string $purposeFilter = '';

    public string $bankedFilter = '';

    public bool $showForm = false;

    public array $form = [
        'member_id' => '',
        'method' => CreditUnionManualReceipt::METHOD_CASH,
        'purpose' => CreditUnionManualReceipt::PURPOSE_SAVINGS,
        'cheque_no' => '',
        'payer_name' => '',
        'amount' => '',
        'received_date' => '',
        'banked' => true,
        'remarks' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardManageReceipts();

        $this->form['received_date'] = today()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPurposeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBankedFilter(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        $this->guardManageReceipts();
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(ManualReceiptService $receipts): void
    {
        $this->guardManageReceipts();

        $validated = $this->validate([
            'form.member_id' => ['nullable', 'integer', 'exists:credit_union_members,id'],
            'form.method' => ['required', 'in:'.implode(',', CreditUnionManualReceipt::METHODS)],
            'form.purpose' => ['required', 'in:'.implode(',', CreditUnionManualReceipt::PURPOSES)],
            'form.cheque_no' => ['nullable', 'string', 'max:100'],
            'form.payer_name' => ['nullable', 'string', 'max:255'],
            'form.amount' => ['required', 'numeric', 'min:0.01'],
            'form.received_date' => ['required', 'date'],
            'form.banked' => ['boolean'],
            'form.remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $member = $validated['form']['member_id'] !== '' && $validated['form']['member_id'] !== null
            ? CreditUnionMember::query()->findOrFail($validated['form']['member_id'])
            : null;

        $receipt = $receipts->record($member, $validated['form'], auth()->id());

        $this->closeForm();
        $this->dispatch('toast', type: 'success', message: 'Receipt recorded for '.str_replace('_', ' ', $receipt->purpose).'.');
    }

    public function markBanked(int $receiptId, ManualReceiptService $receipts): void
    {
        $this->guardManageReceipts();

        $receipt = CreditUnionManualReceipt::query()->findOrFail($receiptId);
        $receipts->markBanked($receipt, null, auth()->id());

        $this->dispatch('toast', type: 'success', message: 'Receipt marked as banked.');
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->form = [
            'member_id' => '',
            'method' => CreditUnionManualReceipt::METHOD_CASH,
            'purpose' => CreditUnionManualReceipt::PURPOSE_SAVINGS,
            'cheque_no' => '',
            'payer_name' => '',
            'amount' => '',
            'received_date' => today()->toDateString(),
            'banked' => true,
            'remarks' => '',
        ];
    }

    protected function guardManageReceipts(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.manage_receipts'))) {
            abort(403);
        }
    }

    public function render()
    {
        return view('livewire.credit-union.receipts', [
            'receipts' => CreditUnionManualReceipt::query()
                ->with(['member', 'recorder'])
                ->when($this->search !== '', function (Builder $query) {
                    $term = '%'.$this->search.'%';
                    $query->where('payer_name', 'like', $term)
                        ->orWhere('cheque_no', 'like', $term)
                        ->orWhereHas('member', fn (Builder $member) => $member
                            ->where('full_name', 'like', $term)
                            ->orWhere('member_number', 'like', $term));
                })
                ->when($this->purposeFilter !== '', fn (Builder $query) => $query->where('purpose', $this->purposeFilter))
                ->when($this->bankedFilter !== '', fn (Builder $query) => $query->where('banked', $this->bankedFilter === 'banked'))
                ->orderByDesc('received_date')
                ->orderByDesc('id')
                ->paginate(15),
            'members' => CreditUnionMember::query()->active()->orderBy('full_name')->get(['id', 'member_number', 'full_name', 'member_type']),
            'methods' => CreditUnionManualReceipt::METHODS,
            'purposes' => CreditUnionManualReceipt::PURPOSES,
        ]);
    }
}
