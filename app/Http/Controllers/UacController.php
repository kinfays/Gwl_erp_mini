<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UacController extends Controller
{
    public function index(Request $request): View
    {
        $stats = [
            'users' => User::query()->count(),
            'roles' => Role::query()->count(),
            'permissions' => Permission::query()->count(),
            'audit_logs' => AuditLog::query()->count(),
        ];

        $recentUsers = User::query()->with('roles')->latest()->take(5)->get();
        $recentLogs = AuditLog::query()->with('user')->latest()->take(6)->get();

        return view('uac.index', [
            'pageTitle' => 'UAC Dashboard',
            'stats' => $stats,
            'recentUsers' => $recentUsers,
            'recentLogs' => $recentLogs,
        ]);
    }

    // Shared data for ERP Sidebar/Header
    protected function sharedLayoutData(Request $request, string $pageTitle): array
    {
        $user = $request->user()->loadMissing('roles');
        return [
            'pageTitle' => $pageTitle,
            'currentUser' => $user,
            'currentRoleName' => $user->roles->first()?->display_name ?? 'Staff', [cite: 21]
        ];
    }

    public function users(Request $request): View
    {
        $search = $request->string('search')->toString();
        $roleId = $request->integer('role_id');
        $status = $request->string('status')->toString();

        $users = User::query()
            ->with(['roles', 'employee']) // Linked to employee for Leave/ERP context
            ->when($search, function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('full_name', 'like', '%' . $search . '%') [cite: 8]
                             ->orWhere('email', 'like', '%' . $search . '%')
                             ->orWhere('staff_id', 'like', '%' . $search . '%');
                });
            })
            ->when($roleId, fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('id', $roleId)))
            [cite_start]->when($status === 'active', fn ($query) => $query->where('is_active', true)) [cite: 9]
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->latest()
            [cite_start]->paginate(15) 
            ->withQueryString();

        return view('uac.users', array_merge($this->sharedLayoutData($request, 'User Management'), [
            'users' => $users,
            'search' => $search,
            'roleId' => $roleId,
            'status' => $status,
            'roles' => Role::query()->orderBy('display_name')->get(),
            'employees' => Employee::orderBy('full_name')->get(), [cite: 10]
        ]));
    }

    public function users(Request $request): View
    {
        $search = $request->string('search')->toString();

        $users = User::query()
            ->with(['roles', 'employee.region', 'employee.district', 'employeeByStaffId.region', 'employeeByStaffId.district'])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('full_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('staff_id', 'like', '%' . $search . '%');
                });
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('uac.users', [
            'pageTitle' => 'Users',
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function rolesPermissions(): View
    {
        $roles = Role::query()->with(['permissions', 'moduleAccesses'])->orderBy('display_name')->get();
        $permissions = Permission::query()->orderBy('module')->orderBy('display_name')->get()->groupBy('module');

        return view('uac.roles-permissions', [
            'pageTitle' => 'Roles & Permissions',
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function bulkImport(): View
    {
        return view('uac.bulk-import', [
            'pageTitle' => 'Bulk Import',
        ]);
    }

    public function auditLog(Request $request): View
    {
        $search = $request->string('search')->toString();

        $logs = AuditLog::query()
            ->with('user')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('action', 'like', '%' . $search . '%')
                        ->orWhere('module', 'like', '%' . $search . '%')
                        ->orWhere('target_type', 'like', '%' . $search . '%')
                        ->orWhere('ip_address', 'like', '%' . $search . '%');
                });
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('uac.audit-log', [
            'pageTitle' => 'Audit Log',
            'logs' => $logs,
            'search' => $search,
        ]);
    }
}