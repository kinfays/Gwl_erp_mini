<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    use HasFactory;

    public const MODULE_UAC = 'uac';
    public const MODULE_LEAVE = 'leave';
    public const MODULE_STAFF = 'staff';
    public const MODULE_LETTERS = 'letters';
    public const MODULE_VISITORS = 'visitors';
    public const MODULE_ASSETS = 'assets';
    public const MODULE_TRANSPORT = 'transport';
    public const MODULE_CREDIT_UNION = 'credit_union';
    public const MODULE_COMMERCIAL = 'commercial';
    public const MODULE_HEALTH_SAFETY = 'health_safety';

    public const MODULES = [
        self::MODULE_UAC,
        self::MODULE_LEAVE,
        self::MODULE_STAFF,
        self::MODULE_LETTERS,
        self::MODULE_VISITORS,
        self::MODULE_ASSETS,
        self::MODULE_TRANSPORT,
        self::MODULE_CREDIT_UNION,
        self::MODULE_COMMERCIAL,
        self::MODULE_HEALTH_SAFETY,
    ];

    protected $fillable = [
        'name',
        'display_name',
        'module',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->using(RolePermission::class);
    }

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function scopeModule($query, string $module)
    {
        return $query->where('module', $module);
    }
}
