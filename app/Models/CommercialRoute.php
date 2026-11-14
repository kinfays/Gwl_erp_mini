<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The route master (design 8.30): the ONE place every report resolves a route to. A route is (district, code); the same code printed
 * under another district by another system is an ALIAS of the canonical route (`canonical_route_id`, null = this is the route).
 */
class CommercialRoute extends Model
{
    public const SOURCE_READING = 'reading';

    public const SOURCE_BILLING = 'billing';

    public const SOURCE_CUSTOMER_LIST = 'customer_list';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [self::SOURCE_READING, self::SOURCE_BILLING, self::SOURCE_CUSTOMER_LIST, self::SOURCE_MANUAL];

    /** The route code that means "no route": its customers are unassigned. */
    public const UNASSIGNED_CODE = '0000';

    protected $fillable = ['district_id', 'region_id', 'name', 'code', 'canonical_route_id', 'source', 'active', 'is_pending', 'pinned'];

    protected $casts = ['district_id' => 'integer', 'region_id' => 'integer', 'canonical_route_id' => 'integer', 'active' => 'boolean', 'is_pending' => 'boolean', 'pinned' => 'boolean'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function canonical(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_route_id');
    }

    public function isAlias(): bool
    {
        return $this->canonical_route_id !== null;
    }
}
