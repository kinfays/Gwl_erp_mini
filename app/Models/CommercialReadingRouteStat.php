<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One CCA's counts on one route for one upload (the reading report BY ROUTE, design 8.36). Verified strength is per route and counts
 * ONCE per route and period, in the OWNER row (`is_route_owner`: the CCA with the most visits). Percentages and the Unvisited column
 * of the source are never stored: unvisited is strength minus visited and every rate is recomputed from the counts.
 */
class CommercialReadingRouteStat extends Model
{
    public const MATCH_MATCHED = 'matched';
    public const MATCH_UNMATCHED = 'unmatched';

    public const FLAG_VISITED = 'visited_not_read_plus_skipped';
    public const FLAG_UNVISITED = 'unvisited_not_strength_minus_visited';

    protected $fillable = [
        'batch_id', 'route_id', 'district_id', 'cca_staff_id', 'cca_name_raw', 'employee_id', 'route_label_raw', 'district_label_raw',
        'verified_strength', 'read_count', 'skipped_count', 'visited_count', 'is_route_owner', 'is_flagged', 'flag_reason', 'match_status',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'route_id' => 'integer',
        'district_id' => 'integer',
        'employee_id' => 'integer',
        'verified_strength' => 'integer',
        'read_count' => 'integer',
        'skipped_count' => 'integer',
        'visited_count' => 'integer',
        'is_route_owner' => 'boolean',
        'is_flagged' => 'boolean',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(CommercialRoute::class, 'route_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** A CCA listed with no route row at all (kept: a finding). */
    public function isCcaOnly(): bool
    {
        return $this->route_label_raw === null;
    }
}
