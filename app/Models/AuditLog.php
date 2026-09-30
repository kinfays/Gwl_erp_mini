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
        'actor_is_super_admin', // frozen at write time: see visibleTo()
    ];

    /**
     * Actions that change another account's roles or access. A super_admin's rows are hidden from everyone else,
     * except these: what changed is shown to the people it affects, with the actor displayed as "System".
     * Assigning or removing the super_admin role itself is deliberately not here (it would reveal that the
     * account exists), and neither is any other super_admin housekeeping.
     */
    public const ROLE_ACCESS_ACTIONS = [
        'create_user',
        'update_user',
        'activate_user',
        'deactivate_user',
        'role_assigned',
        'role_removed',
        'head_office_roles_removed_on_transfer',
        'create_role',
        'update_role',
        'update_role_access',
        'delete_role',
    ];

    protected $casts = [
        'actor_is_super_admin' => 'boolean',
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
     * The one place that decides which audit rows a viewer may see. A super_admin sees everything. Everyone else
     * doesn't see rows written by a super_admin, other than the role/access changes in ROLE_ACCESS_ACTIONS (shown
     * with the actor as "System", see actorLabelFor()). Every list, count and export of audit rows must start here.
     */
    public function scopeVisibleTo($query, ?User $viewer)
    {
        if ($viewer?->isSuperAdmin()) {
            return $query;
        }

        return $query->where(fn ($visible) => $visible
            ->where('audit_logs.actor_is_super_admin', false)
            ->orWhereIn('audit_logs.action', self::ROLE_ACCESS_ACTIONS));
    }

    /** The actor as $viewer should see them: a super_admin's name is never shown to anyone else. */
    public function actorLabelFor(?User $viewer): string
    {
        if ($this->actor_is_super_admin && ! $viewer?->isSuperAdmin()) {
            return 'System';
        }

        return $this->user?->full_name ?? $this->user_name ?? 'System';
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

        if (Schema::hasColumn('audit_logs', 'actor_is_super_admin')) {
            $payload['actor_is_super_admin'] = (bool) $user?->isSuperAdmin();
        }

        self::create($payload);
    }
}
