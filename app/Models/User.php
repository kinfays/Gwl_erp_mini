<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const DEFAULT_PASSWORD = '12345';
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_ICT_TEAM = 'ict_team';
    public const ROLE_TRANSPORT_MANAGER = 'transport_manager';
    public const ROLE_DRIVER = 'driver';

    public const TIER_SUPER_ADMIN = 3;
    public const TIER_GLOBAL_ADMIN = 2;
    public const TIER_ICT = 1;
    public const TIER_STAFF = 0;

    protected $fillable = [
        'full_name',
        'email',
        'staff_id',
        'password',
        'employee_id',
        'is_active',
        'last_login_at',
        'must_change_password',
        'api_token',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'employee_id' => 'integer',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employeeByStaffId(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'staff_id', 'staff_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->using(UserRole::class);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function hasRoles(array|string ...$roleSlugs): bool
    {
        $flattened = collect($roleSlugs)->flatten()->filter()->values()->all();

        if (empty($flattened)) {
            return false;
        }

        if ($this->relationLoaded('roles')) {
            return $this->roles->pluck('name')->intersect($flattened)->isNotEmpty();
        }

        return $this->roles()->whereIn('name', $flattened)->exists();
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->relationLoaded('roles')) {
            $roles = $this->roles;

            if ($roles->isEmpty()) {
                return false;
            }

            $roles->loadMissing('permissions');

            return $roles->flatMap->permissions->pluck('name')->contains($slug);
        }

        return Permission::query()
            ->where('name', $slug)
            ->whereHas('roles.users', fn ($query) => $query->where('users.id', $this->id))
            ->exists();
    }

    public function isHrUser(): bool
    {
        return $this->hasRoles('hr_headoffice', 'hr_region');
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRoles(self::ROLE_SUPER_ADMIN);
    }

    /** The `admin` slug, shown as "Global Admin". */
    public function isGlobalAdmin(): bool
    {
        return $this->hasRoles(self::ROLE_ADMIN);
    }

    /**
     * How much user/role management power the account has: super_admin > Global Admin > ICT team > everyone else.
     * Nobody may grant a role above their own tier.
     */
    public function tier(): int
    {
        return match (true) {
            $this->isSuperAdmin() => self::TIER_SUPER_ADMIN,
            $this->isGlobalAdmin() => self::TIER_GLOBAL_ADMIN,
            $this->hasRoles(self::ROLE_ICT_TEAM) => self::TIER_ICT,
            default => self::TIER_STAFF,
        };
    }

    /** ICT team without Global Admin / super_admin: confined to one location scope (Head Office, or one region). */
    public function isScopedIct(): bool
    {
        return $this->hasRoles(self::ROLE_ICT_TEAM)
            && ! $this->hasRoles(self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN);
    }

    /**
     * The location scope a scoped ICT user works in, or null when they aren't scoped (not ICT, or Global Admin /
     * super_admin). Head Office is a district, not a region, and its staff carry the Head Office district's
     * region_id, so a Head Office ICT is recognised by location_type and a regional ICT's scope excludes Head
     * Office staff even when they share the region_id. No employee record: 'none' (sees nobody).
     *
     * @return array{type: 'head_office'|'region'|'none', region_id: int|null}|null
     */
    public function ictScope(): ?array
    {
        if (! $this->isScopedIct()) {
            return null;
        }

        $employee = $this->employee ?? $this->employeeByStaffId;

        if (! $employee) {
            return ['type' => 'none', 'region_id' => null];
        }

        if ($employee->location_type === 'HeadOffice') {
            return ['type' => 'head_office', 'region_id' => null];
        }

        return $employee->region_id
            ? ['type' => 'region', 'region_id' => (int) $employee->region_id]
            : ['type' => 'none', 'region_id' => null];
    }

    public function isHeadOfficeHr(): bool
    {
        return $this->hasRoles('hr_headoffice');
    }

    public function getAccessibleModules(): array
    {
        $modules = $this->roles()
            ->with('moduleAccess')
            ->get()
            ->flatMap(function (Role $role) {
                if ($role->name === self::ROLE_ADMIN) {
                    $adminModules = $role->moduleAccess
                        ->where('can_access', true)
                        ->pluck('module')
                        // Health & Safety and the Regional Blog are for every member of staff (anyone may report an incident or
                        // read their region's blog), Global Admin included.
                        ->intersect([Permission::MODULE_UAC, Permission::MODULE_ASSETS, Permission::MODULE_HEALTH_SAFETY, Permission::MODULE_BLOG])
                        ->all();

                    $adminModules[] = Permission::MODULE_UAC;

                    return $adminModules;
                }

                return $role->moduleAccess
                    ->where('can_access', true)
                    ->pluck('module');
            })
            ->unique()
            ->values()
            ->toArray();

        if (! in_array(Permission::MODULE_LEAVE, $modules, true)) {
            $modules[] = Permission::MODULE_LEAVE;
        }

        return array_values(array_unique($modules));
    }

    public function visibleRoles()
    {
        $roles = $this->relationLoaded('roles') ? $this->roles : $this->roles()->get();

        return $roles
            ->reject(fn (Role $role) => $role->name === self::ROLE_EMPLOYEE)
            ->values();
    }

    public function displayRoleNames(string $fallback = 'Employee'): string
    {
        return $this->visibleRoles()
            ->pluck('display_name')
            ->filter()
            ->join(', ') ?: $fallback;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisibleInErp($query)
    {
        return $query->whereDoesntHave('roles', fn ($roleQuery) => $roleQuery->where('name', 'super_admin'));
    }

    /**
     * Users $viewer may see in the UAC and staff contexts: super_admin accounts only to a super_admin. Operational
     * pickers (visitor hosts, letter recipients, leave approvers…) keep using visibleInErp(): a developer account is
     * never a real member of staff there, whoever is looking.
     */
    public function scopeVisibleTo($query, ?User $viewer)
    {
        return $viewer?->isSuperAdmin() ? $query : $query->visibleInErp();
    }
}
