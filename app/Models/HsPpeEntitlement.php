<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How many of a PPE type staff with a job title should hold. At least one; no row means no entitlement. */
class HsPpeEntitlement extends Model
{
    protected $fillable = [
        'job_title_id',
        'ppe_type_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(HsPpeType::class, 'ppe_type_id');
    }
}
