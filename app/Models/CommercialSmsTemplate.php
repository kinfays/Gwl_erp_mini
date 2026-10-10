<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A saved message wording with merge fields ({name} {account_no} {balance}); it holds no customer data. */
class CommercialSmsTemplate extends Model
{
    protected $fillable = ['region_id', 'name', 'body', 'created_by'];

    protected $casts = ['region_id' => 'integer', 'created_by' => 'integer'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
