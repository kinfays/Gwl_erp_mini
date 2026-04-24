<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;


class LeaveRequestController extends Controller
{
    public function __construct()
    {
        $this->middleware('leave.access:leave.view')->only(['index', 'dashboard']);
        $this->middleware('leave.access:leave.apply')->only(['create', 'store']);
        $this->middleware('leave.access:leave.recommend')->only(['recommend']);
        $this->middleware('leave.access:leave.approve')->only(['approve']);
    }

    public function recommend(LeaveRequest $leave)
    {
        if (! $this->userCanActOnLeave($leave)) {
            abort(403, 'You are not authorized to recommend this leave.');
        }

        // proceed
    }

    public function index(): View
    {
        return view('leave.requests');
    }

    public function approve(LeaveRequest $leave)
    {
        if (! $this->userCanActOnLeave($leave, true)) {
            abort(403, 'You are not authorized to approve this leave.');
        }

        // proceed
    }

    protected function userCanActOnLeave(LeaveRequest $leave, bool $isFinal = false): bool
    {
        /** @var User|null $user */
        $user = auth()->guard()->user();

        if (! $user) {
            return false;
        }

        // Manager recommending
        if (! $isFinal && $user->id === $leave->manager_id) {
            return true;
        }

        // Chief manager approving (region / department scoped)
        if ($isFinal && $user->hasRole('chief_manager')) {
            return $user->region_id === $leave->requester->region_id;
        }

        return false;
    }
}
