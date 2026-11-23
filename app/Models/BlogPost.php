<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * One article on a region's blog (activities, meetings, workshops, training...). Who may read or change it is decided
 * only by App\Services\Blog\BlogVisibility; every change goes through App\Services\Blog\BlogPostService.
 *
 * Two body formats exist side by side. `markdown` is the original format (the `body` column, rendered as it always was);
 * `rich` is the editor document (`body_json`), rendered and sanitised ONCE when it is saved into `body_html` by
 * BlogContentService. Readers are only ever given `body_html` (see bodyHtml()).
 */
class BlogPost extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PUBLISHED => 'Published',
    ];

    public const ANNOUNCEMENT_ACTIVE = 'active';

    public const ANNOUNCEMENT_SCHEDULED = 'scheduled';

    public const ANNOUNCEMENT_EXPIRED = 'expired';

    public const FORMAT_MARKDOWN = 'markdown';

    public const FORMAT_RICH = 'rich';

    /** The kinds of article, as the staff of a region will look for them. */
    public const CATEGORIES = [
        'activity' => 'Activity',
        'meeting' => 'Meeting',
        'workshop' => 'Workshop',
        'training' => 'Training',
        'announcement' => 'Announcement',
        'other' => 'Other',
    ];

    /** The plural names shown on the category strip of the standalone blog. */
    public const CATEGORY_PLURALS = [
        'activity' => 'Activities',
        'meeting' => 'Meetings',
        'workshop' => 'Workshops',
        'training' => 'Training',
        'announcement' => 'Announcements',
        'other' => 'Other',
    ];

    public const MAX_TAGS = 5;

    protected $fillable = [
        'region_id',
        'district_id',
        'author_user_id',
        'title',
        'slug',
        'category',
        'summary',
        'body',
        'body_format',
        'body_json',
        'body_html',
        'body_text',
        'reading_minutes',
        'tags',
        'event_date',
        'venue',
        'cover_path',
        'cover_media_id',
        'cover_caption',
        'cover_credit',
        'cover_focal_x',
        'cover_focal_y',
        'status',
        'is_pinned',
        'announce_from',
        'announce_until',
        'lock_version',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'district_id' => 'integer',
            'author_user_id' => 'integer',
            'tags' => 'array',
            'body_json' => 'array',
            'reading_minutes' => 'integer',
            'cover_media_id' => 'integer',
            'cover_focal_x' => 'integer',
            'cover_focal_y' => 'integer',
            'event_date' => 'date',
            'announce_from' => 'date',
            'announce_until' => 'date',
            'is_pinned' => 'boolean',
            'lock_version' => 'integer',
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

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(BlogMedia::class, 'cover_media_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(BlogMedia::class, 'blog_post_id')->orderBy('sort_order')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BlogPostRevision::class, 'blog_post_id')->orderByDesc('id');
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

    /** Newest first, ignoring the pin (the "latest" stream and the previous / next links). */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query
            ->orderByDesc($query->qualifyColumn('published_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }

    /**
     * Published articles that are an ACTIVE login announcement on a day (default: today, in the app's timezone): the last day
     * is shown INCLUSIVELY, and a blank first day means "from the day it was published".
     *
     * Worked out here, at query time, so nothing has to flip a flag when the date passes. The comparisons are written
     * `announce_until >= day` and `announce_from < next day` (not `announce_from <= day`) on purpose: a date column holds
     * `2026-11-30` on MySQL but the date cast stores `2026-11-30 00:00:00` on SQLite, and these two forms are right for both,
     * while keeping the (region_id, status, announce_until) index usable.
     */
    public function scopeAnnouncementActive(Builder $query, ?CarbonInterface $day = null): Builder
    {
        $today = ($day ?? Carbon::now(config('app.timezone')))->copy()->startOfDay();

        return $query->published()
            ->whereNotNull($query->qualifyColumn('announce_until'))
            ->where($query->qualifyColumn('announce_until'), '>=', $today->toDateString())
            ->where(fn (Builder $from) => $from
                ->whereNull($query->qualifyColumn('announce_from'))
                ->orWhere($query->qualifyColumn('announce_from'), '<', $today->copy()->addDay()->toDateString()));
    }

    /**
     * Where this article stands as a login announcement on a day: `active`, `scheduled` (starts later), `expired` (the last
     * day has passed), or null (not an announcement, or not published, which can never be one).
     */
    public function announcementState(?CarbonInterface $day = null): ?string
    {
        if ($this->announce_until === null || ! $this->isPublished()) {
            return null;
        }

        $today = ($day ?? Carbon::now(config('app.timezone')))->copy()->startOfDay();

        if ($this->announce_until->copy()->startOfDay()->lt($today)) {
            return self::ANNOUNCEMENT_EXPIRED;
        }

        return $this->announce_from !== null && $this->announce_from->copy()->startOfDay()->gt($today)
            ? self::ANNOUNCEMENT_SCHEDULED
            : self::ANNOUNCEMENT_ACTIVE;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isRich(): bool
    {
        return $this->body_format === self::FORMAT_RICH;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? Str::headline((string) $this->category);
    }

    /** The address readers use: the id decides, the slug is only for a readable link (a wrong slug still opens the article). */
    public function url(): string
    {
        return route('blog.show', array_filter(['post' => $this->id, 'slug' => $this->slug]));
    }

    public function hasCover(): bool
    {
        return $this->cover_media_id !== null || filled($this->cover_path);
    }

    /**
     * The cover's address: a route that checks the region, never a public file. New covers are blog_media pictures in
     * several sizes (thumb / medium / large); an older cover is the single private file and ignores the size.
     */
    public function coverUrl(string $size = 'large'): ?string
    {
        if ($this->cover_media_id !== null && $this->coverMedia) {
            return $this->coverMedia->url($size);
        }

        return filled($this->cover_path)
            ? route('blog.cover', ['post' => $this->id, 'v' => $this->updated_at?->timestamp])
            : null;
    }

    public function coverSrcset(): ?string
    {
        return $this->cover_media_id !== null ? $this->coverMedia?->srcset() : null;
    }

    /** @return array{0: int, 1: int}|null width and height of the cover at a size, when they are known */
    public function coverDimensions(string $size = 'large'): ?array
    {
        return $this->cover_media_id !== null && $this->coverMedia ? $this->coverMedia->dimensions($size) : null;
    }

    /** CSS object-position for the focal point, so a crop keeps the part of the picture the author chose. */
    public function coverFocus(): string
    {
        return ($this->cover_focal_x ?? 50).'% '.($this->cover_focal_y ?? 50).'%';
    }

    /** The summary, or the start of the text. */
    public function teaser(int $limit = 180): string
    {
        if (filled($this->summary)) {
            return $this->summary;
        }

        $text = filled($this->body_text)
            ? $this->body_text
            : trim(preg_replace('/\s+/', ' ', strip_tags($this->bodyHtml()->toHtml())));

        return Str::limit($text, $limit);
    }

    /**
     * The body as safe HTML, the only thing a page may print. A rich article carries it pre-rendered and sanitised
     * (body_html, written on save). A Markdown article that has not been filled yet (rows written outside the service)
     * is rendered here with raw HTML stripped and unsafe links (javascript:, data:) refused, as it always was.
     */
    public function bodyHtml(): HtmlString
    {
        if (filled($this->body_html)) {
            return new HtmlString($this->body_html);
        }

        return new HtmlString(Str::markdown((string) $this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }
}
