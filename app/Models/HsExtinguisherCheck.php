<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One monthly visual check of an extinguisher. Immutable: never edited or deleted. */
class HsExtinguisherCheck extends Model
{
    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';

    protected $fillable = [
        'extinguisher_id',
        'checked_on',
        'checked_by',
        'in_place',
        'accessible',
        'seal_intact',
        'pressure_ok',
        'no_damage',
        'signage_ok',
        'result',
        'notes',
    ];

    protected $casts = [
        'checked_on' => 'date',
        'in_place' => 'boolean',
        'accessible' => 'boolean',
        'seal_intact' => 'boolean',
        'pressure_ok' => 'boolean',
        'no_damage' => 'boolean',
        'signage_ok' => 'boolean',
    ];

    public function extinguisher(): BelongsTo
    {
        return $this->belongsTo(HsFireExtinguisher::class, 'extinguisher_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /** The points that did not pass, in words, for the history list. */
    public function failedPoints(): array
    {
        return collect(HsFireExtinguisher::CHECK_POINTS)
            ->reject(fn (string $label, string $field) => $this->{$field})
            ->values()
            ->all();
    }
}
