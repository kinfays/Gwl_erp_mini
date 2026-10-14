<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A monthly target of one KPI for one district, in force from `effective_from` until a later row replaces it. */
class CommercialDistrictTarget extends Model
{
    protected $fillable = ['district_id', 'metric', 'effective_from', 'value', 'updated_by'];

    protected $casts = ['district_id' => 'integer', 'effective_from' => 'date', 'value' => 'float'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
