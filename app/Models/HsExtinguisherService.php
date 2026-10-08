<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One service of an extinguisher (inspection, refill, recharge, hydrostatic test). Immutable. */
class HsExtinguisherService extends Model
{
    public const TYPE_INSPECTION = 'inspection';
    public const TYPE_REFILL = 'refill';
    public const TYPE_RECHARGE = 'recharge';
    public const TYPE_HYDRO_TEST = 'hydro_test';

    public const TYPES = [
        self::TYPE_INSPECTION => 'Inspection',
        self::TYPE_REFILL => 'Refill',
        self::TYPE_RECHARGE => 'Recharge',
        self::TYPE_HYDRO_TEST => 'Hydrostatic test',
    ];

    protected $fillable = [
        'extinguisher_id',
        'serviced_on',
        'service_type',
        'vendor',
        'certificate_path',
        'certificate_name',
        'certificate_mime',
        'new_expiry_date',
        'next_service_due',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'serviced_on' => 'date',
        'new_expiry_date' => 'date',
        'next_service_due' => 'date',
    ];

    public function extinguisher(): BelongsTo
    {
        return $this->belongsTo(HsFireExtinguisher::class, 'extinguisher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->service_type] ?? $this->service_type;
    }
}
