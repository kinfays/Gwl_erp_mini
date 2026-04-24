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