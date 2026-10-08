<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo on an incident, kept on the private disk and served only through an authorised route. */
class HsIncidentAttachment extends Model
{
    protected $fillable = [
        'incident_id',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(HsIncident::class, 'incident_id');
    }
}
