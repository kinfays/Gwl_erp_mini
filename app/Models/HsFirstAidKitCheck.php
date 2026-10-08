<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One check of a first aid kit. Immutable: never edited or deleted. */
class HsFirstAidKitCheck extends Model
{
    protected $fillable = [
        'kit_id',
        'checked_on',
        'checked_by',
        'result',
        'restocked',
        'notes',
    ];

    protected $casts = [
        'checked_on' => 'date',
        'restocked' => 'boolean',
    ];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(HsFirstAidKit::class, 'kit_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
