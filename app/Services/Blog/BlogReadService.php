<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\BlogPostRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Which articles has this person not read yet?" - the number on the header's Blog link, the "New" markers in the blog, and
 * the read tracking behind them (docs/14-module-regional-blog.md, "Unread articles"). It decides nothing about WHO may read what:
 * the unread query starts from BlogVisibility::scopeFeed(), so another region's articles and drafts can never be counted.
 *
 * ONE definition of "unread" (unreadQuery()): a published article the person may read, first published within the look-back
 * window (gwl.blog_unread_lookback_days), not written by them, with no row in blog_post_reads. The badge count, the markers and
 * "mark all as read" all call it, so they cannot disagree.
 *
 * The count runs in the header of every page for every user, so it is one indexed query and cached per person for a short
 * time. Cache invalidation: the person's own key is forgotten when they read something; a publish, unpublish or delete bumps
 * a global version that is part of every person's key (BlogPostService calls forgetEveryone()), so nobody sees a removed article
 * counted for the rest of the minute. Nothing but numbers is cached.
 */
class BlogReadService
{
    /** Where the global "something was published / unpublished / deleted" version lives. */
    public const VERSION_KEY = 'blog:unread:version';

    public function __construct(protected BlogVisibility $visibility) {}

    // ---------------------------------------------------------------- the number

    /** How many unread articles the person has (0 when the blog is off or the account may not enter it). */
    public function unreadCount(User $user): int
    {
        // Switched off: no cache read, no query.
        if (! config('gwl.blog_module_enabled')) {
            return 0;
        }

        $ttl = max(0, (int) config('gwl.blog_unread_cache_seconds', 60));
        $key = $this->userKey($user);

        if ($ttl > 0 && ($cached = Cache::get($key)) !== null) {
            return (int) $cached;
        }

        // An account without the module never reaches a blog table; its 0 is cached like any other number.
        $count = $this->visibility->canEnter($user) ? (int) $this->unreadQuery($user)->count() : 0;

        if ($ttl > 0) {
            Cache::put($key, $count, $ttl);
        }

        return $count;
    }

    /** What the badge prints: the number, or "99+". An empty string for 0 (the badge is hidden). */
    public function display(int $count): string
    {
        return match (true) {
            $count <= 0 => '',
            $count > 99 => '99+',
            default => (string) $count,
        };
    }

    /**
     * The articles the person has not read. The single definition of "unread" (see the class note). Index use: the
     * (region_id, status, published_at) range on blog_posts, and the (user_id, blog_post_id) primary key for the NOT EXISTS.
     */
    public function unreadQuery(User $user): Builder
    {
        return $this->visibility->scopeFeed(BlogPost::query(), $user)
            ->where('blog_posts.published_at', '>=', $this->windowStart())
            // Authors count their own articles as read (a NULL author, the user having been deleted, is nobody's own).
            ->where(fn (Builder $author) => $author
                ->whereNull('blog_posts.author_user_id')
                ->orWhere('blog_posts.author_user_id', '!=', $user->id))
            ->whereNotExists(fn ($read) => $read
                ->selectRaw('1')
                ->from('blog_post_reads')
                ->whereColumn('blog_post_reads.blog_post_id', 'blog_posts.id')
                ->where('blog_post_reads.user_id', $user->id));
    }

    /** The first day inside the look-back window (the start of that day, in the app's timezone). */
    public function windowStart(): Carbon
    {
        return Carbon::now(config('app.timezone'))->subDays(max(1, (int) config('gwl.blog_unread_lookback_days', 30)))->startOfDay();
    }

    /**
     * Which of these articles are unread for the person (the "New" markers on a page of cards). One query, the same
     * definition as the badge.
     *
     * @param  iterable<int>  $postIds
     * @return list<int>
     */
    public function unreadAmong(User $user, iterable $postIds): array
    {
        $ids = collect($postIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty() || ! $this->visibility->canEnter($user)) {
            return [];
        }

        return $this->unreadQuery($user)
            ->whereIn('blog_posts.id', $ids->all())
            ->pluck('blog_posts.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ---------------------------------------------------------------- writing

    /**
     * Mark an article read because the person opened it. Idempotent and one query (insertOrIgnore): a refresh, a double click
     * or two tabs never make a second row or an error. Only a published article the person may read counts, and an author's
     * own article needs no row. Returns whether a new row was written.
     */
    public function markRead(User $user, BlogPost $post): bool
    {
        if (! $post->isPublished()
            || (int) $post->author_user_id === (int) $user->id
            || ! $this->visibility->canRead($user, $post)) {
            return false;
        }

        $written = BlogPostRead::query()->insertOrIgnore([
            'user_id' => $user->id,
            'blog_post_id' => $post->id,
            'read_at' => Carbon::now(),
        ]) > 0;

        if ($written) {
            $this->forgetUser($user);
        }

        return $written;
    }

    /**
     * "Mark all as read": one INSERT ... SELECT over the unread articles (never a PHP loop), ignoring rows a parallel request
     * has just written. Returns how many articles were marked.
     */
    public function markAllRead(User $user): int
    {
        if (! $this->visibility->canEnter($user)) {
            return 0;
        }

        $select = $this->unreadQuery($user)
            ->toBase()
            ->selectRaw('?, blog_posts.id, ?', [$user->id, Carbon::now()->toDateTimeString()]);

        $marked = BlogPostRead::query()->toBase()->insertOrIgnoreUsing(['user_id', 'blog_post_id', 'read_at'], $select);

        $this->forgetUser($user);

        return (int) $marked;
    }

    // ---------------------------------------------------------------- cache

    /** The person's own number is stale (they read something). */
    public function forgetUser(User $user): void
    {
        Cache::forget($this->userKey($user));
    }

    /**
     * An article was published, unpublished or deleted: every cached number is stale. Bumping a version that is part of each
     * key invalidates all of them at once without having to know who is cached (a fresh random token cannot be raced).
     */
    public function forgetEveryone(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    protected function userKey(User $user): string
    {
        return 'blog:unread:'.$user->id.':'.Cache::get(self::VERSION_KEY, '0');
    }
}
