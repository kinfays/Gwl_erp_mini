<?php

namespace App\Livewire\Leave;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Approvals extends Component
{
    public function getRequestsProperty()
    {
        $employee = Auth::user()->employee;

        return LeaveRequest::query()
            ->where('leave_status', LeaveStatus::PendingApproval)
            ->where(function ($q) use ($employee) {

                // Manager stage
                $q->where('manager_id', $employee->id)

                // Chief Manager stage
                ->orWhere(function ($q2) use ($employee) {
                    $q2->where('manager_recommendation', 'Recommended')
                       ->where(function ($q3) use ($employee) {

                           if ($employee->isHeadOffice()) {
                               $q3->whereNull('region_id');
                           } else {
                               $q3->where('region_id', $employee->region_id);
                           }
                       });
                });
            })
            ->latest()
            ->get();
    }

    public function render()
    {
        return view('livewire.leave.approvals');
    }
}