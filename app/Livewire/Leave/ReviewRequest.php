<?php

namespace App\Livewire\Leave;

use App\Models\LeaveRequest;
use App\Services\Leave\LeaveApprovalEngine;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use App\Exports\Leave\LeaveRequestsExport;
use Maatwebsite\Excel\Facades\Excel;


class ReviewRequest extends Component
{
    public LeaveRequest $leaveRequest;
    public ?string $comments = null;

    public function recommend()
    {
        app(LeaveApprovalEngine::class)->recommend(
            $this->leaveRequest,
            Auth::user()->employee,
            $this->comments
        );

        session()->flash('success', 'Leave recommended.');
        return redirect()->route('leave.approvals');
    }

    public function approve()
    {
        app(LeaveApprovalEngine::class)->approve(
            $this->leaveRequest,
            Auth::user()->employee,
            $this->comments
        );

        session()->flash('success', 'Leave approved.');
        return redirect()->route('leave.approvals');
    }

    public function deny()
    {
        app(LeaveApprovalEngine::class)->deny(
            $this->leaveRequest,
            Auth::user()->employee,
            $this->comments
        );

        session()->flash('success', 'Leave denied.');
        return redirect()->route('leave.approvals');
    }
    
public function export()
{
    return Excel::download(
        new LeaveRequestsExport($this->requests),
        'leave_requests.xlsx'
    
    );
}

    public function render()
    {
        return view('livewire.leave.review-request');
    }
}