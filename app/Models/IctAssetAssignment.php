<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spell an employee holds a device (the Assets twin of VehicleAssignmentHistory). Written only by
 * Services/Assets/AssetAssignmentService. A row is open while is_current = 1 and closed once it has an end_reason;
 * a "corrected" row records a holder that was picked by mistake and is never a "previous" holder.
 */
class IctAssetAssignment extends Model
{
    public const END_REASSIGNED = 'reassigned';
    public const END_UNASSIGNED = 'unassigned';
    public const END_CORRECTED = 'corrected';

    public const END_REASONS = [self::END_REASSIGNED, self::END_UNASSIGNED, self::END_CORRECTED];

    /** The reasons that make a closed row a "previous assigned to" candidate. */
    public const PREVIOUS_REASONS = [self::END_REASSIGNED, self::END_UNASSIGNED];

    /** Why an old row is being marked corrected after the fact (a fixed list: free text never reaches the audit log). */
    public const CORRECTION_CODES = [
        'wrong_person_picked' => 'The wrong person was picked',
        'data_entry_error' => 'Data entry error',
        'never_held' => 'The person never held this device',
    ];

    protected $table = 'ict_asset_assignments';

    protected $fillable = [
        'ict_asset_id',
        'employee_id',
        'assigned_at',
        'ended_at',
        'end_reason',
        'correction_code',
        'is_current',
        'is_backfilled',
        'recorded_by_user_id',
        'ended_by_user_id',
    ];

    protected $casts = [
        'ict_asset_id' => 'integer',
        'employee_id' => 'integer',
        'assigned_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_current' => 'boolean',
        'is_backfilled' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'ict_asset_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('is_current'), true);
    }

    /**
     * THE definition of a "previous assigned to" row: closed by a handover or an unassign, never by a correction.
     * Newest first, so ->first() is the previous holder.
     */
    public function scopePreviousHolders(Builder $query): Builder
    {
        return $query
            ->whereIn($query->getModel()->qualifyColumn('end_reason'), self::PREVIOUS_REASONS)
            ->orderByDesc($query->getModel()->qualifyColumn('id'));
    }

    public function isCorrected(): bool
    {
        return $this->end_reason === self::END_CORRECTED;
    }

    public function reasonLabel(): string
    {
        return match ($this->end_reason) {
            self::END_REASSIGNED => 'Reassigned',
            self::END_UNASSIGNED => 'Unassigned',
            self::END_CORRECTED => 'Corrected',
            default => 'Current',
        };
    }
}
