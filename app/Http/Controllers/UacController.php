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
    
 public function index(Request $request)
    {
        return view('uac.index', [
            'stats' => [
                'users' => User::count(),
                'roles' => Role::count(),
                'permissions' => Permission::count(),
                'audit_logs' => AuditLog::count(),
            ],
            'recentUsers' => User::with('roles')->latest()->take(5)->get(),
            'recentLogs'  => AuditLog::with('user')->latest()->take(6)->get(),
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

    public function users(Request $request)
    
{
        $search = $request->string('search')->toString();

        $users = User::with([
                'roles',
                'employee.region',
                'employee.district',
                'employeeByStaffId.region',
                'employeeByStaffId.district',
            ])
            ->when($search, fn ($q) =>
                $q->where(fn ($sq) =>
                    $sq->where('full_name', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%")
                       ->orWhere('staff_id', 'like', "%{$search}%")
                )
            )
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('uac.users.index', [
            'users' => $users,
            'search' => $search,
            'roles' => Role::orderBy('display_name')->get(),
        ]);
    }


    public function roles()
    
{
        return view('uac.roles.index', [
            'roles' => Role::with(['permissions', 'moduleAccesses'])->orderBy('display_name')->get(),
            'permissions' => Permission::orderBy('module')->orderBy('display_name')->get()->groupBy('module'),
        ]);
    }


    
 public function import()
    {
        return view('uac.import.index');
    }


    
    public function auditLog(Request $request)
    {
        $search = $request->string('search')->toString();

        $logs = AuditLog::with('user')
            ->when($search, fn ($q) =>
                $q->where(fn ($sq) =>
                    $sq->where('action', 'like', "%{$search}%")
                       ->orWhere('module', 'like', "%{$search}%")
                       ->orWhere('target_type', 'like', "%{$search}%")
                       ->orWhere('ip_address', 'like', "%{$search}%")
                )
            )
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('uac.audit-log.index', compact('logs', 'search'));
    }
}
