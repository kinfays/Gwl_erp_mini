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
            ->map(function (array $module) use ($currentModule) {
                $module['active'] = $module['slug'] === $currentModule;

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
                'role' => $user->roles->pluck('display_name')->filter()->join(', ') ?: 'Employee',
                'location' => collect([
                    $employee?->district?->district_name,
                    $employee?->region?->region_name,
                ])->filter()->join(' - ') ?: 'Location not assigned',
            ],
        ];
    }

    protected function moduleDefinitions(): array
    {
        return [
            [
                'slug' => Permission::MODULE_LEAVE,
                'title' => 'Leave Management',
                'route' => $this->safeRoute('leave.home'),
            ],
            [
                'slug' => Permission::MODULE_STAFF,
                'title' => 'Staff Management',
                'route' => $this->safeRoute('staff.index'),
            ],
            [
                'slug' => Permission::MODULE_LETTERS,
                'title' => 'Letters',
                'route' => $this->safeRoute('letters.home'),
            ],
            [
                'slug' => Permission::MODULE_VISITORS,
                'title' => 'Visitors Log',
                'route' => $this->safeRoute('visitors.home'),
            ],
            [
                'slug' => Permission::MODULE_UAC,
                'title' => 'Access Control',
                'route' => $this->safeRoute('uac.index'),
            ],
        ];
    }

    protected function sidebarFor(User $user, string $currentModule): array
    {
        $definitions = match ($currentModule) {
            Permission::MODULE_LEAVE => $this->leaveSidebar($user),
            Permission::MODULE_STAFF => $this->staffSidebar($user),
            Permission::MODULE_UAC => $this->uacSidebar($user),
            Permission::MODULE_LETTERS => $this->lettersSidebar($user),
            Permission::MODULE_VISITORS => $this->visitorsSidebar($user),
            default => [],
        };

        return collect($definitions)
            ->filter(function (array $item) use ($user) {
                if (($item['type'] ?? 'item') === 'section') {
                    return true;
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

        return [
            [
                'label' => $this->isHrViewer($user) ? 'HR Dashboard' : 'Leave Home',
                'route' => 'leave.home',
                'active' => ['leave.home'],
                'icon' => $this->icon('dashboard'),
            ],
            [
                'label' => 'All Requests',
                'route' => 'leave.requests',
                'active' => ['leave.requests'],
                'icon' => $this->icon('list'),
                'can' => $canReview,
            ],
            [
                'label' => 'Apply for Leave',
                'route' => 'leave.apply',
                'active' => ['leave.apply'],
                'icon' => $this->icon('plus-circle'),
            ],
            [
                'label' => 'My Leave History',
                'route' => 'leave.my-history',
                'active' => ['leave.my-history'],
                'icon' => $this->icon('user'),
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
                'can' => $canManageHrTools,
            ],
            [
                'label' => 'Reports',
                'route' => 'leave.reports',
                'active' => ['leave.reports'],
                'icon' => $this->icon('report'),
                'can' => $canExport,
            ],
        ];
    }

    protected function staffSidebar(User $user): array
    {
        $canManage = fn (User $currentUser) => $this->canManageStaff($currentUser);

        return [
            [
                'label' => 'All Employees',
                'route' => 'staff.index',
                'active' => ['staff.index'],
                'icon' => $this->icon('user'),
            ],
            [
                'label' => 'Add Employee',
                'route' => 'staff.create',
                'active' => ['staff.create', 'staff.edit'],
                'icon' => $this->icon('user-plus'),
                'can' => $canManage,
            ],
            [
                'type' => 'section',
                'label' => 'Data',
            ],
            [
                'label' => 'Import / Export',
                'route' => 'staff.import',
                'active' => ['staff.import'],
                'icon' => $this->icon('stack'),
                'can' => $canManage,
            ],
            [
                'label' => 'Departments',
                'route' => 'staff.departments',
                'active' => ['staff.departments'],
                'icon' => $this->icon('grid'),
                'can' => $canManage,
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
            ],
            [
                'label' => 'Roles & Permissions',
                'route' => 'uac.roles',
                'active' => ['uac.roles'],
                'icon' => $this->icon('shield'),
            ],
            [
                'label' => 'Bulk Import',
                'route' => 'uac.import',
                'active' => ['uac.import'],
                'icon' => $this->icon('stack'),
            ],
            [
                'label' => 'Audit Log',
                'route' => 'uac.audit-log',
                'active' => ['uac.audit-log'],
                'icon' => $this->icon('bars'),
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
            ],
            [
                'label' => 'Active Letters',
                'route' => 'letters.active',
                'active' => ['letters.active'],
                'icon' => $this->icon('list'),
            ],
            [
                'label' => 'New Letter',
                'route' => 'letters.create',
                'active' => ['letters.create'],
                'icon' => $this->icon('plus-circle'),
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin') || $currentUser->hasPermission('letters.create'),
            ],
            [
                'label' => 'Closed Letters',
                'route' => 'letters.closed',
                'active' => ['letters.closed'],
                'icon' => $this->icon('check'),
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
            ],
            [
                'label' => 'Historical Log',
                'route' => 'visitors.history',
                'active' => ['visitors.history'],
                'icon' => $this->icon('report'),
            ],
            [
                'label' => 'Kiosk Screen',
                'route' => 'visitors.kiosk',
                'active' => ['visitors.kiosk'],
                'icon' => $this->icon('grid'),
                'can' => fn (User $currentUser) => $currentUser->hasRoles('super_admin', 'receptionist') || $currentUser->hasPermission('visitors.kiosk'),
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
        return $user->hasRoles('super_admin', 'admin', 'hr_headoffice', 'hr_region');
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
