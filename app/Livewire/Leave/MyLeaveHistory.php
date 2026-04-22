<?php

namespace App\Livewire\Leave;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyLeaveHistory extends Component
{
    public bool $showPast = false;

    public function getRequestsProperty()
    {
        $query = LeaveRequest::where('requester_id', Auth::user()->employee->id);

        if (! $this->showPast) {
            $query->whereYear('request_year', now()->year);
        } else {
            $query->whereDate('start_date', '>=', now()->subMonths(36));
        }

        return $query->latest()->get();
    }

    public function reopen(int $id)
    {
        $request = LeaveRequest::findOrFail($id);

        app(\App\Services\Leave\LeaveApprovalEngine::class)
            ->reopen($request, Auth::user()->employee);

        session()->flash('success', 'Leave request reopened.');
    }

    public function render()
    {
        return view('livewire.leave.my-leave-history')
            ->layout('layouts.leave');
    }
}