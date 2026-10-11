<?php

namespace App\Models;

use App\Models\Concerns\GwBooksRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Damaged packs, written off at cost. The table exists from Phase 1; its screen comes in Phase 2. */
class GwDamage extends Model
{
    use GwBooksRow;

    public const STAGES = ['transit', 'loading', 'offloading', 'storage'];

    protected $fillable = [
        'region_id', 'district_id', 'damage_date', 'product_id', 'packs', 'stage', 'unit_cost', 'reason', 'purchase_id', 'status',
        'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'damage_date' => 'date',
        'packs' => 'integer',
        'unit_cost' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(GwPurchase::class, 'purchase_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(GwProduct::class, 'product_id');
    }
}
