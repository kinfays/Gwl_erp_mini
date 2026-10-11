<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed line of the stock ledger. Immutable: GwaterStockService is the only writer, a correction is an `adjustment`
 * row, and a void posts reversing rows. Updating or deleting a row is refused.
 */
class GwStockMovement extends Model
{
    public const OPENING = 'opening';

    public const PURCHASE = 'purchase';

    public const SALE = 'sale';

    public const DAMAGE = 'damage';

    public const ADJUSTMENT = 'adjustment';

    public const TYPES = [self::OPENING, self::PURCHASE, self::SALE, self::DAMAGE, self::ADJUSTMENT];

    protected $fillable = [
        'region_id', 'district_id', 'product_id', 'movement_date', 'type', 'packs', 'source_type', 'source_id', 'note', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'product_id' => 'integer',
        'movement_date' => 'date',
        'packs' => 'integer',
        'source_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock movements are immutable; post a correcting movement instead.'));
        static::deleting(fn () => throw new \LogicException('Stock movements are never deleted; post a reversing movement instead.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(GwProduct::class, 'product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
