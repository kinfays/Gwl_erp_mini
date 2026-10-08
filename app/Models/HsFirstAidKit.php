<?php

namespace App\Models;

use App\Models\Concerns\HasEquipmentState;
use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A first aid kit at a site or in a vehicle, with the items it should hold. The state is computed from the items and the
 * last check (see HasEquipmentState); "missing" and "decommissioned" are set by hand.
 */
class HsFirstAidKit extends Model
{
    use HasEquipmentState;

    public const STATUS_IN_SERVICE = 'in_service';
    public const STATUS_MISSING = 'missing';
    public const STATUS_DECOMMISSIONED = 'decommissioned';

    public const STATUSES = [
        self::STATUS_IN_SERVICE => 'In service',
        self::STATUS_MISSING => 'Missing',
        self::STATUS_DECOMMISSIONED => 'Decommissioned',
    ];

    public const TYPE_SMALL = 'small';
    public const TYPE_MEDIUM = 'medium';
    public const TYPE_LARGE = 'large';
    public const TYPE_VEHICLE = 'vehicle';

    public const TYPES = [
        self::TYPE_SMALL => 'Small',
        self::TYPE_MEDIUM => 'Medium',
        self::TYPE_LARGE => 'Large',
        self::TYPE_VEHICLE => 'Vehicle',
    ];

    public const STATE_MISSING = 'missing';
    public const STATE_ITEM_EXPIRED = 'item_expired';
    public const STATE_ITEM_EXPIRING = 'item_expiring';
    public const STATE_INCOMPLETE = 'incomplete';
    public const STATE_CHECK_FAILED = 'check_failed';
    public const STATE_CHECK_OVERDUE = 'check_overdue';

    protected $fillable = [
        'asset_code',
        'kit_type',
        'site_id',
        'vehicle_id',
        'region_id',
        'district_id',
        'location_detail',
        'responsible_employee_id',
        'status',
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
        'last_checked_on' => 'date',
        'label_printed_at' => 'datetime',
        'decommissioned_on' => 'date',
    ];

    /**
     * First match wins: missing beats everything, then an expired item, an item expiring, an item short of its required
     * quantity, a failed last check, a check overdue. Only a kit in service is evaluated for all but "missing".
     */
    public static function states(): array
    {
        return [
            self::STATE_MISSING => [
                'label' => 'Missing',
                'sql' => fn (Builder $q) => $q->where($q->qualifyColumn('status'), self::STATUS_MISSING),
                'php' => fn (self $k) => $k->status === self::STATUS_MISSING,
            ],
            self::STATE_ITEM_EXPIRED => [
                'label' => 'Item expired',
                'sql' => fn (Builder $q) => $q->evaluated()->whereHas('items', fn (Builder $i) => static::itemDateBeforeToday($i)),
                'php' => fn (self $k) => $k->isEvaluated() && $k->items->contains(fn (HsFirstAidKitItem $item) => static::phpBeforeToday($item->expiry_date)),
            ],
            self::STATE_ITEM_EXPIRING => [
                'label' => 'Item expiring soon',
                'sql' => fn (Builder $q) => $q->evaluated()->whereHas('items', fn (Builder $i) => static::itemDateWithin($i, (int) HealthSafetySettings::value('hs_expiry_warning_days'))),
                'php' => fn (self $k) => $k->isEvaluated() && $k->items->contains(fn (HsFirstAidKitItem $item) => static::phpWithin($item->expiry_date, (int) HealthSafetySettings::value('hs_expiry_warning_days'))),
            ],
            self::STATE_INCOMPLETE => [
                'label' => 'Incomplete',
                'sql' => fn (Builder $q) => $q->evaluated()->whereHas('items', fn (Builder $i) => $i->whereColumn('hs_first_aid_kit_items.current_qty', '<', 'hs_first_aid_kit_items.required_qty')),
                'php' => fn (self $k) => $k->isEvaluated() && $k->items->contains(fn (HsFirstAidKitItem $item) => $item->current_qty < $item->required_qty),
            ],
            self::STATE_CHECK_FAILED => [
                'label' => 'Last check failed',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => static::checkFailedSql($w)),
                'php' => fn (self $k) => $k->isEvaluated() && $k->last_check_result === 'fail',
            ],
            self::STATE_CHECK_OVERDUE => [
                'label' => 'Check overdue',
                'sql' => fn (Builder $q) => $q->evaluated()->where(fn (Builder $w) => static::checkOverdueSql($w)),
                'php' => fn (self $k) => $k->isEvaluated() && $k->isCheckOverdue(),
            ],
        ];
    }

    public function isEvaluated(): bool
    {
        return $this->status === self::STATUS_IN_SERVICE;
    }

    public function scopeEvaluated(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_IN_SERVICE);
    }

    /** Kits in service with at least one item past its expiry date. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->evaluated()->whereHas('items', fn (Builder $i) => static::itemDateBeforeToday($i));
    }

    /** Kits in service with an item whose expiry date falls between today and $days days ahead. */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->evaluated()->whereHas('items', fn (Builder $i) => static::itemDateWithin($i, $days));
    }

    public function scopeCheckOverdue(Builder $query): Builder
    {
        return $query->evaluated()->where(fn (Builder $w) => static::checkOverdueSql($w));
    }

    protected static function itemDateBeforeToday(Builder $items): void
    {
        $items->whereNotNull('hs_first_aid_kit_items.expiry_date')
            ->whereDate('hs_first_aid_kit_items.expiry_date', '<', today()->toDateString());
    }

    protected static function itemDateWithin(Builder $items, int $days): void
    {
        $items->whereNotNull('hs_first_aid_kit_items.expiry_date')
            ->whereDate('hs_first_aid_kit_items.expiry_date', '>=', today()->toDateString())
            ->whereDate('hs_first_aid_kit_items.expiry_date', '<=', today()->addDays($days)->toDateString());
    }

    public function items(): HasMany
    {
        return $this->hasMany(HsFirstAidKitItem::class, 'kit_id')->orderBy('sort_order')->orderBy('id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(HsFirstAidKitCheck::class, 'kit_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(HsSite::class, 'site_id');
    }

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

    public function typeLabel(): string
    {
        return self::TYPES[$this->kit_type] ?? $this->kit_type;
    }

    public function locationLabel(): string
    {
        if ($this->vehicle_id) {
            $plate = $this->vehicle?->number_plate ?? 'a removed vehicle';

            return trim('Vehicle '.$plate.($this->location_detail ? ', '.$this->location_detail : ''));
        }

        return collect([$this->site?->name, $this->location_detail])->filter()->join(', ') ?: 'Unknown location';
    }
}
