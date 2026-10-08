<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person affected by an incident. Health information: shown only to those holding health_safety.view_injury_details.
 */
class HsIncidentPerson extends Model
{
    // "person" does not pluralise to "persons" in Laravel's inflector, so the table is named here.
    protected $table = 'hs_incident_persons';

    public const PERSON_TYPES = [
        'staff' => 'Staff',
        'contractor' => 'Contractor',
        'visitor' => 'Visitor',
        'public' => 'Member of the public',
    ];

    public const TREATMENTS = [
        'none' => 'None',
        'first_aid' => 'First aid',
        'clinic' => 'Clinic',
        'hospital' => 'Hospital',
    ];

    protected $fillable = [
        'incident_id',
        'employee_id',
        'name_raw',
        'person_type',
        'injury_type',
        'body_part',
        'treatment',
        'first_aider_name',
        'lost_time_days',
        'returned_to_work_on',
    ];

    protected $casts = [
        'lost_time_days' => 'integer',
        'returned_to_work_on' => 'date',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(HsIncident::class, 'incident_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function displayName(): string
    {
        return $this->employee?->full_name ?? $this->name_raw ?? 'Unnamed';
    }
}
