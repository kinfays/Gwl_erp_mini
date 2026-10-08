<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The stock level (total across sizes) at or below which a store's item is flagged low. No row means no flag. */
class HsPpeReorderLevel extends Model
{
    protected $fillable = [
        'site_id',
        'ppe_type_id',
        'level',
    ];

    protected $casts = [
        'level' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(HsSite::class, 'site_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(HsPpeType::class, 'ppe_type_id');
    }
}
