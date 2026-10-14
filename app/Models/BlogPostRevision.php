<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved version of an article. Written only by BlogPostService, which keeps the newest gwl.blog_revisions_keep of them.
 */
class BlogPostRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'blog_post_id',
        'user_id',
        'title',
        'body_format',
        'body_json',
        'body_markdown',
        'lock_version',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'blog_post_id' => 'integer',
            'user_id' => 'integer',
            'lock_version' => 'integer',
            'body_json' => 'array',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
