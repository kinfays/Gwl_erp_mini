<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentReport extends Model
{
    use HasFactory;

    protected $table = 'agent_reports';

    protected $fillable = [
        'user_id',
        'ict_asset_id',
        'reporting_region_id',
        'linked_by_user_id',
        'matched',
        'hostname',
        'serial_number',
        'mac_address',
        'os_name',
        'os_version',
        'cpu_name',
        'ram_gb',
        'logged_on_user',
        'last_boot_time',
        'manufacturer',
        'model',
        'bios_version',
        'payload',
        'reported_at',
        'linked_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'ict_asset_id' => 'integer',
        'reporting_region_id' => 'integer',
        'linked_by_user_id' => 'integer',
        'matched' => 'boolean',
        'ram_gb' => 'decimal:2',
        'payload' => 'array',
        'reported_at' => 'datetime',
        'linked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'ict_asset_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'reporting_region_id');
    }
}

