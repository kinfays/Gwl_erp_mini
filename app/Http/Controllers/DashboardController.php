<?php

namespace App\Http\Controllers;

use App\Services\HomeSummaryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, HomeSummaryService $summary): View
    {
        $user = $request->user()->loadMissing([
            'roles.moduleAccesses',
            'employee.region',
            'employee.district',
            'employeeByStaffId.region',
            'employeeByStaffId.district',
            'employee.jobTitle',
            'employeeByStaffId.jobTitle',
        ]);

        $employee = $user->employee ?? $user->employeeByStaffId;
        $roleName = $user->displayRoleNames();

        // One list of module cards for the whole portal (flags and access are applied in the helper).
        $modules = collect(dashboardModules());

        $location = collect([
            $employee?->district?->district_name,
            $employee?->region?->region_name,
        ])->filter()->implode(' - ');

        $greeting = match (true) {
            now()->hour < 12 => 'Good morning',
            now()->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        return view('dashboard', [
            'user' => $user,
            'employee' => $employee,
            'modules' => $modules,
            'location' => $location ?: 'Location not assigned',
            'greeting' => $greeting,
            'firstName' => strtok($user->full_name ?? $user->name ?? 'User', ' '),
            'roleName' => $roleName,
            'today' => now()->format('l, d F Y'),
            'unreadNotifications' => 3,
            'summary' => $summary->forUser($user),
        ]);
    }
}
