<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who is emailed about finally-approved leave for one HR scope: a region, or Head Office (region_id null).
 * See LeaveHrContactService for how staff map onto a scope.
 */
class LeaveHrContact extends Model
{
    protected $fillable = [
        'region_id',
        'email',
        'name',
        'is_active',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function isHeadOffice(): bool
    {
        return $this->region_id === null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** @param  int|null  $regionId  null = Head Office */
    public function scopeForScope($query, ?int $regionId)
    {
        return $regionId === null
            ? $query->whereNull('region_id')
            : $query->where('region_id', $regionId);
    }

    public function label(): string
    {
        return $this->region?->region_name ?? 'Head Office';
    }
}
