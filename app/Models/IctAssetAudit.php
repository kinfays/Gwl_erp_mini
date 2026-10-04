<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One physical stock-check run over a scope of assets. The lines hold the per-asset result. */
class IctAssetAudit extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    protected $table = 'ict_asset_audits';

    protected $fillable = [
        'title',
        'scope_device_category',
        'scope_region_id',
        'scope_district_id',
        'status',
        'started_by_user_id',
        'started_at',
        'completed_at',
        'reconciliation_rate',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'reconciliation_rate' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(IctAssetAuditLine::class, 'ict_asset_audit_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'scope_region_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'scope_district_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** e.g. "Phones · Greater Accra · Tema", or "All assets". */
    public function scopeSummary(): string
    {
        $parts = array_filter([
            match ($this->scope_device_category) {
                IctAsset::DEVICE_CATEGORY_ASSET => 'Assets',
                IctAsset::DEVICE_CATEGORY_PHONE => 'Phones',
                IctAsset::DEVICE_CATEGORY_NETWORK => 'Network',
                default => null,
            },
            $this->region?->region_name,
            $this->district?->district_name,
        ]);

        return $parts ? implode(' · ', $parts) : 'All assets';
    }
}
