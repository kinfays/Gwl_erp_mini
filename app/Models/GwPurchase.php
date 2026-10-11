<?php

namespace App\Models;

use App\Models\Concerns\GwBooksRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One collection trip: the waybill, the packs bought, the Transport references (read-only) and fuel reimbursement. */
class GwPurchase extends Model
{
    use GwBooksRow;

    public const POSTED = 'posted';

    public const VOIDED = 'voided';

    public const NOT_APPLICABLE = 'not_applicable';

    public const PENDING = 'pending';

    public const REIMBURSED = 'reimbursed';

    protected $fillable = [
        'region_id', 'district_id', 'purchase_date', 'waybill_no', 'supplier_ref', 'note', 'status',
        'vehicle_id', 'driver_id', 'mileage_log_id', 'vehicle_expense_id',
        'fuel_paid_by_user_id', 'fuel_reimbursement_status', 'fuel_reimbursed_on', 'fuel_reimbursement_ref', 'fuel_reimbursed_by_user_id',
        'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'purchase_date' => 'date',
        'fuel_reimbursed_on' => 'date',
        'voided_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(GwPurchaseLine::class, 'purchase_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(GwExpense::class, 'purchase_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function mileageLog(): BelongsTo
    {
        return $this->belongsTo(MileageLog::class);
    }

    public function vehicleExpense(): BelongsTo
    {
        return $this->belongsTo(VehicleExpense::class);
    }

    public function fuelPaidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fuel_paid_by_user_id');
    }

    public function totalPacks(): int
    {
        return (int) $this->lines->sum('packs');
    }

    /** Cost of the water bought, in pesewas. */
    public function totalCost(): int
    {
        return (int) $this->lines->sum(fn (GwPurchaseLine $line) => $line->packs * $line->unit_cost);
    }
}
