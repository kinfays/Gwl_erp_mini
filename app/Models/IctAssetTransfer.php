<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change in an asset's life: who holds it, what state it is in, or where it sits. One row per changed dimension. */
class IctAssetTransfer extends Model
{
    public const TYPE_ASSIGNMENT_CHANGE = 'assignment_change';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_DISTRICT_CHANGE = 'district_change';
    public const TYPE_REPLACEMENT = 'replacement';

    public const TYPES = [
        self::TYPE_ASSIGNMENT_CHANGE,
        self::TYPE_STATUS_CHANGE,
        self::TYPE_DISTRICT_CHANGE,
        self::TYPE_REPLACEMENT,
    ];

    protected $table = 'ict_asset_transfers';

    protected $fillable = [
        'ict_asset_id',
        'transfer_type',
        'from_employee_id',
        'to_employee_id',
        'from_status',
        'to_status',
        'from_district_id',
        'to_district_id',
        'related_asset_id',
        'reason',
        'performed_by_user_id',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'ict_asset_id');
    }

    public function relatedAsset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'related_asset_id');
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function fromDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'from_district_id');
    }

    public function toDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'to_district_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /** Plain-language line for the History list, e.g. "Status changed: Active -> Damaged (faulty power button)". */
    public function summary(): string
    {
        $reason = filled($this->reason) ? ' ('.trim($this->reason).')' : '';

        return match ($this->transfer_type) {
            self::TYPE_ASSIGNMENT_CHANGE => match (true) {
                $this->from_employee_id && $this->to_employee_id => 'Reassigned from '.$this->employeeName('fromEmployee').' to '.$this->employeeName('toEmployee'),
                (bool) $this->to_employee_id => 'Assigned to '.$this->employeeName('toEmployee'),
                (bool) $this->from_employee_id => 'Unassigned from '.$this->employeeName('fromEmployee'),
                default => 'Assignment changed',
            },
            self::TYPE_STATUS_CHANGE => 'Status changed: '.($this->from_status ?: 'None').' -> '.($this->to_status ?: 'None'),
            self::TYPE_DISTRICT_CHANGE => 'Location changed from '.($this->fromDistrict?->district_name ?: 'no district').' to '.($this->toDistrict?->district_name ?: 'no district'),
            self::TYPE_REPLACEMENT => 'Replacement linked to '.($this->relatedAsset?->asset_name ?: 'another device'),
            default => ucfirst(str_replace('_', ' ', (string) $this->transfer_type)),
        }.$reason;
    }

    private function employeeName(string $relation): string
    {
        return $this->{$relation}?->full_name ?: 'a former employee';
    }
}
