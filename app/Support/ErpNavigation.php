<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class ErpNavigation
{
    public function build(?User $user, string $currentModule): array
    {
        if (! $user) {
            return [
                'modules' => [],
                'sidebar' => [],
                'currentModule' => [
                    'slug' => $currentModule,
                    'title' => Str::of($currentModule)->replace('_', ' ')->title()->toString(),
                ],
                'identity' => [
                    'name' => 'Guest',
                    'initials' => 'GU',
                    'role' => 'Guest',
                    'location' => 'Unknown location',
                ],
            ];
        }

        $user->loadMissing([
            'roles.moduleAccesses',
            'roles.permissions',
            'employee.region',
            'employee.district',
            'employee.department',
            'employeeByStaffId.region',
            'employeeByStaffId.district',
            'employeeByStaffId.department',
        ]);

        $employee = $user->employee ?? $user->employeeByStaffId;
        $modules = collect($this->moduleDefinitions())
            ->filter(fn (array $module) => $this->userCanAccessModule($user, $module['slug']))
            ->map(function (array $module) use ($currentModule, $user) {
                $module['active'] = $module['slug'] === $currentModule;

                if ($module['slug'] === Permission::MODULE_ASSETS) {
                    $module['route'] = $this->safeRoute($this->assetsLandingRoute($user));
                }

                return $module;
            })
            ->values()
            ->all();

        return [
            'modules' => $modules,
            'sidebar' => $this->sidebarFor($user, $currentModule),
            'currentModule' => collect($this->moduleDefinitions())->firstWhere('slug', $currentModule)
                ?? ['slug' => $currentModule, 'title' => Str::of($currentModule)->replace('_', ' ')->title()->toString()],
            'identity' => [
                'name' => $user->full_name ?? $employee?->full_name ?? $user->email,
                'initials' => $this->initials($user->full_name ?? $employee?->full_name ?? 'User'),
                'role' => $user->displayRoleNames(),
                'location' => collect([
                    $employee?->district?->district_name,
                    $employee?->region?->region_name,
                ])->filter()->join(' - ') ?: 'Location not assigned',
            ],
        ];
    }

    protected function moduleDefinitions(): array
    {
        $definitions = [
            [
                'slug' => Permission::MODULE_LEAVE,
                'title' => 'Leave Management',
                'short' => 'Leave',
                'icon_name' => 'calendar-days',
                'route' => $this->safeRoute('leave.home'),
            ],
            [
                'slug' => Permission::MODULE_STAFF,
                'title' => 'Staff Management',
                'short' => 'Staff',
                'icon_name' => 'users',
                'route' => $this->safeRoute('staff.index'),
            ],
            [
                'slug' => Permission::MODULE_LETTERS,
                'title' => 'Letters',
                'short' => 'Letters',
                'icon_name' => 'mail',
                'route' => $this->safeRoute('letters.home'),
            ],
            [
                'slug' => Permission::MODULE_VISITORS,
                'title' => 'Visitors Log',
                'short' => 'Visitors',
                'icon_name' => 'door-open',
                'route' => $this->safeRoute('visitors.home'),
            ],
            [
                'slug' => Permission::MODULE_ASSETS,
                'title' => 'ICT Assets',
                'short' => 'Assets',
                'icon_name' => 'monitor',
                'route' => $this->safeRoute('assets.home'),
            ],
            [
                'slug' => Permission::MODULE_TRANSPORT,
                'title' => 'Transport',
                'short' => 'Transport',
                'icon_name' => 'car',
                'route' => $this->safeRoute('transport.home'),
            ],
            [
                'slug' => Permission::MODULE_CREDIT_UNION,
                'title' => 'Credit Union',
                'short' => 'Credit Union',
                'icon_name' => 'landmark',
                'route' => $this->safeRoute('credit-union.home'),
            ],
            [
                'slug' => Permission::MODULE_UAC,
                'title' => 'Access Control',
                'short' => 'Access',
                'icon_name' => 'shield-check',
                'route' => $this->safeRoute('uac.index'),
            ],
        ];

        return array_values(array_filter(
            $definitions,
            fn (array $def) => $def['slug'] !== Permission::MODULE_CREDIT_UNION || config('gwl.credit_union_module_enabled')
        ));
    }

    protected function sidebarFor(User $user, string $currentModule): array
    {
        $definitions = match ($currentModule) {
            Permission::MODULE_LEAVE => $this->leaveSidebar($user),
            Permission::MODULE_STAFF => $this->staffSidebar($user),
            Permission::MODULE_UAC => $this->uacSidebar($user),
            Permission::MODULE_LETTERS => $this->lettersSidebar($user),
            Permission::MODULE_VISITORS => $this->visitorsSidebar($user),
            Permission::MODULE_ASSETS => $this->assetsSidebar($user),
            Permission::MODULE_TRANSPORT => $this->transportSidebar($user),
            Permission::MODULE_CREDIT_UNION => $this->creditUnionSidebar($user),
            default => [],
        };

        return collect($definitions)
            ->filter(function (array $item) use ($user) {
                // A section heading is always shown unless it carries its own `can` (used by the MDM heading, so it
                // disappears together with its entries when the feature flag is off).
                if (($item['type'] ?? 'item') === 'section') {
                    return isset($item['can']) ? (bool) $item['can']($user) : true;
                }

                $condition = $item['can'] ?? fn () => true;

                return (bool) $condition($user);
            })
            ->map(function (array $item) {
                if (($item['type'] ?? 'item') === 'section') {
                    return $item;
                }

                $patterns = $item['active'] ?? [$item['route']];
                $item['url'] = $this->safeRoute($item['route']) ?? '#';
                $item['is_active'] = collect((array) $patterns)->contains(
                    fn (string $pattern) => request()->routeIs($pattern)
                );

                return $item;
            })
            ->values()
            ->all();
    }

    protected function leaveSidebar(User $user): array
    {
        $canReview = fn (User $currentUser) => $this->isHrViewer($currentUser) || $this->isManagerialUser($currentUser);
        $canManageHrTools = fn (User $currentUser) => $currentUser->hasPermission('leave.manage_compulsory')
            || $currentUser->hasRoles('super_admin', 'admin', 'hr_headoffice', 'hr_region');
        $canExport = fn (User $currentUser) => $currentUser->hasPermission('leave.export')
            || $currentUser->hasRoles('super_admin', 'admin', 'hr_headoffice', 'hr_region');
        $homeRoute = $this->leaveHomeRoute($user);

        return [
            [
                'label' => $this->isHrViewer($user) ? 'HR Dashboard' : 'Leave Home',
                'route' => $homeRoute,
                'active' => ['leave.home', 'leave.team-dashboard'],
                'icon' => $this->icon('dashboard'),
                'icon_name' => 'layout-dashboard',
            ],
            [
                'label' => 'All Requests',
                'route' => 'leave.requests',
                'active' => ['leave.requests'],
                'icon' => $this->icon('list'),
                'icon_name' => 'list-checks',
                'can' => $canReview,
            ],
            [
                'label' => 'Apply for Leave',
                'route' => 'leave.apply',
                'active' => ['leave.apply'],
                'icon' => $this->icon('plus-circle'),
                'icon_name' => 'circle-plus',
            ],
            [
                'label' => 'My Leave History',
                'route' => 'leave.my-history',
                'active' => ['leave.my-history'],
                'icon' => $this->icon('user'),
                'icon_name' => 'history',
            ],
            [
                'type' => 'section',
                'label' => 'Approvals',
            ],
            [
                'label' => 'Approvals',
                'route' => 'leave.approvals',
                'active' => ['leave.approvals'],
                'icon' => $this->icon('check'),
                'icon_name' => 'square-check-big',
                'can' => $canReview,
            ],
            [
                'type' => 'section',
                'label' => 'HR Tools',
            ],
            [
                'label' => 'Compulsory Leave',
                'route' => 'leave.compulsory',
                'active' => ['leave.compulsory'],
                'icon' => $this->icon('spark'),
                'icon_name' => 'calendar-x',
                'can' => $canManageHrTools,
            ],
            [
                'label' => 'Reports',
                'route' => 'leave.reports',
                'active' => ['leave.reports'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
                'can' => $canExport,
            ],
        ];
    }

    protected function staffSidebar(User $user): array
    {
        $canManage = fn (User $currentUser) => $this->canManageStaff($currentUser);
        $canManageDepartments = fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('staff.manage_departments');
        $canManageRegions = fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('staff.manage_regions');
        $canManageLocations = fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('staff.manage_locations');
        $canManageJobTitles = fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('staff.manage_job_titles');
        $canViewReports = fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('staff.view_reports');

        return [
            [
                'label' => 'All Employees',
                'route' => 'staff.index',
                'active' => ['staff.index'],
                'icon' => $this->icon('user'),
                'icon_name' => 'users',
            ],
            [
                'label' => 'Add Employee',
                'route' => 'staff.create',
                'active' => ['staff.create', 'staff.edit'],
                'icon' => $this->icon('user-plus'),
                'icon_name' => 'user-plus',
                'can' => $canManage,
            ],
            [
                'type' => 'section',
                'label' => 'Data',
            ],
            [
                'label' => 'Reports',
                'route' => 'staff.reports',
                'active' => ['staff.reports'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
                'can' => $canViewReports,
            ],
            [
                'label' => 'Import / Export',
                'route' => 'staff.import',
                'active' => ['staff.import'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'file-spreadsheet',
                'can' => $canManage,
            ],
            [
                'label' => 'Departments',
                'route' => 'staff.departments',
                'active' => ['staff.departments'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'building-2',
                'can' => $canManageDepartments,
            ],
            [
                'label' => 'Regions',
                'route' => 'staff.regions',
                'active' => ['staff.regions'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'map',
                'can' => $canManageRegions,
            ],
            [
                'label' => 'Locations',
                'route' => 'staff.locations',
                'active' => ['staff.locations'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'map-pin',
                'can' => $canManageLocations,
            ],
            [
                'label' => 'Job Titles',
                'route' => 'staff.job-titles',
                'active' => ['staff.job-titles'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'briefcase',
                'can' => $canManageJobTitles,
            ],
        ];
    }

    protected function uacSidebar(User $user): array
    {
        return [
            [
                'label' => 'Users',
                'route' => 'uac.users',
                'active' => ['uac.users'],
                'icon' => $this->icon('user'),
                'icon_name' => 'users',
            ],
            [
                'label' => 'Roles & Permissions',
                'route' => 'uac.roles',
                'active' => ['uac.roles'],
                'icon' => $this->icon('shield'),
                'icon_name' => 'key-round',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'admin'),
            ],
            [
                'label' => 'Bulk Import',
                'route' => 'uac.import',
                'active' => ['uac.import'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'file-spreadsheet',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'admin'),
            ],
            [
                'label' => 'Audit Log',
                'route' => 'uac.audit-log',
                'active' => ['uac.audit-log'],
                'icon' => $this->icon('bars'),
                'icon_name' => 'scroll-text',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin'),
            ],
        ];
    }

    protected function lettersSidebar(User $user): array
    {
        return [
            [
                'label' => 'Dashboard',
                'route' => 'letters.home',
                'active' => ['letters.home'],
                'icon' => $this->icon('dashboard'),
                'icon_name' => 'layout-dashboard',
            ],
            [
                'label' => 'Active Letters',
                'route' => 'letters.active',
                'active' => ['letters.active'],
                'icon' => $this->icon('list'),
                'icon_name' => 'inbox',
            ],
            [
                'label' => 'New Letter',
                'route' => 'letters.create',
                'active' => ['letters.create'],
                'icon' => $this->icon('plus-circle'),
                'icon_name' => 'pen-line',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('letters.create'),
            ],
            [
                'label' => 'Closed Letters',
                'route' => 'letters.closed',
                'active' => ['letters.closed'],
                'icon' => $this->icon('check'),
                'icon_name' => 'archive',
            ],
        ];
    }

    protected function visitorsSidebar(User $user): array
    {
        return [
            [
                'label' => 'Today\'s Log',
                'route' => 'visitors.home',
                'active' => ['visitors.home'],
                'icon' => $this->icon('list'),
                'icon_name' => 'clipboard-list',
            ],
            [
                'label' => 'Historical Log',
                'route' => 'visitors.history',
                'active' => ['visitors.history'],
                'icon' => $this->icon('report'),
                'icon_name' => 'history',
            ],
            [
                'label' => 'Kiosk Screen',
                'route' => 'visitors.kiosk',
                'active' => ['visitors.kiosk'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'tablet',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'receptionist') || $currentUser->hasPermission('visitors.kiosk'),
            ],
        ];
    }

    /**
     * Where the "ICT Assets" module tile should land. `admin` can use MDM but not the rest of Assets, so without this
     * their tile would open the Assets dashboard and answer 403.
     */
    public function assetsLandingRoute(User $user): string
    {
        $canSeeDashboard = $user->hasRoles('super_admin') || $user->hasPermission('assets.view_dashboard');

        if (! $canSeeDashboard && $this->mdmVisibleTo($user) && Route::has('assets.mdm.devices')) {
            return 'assets.mdm.devices';
        }

        return 'assets.home';
    }

    /** MDM entries need the feature flag AND the permission; the flag comes first so it is a hard off-switch. */
    protected function mdmVisibleTo(User $user, string $permission = 'assets.mdm_view'): bool
    {
        return config('gwl.mdm_enabled')
            && ($user->hasRoles('super_admin') || $user->hasPermission($permission));
    }

    protected function assetsSidebar(User $user): array
    {
        $canViewInventory = fn (User $currentUser) => $currentUser->hasPermission('assets.view_inventory') || $currentUser->hasRoles('super_admin');

        return [
            [
                'label' => 'Dashboard',
                'route' => 'assets.home',
                'active' => ['assets.home'],
                'icon' => $this->icon('dashboard'),
                'icon_name' => 'layout-dashboard',
                'can' => fn (User $currentUser) => $currentUser->hasPermission('assets.view_dashboard') || $currentUser->hasRoles('super_admin'),
            ],
            [
                'type' => 'section',
                'label' => 'All Assets',
            ],
            [
                'label' => 'Assets',
                'route' => 'assets.assets',
                'active' => ['assets.assets'],
                'icon' => $this->icon('list'),
                'icon_name' => 'laptop',
                'can' => $canViewInventory,
            ],
            [
                'label' => 'Phones',
                'route' => 'assets.phones',
                'active' => ['assets.phones'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'smartphone',
                'can' => $canViewInventory,
            ],
            [
                'label' => 'Network',
                'route' => 'assets.network',
                'active' => ['assets.network'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'network',
                'can' => $canViewInventory,
            ],
            [
                'label' => 'Maintenance',
                'route' => 'assets.maintenance',
                'active' => ['assets.maintenance'],
                'icon' => $this->icon('spark'),
                'icon_name' => 'wrench',
                'can' => fn (User $currentUser) => $currentUser->hasPermission('assets.manage_maintenance') || $currentUser->hasRoles('super_admin'),
            ],
            [
                'label' => 'Reporting',
                'route' => 'assets.reports',
                'active' => ['assets.reports'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
                'can' => fn (User $currentUser) => $currentUser->hasPermission('assets.manage_reports') || $currentUser->hasRoles('super_admin'),
            ],
            [
                'type' => 'section',
                'label' => 'Mobile Devices',
                'can' => fn (User $currentUser) => $this->mdmVisibleTo($currentUser),
            ],
            [
                'label' => 'MDM Devices',
                'route' => 'assets.mdm.devices',
                'active' => ['assets.mdm.devices', 'assets.mdm.devices.show'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'smartphone',
                'can' => fn (User $currentUser) => $this->mdmVisibleTo($currentUser, 'assets.mdm_view'),
            ],
            [
                'label' => 'Enroll Phone',
                'route' => 'assets.mdm.enroll',
                'active' => ['assets.mdm.enroll'],
                'icon' => $this->icon('plus-circle'),
                'icon_name' => 'circle-plus',
                'can' => fn (User $currentUser) => $this->mdmVisibleTo($currentUser, 'assets.mdm_enroll'),
            ],
            [
                'label' => 'MDM Policies',
                'route' => 'assets.mdm.policies',
                'active' => ['assets.mdm.policies', 'assets.mdm.policies.create', 'assets.mdm.policies.edit'],
                'icon' => $this->icon('shield'),
                'icon_name' => 'shield-check',
                'can' => fn (User $currentUser) => $this->mdmVisibleTo($currentUser, 'assets.mdm_view'),
            ],
            [
                'type' => 'section',
                'label' => 'Settings',
            ],
            [
                'label' => 'Manufacturers',
                'route' => 'assets.settings.manufacturers',
                'active' => ['assets.settings.manufacturers'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'factory',
                'can' => fn (User $currentUser) => $currentUser->hasPermission('assets.manage_manufacturers') || $currentUser->hasRoles('super_admin'),
            ],
            [
                'label' => 'Models',
                'route' => 'assets.settings.models',
                'active' => ['assets.settings.models'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'boxes',
                'can' => fn (User $currentUser) => $currentUser->hasPermission('assets.manage_models') || $currentUser->hasRoles('super_admin'),
            ],
            [
                'label' => 'IP Ranges',
                'route' => 'assets.settings.ip-ranges',
                'active' => ['assets.settings.ip-ranges'],
                'icon' => $this->icon('shield'),
                'icon_name' => 'ethernet-port',
                'can' => fn (User $currentUser) => $currentUser->hasPermission('assets.manage_ip_ranges') || $currentUser->hasRoles('super_admin'),
            ],
            [
                'type' => 'section',
                'label' => 'Agent',
            ],
            [
                'label' => 'Agent Reports',
                'route' => 'assets.agent',
                'active' => ['assets.agent'],
                'icon' => $this->icon('bars'),
                'icon_name' => 'activity',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin'),
            ],
        ];
    }

    protected function transportSidebar(User $user): array
    {
        $canManageVehicles = fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'transport_manager')
            || $currentUser->hasPermission('transport.view_vehicles');
        $canOperate = fn (User $currentUser) => $currentUser->hasPermission('transport.log_mileage')
            || $currentUser->hasPermission('transport.report_issues')
            || $currentUser->hasRoles('super_admin', 'transport_manager');
        $canManageMaintenance = fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'transport_manager')
            || $currentUser->hasPermission('transport.manage_maintenance');
        $canManageExpenses = fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'transport_manager')
            || $currentUser->hasPermission('transport.manage_expenses');
        $canViewReports = fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'transport_manager')
            || $currentUser->hasPermission('transport.view_reports');

        return [
            [
                'label' => 'Dashboard',
                'route' => 'transport.home',
                'active' => ['transport.home'],
                'icon' => $this->icon('dashboard'),
                'icon_name' => 'layout-dashboard',
            ],
            [
                'label' => 'Vehicles',
                'route' => 'transport.vehicles',
                'active' => ['transport.vehicles'],
                'icon' => $this->icon('list'),
                'icon_name' => 'car',
                'can' => $canManageVehicles,
            ],
            [
                'type' => 'section',
                'label' => 'Operations',
            ],
            [
                'label' => 'Mileage',
                'route' => 'transport.mileage',
                'active' => ['transport.mileage'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'gauge',
                'can' => $canOperate,
            ],
            [
                'label' => 'Issues',
                'route' => 'transport.issues',
                'active' => ['transport.issues'],
                'icon' => $this->icon('report'),
                'icon_name' => 'triangle-alert',
                'can' => $canOperate,
            ],
            [
                'label' => 'Maintenance',
                'route' => 'transport.maintenance',
                'active' => ['transport.maintenance'],
                'icon' => $this->icon('spark'),
                'icon_name' => 'wrench',
                'can' => $canManageMaintenance,
            ],
            [
                'label' => 'Expenses',
                'route' => 'transport.expenses',
                'active' => ['transport.expenses'],
                'icon' => $this->icon('bars'),
                'icon_name' => 'receipt',
                'can' => $canManageExpenses,
            ],
            [
                'label' => 'Reports',
                'route' => 'transport.reports',
                'active' => ['transport.reports'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
                'can' => $canViewReports,
            ],
        ];
    }

    protected function creditUnionSidebar(User $user): array
    {
        $canManageMembers = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.manage_members');
        $canSeeApplications = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.manage_members')
            || $currentUser->hasPermission('credit_union.approve_membership');
        $canApply = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.apply_membership');
        $canManageDeductions = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.manage_deductions');
        $canSeeLoans = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.manage_loans')
            || $currentUser->hasPermission('credit_union.approve_loans');
        $canSeeWithdrawals = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.manage_withdrawals')
            || $currentUser->hasPermission('credit_union.approve_withdrawals');
        $canManageRefunds = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.manage_refunds');
        $canManageReceipts = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.manage_receipts');
        $canSeeInterestDistributions = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('credit_union.manage_interest_distribution')
            || $currentUser->hasPermission('credit_union.approve_interest_distribution');

        return [
            [
                'label' => 'Members',
                'route' => 'credit-union.members',
                'active' => ['credit-union.members', 'credit-union.members.show'],
                'icon' => $this->icon('list'),
                'icon_name' => 'users',
                'can' => $canManageMembers,
            ],
            [
                'label' => 'Applications',
                'route' => 'credit-union.members.applications',
                'active' => ['credit-union.members.applications'],
                'icon' => $this->icon('check'),
                'icon_name' => 'clipboard-check',
                'can' => $canSeeApplications,
            ],
            [
                'type' => 'section',
                'label' => 'Contributions',
            ],
            [
                'label' => 'Deductions',
                'route' => 'credit-union.deductions',
                'active' => ['credit-union.deductions', 'credit-union.deductions.show'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'layers',
                'can' => $canManageDeductions,
            ],
            [
                'label' => 'Loans',
                'route' => 'credit-union.loans',
                'active' => ['credit-union.loans', 'credit-union.loans.show'],
                'icon' => $this->icon('bars'),
                'icon_name' => 'hand-coins',
                'can' => $canSeeLoans,
            ],
            [
                'label' => 'Receipts',
                'route' => 'credit-union.receipts',
                'active' => ['credit-union.receipts'],
                'icon' => $this->icon('plus-circle'),
                'icon_name' => 'receipt',
                'can' => $canManageReceipts,
            ],
            [
                'type' => 'section',
                'label' => 'Payouts',
            ],
            [
                'label' => 'Withdrawals',
                'route' => 'credit-union.withdrawals',
                'active' => ['credit-union.withdrawals', 'credit-union.withdrawals.show'],
                'icon' => $this->icon('report'),
                'icon_name' => 'banknote',
                'can' => $canSeeWithdrawals,
            ],
            [
                'label' => 'Refunds',
                'route' => 'credit-union.refunds',
                'active' => ['credit-union.refunds'],
                'icon' => $this->icon('spark'),
                'icon_name' => 'undo-2',
                'can' => $canManageRefunds,
            ],
            [
                'label' => 'Interest Distribution',
                'route' => 'credit-union.interest-distributions',
                'active' => ['credit-union.interest-distributions', 'credit-union.interest-distributions.show'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'percent',
                'can' => $canSeeInterestDistributions,
            ],
            [
                'type' => 'section',
                'label' => 'Self Service',
            ],
            [
                'label' => 'Apply for Membership',
                'route' => 'credit-union.apply',
                'active' => ['credit-union.apply'],
                'icon' => $this->icon('user-plus'),
                'icon_name' => 'user-plus',
                'can' => $canApply,
            ],
        ];
    }

    protected function placeholderSidebar(string $route, string $label): array
    {
        return [
            [
                'label' => $label,
                'route' => $route,
                'active' => [$route],
                'icon' => $this->icon('dashboard'),
                'icon_name' => 'layout-dashboard',
            ],
        ];
    }

    protected function userCanAccessModule(User $user, string $module): bool
    {
        if ($module === Permission::MODULE_LEAVE) {
            return true;
        }

        return in_array($module, $user->getAccessibleModules(), true);
    }

    public function canManageStaff(User $user): bool
    {
        return $user->hasRoles('super_admin', 'hr_headoffice', 'hr_region');
    }

    public function canViewStaff(User $user): bool
    {
        return $this->canManageStaff($user) || $this->isManagerialUser($user);
    }

    public function isManagerialUser(User $user): bool
    {
        return $user->hasRoles(
            'manager',
            'departmental_manager',
            'district_manager',
            'chief_manager',
            'regional_chief_manager'
        );
    }

    public function isHrViewer(User $user): bool
    {
        return $user->hasRoles('super_admin', 'admin', 'hr_headoffice', 'hr_region');
    }

    protected function leaveHomeRoute(User $user): string
    {
        if (! $this->isHrViewer($user) && $this->isManagerialUser($user)) {
            return 'leave.team-dashboard';
        }

        return 'leave.home';
    }

    protected function initials(string $name): string
    {
        $parts = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)));

        return $parts->join('') ?: 'US';
    }

    protected function safeRoute(string $name): ?string
    {
        return Route::has($name) ? route($name) : null;
    }

    protected function icon(string $name): string
    {
        return match ($name) {
            'dashboard' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>',
            'list' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M3 1h10a1 1 0 011 1v12a1 1 0 01-1 1H3a1 1 0 01-1-1V2a1 1 0 011-1zm1 3v1h8V4H4zm0 3v1h8V7H4zm0 3v1h5v-1H4z"/></svg>',
            'plus-circle' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M8 5v6M5 8h6"/></svg>',
            'user' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><circle cx="8" cy="5" r="3"/><path d="M2 13c0-3.314 2.686-5 6-5s6 1.686 6 5H2z"/></svg>',
            'check' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M6.4 11.2L3.2 8l1.1-1.1 2.1 2.1 5.3-5.3L12.8 4l-6.4 7.2z"/></svg>',
            'spark' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1l1.5 3 3.5.5-2.5 2.5.5 3.5L8 9l-3 1.5.5-3.5L3 4.5l3.5-.5z"/></svg>',
            'report' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M2 2h12v12H2V2zm2 2v2h2V4H4zm4 0v2h4V4H8zM4 8v2h2V8H4zm4 0v2h4V8H8z"/></svg>',
            'stack' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M3 2h10v3H3V2zm0 5h10v3H3V7zm0 5h10v2H3v-2z"/></svg>',
            'grid' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>',
            'shield' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1L2 4v4c0 3.5 2.5 6.5 6 7.5C14 14.5 14 11.5 14 8V4L8 1z" fill="none" stroke="currentColor"/></svg>',
            'bars' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M2 3h12v2H2V3zm0 4h12v2H2V7zm0 4h8v2H2v-2z"/></svg>',
            'user-plus' => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><circle cx="8" cy="5" r="3"/><path d="M2 13c0-3.314 2.686-5 6-5 1.365 0 2.624.286 3.63.81"/><path d="M13 9v4M11 11h4" stroke="currentColor" stroke-width="1.2" fill="none"/></svg>',
            default => '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><circle cx="8" cy="8" r="6"/></svg>',
        };
    }
}
