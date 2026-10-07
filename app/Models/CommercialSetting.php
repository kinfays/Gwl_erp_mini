<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One edited Commercial target or threshold: an override of the value in config/gwl.php. */
class CommercialSetting extends Model
{
    protected $fillable = ['name', 'value', 'updated_by'];

    protected $casts = ['updated_by' => 'integer'];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
