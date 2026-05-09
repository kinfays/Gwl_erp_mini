<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveWorkflowService;
use App\Support\Audit;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class MyHistory extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public bool $includePast36Months = false;

    // Drawer state
    public bool $showDrawer = false;

    public ?int $selectedRequestId = null;

    public ?LeaveRequest $selectedRequest = null;

    public int $perPage = 12;

    public function mount(): void
    {
        // ✅ Enforce module access for Livewire requests
        $this->enforceLivewireModule('leave');
    }

    protected function requesterId(): int
    {
        $employee = $this->employee();

        abort_if(! $employee, 403, 'Employee profile is required for leave history.');

        return $employee->id;
    }

    public function togglePast(): void
    {
        $this->includePast36Months = ! $this->includePast36Months;
        $this->resetPage();
    }

    public function viewRequest(int $id): void
    {
        $req = LeaveRequest::query()
            ->with(['department', 'manager', 'approvedBy'])
            ->where('requester_id', $this->requesterId())
            ->findOrFail($id);

        $this->selectedRequestId = $req->id;
        $this->selectedRequest = $req;
        $this->showDrawer = true;
    }

    public function closeDrawer(): void
    {
        $this->showDrawer = false;
        $this->selectedRequestId = null;
        $this->selectedRequest = null;
    }

    public function editRequest(int $id)
    {
        $req = LeaveRequest::query()
            ->where('requester_id', $this->requesterId())
            ->findOrFail($id);

        if (! $req->canBeEditedByRequester()) {
            $this->addError('action', 'Only Planned requests and unrecommended Pending Approval requests can be edited.');
            $this->dispatch('toast', type: 'error', message: 'This request can no longer be edited.');

            return;
        }

        return redirect()->route('leave.apply', ['edit' => $req->id]);
    }

    public function deletePlanned(int $id): void
    {
        $req = LeaveRequest::query()
            ->where('requester_id', $this->requesterId())
            ->findOrFail($id);

        if ($req->leave_status !== 'Planned') {
            $this->addError('action', 'Only Planned requests can be deleted.');
            $this->dispatch('toast', type: 'error', message: 'Only Planned requests can be deleted.');

            return;
        }

        $old = [
            'leave_type' => $req->leave_type,
            'start_date' => $req->start_date?->toDateString(),
            'end_date' => $req->end_date?->toDateString(),
            'days' => $req->total_days_applied,
        ];

        $req->delete();

        Audit::log(
            action: 'leave_delete_planned',
            module: 'leave',
            targetType: 'leave_requests',
            targetId: $id,
            metadata: $old
        );

        session()->flash('success', 'Planned request deleted.');
        $this->dispatch('toast', type: 'success', message: 'Planned request deleted.');
        $this->closeDrawer();
    }

    public function reopenDenied(int $id, LeaveWorkflowService $workflow): void
    {
        $req = LeaveRequest::query()
            ->with('requester')
            ->where('requester_id', $this->requesterId())
            ->findOrFail($id);

        if ($req->leave_status !== 'Denied') {
            $this->addError('action', 'Only Denied requests can be reopened.');
            $this->dispatch('toast', type: 'error', message: 'Only Denied requests can be reopened.');

            return;
        }

        $workflow->reopen($req->requester, $req);

        Audit::log(
            action: 'leave_reopen_denied',
            module: 'leave',
            targetType: 'leave_requests',
            targetId: $req->id,
            metadata: ['new_status' => $req->leave_status]
        );

        session()->flash('success', 'Request reopened and set back to Planned.');
        $this->dispatch('toast', type: 'success', message: 'Request reopened and set back to Planned.');
        $this->closeDrawer();
    }

    public function render()
    {
        $query = LeaveRequest::query()
            ->where('requester_id', $this->requesterId())
            ->orderByDesc('created_at');

        if ($this->includePast36Months) {
            $from = Carbon::now()->subMonths(36)->startOfDay();
            $query->where('start_date', '>=', $from);
        } else {
            $query->where('request_year', (int) now()->format('Y'));
        }

        $requests = $query->paginate($this->perPage);

        return view('livewire.leave.my-history', [
            'requests' => $requests,
        ]);
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
