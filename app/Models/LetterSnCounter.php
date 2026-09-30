<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last serial number issued for a prefix in a year. LetterWorkflowService locks the row while it issues the next
 * one, so two letters created at the same moment cannot get the same number.
 */
class LetterSnCounter extends Model
{
    protected $fillable = [
        'prefix',
        'year',
        'last_number',
    ];

    protected $casts = [
        'year' => 'integer',
        'last_number' => 'integer',
    ];
}
