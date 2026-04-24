<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use Illuminate\Http\Request;

class LeaveApprovalsController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            // Controller-level enforcement (defense-in-depth)
            $this->enforceModule($request, 'leave');
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        return view('leave.approvals');
    }
}