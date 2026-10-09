<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\User;
use App\Services\Leave\LeaveActingAssignmentService;
use App\Services\Leave\LeaveLetterSettingsService;
use App\Services\Leave\SignatureService;
use App\Services\Letters\LetterWorkflowService;
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
            ->map(function (array $module) use ($currentModule, $user, $employee) {
                $module['active'] = $module['slug'] === $currentModule;

                // Hardcopies handed to this person that still wait for their confirmation, on the Letters tab.
                if ($module['slug'] === Permission::MODULE_LETTERS) {
                    $module['badge'] = $employee ? app(LetterWorkflowService::class)->pendingIncomingCount($employee) : 0;
                }

                if ($module['slug'] === Permission::MODULE_STAFF && ! $this->canViewStaff($user)) {
                    $module['route'] = $this->safeRoute('leave.hr-dashboard');
                }

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
                'slug' => Permission::MODULE_STAFF,
                'title' => 'Staff Management',
                'short' => 'Staff',
                'icon_name' => 'users',
                'route' => $this->safeRoute('staff.index'),
            ],
            [
                'slug' => Permission::MODULE_LEAVE,
                'title' => 'Leave Management',
                'short' => 'Leave',
                'icon_name' => 'calendar-days',
                'route' => $this->safeRoute('leave.home'),
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
                'slug' => Permission::MODULE_COMMERCIAL,
                'title' => 'Commercial',
                'short' => 'Commercial',
                'icon_name' => 'chart-column',
                'route' => $this->safeRoute('commercial.home'),
            ],
            [
                'slug' => Permission::MODULE_HEALTH_SAFETY,
                'title' => 'Health & Safety',
                'short' => 'Health & Safety',
                'icon_name' => 'triangle-alert',
                'route' => $this->safeRoute('health_safety.home'),
            ],
            [
                'slug' => Permission::MODULE_BLOG,
                'title' => 'Regional Blog',
                'short' => 'Blog',
                'icon_name' => 'scroll-text',
                'route' => $this->safeRoute('blog.home'),
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
            fn (array $def) => match ($def['slug']) {
                Permission::MODULE_CREDIT_UNION => (bool) config('gwl.credit_union_module_enabled'),
                Permission::MODULE_COMMERCIAL => (bool) config('gwl.commercial_module_enabled'),
                Permission::MODULE_HEALTH_SAFETY => (bool) config('gwl.health_safety_module_enabled'),
                Permission::MODULE_BLOG => (bool) config('gwl.blog_module_enabled'),
                default => true,
            }
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
            Permission::MODULE_COMMERCIAL => $this->commercialSidebar($user),
            Permission::MODULE_HEALTH_SAFETY => $this->healthSafetySidebar($user),
            Permission::MODULE_BLOG => $this->blogSidebar($user),
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
            ->map(function (array $item) use ($user) {
                if (($item['type'] ?? 'item') === 'section') {
                    return $item;
                }

                // An item may carry a `badge` closure returning a count to show next to its label (0 hides it).
                $item['badge'] = isset($item['badge']) ? (int) $item['badge']($user) : 0;

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
        // Someone acting in a final-approver post approves without holding the role.
        $canReview = fn (User $currentUser) => $this->isHrViewer($currentUser)
            || $this->isManagerialUser($currentUser)
            || $currentUser->hasRoles('managing_director')
            || app(LeaveActingAssignmentService::class)->hasActiveAssignment($currentUser);
        $homeRoute = $this->leaveHomeRoute($user);

        return [
            [
                'label' => 'Leave Home',
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
                'label' => 'My Signature',
                'route' => 'leave.signature',
                'active' => ['leave.signature'],
                'icon' => $this->icon('check'),
                'icon_name' => 'pen-line',
                'can' => fn (User $currentUser) => app(SignatureService::class)->canSign($currentUser),
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
        $canViewStaff = fn (User $currentUser) => $this->canViewStaff($currentUser);
        $isHrViewer = fn (User $currentUser) => $this->isHrViewer($currentUser);
        // The HR dashboard and HR tools (moved here from Leave). The routes and permissions are the leave module's own.
        $canManageCompulsory = fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'admin')
            || ($currentUser->hasRoles('hr_headoffice') && $currentUser->hasPermission('leave.manage_compulsory'));
        $canExportLeave = fn (User $currentUser) => $currentUser->hasPermission('leave.export')
            || $currentUser->hasRoles('super_admin', 'admin', 'hr_headoffice', 'hr_region');
        // Regional HR edit only their own region: that limit is enforced by the screen and LeaveHrContactService.
        $canManageHrContacts = fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'admin')
            || ($currentUser->hasRoles('hr_headoffice', 'hr_region') && $currentUser->hasPermission('leave.manage_hr_contacts'));

        return [
            [
                'label' => 'HR Dashboard',
                'route' => 'leave.hr-dashboard',
                'active' => ['leave.hr-dashboard'],
                'icon' => $this->icon('dashboard'),
                'icon_name' => 'layout-dashboard',
                'can' => $isHrViewer,
            ],
            [
                'label' => 'All Employees',
                'route' => 'staff.index',
                'active' => ['staff.index'],
                'icon' => $this->icon('user'),
                'icon_name' => 'users',
                'can' => $canViewStaff,
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
                'label' => 'HR Tools',
                'can' => $isHrViewer,
            ],
            [
                'label' => 'HR Analytics',
                'route' => 'leave.hr-analytics',
                'active' => ['leave.hr-analytics'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
                'can' => $isHrViewer,
            ],
            [
                'label' => 'Compulsory Leave',
                'route' => 'leave.compulsory',
                'active' => ['leave.compulsory'],
                'icon' => $this->icon('spark'),
                'icon_name' => 'calendar-x',
                'can' => $canManageCompulsory,
            ],
            [
                'label' => 'HR Contacts',
                'route' => 'leave.hr-contacts',
                'active' => ['leave.hr-contacts'],
                'icon' => $this->icon('user-plus'),
                'icon_name' => 'mail',
                'can' => $canManageHrContacts,
            ],
            [
                'label' => 'Letter Settings',
                'route' => 'leave.letter-settings',
                'active' => ['leave.letter-settings'],
                'icon' => $this->icon('list'),
                'icon_name' => 'file-text',
                'can' => fn (User $currentUser) => app(LeaveLetterSettingsService::class)->canAccess($currentUser),
            ],
            [
                'label' => 'Acting Assignments',
                'route' => 'leave.acting',
                'active' => ['leave.acting'],
                'icon' => $this->icon('user-plus'),
                'icon_name' => 'user-check',
                'can' => fn (User $currentUser) => app(LeaveActingAssignmentService::class)->canManage($currentUser),
            ],
            [
                'label' => 'Leave Reports',
                'route' => 'leave.reports',
                'active' => ['leave.reports'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
                'can' => $canExportLeave,
            ],
            [
                'label' => 'Staff Reports',
                'route' => 'staff.reports',
                'active' => ['staff.reports'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
                'can' => $canViewReports,
            ],
            [
                'type' => 'section',
                'label' => 'Data',
                'can' => $canViewStaff,
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
                // Global Admin and super_admin; the ICT team never sees the roles screen.
                'can' => fn (User $currentUser) => $currentUser->tier() >= User::TIER_GLOBAL_ADMIN,
            ],
            [
                'label' => 'Bulk Import',
                'route' => 'uac.import',
                'active' => ['uac.import'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'file-spreadsheet',
                'can' => fn (User $currentUser) => $currentUser->tier() >= User::TIER_GLOBAL_ADMIN,
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
                'label' => 'Transmittals',
                'route' => 'letters.transmittals',
                'active' => ['letters.transmittals'],
                'icon' => $this->icon('list'),
                'icon_name' => 'send',
                // Hardcopies handed to this desk that are still waiting for a confirmation.
                'badge' => function (User $currentUser) {
                    $employee = $currentUser->employee ?? $currentUser->employeeByStaffId;

                    return $employee ? app(LetterWorkflowService::class)->pendingIncomingCount($employee) : 0;
                },
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
            [
                // The holder's own register (Excel / PDF); only for people who may export it.
                'label' => 'My register',
                'route' => 'letters.register',
                'active' => ['letters.register'],
                'icon' => $this->icon('list'),
                'icon_name' => 'file-spreadsheet',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('letters.export'),
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
                'label' => 'Summary',
                'route' => 'assets.summary',
                'active' => ['assets.summary'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
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
                'label' => 'Audits',
                'route' => 'assets.audits',
                'active' => ['assets.audits', 'assets.audits.show'],
                'icon' => $this->icon('report'),
                'icon_name' => 'clipboard-check',
                'can' => fn (User $currentUser) => $currentUser->hasPermission('assets.manage_audits') || $currentUser->hasRoles('super_admin'),
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
                'label' => 'Replacement Policy',
                'route' => 'assets.settings.replacement-policy',
                'active' => ['assets.settings.replacement-policy'],
                'icon' => $this->icon('shield'),
                'icon_name' => 'clock',
                'can' => fn (User $currentUser) => $currentUser->hasPermission('assets.manage_replacement_policy') || $currentUser->hasRoles('super_admin'),
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

    protected function commercialSidebar(User $user): array
    {
        $canUpload = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('commercial.upload_reports')
            || $currentUser->hasPermission('commercial.resolve_matches')
            || $currentUser->hasPermission('commercial.void_batches');

        $canSeeReading = fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || $currentUser->hasPermission('commercial.view_reading')
            || $currentUser->hasPermission('commercial.view_reader_performance');

        return [
            [
                'label' => 'Overview',
                'route' => 'commercial.home',
                'active' => ['commercial.home'],
                'icon' => $this->icon('dashboard'),
                'icon_name' => 'layout-dashboard',
            ],
            [
                'label' => 'Summary',
                'route' => 'commercial.summary',
                'active' => ['commercial.summary'],
                'icon' => $this->icon('report'),
                'icon_name' => 'chart-column',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin')
                    || $currentUser->hasPermission('commercial.view_dashboard')
                    || $currentUser->hasPermission('commercial.view_billing')
                    || $currentUser->hasPermission('commercial.view_reading'),
            ],
            [
                'label' => 'Reading',
                'route' => 'commercial.reading',
                'active' => ['commercial.reading', 'commercial.reading.reader'],
                'icon' => $this->icon('report'),
                'icon_name' => 'trending-up',
                'can' => $canSeeReading,
            ],
            [
                'label' => 'Billing',
                'route' => 'commercial.billing',
                'active' => ['commercial.billing'],
                'icon' => $this->icon('report'),
                'icon_name' => 'banknote',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('commercial.view_billing'),
            ],
            [
                'label' => 'Settings',
                'route' => 'commercial.settings',
                'active' => ['commercial.settings'],
                'icon' => $this->icon('shield'),
                'icon_name' => 'settings',
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('commercial.manage_settings'),
            ],
            [
                'label' => 'Uploads',
                'route' => 'commercial.batches',
                'active' => ['commercial.batches', 'commercial.batches.show'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'file-spreadsheet',
                'can' => $canUpload,
            ],
        ];
    }

    protected function healthSafetySidebar(User $user): array
    {
        $can = fn (string ...$slugs) => fn (User $currentUser) => $currentUser->hasRoles('super_admin')
            || collect($slugs)->contains(fn (string $slug) => $currentUser->hasPermission($slug));

        return [
            [
                'label' => 'Overview',
                'route' => 'health_safety.home',
                'active' => ['health_safety.home'],
                'icon' => $this->icon('dashboard'),
                'icon_name' => 'layout-dashboard',
                'can' => $can('health_safety.view_dashboard', 'health_safety.view_incidents'),
            ],
            [
                'label' => 'Report an incident',
                'route' => 'health_safety.report',
                'active' => ['health_safety.report'],
                'icon' => $this->icon('plus-circle'),
                'icon_name' => 'circle-plus',
                'can' => $can('health_safety.report_incident'),
            ],
            [
                'label' => 'My reports',
                'route' => 'health_safety.mine',
                'active' => ['health_safety.mine'],
                'icon' => $this->icon('list'),
                'icon_name' => 'clipboard-list',
                'can' => $can('health_safety.report_incident'),
            ],
            [
                'label' => 'Incidents',
                'route' => 'health_safety.incidents',
                'active' => ['health_safety.incidents', 'health_safety.incidents.show'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'triangle-alert',
                'can' => $can('health_safety.view_incidents'),
            ],
            [
                'label' => 'Actions',
                'route' => 'health_safety.actions',
                'active' => ['health_safety.actions'],
                'icon' => $this->icon('check'),
                'icon_name' => 'list-checks',
                // Whoever has been given an action sees it here, even without the right to see the register.
                'can' => fn (User $currentUser) => $can('health_safety.view_incidents')($currentUser)
                    || $this->hasAssignedHealthSafetyAction($currentUser),
            ],
            [
                'label' => 'Fire extinguishers',
                'route' => 'health_safety.extinguishers.index',
                'active' => ['health_safety.extinguishers.*'],
                'icon' => $this->icon('shield'),
                'icon_name' => 'shield-check',
                'can' => $can('health_safety.view_equipment'),
            ],
            [
                'label' => 'First aid kits',
                'route' => 'health_safety.kits.index',
                'active' => ['health_safety.kits.*'],
                'icon' => $this->icon('plus-circle'),
                'icon_name' => 'briefcase',
                'can' => $can('health_safety.view_equipment'),
            ],
            [
                'label' => 'Expiry register',
                'route' => 'health_safety.expiry-register',
                'active' => ['health_safety.expiry-register'],
                'icon' => $this->icon('clock'),
                'icon_name' => 'calendar-days',
                'can' => $can('health_safety.view_equipment'),
            ],
            [
                'label' => 'My equipment',
                'route' => 'health_safety.my-equipment',
                'active' => ['health_safety.my-equipment'],
                'icon' => $this->icon('list'),
                'icon_name' => 'clipboard-check',
                // Whoever is named as responsible for an extinguisher or kit finds it here, with no safety permission.
                'can' => fn (User $currentUser) => $this->isResponsibleForHealthSafetyEquipment($currentUser),
            ],
            [
                'label' => 'PPE stock',
                'route' => 'health_safety.ppe.stock',
                'active' => ['health_safety.ppe.stock'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'boxes',
                'can' => $can('health_safety.view_equipment'),
            ],
            [
                'label' => 'PPE issues',
                'route' => 'health_safety.ppe.issues',
                'active' => ['health_safety.ppe.issues', 'health_safety.ppe.import'],
                'icon' => $this->icon('list'),
                'icon_name' => 'user-check',
                'can' => $can('health_safety.view_equipment'),
            ],
            [
                'label' => 'PPE gaps',
                'route' => 'health_safety.ppe.gaps',
                'active' => ['health_safety.ppe.gaps'],
                'icon' => $this->icon('report'),
                'icon_name' => 'user-x',
                'can' => $can('health_safety.view_equipment'),
            ],
            [
                'label' => 'My PPE',
                'route' => 'health_safety.my-ppe',
                'active' => ['health_safety.my-ppe'],
                'icon' => $this->icon('user'),
                'icon_name' => 'user',
                // Everyone with an employee record has PPE of their own to look at; nobody needs a permission for it.
                'can' => fn (User $currentUser) => ($currentUser->employee ?? $currentUser->employeeByStaffId) !== null,
            ],
            [
                'label' => 'Kit templates',
                'route' => 'health_safety.kit-templates',
                'active' => ['health_safety.kit-templates'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'list-checks',
                'can' => $can('health_safety.manage_master_data'),
            ],
            [
                'label' => 'Import equipment',
                'route' => 'health_safety.equipment-import',
                'active' => ['health_safety.equipment-import'],
                'icon' => $this->icon('report'),
                'icon_name' => 'file-spreadsheet',
                'can' => $can('health_safety.manage_equipment'),
            ],
            [
                'label' => 'PPE types',
                'route' => 'health_safety.ppe.types',
                'active' => ['health_safety.ppe.types'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'settings',
                'can' => $can('health_safety.manage_master_data'),
            ],
            [
                'label' => 'PPE entitlements',
                'route' => 'health_safety.ppe.entitlements',
                'active' => ['health_safety.ppe.entitlements'],
                'icon' => $this->icon('list'),
                'icon_name' => 'list-checks',
                'can' => $can('health_safety.manage_master_data'),
            ],
            [
                'label' => 'PPE reorder levels',
                'route' => 'health_safety.ppe.reorder-levels',
                'active' => ['health_safety.ppe.reorder-levels'],
                'icon' => $this->icon('bars'),
                'icon_name' => 'trending-down',
                'can' => $can('health_safety.manage_master_data'),
            ],
            [
                'label' => 'Sites',
                'route' => 'health_safety.sites',
                'active' => ['health_safety.sites'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'map-pin',
                'can' => $can('health_safety.manage_master_data'),
            ],
            [
                'label' => 'Settings',
                'route' => 'health_safety.settings',
                'active' => ['health_safety.settings'],
                'icon' => $this->icon('grid'),
                'icon_name' => 'settings',
                'can' => $can('health_safety.manage_settings'),
            ],
        ];
    }

    /** Everyone reads their region's blog; writing (manage, new) is for holders of blog.manage_posts. */
    protected function blogSidebar(User $user): array
    {
        $canManage = fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('blog.manage_posts');

        return [
            [
                'label' => 'Latest articles',
                'route' => 'blog.home',
                'active' => ['blog.home', 'blog.show'],
                'icon' => $this->icon('list'),
                'icon_name' => 'scroll-text',
            ],
            [
                'label' => 'Manage posts',
                'route' => 'blog.manage',
                'active' => ['blog.manage', 'blog.edit'],
                'icon' => $this->icon('stack'),
                'icon_name' => 'list',
                'can' => $canManage,
            ],
            [
                'label' => 'New article',
                'route' => 'blog.create',
                'active' => ['blog.create'],
                'icon' => $this->icon('plus-circle'),
                'icon_name' => 'circle-plus',
                'can' => $canManage,
            ],
        ];
    }

    protected function hasAssignedHealthSafetyAction(User $user): bool
    {
        $employeeId = ($user->employee ?? $user->employeeByStaffId)?->id;

        return $employeeId !== null
            && \App\Models\HsIncidentAction::query()->where('assigned_to_employee_id', $employeeId)->exists();
    }

    protected function isResponsibleForHealthSafetyEquipment(User $user): bool
    {
        $employeeId = ($user->employee ?? $user->employeeByStaffId)?->id;

        return $employeeId !== null
            && (\App\Models\HsFireExtinguisher::query()->where('responsible_employee_id', $employeeId)->exists()
                || \App\Models\HsFirstAidKit::query()->where('responsible_employee_id', $employeeId)->exists());
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

        // The HR dashboard and HR tools are in the Staff module, and Global Admin may use them (not the staff list itself).
        if ($module === Permission::MODULE_STAFF && $user->hasRoles('admin')) {
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
