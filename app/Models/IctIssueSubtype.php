<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IctIssueSubtype extends Model
{
    protected $table = 'ict_issue_subtypes';

    protected $fillable = ['ict_issue_category_id', 'label', 'is_active', 'sort_order'];

    protected $casts = [
        'ict_issue_category_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(IctIssueCategory::class, 'ict_issue_category_id');
    }
}
