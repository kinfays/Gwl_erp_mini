<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LeaveHrContactsController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            // Controller-level enforcement (defense-in-depth); the route also carries the permission middleware.
            $this->enforceModule($request, 'leave');

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        return view('leave.hr-contacts');
    }
}
