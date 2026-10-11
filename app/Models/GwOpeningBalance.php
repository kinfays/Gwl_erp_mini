<?php

namespace App\Models;

use App\Models\Concerns\GwBooksRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The one-off brought-forward of a location: money owed to G Water; the packs are its `opening` ledger rows. */
class GwOpeningBalance extends Model
{
    use GwBooksRow;

    public function lines(): HasMany
    {
        return $this->hasMany(GwOpeningBalanceLine::class, 'opening_balance_id');
    }

    protected $fillable = [
        'region_id', 'district_id', 'as_of_date', 'owed_amount', 'note', 'status',
        'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'as_of_date' => 'date',
        'owed_amount' => 'integer',
        'voided_at' => 'datetime',
    ];
}
