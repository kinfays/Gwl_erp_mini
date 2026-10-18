<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The ANNUAL target of one KPI for one district: `effective_from` is 1 January of the year it is for, and it applies to that year only. */
class CommercialDistrictTarget extends Model
{
    protected $fillable = ['district_id', 'metric', 'effective_from', 'value', 'updated_by'];

    protected $casts = ['district_id' => 'integer', 'effective_from' => 'date', 'value' => 'float'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
