<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
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

    protected function teamEmployeeQuery(): Builder
    {
        $user = auth()->user();
        $manager = $this->employee();

        abort_if(! $manager, 403, 'Employee profile is required for team leave access.');

        $query = Employee::query()
            ->visibleInErp()
            ->where('is_active', true)
            ->where('id', '!=', $manager->id);

        if ($user->hasRoles('regional_chief_manager')) {
            return $manager->region_id
                ? $query->where('region_id', $manager->region_id)
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasRoles('chief_manager')) {
            return $manager->department_id
                ? $query->where('department_id', $manager->department_id)
                    ->where('location_type', 'HeadOffice')
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasRoles('district_manager')) {
            return $manager->district_id
                ? $query->where('district_id', $manager->district_id)
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasRoles('departmental_manager')) {
            if (! $manager->department_id) {
                return $query->whereRaw('1 = 0');
            }

            $query->where('department_id', $manager->department_id);
            $this->applyLocationScope($query, $manager);

            return $query;
        }

        if ($user->hasRoles('manager')) {
            if (! $manager->department_id) {
                return $query->whereRaw('1 = 0');
            }

            $query->where('department_id', $manager->department_id);

            if ($manager->unit) {
                $query->where('unit', $manager->unit);
            }

            $this->applyLocationScope($query, $manager);

            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    protected function teamLeaveQuery(): Builder
    {
        return LeaveRequest::query()
            ->whereIn('requester_id', $this->teamEmployeeQuery()->select('id'));
    }

    protected function loadStats(): void
    {
        $today = today();

        $this->stats = [
            'team_size' => $this->teamEmployeeQuery()->count(),

            'on_leave_now' => $this->teamLeaveQuery()
                ->where('leave_status', 'Approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->distinct('requester_id')
                ->count('requester_id'),

            'pending_approvals' => $this->pendingApprovalsCount(),

            'approved_this_month' => $this->teamLeaveQuery()
                ->where('leave_status', 'Approved')
                ->whereYear('updated_at', now()->year)
                ->whereMonth('updated_at', now()->month)
                ->count(),
        ];
    }

    protected function loadCurrentLeave(): void
    {
        $today = today();

        $this->onLeave = $this->teamLeaveQuery()
            ->where('leave_status', 'Approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->with('requester')
            ->orderBy('end_date')
            ->get();
    }

    protected function loadUpcomingLeave(): void
    {
        $today = today();

        $this->upcoming = $this->teamLeaveQuery()
            ->where('leave_status', 'Approved')
            ->whereDate('start_date', '>', $today)
            ->whereDate('start_date', '<=', $today->copy()->addDays(30))
            ->with('requester')
            ->orderBy('start_date')
            ->get();
    }

    protected function loadLeaveByType(): void
    {
        $this->leaveByType = [];

        $total = $this->teamLeaveQuery()
            ->where('leave_status', 'Approved')
            ->sum('total_days_applied');

        foreach (['Annual', 'Casual', 'Sick', 'Paternity', 'Maternity'] as $type) {
            $days = $this->teamLeaveQuery()
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
        $requests = $this->teamLeaveQuery()
            ->whereIn('leave_status', ['Approved', 'Denied'])
            ->get();

        $times = $requests
            ->filter(fn ($r) => $r->created_at && $r->updated_at)
            ->map(fn ($r) => $r->updated_at->diffInHours($r->created_at));

        $this->slaStats = [
            'avg_cycle_hours' => $times->isNotEmpty()
                ? round($times->avg())
                : 0,
        ];
    }

    protected function pendingApprovalsCount(): int
    {
        $user = auth()->user();
        $employee = $this->employee();
        $count = 0;

        if ($user->hasRoles('manager', 'departmental_manager', 'district_manager')) {
            $count += LeaveRequest::query()
                ->where('manager_id', $employee->id)
                ->where('leave_status', 'Pending Approval')
                ->where('manager_recommendation', 'Pending')
                ->count();
        }

        if ($user->hasRoles('chief_manager', 'regional_chief_manager')) {
            $count += $this->teamLeaveQuery()
                ->where('leave_status', 'Pending Approval')
                ->where('manager_recommendation', 'Recommended')
                ->count();
        }

        return $count;
    }

    protected function applyLocationScope(Builder $query, Employee $manager): void
    {
        if ($manager->district_id) {
            $query->where('district_id', $manager->district_id);

            return;
        }

        if ($manager->region_id) {
            $query->where('region_id', $manager->region_id);

            return;
        }

        $query->where('location_type', 'HeadOffice');
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
