<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This person has opened this article" (the unread badge counts published articles without a row). One row per person and
 * article, enforced by the composite primary key; rows are written only by App\Services\Blog\BlogReadService, set-based
 * (insertOrIgnore / INSERT ... SELECT), never one by one through Eloquent.
 */
class BlogPostRead extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'blog_post_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }
}
