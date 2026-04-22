<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Enums\ManagerRecommendation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'leave_type' => LeaveType::class,
        'leave_status' => LeaveStatus::class,
        'manager_recommendation' => ManagerRecommendation::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'request_year' => 'integer',
    ];

    /* ---------------- Relationships ---------------- */

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}