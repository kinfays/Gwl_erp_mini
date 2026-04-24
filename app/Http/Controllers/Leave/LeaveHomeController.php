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

        if ($user->hasRoles('hr_headoffice', 'hr_region', 'super_admin', 'admin')) {
            return view('leave.dashboard');
        }

        return redirect()->route('leave.apply');
    }
}