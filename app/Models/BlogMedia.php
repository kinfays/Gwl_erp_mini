<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One picture of the Regional Blog. The files (a re-encoded original and thumb / medium / large, each also as WebP) are on the
 * private disk and have no public URL: readers get them only from the region-checked blog.media route, whose rule is
 * BlogVisibility::canViewMedia(). Created and removed only by App\Services\Blog\BlogMediaService.
 */
class BlogMedia extends Model
{
    public const KIND_INLINE = 'inline';

    public const KIND_GALLERY = 'gallery';

    public const KIND_COVER = 'cover';

    public const KINDS = [self::KIND_INLINE, self::KIND_GALLERY, self::KIND_COVER];

    /** Width in pixels of each generated size (never enlarged past the picture itself). */
    public const SIZES = ['thumb' => 400, 'medium' => 900, 'large' => 1600];

    protected $table = 'blog_media';

    protected $fillable = [
        'region_id',
        'blog_post_id',
        'uploaded_by',
        'kind',
        'mime',
        'width',
        'height',
        'bytes',
        'path',
        'variants',
        'alt',
        'caption',
        'credit',
        'sort_order',
        'in_use',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'blog_post_id' => 'integer',
            'uploaded_by' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'variants' => 'array',
            'sort_order' => 'integer',
            'in_use' => 'boolean',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return array{path: string, webp: ?string, w: int, h: int}|null */
    public function variant(string $size): ?array
    {
        return $this->variants[$size] ?? null;
    }

    /** Width and height of a size as the browser will receive it (the picture's own when the size does not exist). */
    public function dimensions(string $size): array
    {
        $variant = $this->variant($size);

        return [(int) ($variant['w'] ?? $this->width), (int) ($variant['h'] ?? $this->height)];
    }

    /** A relative link, so a stored article keeps working whatever the host name becomes. v busts the browser cache on a change. */
    public function url(string $size = 'medium'): string
    {
        return route('blog.media', ['media' => $this->id, 'size' => $size, 'v' => $this->updated_at?->timestamp], false);
    }

    /** "400w, 900w, 1600w" style list for srcset, only the sizes that really differ. */
    public function srcset(): string
    {
        $parts = [];
        $seen = [];

        foreach (array_keys(self::SIZES) as $size) {
            $variant = $this->variant($size);

            if ($variant === null || isset($seen[$variant['w']])) {
                continue;
            }

            $seen[$variant['w']] = true;
            $parts[] = $this->url($size).' '.$variant['w'].'w';
        }

        return implode(', ', $parts);
    }

    /** Every file of this picture on the disk. @return list<string> */
    public function allPaths(): array
    {
        $paths = [$this->path];

        foreach ($this->variants ?? [] as $variant) {
            $paths[] = $variant['path'] ?? null;
            $paths[] = $variant['webp'] ?? null;
        }

        return array_values(array_unique(array_filter($paths)));
    }
}
