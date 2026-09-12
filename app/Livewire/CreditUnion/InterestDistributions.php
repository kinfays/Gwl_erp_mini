<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionInterestDistribution;
use App\Models\Permission;
use App\Services\CreditUnion\InterestDistributionService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class InterestDistributions extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $statusFilter = '';

    public bool $showForm = false;

    public array $form = [
        'period_label' => '',
        'period_start_date' => '',
        'period_end_date' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardCanView();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        $this->guardManageDistributions();
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function compute(InterestDistributionService $distributions): void
    {
        $this->guardManageDistributions();

        $validated = $this->validate([
            'form.period_label' => ['required', 'string', 'max:50'],
            'form.period_start_date' => ['nullable', 'date'],
            'form.period_end_date' => ['required', 'date'],
        ]);

        $distribution = $distributions->compute(
            $validated['form']['period_label'],
            $validated['form']['period_end_date'],
            $validated['form']['period_start_date'] ?: null,
            auth()->id()
        );

        $this->closeForm();
        $this->dispatch('toast', type: 'success', message: 'Distribution computed for '.$distribution->period_label.'.');

        $this->redirectRoute('credit-union.interest-distributions.show', $distribution, navigate: false);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->form = [
            'period_label' => (now()->year - 1).'/'.now()->year,
            'period_start_date' => '',
            'period_end_date' => today()->toDateString(),
        ];
    }

    protected function guardCanView(): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(403);
        }

        $allowed = $user->hasRoles('super_admin')
            || $user->hasPermission('credit_union.manage_interest_distribution')
            || $user->hasPermission('credit_union.approve_interest_distribution');

        if (! $allowed) {
            abort(403);
        }
    }

    protected function guardManageDistributions(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.manage_interest_distribution'))) {
            abort(403);
        }
    }

    public function render()
    {
        return view('livewire.credit-union.interest-distributions', [
            'distributions' => CreditUnionInterestDistribution::query()
                ->withCount('lines')
                ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', $this->statusFilter))
                ->orderByDesc('period_end_date')
                ->orderByDesc('id')
                ->paginate(15),
            'statuses' => CreditUnionInterestDistribution::STATUSES,
        ]);
    }
}
