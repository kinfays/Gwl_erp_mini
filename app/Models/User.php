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
                        ->intersect([Permission::MODULE_UAC, Permission::MODULE_ASSETS])
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
}
