<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
        'ict_assignable',
        'is_protected',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'ict_assignable' => 'boolean',
        'is_protected' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    
/**
 * Backward-compatible alias (in case some code calls moduleAccess())
 */

    public function moduleAccess()
{
    return $this->hasMany(\App\Models\ModuleAccess::class);
}

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->using(RolePermission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->using(UserRole::class);
    }

    public function moduleAccesses(): HasMany
    {
        return $this->hasMany(ModuleAccess::class);
    }

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Roles $viewer may see in role lists, pickers and counts. The implicit base `employee` role is never listed
     * (it isn't managed through the UI), and `super_admin` is listed only to a super_admin.
     */
    public function scopeVisibleTo($query, ?User $viewer)
    {
        $query->where('roles.name', '!=', User::ROLE_EMPLOYEE);

        if (! $viewer?->hasRoles(User::ROLE_SUPER_ADMIN)) {
            $query->where('roles.name', '!=', User::ROLE_SUPER_ADMIN);
        }

        return $query;
    }
}