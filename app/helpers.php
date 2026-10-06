<?php

use Illuminate\Support\Facades\Auth;

if (! function_exists('dashboardGreeting')) {
    function dashboardGreeting(): string
    {
        $hour = now()->hour;

        if ($hour < 12) {
            return 'Good morning';
        }

        if ($hour < 17) {
            return 'Good afternoon';
        }

        return 'Good evening';
    }
}

if (! function_exists('dashboardModules')) {
    function dashboardModules(): array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        $allModules = [
            [
                'slug' => 'staff',
                'title' => 'Staff Management',
                'description' => 'Manage employee records, import staff data, update profiles',
                'icon' => 'ST',
                'route' => route('staff.index'),
            ],
            [
                'slug' => 'leave',
                'title' => 'Leave Management',
                'description' => 'Apply for leave, track balances, and manage approvals',
                'icon' => 'LV',
                'route' => route('leave.home'),
            ],
            [
                'slug' => 'letters',
                'title' => 'Letters & Documents',
                'description' => 'Receive, review, forward and close official correspondence',
                'icon' => 'LT',
                'route' => route('letters.home'),
            ],
            [
                'slug' => 'visitors',
                'title' => 'Visitors Log',
                'description' => 'Monitor visitor sign-ins, manage check-ins and check-outs',
                'icon' => 'VS',
                'route' => route('visitors.home'),
            ],
            [
                'slug' => 'assets',
                'title' => 'ICT Assets',
                'description' => 'Track inventory, maintenance, and device telemetry',
                'icon' => 'AS',
                'route' => route(app(\App\Support\ErpNavigation::class)->assetsLandingRoute($user)),
            ],
            [
                'slug' => 'transport',
                'title' => 'Transport',
                'description' => 'Manage fleet assignments, mileage, issues, and expenses',
                'icon' => 'TR',
                'route' => route('transport.home'),
            ],
            ...(config('gwl.credit_union_module_enabled') ? [[
                'slug' => 'credit_union',
                'title' => 'Credit Union',
                'description' => 'Register members, post shares and savings, and issue statements',
                'icon' => 'CU',
                'route' => route('credit-union.home'),
            ]] : []),
            ...(config('gwl.commercial_module_enabled') ? [[
                'slug' => 'commercial',
                'title' => 'Commercial',
                'description' => 'Billing and meter-reading analytics from the weekly and monthly reports',
                'icon' => 'CM',
                'route' => route('commercial.home'),
            ]] : []),
            [
                'slug' => 'uac',
                'title' => 'User Access Control',
                'description' => 'Manage users, roles, permissions, and bulk data imports',
                'icon' => 'AC',
                'route' => route('uac.index'),
            ],
        ];

        $visible = collect($allModules)->where('slug', 'leave');
        $allowedSlugs = $user->getAccessibleModules();

        $visible = $visible->merge(
            collect($allModules)->whereIn('slug', $allowedSlugs)
        );

        return $visible->unique('slug')->values()->all();
    }
}
