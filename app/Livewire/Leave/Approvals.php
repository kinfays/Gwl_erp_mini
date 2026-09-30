<?php

namespace App\Livewire\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveApprovalChainResolver;
use App\Services\Leave\LeaveWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class Approvals extends Component
{
    use WithPagination;

    public string $tab = 'pending';

    public string $search = '';

    public array $comments = [];

    public bool $showDrawer = false;

    public ?int $selectedRequestId = null;

    public ?LeaveRequest $selectedRequest = null;

    public function setTab(string $tab): void
    {
        $this->tab = 'pending';
        $this->resetPage();
    }

    public function viewRequest(int $requestId): void
    {
        $req = LeaveRequest::query()
            ->with(['requester.region', 'requester.district', 'department', 'manager', 'approvedBy', 'chiefUser'])
            ->findOrFail($requestId);

        abort_unless($this->canSeeRequest($req), 403, 'You are not allowed to view this leave request.');

        $this->selectedRequestId = $req->id;
        $this->selectedRequest = $req;
        $this->comments[$req->id] ??= '';
        $this->showDrawer = true;
    }

    public function closeDrawer(): void
    {
        $this->showDrawer = false;
        $this->selectedRequestId = null;
        $this->selectedRequest = null;
    }

    public function approveRequest(int $requestId, LeaveWorkflowService $workflow): void
    {
        $this->decide($requestId, $workflow, true);
    }

    public function denyRequest(int $requestId, LeaveWorkflowService $workflow): void
    {
        $this->decide($requestId, $workflow, false);
    }

    public function recommend(int $requestId, LeaveWorkflowService $workflow): void
    {
        $this->approveRequest($requestId, $workflow);
    }

    public function reject(int $requestId, LeaveWorkflowService $workflow): void
    {
        $this->denyRequest($requestId, $workflow);
    }

    public function approve(int $requestId, LeaveWorkflowService $workflow): void
    {
        $this->approveRequest($requestId, $workflow);
    }

    public function deny(int $requestId, LeaveWorkflowService $workflow): void
    {
        $this->denyRequest($requestId, $workflow);
    }

    /**
     * One click on Approve/Deny. Whether this is the manager's recommendation or the final decision depends
     * on where the request is waiting; the workflow service re-checks that (and who is acting) under a lock,
     * so a request someone else has just actioned is refused here with a message instead of being applied twice.
     */
    protected function decide(int $requestId, LeaveWorkflowService $workflow, bool $approve): void
    {
        $req = $this->actionableRequest($requestId);

        if (! $req) {
            return;
        }

        $employee = $this->employee();
        $comment = $this->commentFor($requestId);
        $recommendStage = $req->manager_recommendation === 'Pending';

        try {
            $recommendStage
                ? $workflow->recommend($employee, $req, $comment, $approve)
                : $workflow->finalDecision($employee, $req, $comment, $approve);
        } catch (RuntimeException $e) {
            $this->refuse($e->getMessage());

            return;
        }

        $message = match (true) {
            ! $approve => 'Request denied.',
            $recommendStage => 'Request approved for final review.',
            default => 'Request approved.',
        };

        unset($this->comments[$requestId]);
        $this->closeDrawer();

        session()->flash('success', $message);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $actor = $this->employee();

        // Base query: load required relations
        $base = LeaveRequest::query()
            ->with(['requester.region', 'requester.district', 'department', 'manager', 'approvedBy', 'chiefUser'])
            ->when($this->search, function ($q) {
                $q->whereHas('requester', fn ($qq) => $qq->where('full_name', 'like', "%{$this->search}%"));
            })
            ->where('leave_status', 'Pending Approval');

        /**
         * Visibility rules:
         * - Managers/chiefs/MD: see only the requests they can act on (LeaveApprovalChainResolver::actionableRequests()).
         * - HR users: read-only pending view, region scoped (HO HR sees all).
         */
        if ($user->hasRoles('super_admin', 'admin') || $user->isHrUser()) {
            if ($user->isHrUser() && ! $user->isHeadOfficeHr()) {
                abort_if(! $actor, 403, 'Employee profile is required for regional leave access.');

                $base->where('region_id', $actor->region_id);
            }
            $requests = $base->latest()->paginate(12);

            return view('livewire.leave.approvals', [
                'requests' => $requests,
                'tab' => $this->tab,
                'readOnly' => true,
            ]);
        }

        abort_if(! $actor, 403, 'Employee profile is required for leave approvals.');

        $requests = $base
            ->whereIn('id', app(LeaveApprovalChainResolver::class)->actionableRequests($user)->pluck('id')->all())
            ->latest()
            ->paginate(12);

        return view('livewire.leave.approvals', [
            'requests' => $requests,
            'tab' => $this->tab,
            'readOnly' => false,
        ]);
    }

    protected function employee(): ?Employee
    {
        $user = Auth::user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }

    /**
     * The request to act on, or null (after telling the user) when it is no longer waiting — someone else
     * got there first. 403 when the user isn't allowed to act on it at all.
     */
    protected function actionableRequest(int $requestId): ?LeaveRequest
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isHrUser()) {
            abort(403, 'HR users are read-only for approvals.');
        }

        abort_if(! $this->employee(), 403, 'Employee profile is required for leave approvals.');

        $req = LeaveRequest::query()->with('requester')->findOrFail($requestId);

        if ($req->leave_status !== 'Pending Approval') {
            $this->refuse('This request has already been actioned.');

            return null;
        }

        abort_unless(app(LeaveApprovalChainResolver::class)->canAct($user, $req), 403, 'You are not allowed to act on this leave request.');

        if (! in_array($req->manager_recommendation, ['Pending', 'Recommended'], true)) {
            $this->refuse('This request is not waiting for approval.');

            return null;
        }

        return $req;
    }

    protected function refuse(string $message): void
    {
        $this->addError('action', $message);
        $this->dispatch('toast', type: 'error', message: $message);
        $this->closeDrawer();
    }

    protected function commentFor(int $requestId): ?string
    {
        $this->validate([
            "comments.{$requestId}" => 'nullable|string|max:2000',
        ], [
            "comments.{$requestId}.max" => 'Comments must not exceed 2000 characters.',
        ]);

        $comment = trim((string) ($this->comments[$requestId] ?? ''));

        return $comment !== '' ? $comment : null;
    }

    protected function canSeeRequest(LeaveRequest $request): bool
    {
        /** @var User $user */
        $user = Auth::user();
        $actor = $this->employee();

        if ($request->leave_status !== 'Pending Approval') {
            return false;
        }

        if ($user->hasRoles('super_admin', 'admin')) {
            return true;
        }

        if ($user->isHrUser()) {
            return $user->isHeadOfficeHr()
                || ($actor && $request->region_id === $actor->region_id);
        }

        if (! $actor) {
            return false;
        }

        return app(LeaveApprovalChainResolver::class)->canAct($user, $request);
    }
}
