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

        $this->loadStats();
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

    public function render()
    {
        return view('livewire.leave.hr-dashboard');
    }
}