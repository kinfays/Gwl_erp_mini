<?php

namespace App\Livewire\Leave;

use Livewire\Component;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;

class HrDashboard extends Component
{
    use EnforcesModuleAccess;

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
        $this->loadStats();
        $this->loadSlaStats();
        $this->loadSlowApprovals();

    }

    protected function loadStats(): void
    {
        /** @var User $user */
        $user = Auth::user();
        
        $employee = $user->employee;

        $q = LeaveRequest::query();

        // Region scoping for HR users
        if ($user->isHrUser() && ! $user->isHeadOfficeHr()) {
            $q->where('region_id', $employee->region_id);
        }

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

    protected function loadSlaStats(): void
{
    $q = \App\Models\LeaveRequest::query()
        ->whereIn('leave_status', ['Approved', 'Denied']);

    if (auth()->user()->isHrUser() && ! auth()->user()->isHeadOfficeHr()) {
        $q->where('region_id', auth()->user()->employee->region_id);
    }

    $requests = $q->get();

    $managerTimes = [];
    $finalTimes = [];
    $totalTimes = [];

    foreach ($requests as $r) {
        // Time to manager recommendation
        if ($r->manager_recommendation !== 'Pending') {
            $managerTimes[] = $r->updated_at->diffInHours($r->created_at);
        }

        // Time to final decision
        if ($r->approved_by_id || $r->leave_status === 'Denied') {
            $finalTimes[] = $r->updated_at->diffInHours($r->created_at);
        }

        // Total cycle time
        $totalTimes[] = $r->updated_at->diffInHours($r->created_at);
    }

    $this->slaStats = [
        'avg_manager_hours' => $managerTimes
            ? round(array_sum($managerTimes) / count($managerTimes))
            : 0,

        'avg_final_hours' => $finalTimes
            ? round(array_sum($finalTimes) / count($finalTimes))
            : 0,

        'avg_total_hours' => $totalTimes
            ? round(array_sum($totalTimes) / count($totalTimes))
            : 0,
    ];
}

    protected function loadSlowApprovals(): void
{
    $q = \App\Models\LeaveRequest::query()
        ->where('leave_status', 'Approved')
        ->with('requester')
        ->orderByDesc('updated_at');

    if (auth()->user()->isHrUser() && ! auth()->user()->isHeadOfficeHr()) {
        $q->where('region_id', auth()->user()->employee->region_id);
    }

    // SLA breach: > 72 hours total cycle
    $this->slowestApprovals = $q->get()->filter(function ($r) {
        return $r->updated_at->diffInHours($r->created_at) > 72;
    })->take(5);
}

    
    protected function loadPendingApprovals(): void
{
    $user = auth()->user();
    $emp = $user->employee;

    $q = \App\Models\LeaveRequest::query()
        ->with(['requester', 'department'])
        ->where('leave_status', 'Pending Approval');

    if ($user->isHrUser() && ! $user->isHeadOfficeHr()) {
        $q->where('region_id', $emp->region_id);
    }

    $this->pendingApprovals = $q->latest()->limit(5)->get();
}

protected function loadLeaveByType(): void
{
    $year = now()->year;

    $q = \App\Models\LeaveRequest::query()
        ->where('leave_status', 'Approved')
        ->whereYear('start_date', $year);

    if (auth()->user()->isHrUser() && ! auth()->user()->isHeadOfficeHr()) {
        $q->where('region_id', auth()->user()->employee->region_id);
    }

    $total = (clone $q)->sum('total_days_applied');

    foreach (['Annual','Sick','Casual','Paternity','Maternity'] as $type) {
        $days = (clone $q)->where('leave_type', $type)->sum('total_days_applied');
        $this->leaveByType[$type] = $total > 0 ? round(($days / $total) * 100) : 0;
    }
}

protected function loadGenderBreakdown(): void
{
    $q = \App\Models\LeaveRequest::query()
        ->where('leave_status', 'Approved');

    if (auth()->user()->isHrUser() && ! auth()->user()->isHeadOfficeHr()) {
        $q->where('region_id', auth()->user()->employee->region_id);
    }

    $this->genderBreakdown = [
        'male' => (clone $q)->whereHas('requester', fn ($e) => $e->where('gender', 'Male'))->count(),
        'female' => (clone $q)->whereHas('requester', fn ($e) => $e->where('gender', 'Female'))->count(),
    ];
}

    public function render()
    {
        return view('livewire.leave.hr-dashboard');
    }
}