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
            // The Commercial module was removed; only its name stays, as an inert card for everyone (no route, no flag,
            // no module access, no query). The view draws a card with 'placeholder' as a plain box, not a link.
            [
                'slug' => 'commercial',
                'title' => 'Commercial',
                'description' => 'Coming soon',
                'icon' => 'CM',
                'route' => null,
                'placeholder' => true,
            ],
            ...(config('gwl.health_safety_module_enabled') ? [[
                'slug' => 'health_safety',
                'title' => 'Environmental Health and Safety',
                'description' => 'Report incidents and near misses, and follow them to closure',
                'icon' => 'HS',
                'route' => route('health_safety.home'),
            ]] : []),
            ...(config('gwl.gwater_module_enabled') ? [[
                'slug' => 'gwater',
                'title' => 'G-Water',
                'description' => 'Bottled and sachet water sales, stock, trips, expenses, and profit and loss',
                'icon' => 'GW',
                'route' => route('gwater.home'),
            ]] : []),
            // Finance and Audit are one payment-voucher workflow shown through two modules, behind one flag.
            ...(config('gwl.finance_audit_enabled') ? [[
                'slug' => 'finance',
                'title' => 'Finance',
                'description' => 'Prepare payment vouchers, send them to Audit, and follow queries and acknowledgements',
                'icon' => 'FN',
                'route' => route('finance.home'),
            ], [
                'slug' => 'audit',
                'title' => 'Audit',
                'description' => 'Review payment vouchers from Finance: tick them off or query them',
                'icon' => 'AU',
                'route' => route(app(\App\Support\ErpNavigation::class)->auditLandingRoute($user)),
            ]] : []),
            [
                'slug' => 'uac',
                'title' => 'User Access Control',
                'description' => 'Manage users, roles, permissions, and bulk data imports',
                'icon' => 'AC',
                'route' => route('uac.index'),
            ],
        ];

        // Hardcopies handed to this person that still wait for their confirmation, shown on the Letters card.
        $employee = $user->employee ?? $user->employeeByStaffId;
        $pendingLetters = $employee ? app(\App\Services\Letters\LetterWorkflowService::class)->pendingIncomingCount($employee) : 0;

        $allModules = array_map(
            fn (array $module) => $module['slug'] === 'letters' ? $module + ['badge' => $pendingLetters] : $module,
            $allModules
        );

        $visible = collect($allModules)->where('slug', 'leave');
        $allowedSlugs = $user->getAccessibleModules();

        // Leave is for everyone and comes first; so is the Commercial name card (a placeholder that opens nothing), in its place.
        $visible = $visible->merge(
            collect($allModules)->filter(fn (array $module) => in_array($module['slug'], $allowedSlugs, true) || ($module['placeholder'] ?? false))
        );

        return $visible->unique('slug')->values()->all();
    }
}
