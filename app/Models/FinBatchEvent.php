<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a batch's trail. Immutable: BatchWorkflowService is the only writer, and updating or deleting a row is refused.
 */
class FinBatchEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED = 'created';

    public const PV_ADDED = 'pv_added';

    public const PV_REMOVED = 'pv_removed';

    public const SENT = 'sent';

    public const ASSIGNED = 'assigned';

    public const REASSIGNED = 'reassigned';

    public const CANCELLED = 'cancelled';

    public const ACTIONS = [
        self::CREATED => 'Batch created',
        self::PV_ADDED => 'Voucher added',
        self::PV_REMOVED => 'Voucher removed',
        self::SENT => 'Sent to Audit',
        self::ASSIGNED => 'Assigned to an Audit officer',
        self::REASSIGNED => 'Reassigned to another Audit officer',
        self::CANCELLED => 'Batch cancelled',
    ];

    protected $fillable = ['batch_id', 'action', 'actor_user_id', 'pv_id', 'from_user_id', 'to_user_id', 'comment'];

    protected $casts = [
        'batch_id' => 'integer',
        'actor_user_id' => 'integer',
        'pv_id' => 'integer',
        'from_user_id' => 'integer',
        'to_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Batch events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Batch events are never deleted.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinPvBatch::class, 'batch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function pv(): BelongsTo
    {
        return $this->belongsTo(FinPaymentVoucher::class, 'pv_id');
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst(str_replace('_', ' ', (string) $this->action));
    }

    public function actorName(): string
    {
        return $this->actor?->full_name ?? 'Unknown user';
    }
}
