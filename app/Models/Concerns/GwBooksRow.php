<?php

namespace App\Models\Concerns;

use App\Models\District;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared by the G-Water rows that are voided rather than deleted. Visibility is NOT here: which rows a user reaches is
 * decided only by GwaterVisibility (docs/17-module-g-water.md).
 */
trait GwBooksRow
{
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), '!=', 'voided');
    }
}
