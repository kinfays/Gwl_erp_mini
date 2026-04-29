<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_name', // Added for historical permanence
        'action',
        'module',
        'target_type',
        'target_id',
        'old_values',
        'new_values',
        'ip_address',
        'metadata', // For any additional contextual info
    ];

    protected $casts = [
        'user_id' => 'integer',
        'target_id' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Core helper method to record system actions.
     */
    public static function record(
        string $action,
        string $module,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $old = null,
        ?array $new = null,
        ?array $metadata = null
    ): void
    {
        $user = Auth::user();

        $payload = [
            'user_id' => $user ? $user->id : null,
            'action' => $action,
            'module' => $module,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
        ];

        if (Schema::hasColumn('audit_logs', 'user_name')) {
            $payload['user_name'] = $user?->full_name ?? $user?->email ?? 'System/Guest';
        }

        if (Schema::hasColumn('audit_logs', 'metadata')) {
            $payload['metadata'] = $metadata;
        }

        self::create($payload);
    }
}
