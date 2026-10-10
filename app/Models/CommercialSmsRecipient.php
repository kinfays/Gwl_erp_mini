<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One customer a campaign sends to: an id and a status. There is deliberately no phone column. */
class CommercialSmsRecipient extends Model
{
    public $timestamps = false;

    public const PENDING = 'pending';

    public const CLAIMED = 'claimed';

    public const SUBMITTED = 'submitted';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    /** Order a status may only move forward in (a replayed delivery report cannot undo a later one). */
    public const RANK = [self::PENDING => 0, self::CLAIMED => 1, self::UNKNOWN => 2, self::SUBMITTED => 3, self::FAILED => 4, self::DELIVERED => 5];

    protected $fillable = ['campaign_id', 'customer_id', 'slot', 'status', 'claim_token', 'provider_message_id', 'error_code', 'claimed_at', 'submitted_at', 'delivered_at'];

    protected $casts = ['claimed_at' => 'datetime', 'submitted_at' => 'datetime', 'delivered_at' => 'datetime'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CommercialSmsCampaign::class, 'campaign_id');
    }
}
