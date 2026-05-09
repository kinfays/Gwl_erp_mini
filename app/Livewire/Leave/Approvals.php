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
            ->with(['requester.region', 'requester.district', 'department', 'manager', 'approvedBy'])
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
        $req = $this->actionableRequest($requestId);
        $employee = $this->employee();
        $comment = $this->commentFor($requestId);

        if ($req->manager_recommendation === 'Pending') {
            $workflow->recommend($employee, $req, $comment, true);
            $message = 'Request approved for final review.';
        } else {
            $workflow->finalDecision($employee, $req, $comment, true);
            $message = 'Request approved.';
        }

        unset($this->comments[$requestId]);
        $this->closeDrawer();

        session()->flash('success', $message);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function denyRequest(int $requestId, LeaveWorkflowService $workflow): void
    {
        $req = $this->actionableRequest($requestId);
        $employee = $this->employee();
        $comment = $this->commentFor($requestId);

        if ($req->manager_recommendation === 'Pending') {
            $workflow->recommend($employee, $req, $comment, false);
        } else {
            $workflow->finalDecision($employee, $req, $comment, false);
        }

        unset($this->comments[$requestId]);
        $this->closeDrawer();

        session()->flash('success', 'Request denied.');
        $this->dispatch('toast', type: 'success', message: 'Request denied.');
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

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $actor = $this->employee();

        $resolver = app(LeaveApprovalChainResolver::class);

        // Base query: load required relations
        $base = LeaveRequest::query()
            ->with(['requester.region', 'requester.district', 'department', 'manager', 'approvedBy'])
            ->when($this->search, function ($q) {
                $q->whereHas('requester', fn ($qq) => $qq->where('full_name', 'like', "%{$this->search}%"));
            })
            ->where('leave_status', 'Pending Approval');

        /**
         * Visibility rules:
         * - Managers/chiefs: see only items in their chain.
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

        /**
         * Non-HR (managers/chiefs):
         * Show:
         * - manager queue: manager_id = actor.id AND manager_recommendation pending
         * - chief queue: manager_recommendation recommended AND actor is resolved chief approver
         */
        abort_if(! $actor, 403, 'Employee profile is required for leave approvals.');

        $candidate = (clone $base)
            ->where(function ($q) use ($actor) {
                $q->where(function ($m) use ($actor) {
                    $m->where('manager_id', $actor->id)
                        ->where('manager_recommendation', 'Pending');
                })
                    ->orWhere(function ($c) {
                        $c->where('manager_recommendation', 'Recommended');
                    });
            })
            ->latest()
            ->get();

        // Filter chief-queue in PHP using resolver (correctness > complex SQL)
        $filteredIds = $candidate->filter(function ($req) use ($actor, $resolver) {
            try {
                [$mgr, $chief] = $resolver->resolve($req->requester);
                // manager stage passes if actor is manager_id (already in query)
                // chief stage passes if actor is the resolved chief
                if ($req->manager_recommendation === 'Recommended') {
                    return $chief->id === $actor->id;
                }

                return true;
            } catch (\Throwable $e) {
                return false;
            }
        })->pluck('id')->toArray();

        $requests = LeaveRequest::query()
            ->with(['requester.region', 'requester.district', 'department', 'manager', 'approvedBy'])
            ->whereIn('id', $filteredIds)
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

    protected function actionableRequest(int $requestId): LeaveRequest
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isHrUser()) {
            abort(403, 'HR users are read-only for approvals.');
        }

        abort_if(! $this->employee(), 403, 'Employee profile is required for leave approvals.');

        $req = LeaveRequest::query()
            ->with('requester')
            ->where('leave_status', 'Pending Approval')
            ->findOrFail($requestId);

        abort_unless($this->canSeeRequest($req), 403, 'You are not allowed to act on this leave request.');

        if (! in_array($req->manager_recommendation, ['Pending', 'Recommended'], true)) {
            abort(422, 'This request is not waiting for approval.');
        }

        return $req;
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

        if ($request->manager_recommendation === 'Pending') {
            return $request->manager_id === $actor->id;
        }

        if ($request->manager_recommendation === 'Recommended') {
            try {
                [$mgr, $chief] = app(LeaveApprovalChainResolver::class)->resolve($request->requester);

                return $chief->id === $actor->id;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
