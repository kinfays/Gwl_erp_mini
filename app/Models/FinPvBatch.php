<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch: a CONTAINER for sending, assigning, tracking and printing a group of payment vouchers. It is NOT a unit of approval
 * (Audit still ticks or queries each voucher on its own, with its own event, actor and reason). Every change goes through
 * BatchWorkflowService; who may read one is FinanceScope's decision.
 *
 * It has NO stored progress. What a screen, a count or an export calls its "state" is computed from the batch's own status and
 * its members in ONE place, computeState(); there is deliberately no SQL twin of it.
 */
class FinPvBatch extends Model
{
    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::SENT => 'Sent',
        self::CANCELLED => 'Cancelled',
    ];

    public const STATE_DRAFT = 'draft';

    public const STATE_AWAITING_ASSIGNMENT = 'awaiting_assignment';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_COMPLETED = 'completed';

    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_DRAFT => 'Draft',
        self::STATE_AWAITING_ASSIGNMENT => 'Awaiting assignment',
        self::STATE_IN_PROGRESS => 'In progress',
        self::STATE_COMPLETED => 'Completed',
        self::STATE_CANCELLED => 'Cancelled',
    ];

    protected $table = 'fin_pv_batches';

    protected $fillable = [
        'batch_no', 'title', 'remarks', 'region_id', 'district_id', 'status', 'created_by', 'sent_at', 'sent_by',
        'cancelled_at', 'cancelled_by', 'cancel_reason', 'lock_version',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'created_by' => 'integer',
        'sent_by' => 'integer',
        'cancelled_by' => 'integer',
        'lock_version' => 'integer',
        'sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * THE definition of a batch's state, used by every screen, count and export (never re-derive it in a query or a view).
     *
     *  - cancelled / draft: the batch's own status;
     *  - sent, every voucher Completed or Voided: Completed;
     *  - sent, at least one voucher that is still open (not Completed or Voided) with no officer: Awaiting assignment;
     *  - otherwise (every open voucher has an officer): In progress, with `done` of `total` vouchers Completed or Voided.
     *
     * A voucher in a sent batch that was voided still counts in `total` and `done` (the batch shows it as voided).
     *
     * @param  iterable<int, FinPaymentVoucher|array<string, mixed>|object>  $members  anything with `status` and `assigned_to_user_id`
     * @return array{key: string, label: string, done: int, total: int, unassigned: int}
     */
    public static function computeState(string $status, iterable $members): array
    {
        $total = 0;
        $done = 0;
        $unassigned = 0;

        foreach ($members as $member) {
            $total++;
            $memberStatus = data_get($member, 'status');

            if (in_array($memberStatus, [FinPaymentVoucher::COMPLETED, FinPaymentVoucher::VOIDED], true)) {
                $done++;

                continue;
            }

            if (data_get($member, 'assigned_to_user_id') === null) {
                $unassigned++;
            }
        }

        $key = match (true) {
            $status === self::CANCELLED => self::STATE_CANCELLED,
            $status === self::DRAFT => self::STATE_DRAFT,
            $done === $total => self::STATE_COMPLETED,
            $unassigned > 0 => self::STATE_AWAITING_ASSIGNMENT,
            default => self::STATE_IN_PROGRESS,
        };

        $label = $key === self::STATE_IN_PROGRESS
            ? self::STATES[$key]." ({$done} of {$total} done)"
            : self::STATES[$key];

        return ['key' => $key, 'label' => $label, 'done' => $done, 'total' => $total, 'unassigned' => $unassigned];
    }

    /** This batch's state (loads its vouchers' status and assignment once). */
    public function state(): array
    {
        $members = $this->relationLoaded('vouchers')
            ? $this->vouchers
            : $this->vouchers()->get(['fin_pvs.id', 'fin_pvs.status', 'fin_pvs.assigned_to_user_id']);

        return self::computeState((string) $this->status, $members);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(FinPaymentVoucher::class, 'batch_id')->orderBy('fin_pvs.id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FinBatchEvent::class, 'batch_id')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }
}
