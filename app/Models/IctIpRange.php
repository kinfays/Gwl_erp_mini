<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IctIpRange extends Model
{
    use HasFactory;

    protected $table = 'ict_ip_ranges';

    protected $fillable = [
        'label',
        'region_id',
        'district_id',
        'start_ip',
        'end_ip',
        'cidr',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
