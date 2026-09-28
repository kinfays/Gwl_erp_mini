<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use App\Services\Leave\LeaveDashboardService;

class HrDashboard extends Component
{
    use EnforcesModuleAccess;

    public int $zoneStaffCount = 0;

    public int $onLeaveNowCount = 0;

    public int $pendingCount = 0;

    public int $approvedThisMonth = 0;

    public int $deniedThisMonth = 0;

    public array $leaveByType = [];

    public array $genderBreakdown = [];

    public $pendingApprovals;

    public array $slaStats = [];

    public $slowestApprovals;

    public function mount(): void
    {
        // ✅ Livewire must enforce module too
        $this->enforceLivewireModule('leave');

        /** @var User $user */
        $user = Auth::user();

        // HR + admin/super_admin can view this dashboard
        if (! $user->isHrUser() && ! $user->hasRoles('admin', 'super_admin')) {
            abort(403, 'HR dashboard is restricted.');
        }
        $this->loadPendingApprovals();
        $this->loadLeaveByType();
        $this->loadGenderBreakdown();
        $this->loadZoneStaffCount();
        $this->loadStats();
        $this->loadSlaStats();
        $this->loadSlowApprovals();

    }

    protected function loadStats(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $employee = $this->employee();

        $q = LeaveRequest::query();

        // Region scoping for HR users
        if ($user->isHrUser() && ! $user->isHeadOfficeHr()) {
            abort_if(! $employee, 403, 'Employee profile is required for regional leave access.');

            $q->where('region_id', $employee->region_id);
        }

        $today = today();

        $this->onLeaveNowCount = (clone $q)
            ->where('leave_status', 'Approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->distinct('requester_id')
            ->count('requester_id');

        $this->pendingCount = (clone $q)->where('leave_status', 'Pending Approval')->count();

        $this->approvedThisMonth = (clone $q)
            ->where('leave_status', 'Approved')
            ->whereYear('updated_at', now()->year)
            ->whereMonth('updated_at', now()->month)
            ->count();

        $this->deniedThisMonth = (clone $q)
            ->where('leave_status', 'Denied')
            ->whereYear('updated_at', now()->year)
            ->whereMonth('updated_at', now()->month)
            ->count();
    }

    protected function loadZoneStaffCount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $employee = $this->employee();

        $query = Employee::query()
            ->visibleInErp()
            ->where('is_active', true);

        if ($user->isHrUser() && ! $user->isHeadOfficeHr()) {
            abort_if(! $employee, 403, 'Employee profile is required for regional leave access.');

            $query->where('region_id', $employee->region_id);
        }

        $this->zoneStaffCount = (int) $query->count();
    }

    protected function loadSlaStats(): void
    {
        // Every request with at least one finished stage; each average skips requests whose
        // stage times are unknown (see LeaveRequest::managerResponseHours() and friends).
        $q = LeaveRequest::query()
            ->where(fn ($query) => $query->whereNotNull('recommended_at')->orWhereNotNull('decided_at'));

        if (auth()->user()->isHrUser() && ! auth()->user()->isHeadOfficeHr()) {
            $employee = $this->employee();

            abort_if(! $employee, 403, 'Employee profile is required for regional leave access.');

            $q->where('region_id', $employee->region_id);
        }

        $requests = $q->get();

        $this->slaStats = [
            // Submission to the manager's recommendation (or rejection)
            'avg_manager_hours' => $this->averageHours($requests->map->managerResponseHours()),

            // Recommendation to the final approver's decision
            'avg_final_hours' => $this->averageHours($requests->map->approverHours()),

            // Submission to the final decision, whoever made it
            'avg_total_hours' => $this->averageHours($requests->map->cycleHours()),
        ];
    }

    /** Rounded mean of the known values, or null when there are none yet. */
    protected function averageHours(Collection $hours): ?float
    {
        $known = $hours->reject(fn ($value) => $value === null);

        return $known->isNotEmpty() ? round($known->avg()) : null;
    }

    protected function loadSlowApprovals(): void
    {
        $q = LeaveRequest::query()
            ->where('leave_status', 'Approved')
            ->whereNotNull('submitted_at')
            ->whereNotNull('decided_at')
            ->with('requester')
            ->orderByDesc('decided_at');

        if (auth()->user()->isHrUser() && ! auth()->user()->isHeadOfficeHr()) {
            $employee = $this->employee();

            abort_if(! $employee, 403, 'Employee profile is required for regional leave access.');

            $q->where('region_id', $employee->region_id);
        }

        // SLA breach: more than 72 hours from submission to approval
        $this->slowestApprovals = $q->get()->filter(fn (LeaveRequest $r) => $r->cycleHours() > 72)->take(5);
    }

    protected function loadPendingApprovals(): void
    {
        $user = auth()->user();
        $emp = $this->employee();

        $q = LeaveRequest::query()
            ->with(['requester', 'department'])
            ->where('leave_status', 'Pending Approval');

        if ($user->isHrUser() && ! $user->isHeadOfficeHr()) {
            abort_if(! $emp, 403, 'Employee profile is required for regional leave access.');

            $q->where('region_id', $emp->region_id);
        }

        $this->pendingApprovals = $q->latest()->limit(5)->get();
    }

    protected function loadLeaveByType(): void
    {
        $year = now()->year;

        $q = LeaveRequest::query()
            ->where('leave_status', 'Approved')
            ->whereYear('start_date', $year);

        if (auth()->user()->isHrUser() && ! auth()->user()->isHeadOfficeHr()) {
            $employee = $this->employee();

            abort_if(! $employee, 403, 'Employee profile is required for regional leave access.');

            $q->where('region_id', $employee->region_id);
        }

        $total = (clone $q)->sum('total_days_applied');

        foreach (['Annual', 'Sick', 'Casual', 'Paternity', 'Maternity'] as $type) {
            $days = (clone $q)->where('leave_type', $type)->sum('total_days_applied');
            $this->leaveByType[$type] = $total > 0 ? round(($days / $total) * 100) : 0;
        }
    }

    protected function loadGenderBreakdown(): void
    {
        $q = LeaveRequest::query()
            ->where('leave_status', 'Approved');

        if (auth()->user()->isHrUser() && ! auth()->user()->isHeadOfficeHr()) {
            $employee = $this->employee();

            abort_if(! $employee, 403, 'Employee profile is required for regional leave access.');

            $q->where('region_id', $employee->region_id);
        }

        $this->genderBreakdown = [
            'male' => (clone $q)->whereHas('requester', fn ($e) => $e->where('gender', 'Male'))->count(),
            'female' => (clone $q)->whereHas('requester', fn ($e) => $e->where('gender', 'Female'))->count(),
        ];
    }

    public function render()
    {
        $dashboard = app(LeaveDashboardService::class);
        $user = Auth::user();
        $employee = $this->employee();
        $year = (int) now()->format('Y');

        return view('livewire.leave.hr-dashboard', [
            'daysByMonth' => $dashboard->approvedDaysByMonth($user, $employee, $year),
            'requestsByStatus' => $dashboard->requestsByStatus($user, $employee, $year),
            'upcomingAbsences' => $dashboard->upcomingAbsences($user, $employee),
        ]);
    }

    protected function employee(): ?Employee
    {
        $user = Auth::user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
