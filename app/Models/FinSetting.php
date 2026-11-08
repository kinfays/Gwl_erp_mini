<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One override of a config default for a location's books (`unit` = 'ho' or 'r<region id>'). Read and written only through FinanceSettings. */
class FinSetting extends Model
{
    protected $fillable = ['unit', 'key', 'value', 'updated_by'];

    protected $casts = ['value' => 'integer'];
}
