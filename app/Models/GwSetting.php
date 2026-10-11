<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One override of a config default for a location. Read and written only through GwaterSettings / the workflow service. */
class GwSetting extends Model
{
    protected $fillable = ['region_id', 'district_id', 'key', 'value', 'updated_by'];

    protected $casts = ['region_id' => 'integer', 'district_id' => 'integer', 'value' => 'integer'];
}
