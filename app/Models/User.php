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

    protected $fillable = [
        'full_name',
        'email',
        'staff_id',
        'password',
        'employee_id',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'employee_id' => 'integer',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
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

    public function getAccessibleModules(): array
    {
        $roles = $this->relationLoaded('roles') ? $this->roles : $this->roles()->with('moduleAccesses')->get();

        if ($roles->isEmpty()) {
            return [];
        }

        $roles->loadMissing('moduleAccesses');

        return $roles
            ->flatMap(fn ($role) => $role->moduleAccesses)
            ->where('can_access', true)
            ->pluck('module')
            ->unique()
            ->values()
            ->all();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}