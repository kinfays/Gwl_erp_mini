<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place where incidents happen and (from Phase 2) equipment lives. Sites are deactivated, never deleted.
 */
class HsSite extends Model
{
    public const KIND_HEAD_OFFICE = 'head_office';
    public const KIND_REGIONAL_OFFICE = 'regional_office';
    public const KIND_DISTRICT_OFFICE = 'district_office';
    public const KIND_PAY_POINT = 'pay_point';
    public const KIND_DEPOT = 'depot';
    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_HEAD_OFFICE => 'Head Office',
        self::KIND_REGIONAL_OFFICE => 'Regional Office',
        self::KIND_DISTRICT_OFFICE => 'District Office',
        self::KIND_PAY_POINT => 'Pay Point',
        self::KIND_DEPOT => 'Depot',
        self::KIND_OTHER => 'Other',
    ];

    protected $fillable = [
        'name',
        'kind',
        'region_id',
        'district_id',
        'address',
        'is_active',
        'is_ppe_store',
        'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'is_active' => 'boolean',
        'is_ppe_store' => 'boolean',
        'created_by' => 'integer',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Active sites flagged as holding PPE stock. */
    public function scopePpeStores($query)
    {
        return $query->where('is_active', true)->where('is_ppe_store', true);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
