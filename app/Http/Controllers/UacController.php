<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Employee;
use App\Notifications\InviteUserNotification;
use App\Http\Requests\Uac\StoreUserRequest;
use App\Http\Requests\Uac\UpdateUserRequest;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;

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
            'currentRoleName' => $user->roles->first()?->display_name ?? 'Staff'
        ];
    }

    public function users(Request $request)
    
{
        $search = $request->string('search')->toString();
        $roleId = $request->integer('role_id') ?: null;
        $status = $request->string('status')->toString();


       
$users = User::query()
        ->with([
            'roles',
            'employee.region',
            'employee.district',
            'employeeByStaffId.region',
            'employeeByStaffId.district',
        ])
        ->when($search, function ($query) use ($search) {
            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('staff_id', 'like', "%{$search}%");
            });
        })
        ->when($roleId, function ($query) use ($roleId) {
            $query->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId));
        })
        ->when($status === 'active', fn ($query) => $query->where('is_active', true))
        ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
        ->latest()
        ->paginate(15)
        ->withQueryString();

    return view('uac.users.index', [
        'users' => $users,
        'search' => $search,
        'roles' => Role::orderBy('display_name')->get(),
        'roleId' => $roleId,
        'status' => $status,
    ])
    ->with($this->sharedLayoutData($request, 'User Management'));
    }

    
public function store(StoreUserRequest $request)
{
    /* $employee = Employee::where('staff_id', $request->staff_id)->first();

    if (! $employee) {
        abort(422, 'Employee record not found for the given staff ID.');
    }

    $user = User::create([
        'staff_id'    => $request->staff_id,
        'employee_id' => $employee->id,
       'full_name'   => $request->full_name,
        'email'       => $request->email,
        'password'    => Hash::make(Str::random(12)),
        'is_active'   => true,
    ]);
    
    $user->roles()->sync($request->roles); */

$employee = Employee::findOrFail($request->employee_id);

if (User::where('staff_id', $employee->staff_id)->exists()) {
    return back()->withErrors(['employee_id' => 'A user already exists for this employee.'])->withInput();
}

$user = User::create([
    'staff_id'    => $employee->staff_id,
    'employee_id' => $employee->id,
    'email'       => $employee->email,
    'password'    => Hash::make(Str::random(12)),
    'is_active'   => true,
]);

if (Schema::hasColumn('users', 'full_name')) {
    $user->update(['full_name' => $employee->full_name]);
}

$user->roles()->sync($request->roles);



    // send invite email (set password link)
    $this->sendInviteEmail($user);

    Audit::log(
        action: 'create_user',
        module: 'uac.users',
        targetType: 'users',
        targetId: $user->id,
        metadata: [
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->toArray(),
        ]
    );

    return redirect()
        ->route('uac.users')
        ->with('success', 'User created successfully.');
}


public function update(UpdateUserRequest $request, User $user)
{
    $user->update([
        'full_name' => $request->full_name,
        'email'     => $request->email,
    ]);

    $user->roles()->sync($request->roles);

    Audit::log(
        action: 'update_user',
        module: 'uac.users',
        targetType: 'users',
        targetId: $user->id,
        metadata: [
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->toArray(),
        ]
    );

    return back()->with('success', 'User updated.');
}

protected function sendInviteEmail(User $user): void
{
    // Create a reset token for the user
    $token = Password::broker()->createToken($user);

    // Breeze standard password reset route:
    // /reset-password/{token}?email=user@email.com
    $url = url(route('password.reset', [
        'token' => $token,
        'email' => $user->email,
    ], false));

    $user->notify(new InviteUserNotification($url, $user->staff_id));
}

public function resendInvite(User $user)
{
    // only allow resend if user never logged in
    if ($user->last_login_at) abort(403);

    $this->sendInviteEmail($user);

    return back()->with('success', 'Invite email resent successfully.');
}


    public function toggleStatus(User $user)
        {
         if ($user->roles()->where('name', 'super_admin')->exists()) {
        abort(403, 'Super admin cannot be deactivated.');
    }

    $user->update([
        'is_active' => ! $user->is_active,
    ]);

    Audit::log(
        action: $user->is_active ? 'activate_user' : 'deactivate_user',
        module: 'uac.users',
        targetType: 'users',
        targetId: $user->id,
        metadata: [
            'status' => $user->is_active ? 'active' : 'inactive',
        ]
    );

    return back()->with('success', 'User status updated.');
}


public function show(Request $request, User $user)
{
    // UAC protection already handled by middleware.
    // Load relationships used in the drawer.
    $user->load([
        'roles:id,name,display_name',
        'employee.jobTitle:id,job_title_name',
        'employee.department:id,department_name',
        'employee.region:id,region_name',
        'employee.district:id,district_name',
    ]);

    // If user has no employee linked, we still return user info
    $employee = $user->employee;

    return response()->json([
        'user' => [
            'id' => $user->id,
            'staff_id' => $user->staff_id,
            'full_name' => $user->full_name ?? ($employee?->full_name),
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'last_login_at' => optional($user->last_login_at)->toDateTimeString(),
            'roles' => $user->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'display_name' => $r->display_name,
            ]),
        ],
        'employee' => $employee ? [
            'staff_id' => $employee->staff_id,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'gender' => $employee->gender,
            'category' => $employee->category,
            'location_type' => $employee->location_type,
            'unit' => $employee->unit,
            'present_appointment' => $employee->present_appointment,
            'date_of_birth' => optional($employee->date_of_birth)->toDateString(),
            'age' => $employee->age,
            'date_joined' => optional($employee->date_joined)->toDateString(),

            'job_title' => $employee->jobTitle?->job_title_name,
            'department' => $employee->department?->department_name,
            'region' => $employee->region?->region_name,
            'district' => $employee->district?->district_name,

            // Leave entitlements from accessors
            'annual_leave_days' => $employee->annual_leave_days,
            'casual_leave_days' => $employee->casual_leave_days,
            'parental_days' => $employee->parental_days,
        ] : null,
    ]);
}


public function searchEmployees(Request $request)
{
    $q = $request->string('q')->toString();

    $employees = Employee::query()
        ->when($q, function ($query) use ($q) {
            $query->where('staff_id', 'like', "%{$q}%")
                ->orWhere('full_name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%");
        })
        ->orderBy('staff_id')
        ->limit(10)
        ->get(['id', 'staff_id', 'full_name', 'email']);

    return response()->json($employees);
}

    public function roles()
    
{
        return view('uac.roles.index', [
            'roles' => Role::with(['permissions', 'moduleAccesses'])->orderBy('display_name')->get(),
            'permissions' => Permission::orderBy('module')->orderBy('display_name')->get()->groupBy('module'),
        ]);
    }

public function rolesPermissions()
{
    return view('uac.roles.index');
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
