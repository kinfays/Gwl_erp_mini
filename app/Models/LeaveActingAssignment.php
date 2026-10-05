<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone acting in a final-approver post for a date window (see LeaveActingAssignmentService). */
class LeaveActingAssignment extends Model
{
    protected $fillable = ['user_id', 'acting_for_role', 'region_id', 'department_id', 'starts_on', 'ends_on', 'is_active', 'created_by'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** In force on $date: switched on and inside its window (both days included). */
    public function scopeActiveOn(Builder $query, $date = null): Builder
    {
        $day = ($date ? \Carbon\Carbon::parse($date) : today())->toDateString();

        return $query->where('is_active', true)
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day);
    }

    public function isActiveOn($date = null): bool
    {
        $day = ($date ? \Carbon\Carbon::parse($date) : today())->startOfDay();

        return $this->is_active && $this->starts_on->lte($day) && $this->ends_on->gte($day);
    }
}
