<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdmDevice extends Model
{
    use HasFactory;

    public const MANAGEMENT_MODES = [
        'DEVICE_OWNER' => 'Fully managed',
        'PROFILE_OWNER' => 'Work profile',
    ];

    protected $table = 'mdm_devices';

    protected $fillable = [
        'ict_asset_id',
        'mdm_policy_id',
        'google_device_name',
        'enrollment_token_name',
        'management_mode',
        'state',
        'applied_state',
        'policy_compliant',
        'non_compliance',
        'android_version',
        'security_patch_level',
        'hardware_info',
        'application_reports',
        'last_status_report_at',
        'last_policy_sync_at',
        'last_synced_at',
        'enrolled_at',
        'is_lost',
        'lost_at',
        'needs_review',
        'review_reason',
    ];

    protected $casts = [
        'ict_asset_id' => 'integer',
        'mdm_policy_id' => 'integer',
        'policy_compliant' => 'boolean',
        'non_compliance' => 'array',
        'hardware_info' => 'array',
        'application_reports' => 'array',
        'last_status_report_at' => 'datetime',
        'last_policy_sync_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'is_lost' => 'boolean',
        'lost_at' => 'datetime',
        'needs_review' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'ict_asset_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(MdmPolicy::class, 'mdm_policy_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(MdmDeviceCommand::class)->latest('requested_at')->latest('id');
    }

    public function isDeleted(): bool
    {
        return $this->state === 'DELETED';
    }

    public function hasReported(): bool
    {
        return $this->last_status_report_at !== null;
    }

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('state')->orWhere('state', '!=', 'DELETED'));
    }

    public function managementModeLabel(): string
    {
        return self::MANAGEMENT_MODES[$this->management_mode] ?? ($this->management_mode ?: 'Unknown');
    }
}
