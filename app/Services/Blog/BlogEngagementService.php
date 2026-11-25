<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\BlogPostLike;
use App\Models\BlogPostRead;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Likes and view counts of Regional Blog articles (docs/14-module-regional-blog.md, "Likes and views"). It decides nothing about
 * who may read what (BlogVisibility does, and every method here asks it); it owns the counting rules and is the ONLY writer of
 * blog_post_likes, blog_post_view_daily and the two counter columns of blog_posts.
 *
 * Why the counters are columns: they are printed on every feed card, so they are read as plain columns and maintained by atomic
 * statements, never by COUNT(*) at read time and never by read-modify-write.
 *
 * Counters never touch the article's own bookkeeping: every write goes through toBase() queries (no Eloquent timestamps, no model
 * events, no audit row), so updated_at and lock_version stay put and a like or a view can never make the editor think someone
 * else saved the article. Likes and views are not audited (volume); like/unlike are rate-limited on the route.
 */
class BlogEngagementService
{
    /** How many days the "Most read" block and the sparkline look back. */
    public const POPULAR_DAYS = 7;

    public const SPARKLINE_DAYS = 14;

    public function __construct(protected BlogVisibility $visibility) {}

    // ---------------------------------------------------------------- likes

    /** A person may like a PUBLISHED article they can read (their own region; super_admin any). A draft is never likeable. */
    public function mayLike(User $user, BlogPost $post): bool
    {
        return $post->isPublished() && $this->visibility->canRead($user, $post);
    }

    /**
     * Like an article. Idempotent: liking twice (a double click, two tabs) leaves one row and counts once. The counter moves only
     * when a row was really inserted, in the same transaction.
     *
     * @return array{liked: bool, count: int}
     */
    public function like(User $user, BlogPost $post): array
    {
        $this->authorise($user, $post);

        $count = DB::transaction(function () use ($user, $post) {
            $inserted = DB::table('blog_post_likes')->insertOrIgnore([
                'user_id' => $user->id,
                'blog_post_id' => $post->id,
                'created_at' => Carbon::now(),
            ]) > 0;

            if ($inserted) {
                $this->counters()->where('id', $post->id)->increment('like_count');
            }

            return (int) $this->counters()->where('id', $post->id)->value('like_count');
        });

        return ['liked' => true, 'count' => $count];
    }

    /**
     * Take a like back. Idempotent; the counter moves only when a row was really deleted, and never goes below zero.
     *
     * @return array{liked: bool, count: int}
     */
    public function unlike(User $user, BlogPost $post): array
    {
        $this->authorise($user, $post);

        $count = DB::transaction(function () use ($user, $post) {
            $deleted = DB::table('blog_post_likes')
                ->where('user_id', $user->id)
                ->where('blog_post_id', $post->id)
                ->delete() > 0;

            if ($deleted) {
                $this->counters()->where('id', $post->id)->where('like_count', '>', 0)->decrement('like_count');
            }

            return (int) $this->counters()->where('id', $post->id)->value('like_count');
        });

        return ['liked' => false, 'count' => $count];
    }

    /**
     * Which of these articles the person likes: ONE query for a whole page of cards, never one per card.
     *
     * @param  iterable<int>  $postIds
     * @return list<int>
     */
    public function likedAmong(User $user, iterable $postIds): array
    {
        $ids = collect($postIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('blog_post_likes')
            ->where('user_id', $user->id)
            ->whereIn('blog_post_id', $ids->all())
            ->pluck('blog_post_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Who liked an article (name, district, when), newest first. Managers of the article's region only (and super_admin): a reader
     * never learns who else liked something.
     */
    public function likers(User $actor, BlogPost $post, int $perPage = 20): LengthAwarePaginator
    {
        abort_unless($this->visibility->canManagePost($actor, $post), 404);

        return BlogPostLike::query()
            ->where('blog_post_id', $post->id)
            ->with(['user:id,full_name,staff_id,employee_id', 'user.employee:id,district_id', 'user.employee.district:id,district_name', 'user.employeeByStaffId:id,staff_id,district_id', 'user.employeeByStaffId.district:id,district_name'])
            ->orderByDesc('created_at')
            ->orderByDesc('user_id')
            ->paginate($perPage);
    }

    // ---------------------------------------------------------------- views

    /**
     * Count one view of an article page. Returns whether it counted. The rules (docs/14, "Likes and views"):
     *  - only a published article the reader may read (anything else is a 404 before this is called, and never counts);
     *  - the author and the article's managers (PR Officer of the region, super_admin) never count: no self-inflation;
     *  - one person's repeat opens of one article within gwl.blog_view_throttle_minutes count once. The throttle is a cache key
     *    (user + article) taken with an atomic Cache::add, so a refresh or a double request costs no database read;
     *  - the total and the day's row go up in ONE transaction, with atomic statements that leave updated_at alone.
     */
    public function recordView(User $user, BlogPost $post): bool
    {
        if (! $post->isPublished()
            || (int) $post->author_user_id === (int) $user->id
            || ! $this->visibility->canRead($user, $post)
            || $this->visibility->canManagePost($user, $post)) {
            return false;
        }

        $minutes = max(0, (int) config('gwl.blog_view_throttle_minutes', 30));
        $key = $this->viewKey($user, $post);

        if ($minutes > 0 && ! Cache::add($key, 1, Carbon::now()->addMinutes($minutes))) {
            return false;
        }

        try {
            DB::transaction(function () use ($post) {
                $this->counters()->where('id', $post->id)->increment('view_count');

                DB::table('blog_post_view_daily')->upsert(
                    [['blog_post_id' => $post->id, 'view_date' => $this->today(), 'views' => 1]],
                    ['blog_post_id', 'view_date'],
                    ['views' => DB::raw('views + 1')],
                );
            });
        } catch (QueryException $e) {
            // A statistics failure must never stop someone reading an article; let the next open count instead.
            Cache::forget($key);
            report($e);

            return false;
        }

        return true;
    }

    /** The first day of the "this week" window (today and the six days before it, in the app's timezone). */
    public function popularSince(): string
    {
        return Carbon::now(config('app.timezone'))->subDays(self::POPULAR_DAYS - 1)->toDateString();
    }

    /**
     * Views per day for a set of articles: ONE query. Each list holds $days numbers, oldest first, today last (zero for a day
     * with no views).
     *
     * @param  iterable<int>  $postIds
     * @return array<int, list<int>>
     */
    public function dailyViews(iterable $postIds, int $days = self::SPARKLINE_DAYS): array
    {
        $ids = collect($postIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $dates = collect(range($days - 1, 0))->map(fn (int $ago) => $today->copy()->subDays($ago)->toDateString());

        $series = $ids->mapWithKeys(fn (int $id) => [$id => array_fill(0, $days, 0)])->all();

        if ($ids->isEmpty()) {
            return $series;
        }

        $rows = DB::table('blog_post_view_daily')
            ->whereIn('blog_post_id', $ids->all())
            ->where('view_date', '>=', $dates->first())
            ->get(['blog_post_id', 'view_date', 'views']);

        $position = $dates->flip();

        foreach ($rows as $row) {
            $slot = $position->get(substr((string) $row->view_date, 0, 10));

            if ($slot !== null) {
                $series[(int) $row->blog_post_id][$slot] = (int) $row->views;
            }
        }

        return $series;
    }

    // ---------------------------------------------------------------- readers

    /** Distinct readers of one article: the rows of blog_post_reads (the unread badge's table), never a second tracker. */
    public function readersOf(BlogPost $post): int
    {
        return BlogPostRead::query()->where('blog_post_id', $post->id)->count();
    }

    // ---------------------------------------------------------------- internals

    /** A bare query on blog_posts: no Eloquent timestamps, no model events. */
    protected function counters()
    {
        return BlogPost::query()->toBase();
    }

    protected function authorise(User $user, BlogPost $post): void
    {
        // The same 404 as an article that does not exist, so nobody learns what another region has (or what is still a draft).
        if (! $this->mayLike($user, $post)) {
            throw new NotFoundHttpException;
        }
    }

    protected function viewKey(User $user, BlogPost $post): string
    {
        return 'blog:view:'.$user->id.':'.$post->id;
    }

    protected function today(): string
    {
        return Carbon::now(config('app.timezone'))->toDateString();
    }
}
