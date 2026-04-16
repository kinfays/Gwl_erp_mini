<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user()->loadMissing([
            'roles.moduleAccesses',
            'employee.region',
            'employee.district',
            'employeeByStaffId.region',
            'employeeByStaffId.district',
        ]);

        $employee = $user->employee ?? $user->employeeByStaffId;
        $role = $user->roles->first();

        $modules = collect([
            [
                'slug' => Permission::MODULE_LEAVE,
                'title' => 'Leave Management',
                'description' => 'Apply for leave, track balances, and manage approvals',
                'route' => '#',
                'badge' => 'Everyone',
                'accent' => 'border-t-4 border-[#185FA5]',
                'icon_bg' => 'bg-blue-50',
                'icon_color' => 'text-[#185FA5]',
                'always' => true,
            ],
            [
                'slug' => Permission::MODULE_STAFF,
                'title' => 'Staff Management',
                'description' => 'Manage employee records, import staff data, update profiles',
                'route' => '#',
                'badge' => $role?->display_name ?? 'Authorized',
                'accent' => 'border-t-4 border-slate-200',
                'icon_bg' => 'bg-sky-50',
                'icon_color' => 'text-sky-700',
                'always' => false,
            ],
            [
                'slug' => Permission::MODULE_LETTERS,
                'title' => 'Letters & Documents',
                'description' => 'Receive, review, forward and close official correspondence',
                'route' => '#',
                'badge' => $role?->display_name ?? 'Authorized',
                'accent' => 'border-t-4 border-slate-200',
                'icon_bg' => 'bg-amber-50',
                'icon_color' => 'text-amber-700',
                'always' => false,
            ],
            [
                'slug' => Permission::MODULE_VISITORS,
                'title' => 'Visitors Log',
                'description' => 'Monitor visitor sign-ins, manage check-ins and check-outs',
                'route' => '#',
                'badge' => $role?->display_name ?? 'Authorized',
                'accent' => 'border-t-4 border-slate-200',
                'icon_bg' => 'bg-emerald-50',
                'icon_color' => 'text-emerald-700',
                'always' => false,
            ],
            [
                'slug' => Permission::MODULE_UAC,
                'title' => 'User Access Control',
                'description' => 'Manage users, roles, permissions, and bulk data imports',
                'route' => route('uac.index'),
                'badge' => $role?->display_name ?? 'Authorized',
                'accent' => 'border-t-4 border-slate-200',
                'icon_bg' => 'bg-indigo-50',
                'icon_color' => 'text-indigo-700',
                'always' => false,
            ],
        ])->filter(function (array $module) use ($user) {
            return $module['always'] || in_array($module['slug'], $user->getAccessibleModules(), true);
        })->sortByDesc(fn (array $module) => $module['always'] ? 1 : 0)->values();

        $location = collect([
            $employee?->district?->district_name,
            $employee?->region?->region_name,
        ])->filter()->implode(' • ');

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
            'roleName' => $role?->display_name ?? 'Employee',
            'today' => now()->format('l, d F Y'),
            'unreadNotifications' => 3,
        ]);
    }
}