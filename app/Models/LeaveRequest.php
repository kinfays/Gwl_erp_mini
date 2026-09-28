<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $fillable = [
        'requester_id',
        'leave_type',
        'start_date',
        'end_date',
        'total_days_applied',
        'leave_details',
        'manager_id',
        'manager_comments',
        'manager_recommendation',
        'leave_status',
        'submitted_at',
        'recommended_at',
        'decided_at',
        'approved_by_id',
        'chiefManager_comments',
        'request_year',
        'department_id',
        'region_id',
        'file_attachment',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'request_year' => 'integer',
        'submitted_at' => 'datetime',
        'recommended_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function canBeEditedByRequester(): bool
    {
        return $this->leave_status === 'Planned'
            || ($this->leave_status === 'Pending Approval' && $this->manager_recommendation === 'Pending');
    }

    /**
     * Hours the manager took to recommend or reject, from submission. Null when the manager
     * hasn't acted, when the step was skipped (a manager's own request is recommended the moment
     * it is submitted), or when the time predates these columns.
     */
    public function managerResponseHours(): ?float
    {
        if (! $this->submitted_at || ! $this->recommended_at || ! $this->recommended_at->gt($this->submitted_at)) {
            return null;
        }

        return $this->submitted_at->diffInHours($this->recommended_at);
    }

    /**
     * Hours the final approver took to approve or deny, from the recommendation (or from
     * submission when the recommendation step was skipped). Null for requests the manager
     * rejected, which never reached the approver.
     */
    public function approverHours(): ?float
    {
        if (! $this->approved_by_id || ! $this->recommended_at || ! $this->decided_at) {
            return null;
        }

        return $this->recommended_at->diffInHours($this->decided_at);
    }

    /** Hours from submission to the final decision, whoever made it. */
    public function cycleHours(): ?float
    {
        if (! $this->submitted_at || ! $this->decided_at) {
            return null;
        }

        return $this->submitted_at->diffInHours($this->decided_at);
    }

    public function scopeVisibleForApprovals($query, Employee $actor, User $actorUser)
    {
        // Manager queue: direct recommender
        $query->where(function ($q) use ($actor) {
            $q->where('manager_id', $actor->id);
        });

        // Chief queue: only those where the resolved chief == actor
        // We resolve by matching current chain rules using stored manager_id and region/location data.
        // For correctness, we filter by "recommended & pending" and then match approver in service layer
        // (efficient enough for pagination-size lists).
        return $query;
    }

}
