<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IctAssetIssueReport extends Model
{
    use HasFactory;

    protected $table = 'ict_asset_issue_reports';

    protected $fillable = [
        'title',
        'issue_type',
        'reason',
        'status',
        'date_solved',
        'linked_asset_id',
        'reporting_region_id',
        'reporting_district_id',
        'reported_by_user_id',
    ];

    protected $casts = [
        'linked_asset_id' => 'integer',
        'reporting_region_id' => 'integer',
        'reporting_district_id' => 'integer',
        'reported_by_user_id' => 'integer',
        'date_solved' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'linked_asset_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'reporting_region_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'reporting_district_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }
}

