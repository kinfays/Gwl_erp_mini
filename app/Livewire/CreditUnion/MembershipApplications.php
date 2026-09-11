<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Models\User;
use App\Services\CreditUnion\MembershipApplicationService;
use Livewire\Component;
use Livewire\WithPagination;

class MembershipApplications extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public ?int $rejectingId = null;

    public string $rejectionReason = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardCanView();
    }

    /**
     * Viewing the queue is open to officers, but the decision itself is committee-only
     * and is re-checked inside MembershipApplicationService.
     */
    public function approve(int $memberId, MembershipApplicationService $applications): void
    {
        $member = CreditUnionMember::query()->findOrFail($memberId);
        $user = $this->guardCanDecide();

        $applications->approve($member, $user);

        $this->dispatch('toast', type: 'success', message: 'Membership approved for '.$member->member_number.'.');
    }

    public function startReject(int $memberId): void
    {
        $this->rejectingId = $memberId;
        $this->rejectionReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectionReason = '';
    }

    public function reject(MembershipApplicationService $applications): void
    {
        $member = CreditUnionMember::query()->findOrFail($this->rejectingId);
        $user = $this->guardCanDecide();

        $applications->reject($member, $user, $this->rejectionReason);

        $this->cancelReject();
        $this->dispatch('toast', type: 'success', message: 'Application rejected.');
    }

    protected function guardCanDecide(): User
    {
        $user = auth()->user();

        if (! $user) {
            abort(403);
        }

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission(MembershipApplicationService::APPROVE_PERMISSION)) {
            abort(403, 'Membership approval requires the credit union committee permission.');
        }

        return $user;
    }

    protected function guardCanView(): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(403);
        }

        $allowed = $user->hasRoles('super_admin')
            || $user->hasPermission('credit_union.manage_members')
            || $user->hasPermission(MembershipApplicationService::APPROVE_PERMISSION);

        if (! $allowed) {
            abort(403);
        }
    }

    public function render()
    {
        $applications = CreditUnionMember::query()
            ->with(['employee.department', 'applicant'])
            ->pending()
            ->orderBy('created_at')
            ->paginate(15);

        $service = app(MembershipApplicationService::class);
        $user = auth()->user();

        return view('livewire.credit-union.membership-applications', [
            'applications' => $applications,
            'approvable' => $applications->mapWithKeys(
                fn (CreditUnionMember $member) => [$member->id => $service->canApprove($user, $member)]
            ),
        ]);
    }
}
