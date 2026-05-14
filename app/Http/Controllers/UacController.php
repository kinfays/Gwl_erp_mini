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
use App\Support\UserProfilePayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;


class UacController extends Controller
{
    
 public function index(Request $request)
    {
        $usersQuery = $this->usersQueryFor($request->user());
        $recentLogsQuery = AuditLog::query()->with('user.roles');

        if ($this->isRegionScopedIct($request->user())) {
            $recentLogsQuery->whereIn('user_id', (clone $usersQuery)->select('users.id'));
        }

        return view('uac.index', [
            'stats' => [
                'users' => (clone $usersQuery)->count(),
                'roles' => Role::whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])->count(),
                'permissions' => Permission::count(),
                'audit_logs' => (clone $recentLogsQuery)->count(),
            ],
            'recentUsers' => (clone $usersQuery)->with('roles')->latest()->take(5)->get(),
            'recentLogs'  => $recentLogsQuery->latest()->take(6)->get(),
        ]);
    }


    // Shared data for ERP Sidebar/Header
    protected function sharedLayoutData(Request $request, string $pageTitle): array
    {
        $user = $request->user()->loadMissing('roles');
        return [
            'pageTitle' => $pageTitle,
            'currentUser' => $user,
            'currentRoleName' => $user->displayRoleNames('Staff')
        ];
    }

    public function users(Request $request)
    
{
        $search = $request->string('search')->toString();
        $roleId = $request->integer('role_id') ?: null;
        $status = $request->string('status')->toString();
        $perPageOptions = [10, 15, 20, 50, 100];
        $perPage = $request->integer('per_page', 15);

        if (! in_array($perPage, $perPageOptions, true)) {
            $perPage = 15;
        }


       
$users = $this->usersQueryFor($request->user())
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
        ->paginate($perPage)
        ->withQueryString();

    return view('uac.users.index', [
        'users' => $users,
        'search' => $search,
        'roles' => $this->assignableRolesFor($request->user()),
        'roleId' => $roleId,
        'status' => $status,
        'perPage' => $perPage,
        'perPageOptions' => $perPageOptions,
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

$employee = Employee::visibleInErp()->findOrFail($request->employee_id);
$this->ensureEmployeeVisibleToActor($request->user(), $employee);

if (User::where('staff_id', $employee->staff_id)->exists()) {
    return back()->withErrors(['employee_id' => 'A user already exists for this employee.'])->withInput();
}

$payload = [
    'staff_id'    => $employee->staff_id,
    'employee_id' => $employee->id,
    'email'       => $employee->email,
    'password'    => Hash::make(User::DEFAULT_PASSWORD),
    'is_active'   => true,
];

if (Schema::hasColumn('users', 'must_change_password')) {
    $payload['must_change_password'] = true;
}

$user = User::create($payload);

if (Schema::hasColumn('users', 'full_name')) {
    $user->update(['full_name' => $employee->full_name]);
}

$user->roles()->sync($this->roleIdsAllowedFor($request->user(), (array) $request->input('roles', [])));



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
    abort_if($user->hasRoles('super_admin'), 404);
    $this->ensureUserVisibleToActor($request->user(), $user);

    $user->roles()->sync($this->roleIdsAllowedFor($request->user(), (array) $request->input('roles', [])));

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

    // Breeze standard password reset route with first-time password setup context.
    $url = url(route('password.reset', [
        'token' => $token,
        'email' => $user->email,
        'staff_id' => $user->staff_id,
        'set_password' => true,
    ], false));

    $user->notify(new InviteUserNotification($url, $user->staff_id));
}

public function resendInvite(User $user)
{
    abort_if($user->hasRoles('super_admin'), 404);
    $this->ensureUserVisibleToActor(request()->user(), $user);

    // only allow resend if user never logged in
    if ($user->last_login_at) abort(403);

    $this->sendInviteEmail($user);

    return back()->with('success', 'Invite email resent successfully.');
}


    public function toggleStatus(User $user)
        {
         if ($user->roles()->where('name', 'super_admin')->exists()) {
        abort(404);
    }

    $this->ensureUserVisibleToActor(request()->user(), $user);

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


public function show(Request $request, User $user, UserProfilePayload $profiles)
{
    abort_if($user->hasRoles('super_admin'), 404);
    $this->ensureUserVisibleToActor($request->user(), $user);

    return response()->json($profiles->for($user));
}


public function searchEmployees(Request $request)
{
    $q = $request->string('q')->toString();

    $employees = $this->employeesQueryFor($request->user())
        ->visibleInErp()
        ->when($q, function ($query) use ($q) {
            $query->where(function (Builder $searchQuery) use ($q) {
                $searchQuery
                    ->where('staff_id', 'like', "%{$q}%")
                    ->orWhere('full_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        })
        ->orderBy('staff_id')
        ->limit(10)
        ->get(['id', 'staff_id', 'full_name', 'email']);

    return response()->json($employees);
}

protected function usersQueryFor(User $actor): Builder
{
    return $this->scopeUsersToActor(
        User::query()->visibleInErp(),
        $actor
    );
}

protected function employeesQueryFor(User $actor): Builder
{
    return $this->scopeEmployeesToActor(
        Employee::query(),
        $actor
    );
}

protected function scopeUsersToActor(Builder $query, User $actor): Builder
{
    if (! $this->isRegionScopedIct($actor)) {
        return $query;
    }

    $regionId = $this->actorRegionId($actor);

    if (! $regionId) {
        return $query->whereRaw('1 = 0');
    }

    return $query->where(function (Builder $scoped) use ($regionId) {
        $scoped
            ->whereHas('employee', fn (Builder $employee) => $employee->where('region_id', $regionId))
            ->orWhereHas('employeeByStaffId', fn (Builder $employee) => $employee->where('region_id', $regionId));
    });
}

protected function scopeEmployeesToActor(Builder $query, User $actor): Builder
{
    if (! $this->isRegionScopedIct($actor)) {
        return $query;
    }

    $regionId = $this->actorRegionId($actor);

    return $regionId
        ? $query->where('region_id', $regionId)
        : $query->whereRaw('1 = 0');
}

protected function ensureUserVisibleToActor(User $actor, User $target): void
{
    if (! $this->usersQueryFor($actor)->whereKey($target->id)->exists()) {
        abort(403, 'You can only manage users in your region.');
    }
}

protected function ensureEmployeeVisibleToActor(User $actor, Employee $employee): void
{
    if (! $this->employeesQueryFor($actor)->whereKey($employee->id)->exists()) {
        abort(403, 'You can only manage employees in your region.');
    }
}

protected function isRegionScopedIct(User $actor): bool
{
    return $actor->hasRoles(User::ROLE_ICT_TEAM)
        && ! $actor->hasRoles(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
}

protected function actorRegionId(User $actor): ?int
{
    $employee = $actor->employee ?? $actor->employeeByStaffId;

    return $employee?->region_id;
}

protected function assignableRolesFor(User $actor)
{
    $query = Role::query()
        ->whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE]);

    if (! $actor->hasRoles(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)) {
        $query->whereNotIn('name', [User::ROLE_ADMIN, User::ROLE_ICT_TEAM]);
    }

    return $query->orderBy('display_name')->get();
}

protected function roleIdsAllowedFor(User $actor, array $submittedRoleIds): array
{
    $allowedRoleIds = $this->assignableRolesFor($actor)->pluck('id')->all();

    return collect($submittedRoleIds)
        ->map(fn ($roleId) => (int) $roleId)
        ->intersect($allowedRoleIds)
        ->values()
        ->all();
}

    public function roles()
    
{
        return view('uac.roles.index', [
            'roles' => Role::with(['permissions', 'moduleAccesses'])->whereNotIn('name', [User::ROLE_SUPER_ADMIN, User::ROLE_EMPLOYEE])->orderBy('display_name')->get(),
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

        $logs = AuditLog::with('user.roles')
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
