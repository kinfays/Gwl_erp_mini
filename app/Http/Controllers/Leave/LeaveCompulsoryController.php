<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Services\Leave\CompulsoryLeaveService;
use Illuminate\Http\Request;

class LeaveCompulsoryController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            // Controller-level enforcement (defense-in-depth); the route also carries the role and permission middleware.
            $this->enforceModule($request, 'leave');

            abort_unless(app(CompulsoryLeaveService::class)->canManage($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        return view('leave.compulsory');
    }
}
