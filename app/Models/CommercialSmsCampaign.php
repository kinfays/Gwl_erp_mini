<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One SMS send to customers. Holds the message TEMPLATE and the audience RULES, never a phone number or a merged message.
 * Every state change goes through Services/Commercial/Sms/SmsCampaignService.
 */
class CommercialSmsCampaign extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const OPEN = [self::STATUS_SCHEDULED, self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_CANCELLING];

    protected $fillable = [
        'region_id', 'created_by', 'name', 'status', 'audience_type', 'audience', 'include_second_number', 'include_missing', 'sender_id',
        'template_id', 'body', 'personalised', 'encoding', 'segments', 'accounts_total', 'recipients_total', 'credits_estimated',
        'credits_used', 'totals', 'details_purged_at', 'phase', 'cursor', 'cancel_requested', 'error_message', 'scheduled_at', 'started_at', 'finished_at', 'cancelled_at',
    ];

    protected $casts = [
        'audience' => 'array',
        'totals' => 'array',
        'details_purged_at' => 'datetime',
        'include_second_number' => 'boolean',
        'include_missing' => 'boolean',
        'personalised' => 'boolean',
        'cancel_requested' => 'boolean',
        'region_id' => 'integer',
        'created_by' => 'integer',
        'segments' => 'integer',
        'accounts_total' => 'integer',
        'recipients_total' => 'integer',
        'credits_estimated' => 'integer',
        'credits_used' => 'integer',
        'cursor' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CommercialSmsRecipient::class, 'campaign_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
