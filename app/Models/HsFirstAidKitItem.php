<?php

namespace App\Models;

use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a kit's contents: what it should hold, what it holds now, and when it expires. */
class HsFirstAidKitItem extends Model
{
    protected $fillable = [
        'kit_id',
        'item_name',
        'required_qty',
        'current_qty',
        'has_expiry',
        'expiry_date',
        'sort_order',
    ];

    protected $casts = [
        'required_qty' => 'integer',
        'current_qty' => 'integer',
        'has_expiry' => 'boolean',
        'expiry_date' => 'date',
        'sort_order' => 'integer',
    ];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(HsFirstAidKit::class, 'kit_id');
    }

    public function isShort(): bool
    {
        return $this->current_qty < $this->required_qty;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->copy()->startOfDay()->lt(today());
    }

    public function isExpiring(): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->copy()->startOfDay()->gte(today())
            && $this->expiry_date->copy()->startOfDay()->lte(today()->addDays((int) HealthSafetySettings::value('hs_expiry_warning_days')));
    }
}
