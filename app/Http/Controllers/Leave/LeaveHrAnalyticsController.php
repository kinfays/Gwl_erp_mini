<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Services\Hr\HrAnalyticsService;
use Illuminate\Http\Request;

class LeaveHrAnalyticsController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            // Controller-level enforcement (defense-in-depth); the route also carries the role middleware.
            $this->enforceModule($request, 'leave');

            app(HrAnalyticsService::class)->scopeFor($request->user());

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        return view('leave.hr-analytics');
    }
}
