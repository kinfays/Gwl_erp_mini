<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionInterestDistribution;
use App\Models\Permission;
use App\Models\User;
use App\Services\CreditUnion\InterestDistributionService;
use Livewire\Component;
use Livewire\WithPagination;

class InterestDistributionShow extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public CreditUnionInterestDistribution $distribution;

    public function mount(CreditUnionInterestDistribution $distribution): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardCanView();

        $this->distribution = $distribution;
    }

    public function approve(InterestDistributionService $distributions): void
    {
        $approver = $this->guardCanApprove();

        $distributions->approve($this->distribution, $approver);

        $this->distribution->refresh();
        $this->dispatch('toast', type: 'success', message: 'Distribution approved and ready to post.');
    }

    public function post(InterestDistributionService $distributions): void
    {
        $this->guardCanApprove();

        $distributions->post($this->distribution, auth()->id());

        $this->distribution->refresh();
        $this->dispatch('toast', type: 'success', message: 'Distribution posted to member ledgers.');
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

    /**
     * Approving and posting a run is committee business. There is no applicant on a
     * union-wide batch, so unlike loans and withdrawals no self-approval guard applies.
     */
    protected function guardCanApprove(): User
    {
        $user = auth()->user();

        if (! $user) {
            abort(403);
        }

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.approve_interest_distribution')) {
            abort(403, 'Interest distribution approval requires the credit union committee permission.');
        }

        return $user;
    }

    public function render()
    {
        $this->distribution->loadMissing(['computer', 'approver', 'poster']);

        $user = auth()->user();

        return view('livewire.credit-union.interest-distribution-show', [
            'lines' => $this->distribution->lines()
                ->with(['member', 'ledgerEntry'])
                ->orderByDesc('amount')
                ->paginate(25),
            'lineTotal' => $this->distribution->lineTotal(),
            'canApprove' => $user && ($user->hasRoles('super_admin') || $user->hasPermission('credit_union.approve_interest_distribution')),
        ]);
    }
}
