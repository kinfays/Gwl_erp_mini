<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Livewire\Component;

class ManagerDashboard extends Component
{
    use EnforcesModuleAccess;

    public array $stats = [];

    public $onLeave;

    public $upcoming;

    public array $leaveByType = [];

    public array $slaStats = [];

    public function mount(): void
    {
        $this->enforceLivewireModule('leave');

        $user = auth()->user();
        $manager = $this->employee();

        if (! $manager || ! $user->hasRoles('manager', 'departmental_manager', 'district_manager', 'chief_manager', 'regional_chief_manager')) {
            abort(403);
        }

        $this->loadStats();
        $this->loadCurrentLeave();
        $this->loadUpcomingLeave();
        $this->loadLeaveByType();
        $this->loadSlaStats();
    }

    protected function teamQuery()
    {
        $manager = $this->employee();

        abort_if(! $manager, 403, 'Employee profile is required for team leave access.');

        return LeaveRequest::query()
            ->where('manager_id', $manager->id);
    }

    protected function loadStats(): void
    {
        $today = today();

        $this->stats = [
            'team_size' => (clone $this->teamQuery())->distinct('requester_id')->count('requester_id'),

            'on_leave_now' => $this->teamQuery()
                ->where('leave_status', 'Approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->count(),

            'pending_approvals' => $this->teamQuery()
                ->where('leave_status', 'Pending Approval')
                ->count(),

            'approved_this_month' => $this->teamQuery()
                ->where('leave_status', 'Approved')
                ->whereMonth('updated_at', now()->month)
                ->count(),
        ];
    }

    protected function loadCurrentLeave(): void
    {
        $today = today();

        $this->onLeave = $this->teamQuery()
            ->where('leave_status', 'Approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->with('requester')
            ->get();
    }

    protected function loadUpcomingLeave(): void
    {
        $this->upcoming = $this->teamQuery()
            ->where('leave_status', 'Approved')
            ->whereBetween('start_date', [now(), now()->addDays(30)])
            ->with('requester')
            ->get();
    }

    protected function loadLeaveByType(): void
    {
        $total = $this->teamQuery()
            ->where('leave_status', 'Approved')
            ->sum('total_days_applied');

        foreach (['Annual', 'Casual', 'Sick', 'Paternity', 'Maternity'] as $type) {
            $days = $this->teamQuery()
                ->where('leave_status', 'Approved')
                ->where('leave_type', $type)
                ->sum('total_days_applied');

            $this->leaveByType[$type] = $total > 0
                ? round(($days / $total) * 100)
                : 0;
        }
    }

    protected function loadSlaStats(): void
    {
        $requests = $this->teamQuery()
            ->whereIn('leave_status', ['Approved', 'Denied'])
            ->get();

        $times = $requests->map(fn ($r) => $r->updated_at->diffInHours($r->created_at)
        );

        $this->slaStats = [
            'avg_cycle_hours' => $times->isNotEmpty()
                ? round($times->avg())
                : 0,
        ];
    }

    public function render()
    {
        return view('livewire.leave.manager-dashboard');
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
