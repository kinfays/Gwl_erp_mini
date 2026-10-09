<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * One article on a region's blog (activities, meetings, workshops, training...). Who may read or change it is decided
 * only by App\Services\Blog\BlogVisibility; every change goes through App\Services\Blog\BlogPostService.
 */
class BlogPost extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PUBLISHED => 'Published',
    ];

    /** The kinds of article, as the staff of a region will look for them. */
    public const CATEGORIES = [
        'activity' => 'Activity',
        'meeting' => 'Meeting',
        'workshop' => 'Workshop',
        'training' => 'Training',
        'announcement' => 'Announcement',
        'other' => 'Other',
    ];

    public const MAX_TAGS = 5;

    protected $fillable = [
        'region_id',
        'district_id',
        'author_user_id',
        'title',
        'category',
        'summary',
        'body',
        'tags',
        'event_date',
        'venue',
        'cover_path',
        'status',
        'is_pinned',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'district_id' => 'integer',
            'author_user_id' => 'integer',
            'tags' => 'array',
            'event_date' => 'date',
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_PUBLISHED);
    }

    /** Pinned first, then newest published first. */
    public function scopeFeedOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc($query->qualifyColumn('is_pinned'))
            ->orderByDesc($query->qualifyColumn('published_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? Str::headline((string) $this->category);
    }

    public function hasCover(): bool
    {
        return filled($this->cover_path);
    }

    /** The cover's URL: a route that checks the region, never a public file. The version busts the browser cache on replace. */
    public function coverUrl(): ?string
    {
        return $this->hasCover()
            ? route('blog.cover', ['post' => $this->id, 'v' => $this->updated_at?->timestamp])
            : null;
    }

    /** The summary, or the start of the body with the formatting taken out. */
    public function teaser(int $limit = 180): string
    {
        if (filled($this->summary)) {
            return $this->summary;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->bodyHtml()->toHtml()))), $limit);
    }

    /**
     * The body as safe HTML. Markdown with raw HTML stripped and unsafe links (javascript:, data:) refused, so what an
     * author types can format the article but never inject markup into the page of a colleague.
     */
    public function bodyHtml(): HtmlString
    {
        return new HtmlString(Str::markdown((string) $this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }
}
