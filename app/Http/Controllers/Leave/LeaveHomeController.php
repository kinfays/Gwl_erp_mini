<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\EnforcesModuleAccess; // ✅ correct trait for controllers
use Illuminate\Http\Request;

class LeaveHomeController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->enforceModule($request, 'leave'); // ✅ exists in controller trait
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $user = $request->user();

        // HR viewers have their own dashboard under Staff Management; Leave Home is their personal one, so an HR
        // account with no employee record (nothing of its own to show) is sent there instead.
        if ($user->hasRoles('hr_headoffice', 'hr_region', 'super_admin', 'admin') && ! ($user->employee ?? $user->employeeByStaffId)) {
            return redirect()->route('leave.hr-dashboard');
        }

        if ($user->hasRoles('hr_headoffice', 'hr_region', 'super_admin', 'admin')) {
            return view('leave.home');
        }

        if ($user->hasRoles('manager', 'departmental_manager', 'district_manager', 'chief_manager', 'regional_chief_manager')) {
            return view('leave.team-dashboard');
        }

        return view('leave.home');
    }

    public function hrDashboard(Request $request)
    {
        return view('leave.dashboard');
    }
}
