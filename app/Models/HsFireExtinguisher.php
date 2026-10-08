<?php

namespace App\Models;

use App\Models\Concerns\HasEquipmentState;
use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fire extinguisher at a site or in a vehicle. The dates (expiry, next service, next hydrostatic test, last check) drive
 * a computed state (see HasEquipmentState); the lifecycle status is set by hand.
 */
class HsFireExtinguisher extends Model
{
    use HasEquipmentState;

    public const STATUS_IN_SERVICE = 'in_service';
    public const STATUS_OUT_FOR_SERVICE = 'out_for_service';
    public const STATUS_DISCHARGED = 'discharged';
    public const STATUS_DECOMMISSIONED = 'decommissioned';

    public const STATUSES = [
        self::STATUS_IN_SERVICE => 'In service',
        self::STATUS_OUT_FOR_SERVICE => 'Out for service',
        self::STATUS_DISCHARGED => 'Discharged',
        self::STATUS_DECOMMISSIONED => 'Decommissioned',
    ];

    /** Statuses a unit is evaluated in (an out-for-service unit still has dates that run out). */
    public const EVALUATED_STATUSES = [self::STATUS_IN_SERVICE, self::STATUS_OUT_FOR_SERVICE];

    /** Statuses an officer may switch between by hand (decommissioning has its own action and a reason). */
    public const SETTABLE_STATUSES = [self::STATUS_IN_SERVICE, self::STATUS_OUT_FOR_SERVICE, self::STATUS_DISCHARGED];

    public const TYPE_WATER = 'water';
    public const TYPE_FOAM = 'foam';
    public const TYPE_DRY_POWDER = 'dry_powder';
    public const TYPE_CO2 = 'co2';
    public const TYPE_WET_CHEMICAL = 'wet_chemical';

    public const TYPES = [
        self::TYPE_WATER => 'Water',
        self::TYPE_FOAM => 'Foam',
        self::TYPE_DRY_POWDER => 'Dry powder',
        self::TYPE_CO2 => 'CO2',
        self::TYPE_WET_CHEMICAL => 'Wet chemical',
    ];

    public const STATE_EXPIRED = 'expired';
    public const STATE_SERVICE_OVERDUE = 'service_overdue';
    public const STATE_HYDRO_OVERDUE = 'hydro_overdue';
    public const STATE_EXPIRING = 'expiring';
    public const STATE_SERVICE_DUE_SOON = 'service_due_soon';
    public const STATE_CHECK_FAILED = 'check_failed';
    public const STATE_CHECK_OVERDUE = 'check_overdue';

    /** The six yes/no points of a monthly visual check. */
    public const CHECK_POINTS = [
        'in_place' => 'In its place',
        'accessible' => 'Easy to reach',
        'seal_intact' => 'Seal / pin intact',
        'pressure_ok' => 'Pressure in the green',
        'no_damage' => 'No damage or corrosion',
        'signage_ok' => 'Sign in place',
    ];

    protected $fillable = [
        'asset_code',
        'serial_number',
        'extinguisher_type',
        'capacity',
        'manufacturer',
        'manufactured_on',
        'site_id',
        'vehicle_id',
        'region_id',
        'district_id',
        'location_detail',
        'responsible_employee_id',
        'status',
        'expiry_date',
        'last_serviced_on',
        'next_service_due',
        'last_hydro_test_on',
        'next_hydro_test_due',
        'last_checked_on',
        'last_check_result',
        'decommissioned_on',
        'decommission_reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'vehicle_id' => 'integer',
        'region_id' => 'integer',
        'district_id' => 'integer',
        'responsible_employee_id' => 'integer',
        'manufactured_on' => 'date',
        'expiry_date' => 'date',
        'last_serviced_on' => 'date',
        'next_service_due' => 'date',
        'last_hydro_test_on' => 'date',
        'next_hydro_test_due' => 'date',
        'last_checked_on' => 'date',
        'label_printed_at' => 'datetime',
        'decommissioned_on' => 'date',
    ];

    /**
     * First match wins (design 2.3 as amended by 8.16 item 1): expired, service overdue, hydrostatic test overdue, last
     * check failed, expiring, service (or hydrostatic test) due soon, check overdue. A failed check sits before "expiring"
     * so a unit that may not work today is never shown as merely expiring.
     */
    public static function states(): array
    {
        return [
            self::STATE_EXPIRED => [
                'label' => 'Expired',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => static::dateBeforeToday($w, 'expiry_date')),
                'php' => fn (self $e) => $e->isEvaluated() && static::phpBeforeToday($e->expiry_date),
            ],
            self::STATE_SERVICE_OVERDUE => [
                'label' => 'Service overdue',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => static::dateBeforeToday($w, 'next_service_due')),
                'php' => fn (self $e) => $e->isEvaluated() && static::phpBeforeToday($e->next_service_due),
            ],
            self::STATE_HYDRO_OVERDUE => [
                'label' => 'Hydrostatic test overdue',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => static::dateBeforeToday($w, 'next_hydro_test_due')),
                'php' => fn (self $e) => $e->isEvaluated() && static::phpBeforeToday($e->next_hydro_test_due),
            ],
            self::STATE_CHECK_FAILED => [
                'label' => 'Last check failed',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => static::checkFailedSql($w)),
                'php' => fn (self $e) => $e->isEvaluated() && $e->last_check_result === 'fail',
            ],
            self::STATE_EXPIRING => [
                'label' => 'Expiring soon',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => static::dateWithin($w, 'expiry_date', (int) HealthSafetySettings::value('hs_expiry_warning_days'))),
                'php' => fn (self $e) => $e->isEvaluated() && static::phpWithin($e->expiry_date, (int) HealthSafetySettings::value('hs_expiry_warning_days')),
            ],
            self::STATE_SERVICE_DUE_SOON => [
                'label' => 'Service due soon',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => $w
                    ->where(fn (Builder $a) => static::dateWithin($a, 'next_service_due', (int) HealthSafetySettings::value('hs_expiry_warning_days')))
                    ->orWhere(fn (Builder $b) => static::dateWithin($b, 'next_hydro_test_due', (int) HealthSafetySettings::value('hs_expiry_warning_days')))),
                'php' => fn (self $e) => $e->isEvaluated()
                    && (static::phpWithin($e->next_service_due, (int) HealthSafetySettings::value('hs_expiry_warning_days'))
                        || static::phpWithin($e->next_hydro_test_due, (int) HealthSafetySettings::value('hs_expiry_warning_days'))),
            ],
            self::STATE_CHECK_OVERDUE => [
                'label' => 'Check overdue',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => static::checkOverdueSql($w)),
                'php' => fn (self $e) => $e->isEvaluated() && $e->isCheckOverdue(),
            ],
        ];
    }

    public function isEvaluated(): bool
    {
        return in_array($this->status, self::EVALUATED_STATUSES, true);
    }

    public function scopeEvaluated(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), self::EVALUATED_STATUSES);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->evaluated()->where(fn (Builder $w) => static::dateBeforeToday($w, 'expiry_date'));
    }

    /** Evaluated units whose expiry date falls between today and $days days ahead. */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->evaluated()->where(fn (Builder $w) => static::dateWithin($w, 'expiry_date', $days));
    }

    public function scopeServiceOverdue(Builder $query): Builder
    {
        return $query->evaluated()->where(fn (Builder $w) => static::dateBeforeToday($w, 'next_service_due'));
    }

    public function scopeCheckOverdue(Builder $query): Builder
    {
        return $query->evaluated()->where(fn (Builder $w) => static::checkOverdueSql($w));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(HsSite::class, 'site_id');
    }

    /** Includes a vehicle that has since been removed from the fleet, so the register still says where the unit was. */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(HsExtinguisherCheck::class, 'extinguisher_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(HsExtinguisherService::class, 'extinguisher_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->extinguisher_type] ?? $this->extinguisher_type;
    }

    /** Where it is, in words: the vehicle's plate, or the site and the spot within it. */
    public function locationLabel(): string
    {
        if ($this->vehicle_id) {
            $plate = $this->vehicle?->number_plate ?? 'a removed vehicle';

            return trim('Vehicle '.$plate.($this->location_detail ? ', '.$this->location_detail : ''));
        }

        return collect([$this->site?->name, $this->location_detail])->filter()->join(', ') ?: 'Unknown location';
    }
}
