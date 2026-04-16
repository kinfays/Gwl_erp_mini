<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleAccess extends Model
{
    use HasFactory;

    protected $table = 'module_access';

    protected $fillable = [
        'role_id',
        'module',
        'can_access',
    ];

    protected $casts = [
        'role_id' => 'integer',
        'can_access' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function scopeAllowed($query)
    {
        return $query->where('can_access', true);
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }
}