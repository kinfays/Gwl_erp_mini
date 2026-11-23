<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A network cabinet / server rack. Devices point at it (ict_assets.rack_id, with a starting U and a height in U); a rack
 * is never an asset type. Unit 1 is the BOTTOM of the rack. Every write goes through Services/Assets/RackService, and a
 * device's placement through AssetRecordService (which asks RackService whether it fits).
 */
class IctRack extends Model
{
    public const STATUS_ACTIVE = 'Active';

    public const STATUS_UNDER_MAINTENANCE = 'Under Maintenance';

    public const STATUS_DECOMMISSIONED = 'Decommissioned';

    /** Every status a form or filter may offer. Add new statuses here, not at each call site. */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_UNDER_MAINTENANCE,
        self::STATUS_DECOMMISSIONED,
    ];

    /** The height a new rack starts with, and the most a rack may be (total_units is an unsigned tiny integer). */
    public const DEFAULT_UNITS = 42;

    public const MAX_UNITS = 100;

    protected $table = 'ict_racks';

    protected $fillable = [
        'name',
        'code',
        'region_id',
        'district_id',
        'location',
        'total_units',
        'status',
        'notes',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'total_units' => 'integer',
    ];

    /** Names are unique ignoring case and surrounding spaces: this is the comparable form (the index is case-sensitive on SQLite). */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** The devices mounted here, bottom to top. */
    public function devices(): HasMany
    {
        return $this->hasMany(IctAsset::class, 'rack_id')->orderBy('rack_unit')->orderBy('id');
    }

    /**
     * Every U that has a device on it, as a sorted list of unit numbers (clipped to the rack, so a device left above a
     * rack that was later made shorter cannot count units that do not exist). Devices with no position count for nothing.
     *
     * @param  iterable<IctAsset>|null  $devices  the rack's devices, when the caller has them already
     * @return array<int, int>
     */
    public function occupiedUnits(?iterable $devices = null): array
    {
        $devices ??= $this->relationLoaded('devices') ? $this->devices : $this->devices()->get(['id', 'rack_id', 'rack_unit', 'rack_unit_span']);
        $units = [];

        foreach ($devices as $device) {
            if (! $device->rack_unit) {
                continue;
            }

            foreach (range((int) $device->rack_unit, (int) $device->rack_unit + max(1, (int) $device->rack_unit_span) - 1) as $unit) {
                if ($unit >= 1 && $unit <= $this->total_units) {
                    $units[$unit] = $unit;
                }
            }
        }

        ksort($units);

        return array_values($units);
    }

    /** How many U have a device on them. */
    public function usedUnits(): int
    {
        return count($this->occupiedUnits());
    }

    /** How many U are free. */
    public function freeUnits(): int
    {
        return max(0, (int) $this->total_units - $this->usedUnits());
    }

    /**
     * The free stretches of the rack, bottom to top, as [first U, last U] pairs ("U1-U3, U10-U42").
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function freeRanges(): array
    {
        $taken = array_flip($this->occupiedUnits());
        $ranges = [];
        $start = null;

        for ($unit = 1; $unit <= $this->total_units; $unit++) {
            if (isset($taken[$unit])) {
                if ($start !== null) {
                    $ranges[] = [$start, $unit - 1];
                    $start = null;
                }

                continue;
            }

            $start ??= $unit;
        }

        if ($start !== null) {
            $ranges[] = [$start, (int) $this->total_units];
        }

        return $ranges;
    }

    /** "U12" or "U12-U13": a position as people read it. */
    public static function positionLabel(?int $start, ?int $span): string
    {
        if (! $start) {
            return '';
        }

        $end = $start + max(1, (int) $span) - 1;

        return $end > $start ? "U{$start}-U{$end}" : "U{$start}";
    }
}
