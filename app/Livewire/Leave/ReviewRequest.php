<?php

namespace App\Livewire\Leave;

use App\Models\LeaveRequest;
use App\Services\Leave\LeaveApprovalEngine;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

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

    public function render()
    {
        return view('livewire.leave.review-request');
    }
}