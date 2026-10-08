<?php

namespace App\Models;

use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An incident report: what the four Microsoft Forms collected, plus what happens next (triage, investigation, actions,
 * closure). What each viewer may see of one is decided only by App\Services\HealthSafety\IncidentVisibility.
 */
class HsIncident extends Model
{
    public const STATUS_REPORTED = 'reported';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_PENDING_CLOSURE = 'pending_closure';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REPORTED => 'Reported',
        self::STATUS_ACKNOWLEDGED => 'Acknowledged',
        self::STATUS_INVESTIGATING => 'Investigating',
        self::STATUS_PENDING_CLOSURE => 'Pending closure',
        self::STATUS_CLOSED => 'Closed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Statuses in which the incident is still being worked on. */
    public const OPEN_STATUSES = [
        self::STATUS_REPORTED,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_INVESTIGATING,
        self::STATUS_PENDING_CLOSURE,
    ];

    // The form's six types, in the form's words.
    public const TYPE_NEAR_MISS = 'near_miss';
    public const TYPE_INJURY = 'injury';
    public const TYPE_PROPERTY_DAMAGE = 'property_damage';
    public const TYPE_ENVIRONMENTAL = 'environmental';
    public const TYPE_INCIDENT = 'incident';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_NEAR_MISS => 'Near miss',
        self::TYPE_INJURY => 'Injury',
        self::TYPE_PROPERTY_DAMAGE => 'Property damage',
        self::TYPE_ENVIRONMENTAL => 'Environmental',
        self::TYPE_INCIDENT => 'Incident',
        self::TYPE_OTHER => 'Other',
    ];

    /** One line of plain-language help under each type on the report form. */
    public const TYPE_HELP = [
        self::TYPE_NEAR_MISS => 'Nothing happened this time, but it could have.',
        self::TYPE_INJURY => 'Someone was hurt or became unwell.',
        self::TYPE_PROPERTY_DAMAGE => 'Equipment, a vehicle, a building or other property was damaged.',
        self::TYPE_ENVIRONMENTAL => 'A spill, leak, release or other harm to the environment.',
        self::TYPE_INCIDENT => 'Something else went wrong that is none of the above.',
        self::TYPE_OTHER => 'Anything else you want the Health & Safety Officer to know.',
    ];

    /** Types that need a root cause and findings before they can be closed (Near miss / Other do not). */
    public const TYPES_NEEDING_INVESTIGATION = [
        self::TYPE_INJURY,
        self::TYPE_PROPERTY_DAMAGE,
        self::TYPE_ENVIRONMENTAL,
        self::TYPE_INCIDENT,
    ];

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_LOW => 'Low',
        self::SEVERITY_MEDIUM => 'Medium',
        self::SEVERITY_HIGH => 'High',
        self::SEVERITY_CRITICAL => 'Critical',
    ];

    /** Severities that need an approver to close (separation of duties). */
    public const SEVERITIES_NEEDING_APPROVAL = [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL];

    public const CONTEXT_REGIONAL_OFFICE = 'regional_office';
    public const CONTEXT_DISTRICT_OFFICE = 'district_office';
    public const CONTEXT_PAY_POINT = 'pay_point';
    public const CONTEXT_FIELD_WORK = 'field_work';

    public const CONTEXTS = [
        self::CONTEXT_REGIONAL_OFFICE => 'Regional Office',
        self::CONTEXT_DISTRICT_OFFICE => 'District Office',
        self::CONTEXT_PAY_POINT => 'Pay Point',
        self::CONTEXT_FIELD_WORK => 'Field Work',
    ];

    public const FIRST_AID_YES = 'yes';
    public const FIRST_AID_NO = 'no';
    public const FIRST_AID_NOT_NEEDED = 'no_need';

    public const FIRST_AID = [
        self::FIRST_AID_YES => 'Yes',
        self::FIRST_AID_NO => 'No',
        self::FIRST_AID_NOT_NEEDED => 'No need',
    ];

    public const ROOT_CAUSES = [
        'human' => 'Human',
        'equipment' => 'Equipment',
        'process' => 'Process',
        'environment' => 'Environment',
        'management' => 'Management',
    ];

    protected $fillable = [
        'reference',
        'incident_type',
        'other_type_text',
        'severity',
        'context',
        'region_id',
        'district_id',
        'department_id',
        'site_id',
        'site_name_raw',
        'location_detail',
        'occurred_on',
        'occurred_time',
        'description',
        'first_aid',
        'witness_name',
        'witness_contact',
        'no_witness',
        'reported_by_user_id',
        'reported_by_employee_id',
        'reporter_name_raw',
        'recorded_by_user_id',
        'is_confidential',
        'is_urgent',
        'is_anonymous',
        'status',
        'owner_user_id',
        'acknowledged_at',
        'acknowledged_by',
        'root_cause_category',
        'findings',
        'closure_note',
        'closed_at',
        'closed_by',
        'approved_at',
        'approved_by',
        'cancel_reason',
        'reopened_count',
        'reportable_externally',
        'external_ref',
        'external_reported_on',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'department_id' => 'integer',
        'site_id' => 'integer',
        'occurred_on' => 'date',
        'no_witness' => 'boolean',
        'is_confidential' => 'boolean',
        'is_urgent' => 'boolean',
        'is_anonymous' => 'boolean',
        'reportable_externally' => 'boolean',
        'reopened_count' => 'integer',
        'acknowledged_at' => 'datetime',
        'closed_at' => 'datetime',
        'approved_at' => 'datetime',
        'external_reported_on' => 'date',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(HsSite::class, 'site_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function reporterEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by_employee_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function persons(): HasMany
    {
        return $this->hasMany(HsIncidentPerson::class, 'incident_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(HsIncidentAction::class, 'incident_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(HsIncidentAttachment::class, 'incident_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(HsIncidentStatusLog::class, 'incident_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function typeLabel(): string
    {
        $label = self::TYPES[$this->incident_type] ?? $this->incident_type;

        return $this->incident_type === self::TYPE_OTHER && filled($this->other_type_text)
            ? $label.': '.$this->other_type_text
            : $label;
    }

    public function needsApprovalToClose(): bool
    {
        return in_array($this->severity, self::SEVERITIES_NEEDING_APPROVAL, true);
    }

    /** Where it happened, in words: the department, district, pay point or place, as the form's first question set it. */
    public function placeLabel(): string
    {
        $parts = match ($this->context) {
            self::CONTEXT_REGIONAL_OFFICE => [$this->department?->department_name, $this->region?->region_name ? $this->region->region_name.' Regional Office' : null],
            self::CONTEXT_DISTRICT_OFFICE => [$this->district?->district_name, $this->location_detail],
            self::CONTEXT_PAY_POINT => [$this->site?->name ?? $this->site_name_raw, $this->district?->district_name],
            self::CONTEXT_FIELD_WORK => [$this->location_detail, $this->district?->district_name],
            default => [],
        };

        return collect($parts)->filter()->join(', ') ?: (self::CONTEXTS[$this->context] ?? '');
    }

    /** Still unacknowledged after the configured number of hours. */
    public function isAcknowledgementOverdue(): bool
    {
        return $this->status === self::STATUS_REPORTED
            && $this->created_at !== null
            && $this->created_at->lte(now()->subHours((int) HealthSafetySettings::value('hs_ack_hours')));
    }

    /** The date the investigation should be finished by, counted from acknowledgement. */
    public function investigationDueOn(): ?Carbon
    {
        return $this->acknowledged_at?->copy()->addDays((int) HealthSafetySettings::value('hs_investigation_due_days'))->startOfDay();
    }
}
