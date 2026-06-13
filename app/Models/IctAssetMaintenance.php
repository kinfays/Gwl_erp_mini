<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IctAssetMaintenance extends Model
{
    use HasFactory;

    protected $table = 'ict_asset_maintenances';

    protected $fillable = [
        'ict_asset_id',
        'maintenance_type',
        'status',
        'completion_date',
        'technician',
        'location',
        'notes',
        'performed_by_user_id',
    ];

    protected $casts = [
        'ict_asset_id' => 'integer',
        'performed_by_user_id' => 'integer',
        'completion_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'ict_asset_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}

