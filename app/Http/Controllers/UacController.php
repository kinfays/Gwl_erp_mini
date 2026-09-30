<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Requests\Uac\StoreUserRequest;
use App\Http\Requests\Uac\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Services\Uac\RoleAssignmentService;
use App\Services\Uac\RoleGrantPolicy;
use App\Support\Audit;
use App\Support\UserProfilePayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;

/**
 * User management. What a person may see and change is decided by RoleGrantPolicy (tiers, location scope, role
 * classification); this controller only asks it and applies the answer.
 */
class UacController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(
        protected RoleGrantPolicy $policy,
        protected RoleAssignmentService $assignments,
    ) {
        $this->middleware(function ($request, $next) {
            // Controller-level enforcement (defense-in-depth), on top of the route's module and role middleware.
            $this->enforceModule($request, 'uac');

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $viewer = $request->user();
        $usersQuery = $this->policy->usersQueryFor($viewer);
        $logsQuery = $this->auditLogsFor($viewer);

        return view('uac.index', [
            'stats' => [
                'users' => (clone $usersQuery)->count(),
                'roles' => Role::query()->visibleTo($viewer)->count(),
                'permissions' => Permission::count(),
                'audit_logs' => (clone $logsQuery)->count(),
            ],
            'recentUsers' => (clone $usersQuery)->with('roles')->latest()->take(5)->get(),
            'recentLogs' => $logsQuery->latest()->take(6)->get(),
        ]);
    }

    // Shared data for ERP Sidebar/Header
    protected function sharedLayoutData(Request $request, string $pageTitle): array
    {
        $user = $request->user()->loadMissing('roles');

        return [
            'pageTitle' => $pageTitle,
            'currentUser' => $user,
            'currentRoleName' => $user->displayRoleNames('Staff'),
        ];
    }

    public function users(Request $request)
    {
        $viewer = $request->user();
        $search = $request->string('search')->toString();
        $roleId = $request->integer('role_id') ?: null;
        $status = $request->string('status')->toString();
        $perPageOptions = [10, 15, 20, 50, 100];
        $perPage = $request->integer('per_page', 15);

        if (! in_array($perPage, $perPageOptions, true)) {
            $perPage = 15;
        }

        $users = $this->policy->usersQueryFor($viewer)
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

        // What the edit dialog offers for each row: only roles this actor may change on that person.
        $candidateRoles = Role::query()->visibleTo($viewer)->with('permissions')->orderBy('display_name')->get();
        $governed = $this->policy->governedPermissionNames($viewer);
        $manageable = $users->getCollection()->mapWithKeys(fn (User $user) => [
            $user->id => $this->policy->manageableRoleIdsFor($viewer, $user, true, $candidateRoles, $governed),
        ]);

        return view('uac.users.index', [
            'users' => $users,
            'search' => $search,
            // The filter lists every role the viewer can see; the create/edit dialogs only what they may assign.
            'roles' => $candidateRoles,
            'assignableRoles' => $this->policy->assignableRolesFor($viewer, null, false, $candidateRoles, $governed),
            'manageableRoleIds' => $manageable,
            'roleId' => $roleId,
            'status' => $status,
            'perPage' => $perPage,
            'perPageOptions' => $perPageOptions,
        ])
            ->with($this->sharedLayoutData($request, 'User Management'));
    }

    public function store(StoreUserRequest $request)
    {
        $actor = $request->user();
        $employee = Employee::query()->visibleTo($actor)->findOrFail($request->employee_id);

        if ($denial = $this->policy->targetDenial($actor, $employee)) {
            abort(403, $denial);
        }

        if (User::where('staff_id', $employee->staff_id)->exists()) {
            return back()->withErrors(['employee_id' => 'A user already exists for this employee.'])->withInput();
        }

        // Roles are checked against the new account inside the same transaction: a role this actor may not give
        // (or that doesn't fit the employee's location) rolls the whole creation back.
        $user = DB::transaction(function () use ($actor, $employee, $request) {
            $payload = [
                'staff_id' => $employee->staff_id,
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'password' => Hash::make(User::DEFAULT_PASSWORD),
                'is_active' => true,
            ];

            if (Schema::hasColumn('users', 'must_change_password')) {
                $payload['must_change_password'] = true;
            }

            $user = User::create($payload);

            if (Schema::hasColumn('users', 'full_name')) {
                $user->update(['full_name' => $employee->full_name]);
            }

            $this->assignments->sync($actor, $user, (array) $request->input('roles', []));

            return $user;
        });

        // send invite email (set password link)
        $this->sendInviteEmail($user);

        Audit::log(
            action: 'create_user',
            module: 'uac.users',
            targetType: 'users',
            targetId: $user->id,
            metadata: [
                'email' => $user->email,
                'roles' => $user->roles()->pluck('name')->toArray(),
            ]
        );

        return redirect()
            ->route('uac.users')
            ->with('success', 'User created successfully.');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->ensureVisible($request->user(), $user);

        // Adds and removes only the roles this actor may manage; anything else the user holds is left alone.
        // Each change is audited by the service.
        $this->assignments->sync($request->user(), $user, (array) $request->input('roles', []));

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
        $this->ensureManageable(request()->user(), $user);

        // only allow resend if user never logged in
        if ($user->last_login_at) {
            abort(403);
        }

        $this->sendInviteEmail($user);

        return back()->with('success', 'Invite email resent successfully.');
    }

    public function toggleStatus(User $user)
    {
        $actor = request()->user();

        $this->ensureVisible($actor, $user);

        if ($denial = $this->policy->statusDenial($actor, $user)) {
            abort(403, $denial);
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

    public function show(Request $request, User $user, UserProfilePayload $profiles)
    {
        $this->ensureManageable($request->user(), $user);

        return response()->json($profiles->for($user));
    }

    public function searchEmployees(Request $request)
    {
        $actor = $request->user();
        $q = $request->string('q')->toString();

        $employees = $this->policy->employeesQueryFor($actor)
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
            ->get(['id', 'staff_id', 'full_name', 'email', 'location_type', 'region_id']);

        // The roles this actor could give each candidate, so the create dialog only offers those.
        $candidateRoles = Role::query()->visibleTo($actor)->with('permissions')->orderBy('display_name')->get();
        $governed = $this->policy->governedPermissionNames($actor);

        return response()->json($employees->map(fn (Employee $employee) => [
            'id' => $employee->id,
            'staff_id' => $employee->staff_id,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'assignable_role_ids' => $this->policy
                ->assignableRolesFor($actor, $employee, true, $candidateRoles, $governed)
                ->pluck('id')
                ->values(),
        ]));
    }

    /** Audit rows the viewer may see; a scoped ICT user also only sees what their own scope's accounts did. */
    protected function auditLogsFor(User $viewer): Builder
    {
        $logs = AuditLog::query()->visibleTo($viewer)->with('user');

        if ($viewer->isScopedIct()) {
            $logs->whereIn('audit_logs.user_id', $this->policy->usersQueryFor($viewer)->select('users.id'));
        }

        return $logs;
    }

    /** A super_admin account doesn't exist as far as anyone else is concerned. */
    protected function ensureVisible(User $actor, User $target): void
    {
        abort_unless(User::query()->visibleTo($actor)->whereKey($target->id)->exists(), 404);
    }

    protected function ensureManageable(User $actor, User $target): void
    {
        $this->ensureVisible($actor, $target);

        abort_unless(
            $this->policy->usersQueryFor($actor)->whereKey($target->id)->exists(),
            403,
            $this->policy->outOfScopeMessage($actor)
        );
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

        $logs = AuditLog::query()
            ->visibleTo($request->user())
            ->with('user')
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq->where('action', 'like', "%{$search}%")
                ->orWhere('module', 'like', "%{$search}%")
                ->orWhere('target_type', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")
            ))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('uac.audit-log.index', compact('logs', 'search'));
    }
}
