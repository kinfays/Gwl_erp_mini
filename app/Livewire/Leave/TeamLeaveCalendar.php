<?php

namespace App\Livewire\Leave;

use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TeamLeaveCalendar extends Component
{
    public function getTeamLeavesProperty()
    {
        $employee = Auth::user()->employee;

        return LeaveRequest::query()
            ->whereIn('leave_status', ['Planned', 'Pending Approval', 'Approved'])
            ->where(function ($q) use ($employee) {

                if ($employee->unit_id) {
                    $q->whereHas('requester', fn ($q2) =>
                        $q2->where('unit_id', $employee->unit_id)
                    );
                } elseif ($employee->department_id) {
                    $q->where('department_id', $employee->department_id);
                } elseif ($employee->region_id) {
                    $q->where('region_id', $employee->region_id);
                }
            })
            ->orderBy('start_date')
            ->get();
    }

    public function render()
    {
        return view('livewire.leave.team-leave-calendar')
            ->layout('layouts.leave');
    }
}
