<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A corrective or preventive action raised on an incident. */
class HsIncidentAction extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_DONE = 'done';
    public const STATUS_VERIFIED = 'verified';

    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_DONE => 'Done',
        self::STATUS_VERIFIED => 'Verified',
    ];

    protected $fillable = [
        'incident_id',
        'description',
        'assigned_to_employee_id',
        'due_on',
        'status',
        'completed_on',
        'completion_note',
        'verified_by',
        'verified_at',
        'created_by',
    ];

    protected $casts = [
        'due_on' => 'date',
        'completed_on' => 'date',
        'verified_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(HsIncident::class, 'incident_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->due_on !== null && $this->due_on->lt(today());
    }
}
