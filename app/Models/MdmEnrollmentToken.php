<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdmEnrollmentToken extends Model
{
    use HasFactory;

    protected $table = 'mdm_enrollment_tokens';

    protected $fillable = [
        'ict_asset_id',
        'mdm_policy_id',
        'google_token_name',
        'expires_at',
        'used_at',
        'created_by',
    ];

    protected $casts = [
        'ict_asset_id' => 'integer',
        'mdm_policy_id' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_by' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'ict_asset_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(MdmPolicy::class, 'mdm_policy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
