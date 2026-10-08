<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the stock ledger. Immutable: never edited or deleted, corrections are new adjustment lines. The quantity is
 * signed and a balance is the sum.
 */
class HsPpeStockMovement extends Model
{
    public const RECEIPT = 'receipt';
    public const ISSUE = 'issue';
    public const RETURN = 'return';
    public const WRITE_OFF = 'write_off';
    public const ADJUSTMENT = 'adjustment';
    public const TRANSFER_IN = 'transfer_in';
    public const TRANSFER_OUT = 'transfer_out';

    public const TYPES = [
        self::RECEIPT => 'Received',
        self::ISSUE => 'Issued to staff',
        self::RETURN => 'Returned',
        self::WRITE_OFF => 'Written off',
        self::ADJUSTMENT => 'Adjusted',
        self::TRANSFER_IN => 'Transferred in',
        self::TRANSFER_OUT => 'Transferred out',
    ];

    /** The sign each movement type must carry: 1 positive, -1 negative, 0 either way (but never zero). */
    public const SIGNS = [
        self::RECEIPT => 1,
        self::RETURN => 1,
        self::TRANSFER_IN => 1,
        self::ISSUE => -1,
        self::WRITE_OFF => -1,
        self::TRANSFER_OUT => -1,
        self::ADJUSTMENT => 0,
    ];

    protected $fillable = [
        'site_id',
        'ppe_type_id',
        'size',
        'movement_type',
        'quantity',
        'reference',
        'issue_id',
        'notes',
        'created_by',
        'occurred_on',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'occurred_on' => 'date',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(HsSite::class, 'site_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(HsPpeType::class, 'ppe_type_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(HsPpeIssue::class, 'issue_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
