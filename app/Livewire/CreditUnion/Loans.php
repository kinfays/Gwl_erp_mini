<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionLoan;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\LoanService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Loans extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showForm = false;

    public array $form = [
        'member_id' => '',
        'principal_amount' => '',
        'term_months' => '12',
        'purpose' => '',
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
        $this->guardManageLoans();
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(LoanService $loans): void
    {
        $this->guardManageLoans();

        $validated = $this->validate([
            'form.member_id' => ['required', 'integer', 'exists:credit_union_members,id'],
            'form.principal_amount' => ['required', 'numeric', 'min:0.01'],
            'form.term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'form.purpose' => ['nullable', 'string', 'max:2000'],
        ]);

        $member = CreditUnionMember::query()->findOrFail($validated['form']['member_id']);
        $loan = $loans->apply($member, $validated['form'], auth()->id());

        $this->closeForm();
        $this->dispatch('toast', type: 'success', message: 'Loan '.$loan->loan_number.' raised ('.str_replace('_', ' ', $loan->status).').');

        $this->redirectRoute('credit-union.loans.show', $loan, navigate: false);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->form = [
            'member_id' => '',
            'principal_amount' => '',
            'term_months' => '12',
            'purpose' => '',
        ];
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

    public function render(LoanService $loans)
    {
        $selectedMember = $this->form['member_id'] !== ''
            ? CreditUnionMember::query()->find($this->form['member_id'])
            : null;

        $termsPreview = null;

        if ($selectedMember && is_numeric($this->form['principal_amount']) && (int) $this->form['term_months'] > 0) {
            $termsPreview = $loans->computeTerms(
                $selectedMember,
                (float) $this->form['principal_amount'],
                (int) $this->form['term_months']
            );
        }

        return view('livewire.credit-union.loans', [
            'loans' => CreditUnionLoan::query()
                ->with('member')
                ->when($this->search !== '', function (Builder $query) {
                    $term = '%'.$this->search.'%';
                    $query->where(fn (Builder $inner) => $inner
                        ->where('loan_number', 'like', $term)
                        ->orWhereHas('member', fn (Builder $member) => $member
                            ->where('full_name', 'like', $term)
                            ->orWhere('member_number', 'like', $term)));
                })
                ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', $this->statusFilter))
                ->orderByDesc('id')
                ->paginate(15),
            'members' => CreditUnionMember::query()->active()->orderBy('full_name')->get(['id', 'member_number', 'full_name', 'member_type']),
            'statuses' => CreditUnionLoan::STATUSES,
            'termsPreview' => $termsPreview,
            'selectedMember' => $selectedMember,
        ]);
    }
}
