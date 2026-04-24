<?php

namespace App\Livewire\Leave;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveWorkflowService;
use Illuminate\Support\Facades\Auth;

class Approvals extends Component
{
    use WithPagination;

    public string $tab = 'pending'; // pending | approved | denied
    public string $search = '';

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function recommend(int $requestId, LeaveWorkflowService $workflow): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isHrUser()) {
            abort(403, 'HR users are read-only for approvals.');
        }

        $employee = $user->employee;

        $req = LeaveRequest::with('requester')->findOrFail($requestId);

        $workflow->recommend($employee, $req, 'Recommended', true);

        session()->flash('success', 'Recommendation sent.');
    }

    public function reject(int $requestId, LeaveWorkflowService $workflow): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isHrUser()) {
            abort(403, 'HR users are read-only for approvals.');
        }

        $employee = $user->employee;

        $req = LeaveRequest::with('requester')->findOrFail($requestId);

        $workflow->recommend($employee, $req, 'Rejected', false);

        session()->flash('success', 'Request rejected.');
    }

    public function approve(int $requestId, LeaveWorkflowService $workflow): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isHrUser()) {
            abort(403, 'HR users are read-only for approvals.');
        }

        $employee = $user->employee;

        $req = LeaveRequest::with('requester')->findOrFail($requestId);

        $workflow->finalDecision($employee, $req, 'Approved', true);

        session()->flash('success', 'Request approved.');
    }

    public function deny(int $requestId, LeaveWorkflowService $workflow): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isHrUser()) {
            abort(403, 'HR users are read-only for approvals.');
        }

        $employee = $user->employee;

        $req = LeaveRequest::with('requester')->findOrFail($requestId);

        $workflow->finalDecision($employee, $req, 'Denied', false);

        session()->flash('success', 'Request denied.');
    }

    public function render()
{
    /** @var User $user */
    $user = Auth::user();
    $actor = $user->employee;

    $resolver = app(\App\Services\Leave\LeaveApprovalChainResolver::class);

    // Base query: load required relations
    $base = \App\Models\LeaveRequest::query()
        ->with(['requester.region', 'requester.district', 'department'])
        ->when($this->search, function ($q) {
            $q->whereHas('requester', fn ($qq) => $qq->where('full_name', 'like', "%{$this->search}%"));
        });

    // Tabs filter
    if ($this->tab === 'pending') {
        $base->where('leave_status', 'Pending Approval');
    } elseif ($this->tab === 'approved') {
        $base->where('leave_status', 'Approved');
    } elseif ($this->tab === 'denied') {
        $base->where('leave_status', 'Denied');
    }

    /**
     * Visibility rules:
     * - Managers/chiefs: see only items in their chain.
     * - HR users: read-only view, region scoped (HO HR sees all). [1](https://ghanawater-my.sharepoint.com/personal/fewuntomah_gwcl_com_gh/Documents/Microsoft%20Copilot%20Chat%20Files/PHASE%203.txt)
     */
    if ($user->isHrUser()) {
        // HR: can view pending/approved/denied requests in scope
        if (! $user->isHeadOfficeHr()) {
            $base->where('region_id', $actor->region_id);
        }
        // HR should not see planned requests on approvals page
        $base->where('leave_status', '!=', 'Planned');

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
    $candidate = (clone $base)
        ->where('leave_status', 'Pending Approval')
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

    $requests = \App\Models\LeaveRequest::query()
        ->with(['requester.region', 'requester.district', 'department'])
        ->whereIn('id', $filteredIds)
        ->latest()
        ->paginate(12);

    return view('livewire.leave.approvals', [
        'requests' => $requests,
        'tab' => $this->tab,
        'readOnly' => false,
    ]);
}

   /* public function render()
    {
        $employee = auth()->user()->employee;

        // Requests where current user is the recommending manager
        $managerQueue = LeaveRequest::query()
            ->where('manager_id', $employee->id);

        // Requests awaiting final approval: manager recommended AND still pending approval
        // NOTE: This assumes your LeaveWorkflowService sets leave_status and manager_recommendation as in Phase 3 design. [1](https://ghanawater-my.sharepoint.com/personal/fewuntomah_gwcl_com_gh/Documents/Microsoft%20Copilot%20Chat%20Files/PHASE%203.txt)
        $chiefQueue = LeaveRequest::query()
            ->where('manager_recommendation', 'Recommended')
            ->where('leave_status', 'Pending Approval');

        // Combine queues: if the user can be both, show union
        $query = LeaveRequest::query()
            ->where(function ($q) use ($employee) {
                $q->where('manager_id', $employee->id)
                  ->orWhere(function ($qq) {
                      $qq->where('manager_recommendation', 'Recommended')
                         ->where('leave_status', 'Pending Approval');
                  });
            })
            ->with(['requester', 'department'])
            ->when($this->search, function ($q) {
                $q->whereHas('requester', fn ($qq) => $qq->where('full_name', 'like', "%{$this->search}%"));
            });

        if ($this->tab === 'pending') {
            $query->whereIn('leave_status', ['Pending Approval']);
        } elseif ($this->tab === 'approved') {
            $query->where('leave_status', 'Approved');
        } elseif ($this->tab === 'denied') {
            $query->where('leave_status', 'Denied');
        }

        $requests = $query->latest()->paginate(12);

        return view('livewire.leave.approvals', compact('requests'));
    } */
}
