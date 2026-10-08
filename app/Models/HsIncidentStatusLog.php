<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The timeline of an incident. A row whose from and to status are equal records a change that is not a status move
 * (severity, type or owner set at triage), so the timeline tells the whole story.
 */
class HsIncidentStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'incident_id',
        'from_status',
        'to_status',
        'note',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(HsIncident::class, 'incident_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedStatus(): bool
    {
        return $this->from_status !== $this->to_status;
    }
}
