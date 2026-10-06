<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A report's spelling of a region or district, mapped once to the real one so later uploads match by themselves. */
class CommercialLocationAlias extends Model
{
    public const KIND_REGION = 'region';
    public const KIND_DISTRICT = 'district';

    protected $fillable = [
        'kind',
        'alias_normalized',
        'region_id',
        'district_id',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
