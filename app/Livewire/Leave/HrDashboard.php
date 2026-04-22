<?php

namespace App\Livewire\Leave;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use App\Exports\Leave\LeaveRequestsExport;
use App\Exports\Leave\HrDashboardExport;
use Maatwebsite\Excel\Facades\Excel;


class HrDashboard extends Component
{
    public string $statusTab = 'all';

    /* ===================== KPI STATS ===================== */

    public function getStatsProperty(): array
    {
        $query = $this->scopedRequests();

        return [
            'on_leave_now' => (clone $query)
                ->where('leave_status', LeaveStatus::Approved)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->count(),

            'pending' => (clone $query)
                ->where('leave_status', LeaveStatus::PendingApproval)
                ->count(),

            'approved_this_month' => (clone $query)
                ->where('leave_status', LeaveStatus::Approved)
                ->whereMonth('updated_at', now()->month)
                ->count(),

            'denied_this_month' => (clone $query)
                ->where('leave_status', LeaveStatus::Denied)
                ->whereMonth('updated_at', now()->month)
                ->count(),
        ];
    }

    /* ===================== TABLE ===================== */

    public function getRequestsProperty()
    {
        $query = $this->scopedRequests();

        if ($this->statusTab !== 'all') {
            $query->where('leave_status', $this->statusTab);
        }

        return $query->latest()->limit(10)->get();
    }

    /* ===================== CHARTS ===================== */

    public function getLeaveByTypeProperty()
    {
        return $this->scopedRequests()
            ->where('leave_status', LeaveStatus::Approved)
            ->selectRaw('leave_type, COUNT(*) as total')
            ->groupBy('leave_type')
            ->pluck('total', 'leave_type');
    }

    public function getGenderBreakdownProperty()
    {
        return $this->scopedRequests()
            ->where('leave_status', LeaveStatus::Approved)
            ->join('employees', 'leave_requests.requester_id', '=', 'employees.id')
            ->selectRaw('leave_type, gender, COUNT(*) as total')
            ->groupBy('leave_type', 'gender')
            ->get();
    }

    /* ===================== SCOPING ===================== */

    protected function scopedRequests()
    {
        $employee = Auth::user()->employee;

        return LeaveRequest::query()
            ->when(
                ! $employee->isHeadOffice(),
                fn ($q) => $q->where('region_id', $employee->region_id)
            );
    }



public function export()
{
    $data = $this->scopedRequests()
        ->where('leave_status', LeaveStatus::Approved)
        ->get();

    return Excel::download(
        new HrDashboardExport($data),   
        'hr_leave_dashboard.xlsx'
    );
}


    public function render()
    {
        return view('livewire.leave.hr-dashboard');
    }
}