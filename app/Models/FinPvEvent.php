<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a payment voucher's timeline. Immutable: PaymentVoucherWorkflowService is the only writer, and updating or
 * deleting a row is refused. Every name shown for an action comes from the event's actor.
 */
class FinPvEvent extends Model
{
    public const UPDATED_AT = null;

    public const SUBMITTED = 'submitted';

    public const QUERIED = 'queried';

    public const AUDIT_ACKNOWLEDGED = 'audit_acknowledged';

    public const TICK_WITHDRAWN = 'tick_withdrawn';

    public const RESUBMITTED = 'resubmitted';

    public const FINANCE_ACKNOWLEDGED = 'finance_acknowledged';

    public const VOIDED = 'voided';

    public const ACTIONS = [
        self::SUBMITTED => 'Sent to Audit',
        self::QUERIED => 'Queried by Audit',
        self::AUDIT_ACKNOWLEDGED => 'Acknowledged by Audit',
        self::TICK_WITHDRAWN => 'Audit tick withdrawn',
        self::RESUBMITTED => 'Corrected and resubmitted',
        self::FINANCE_ACKNOWLEDGED => 'Acknowledged by Finance',
        self::VOIDED => 'Voided',
    ];

    /** The actions that put the voucher in front of Audit: whoever did one of them can never review it. */
    public const SUBMITTING = [self::SUBMITTED, self::RESUBMITTED];

    protected $fillable = ['pv_id', 'round', 'action', 'actor_user_id', 'comment', 'query_reason_code', 'changes'];

    protected $casts = [
        'pv_id' => 'integer',
        'round' => 'integer',
        'actor_user_id' => 'integer',
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payment voucher events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Payment voucher events are never deleted.'));
    }

    public function pv(): BelongsTo
    {
        return $this->belongsTo(FinPaymentVoucher::class, 'pv_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst(str_replace('_', ' ', (string) $this->action));
    }

    public function actorName(): string
    {
        return $this->actor?->full_name ?? 'Unknown user';
    }

    public function reasonLabel(): ?string
    {
        return $this->query_reason_code ? (FinPaymentVoucher::QUERY_REASONS[$this->query_reason_code] ?? $this->query_reason_code) : null;
    }
}
