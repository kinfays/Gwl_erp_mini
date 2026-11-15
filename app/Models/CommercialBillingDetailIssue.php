<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account on a capped exception list of the Detailed Billing Report (design 8.39): the ACCOUNT NUMBER, where it sat, and the figure
 * that put it on the list. Never a name, address, phone or meter number. Kept for commercial_billing_detail_issue_keep_months, then
 * purged by commercial:billing-details:purge (the rollups and bands stay with their batch).
 */
class CommercialBillingDetailIssue extends Model
{
    public const ACTIVE_UNBILLED = 'active_unbilled';

    public const BILLED_NOT_ACTIVE = 'billed_not_active';

    public const TARIFF_MISMATCH = 'tariff_mismatch';

    public const ZERO_CONSUME_REGULAR = 'zero_consume_regular';

    public const STALE_READ = 'stale_read';

    public const BILLED_UNPAID = 'billed_unpaid';

    public const LARGE_ADJUSTMENT = 'large_adjustment';

    public const TOP_DEBTOR = 'top_debtor';

    public const DUPLICATE_ACCOUNT = 'duplicate_account';

    public const NEGATIVE_CONSUME = 'negative_consume';

    /** Phase 8e: an account whose status differs between the bill and the customer list (the figure is always 0; written by the customer-list link, not the scan). */
    public const STATUS_CHANGED = 'status_changed';

    /** The kinds the link writes (kept apart from KINDS, which the Billing details screen lists). */
    public const LINK_KINDS = [self::STATUS_CHANGED => 'Not used (0)'];

    /** kind => what the figure in `amount` means (the Billing details screen and the exports label the column with it). */
    public const KINDS = [
        self::ACTIVE_UNBILLED => 'Balance due (GH¢)',
        self::BILLED_NOT_ACTIVE => 'Month total (GH¢)',
        self::TARIFF_MISMATCH => 'Difference, charged less rate x consume (GH¢)',
        self::ZERO_CONSUME_REGULAR => 'Month total (GH¢)',
        self::STALE_READ => 'Days since the last read (9999 = never)',
        self::BILLED_UNPAID => 'Month total (GH¢)',
        self::LARGE_ADJUSTMENT => 'Adjusted (GH¢)',
        self::TOP_DEBTOR => 'Balance due (GH¢)',
        self::DUPLICATE_ACCOUNT => 'Month total of the second row (GH¢)',
        self::NEGATIVE_CONSUME => 'Consume',
    ];

    /** The amount stored for an account that has no last read date at all. */
    public const NEVER_READ_DAYS = 9999;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['batch_id' => 'integer', 'route_id' => 'integer', 'district_id' => 'integer', 'amount' => 'float', 'created_at' => 'datetime'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }
}
