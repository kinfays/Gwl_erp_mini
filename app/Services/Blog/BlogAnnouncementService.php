<?php

namespace App\Services\Blog;

use App\Models\BlogAnnouncementDismissal;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Login announcements (docs/14-module-regional-blog.md, "Login announcements"): published articles an officer marked "show at
 * login until <date>". Two readers use it: the window shown on the first ERP page after a login, and the strip at the top of the
 * blog home.
 *
 * What counts as ACTIVE is BlogPost::scopeAnnouncementActive() (computed at query time, no job flips anything), and WHO is
 * given it is BlogVisibility::scopeFeed(): the person's own region, published only. This class adds only the personal part:
 * "don't show again" (blog_announcement_dismissals), "closed for this login session" (kept in the session) and the cap.
 *
 * Arming: the Login event sets a session flag (arm()); the first ERP page that renders consumes it (consumeArmed()), so the
 * window shows ONCE per login and never again on a refresh. Dismissals are personal preferences and are not audited.
 */
class BlogAnnouncementService
{
    /** Set at login, cleared by the first ERP page that shows (or would show) the window. */
    public const SESSION_ARMED = 'blog.login_announcements';

    /** Ids closed (or dismissed) in this login session: the window and the strip both skip them. */
    public const SESSION_HIDDEN = 'blog.announcements_hidden';

    public function __construct(protected BlogVisibility $visibility) {}

    // ---------------------------------------------------------------- arming at login

    /**
     * Remember, for the first page after a login, that the window may show. Does nothing when the blog is off or the account
     * may not enter it. The login form always arms; a remember-me re-authentication (no password typed, a new browser session)
     * arms only when the person has not been armed for gwl.blog_login_announcements_recall_hours (0 = never), so a long-lived
     * cookie cannot make the window appear every morning.
     *
     * @return bool whether the window is now armed
     */
    public function arm(User $user, bool $viaLoginForm): bool
    {
        if (! $this->visibility->canEnter($user)) {
            return false;
        }

        $hours = max(0, (int) config('gwl.blog_login_announcements_recall_hours', 12));
        $key = 'blog:announcements:armed:'.$user->id;

        if ($viaLoginForm) {
            if ($hours > 0) {
                Cache::put($key, 1, Carbon::now()->addHours($hours));
            }
        } elseif ($hours === 0 || ! Cache::add($key, 1, Carbon::now()->addHours($hours))) {
            return false;
        }

        session()->put(self::SESSION_ARMED, true);

        return true;
    }

    /** Take the "show the window" flag (once). True only for the first page after a login. */
    public function consumeArmed(): bool
    {
        return (bool) session()->pull(self::SESSION_ARMED, false);
    }

    // ---------------------------------------------------------------- what to show

    /**
     * The active announcements the person has not dismissed or closed this session, newest first, at most $limit
     * (gwl.blog_login_announcements_max by default). $regionId only narrows for someone who sees every region.
     *
     * @return Collection<int, BlogPost>
     */
    public function activeFor(User $user, ?int $limit = null, ?int $regionId = null): Collection
    {
        if (! $this->visibility->canEnter($user)) {
            return collect();
        }

        $limit ??= (int) config('gwl.blog_login_announcements_max', 3);

        if ($limit < 1) {
            return collect();
        }

        $hidden = $this->hiddenIds();

        return $this->visibility->scopeFeed(BlogPost::query(), $user)
            ->announcementActive()
            ->when(
                $regionId !== null && $this->visibility->seesAllRegions($user),
                fn (Builder $query) => $query->where('blog_posts.region_id', $regionId)
            )
            ->whereNotExists(fn ($dismissed) => $dismissed
                ->selectRaw('1')
                ->from('blog_announcement_dismissals')
                ->whereColumn('blog_announcement_dismissals.blog_post_id', 'blog_posts.id')
                ->where('blog_announcement_dismissals.user_id', $user->id))
            ->when($hidden !== [], fn (Builder $query) => $query->whereNotIn('blog_posts.id', $hidden))
            ->with(['district:id,district_name', 'coverMedia'])
            ->newestFirst()
            ->limit($limit)
            ->get();
    }

    // ---------------------------------------------------------------- closing

    /** "Close": hide these for the rest of this login session only. @param iterable<int> $postIds */
    public function hideForSession(iterable $postIds): void
    {
        session()->put(self::SESSION_HIDDEN, array_values(array_unique([
            ...$this->hiddenIds(),
            ...collect($postIds)->map(fn ($id) => (int) $id)->filter()->all(),
        ])));
    }

    /**
     * "Don't show again": never again for this person. Only an article the person may read can be dismissed (anything else
     * is ignored, so an id from another region cannot be probed). Idempotent. Returns whether the article was found.
     */
    public function dismiss(User $user, int $postId): bool
    {
        $post = $this->visibility->scopeFeed(BlogPost::query(), $user)->find($postId);

        if ($post === null) {
            return false;
        }

        BlogAnnouncementDismissal::query()->insertOrIgnore([
            'user_id' => $user->id,
            'blog_post_id' => $post->id,
            'dismissed_at' => Carbon::now(),
        ]);

        $this->hideForSession([$post->id]);

        return true;
    }

    /** @return list<int> */
    protected function hiddenIds(): array
    {
        return array_values(array_map('intval', (array) session()->get(self::SESSION_HIDDEN, [])));
    }
}
