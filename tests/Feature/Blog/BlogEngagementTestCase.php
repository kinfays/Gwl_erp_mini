<?php

namespace Tests\Feature\Blog;

use App\Models\BlogPost;
use App\Services\Blog\BlogEngagementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Shared helpers for the likes and view-count tests (docs/14-module-regional-blog.md, "Likes and views"). */
abstract class BlogEngagementTestCase extends BlogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fixed clock in the middle of a day, so "30 minutes later" never crosses midnight.
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function engagement(): BlogEngagementService
    {
        return app(BlogEngagementService::class);
    }

    /** Write the counters straight to the database (they are not mass-assignable). */
    protected function setCounters(BlogPost $post, int $views = 0, int $likes = 0): BlogPost
    {
        DB::table('blog_posts')->where('id', $post->id)->update(['view_count' => $views, 'like_count' => $likes]);

        return $post->fresh();
    }

    protected function likeRows(BlogPost $post): int
    {
        return DB::table('blog_post_likes')->where('blog_post_id', $post->id)->count();
    }

    protected function like(\App\Models\User $user, BlogPost $post)
    {
        return $this->actingAs($user)->postJson(route('blog.like', $post));
    }

    protected function unlike(\App\Models\User $user, BlogPost $post)
    {
        return $this->actingAs($user)->deleteJson(route('blog.unlike', $post));
    }

    /** @return list<string> the SQL of the queries run while the callback ran */
    protected function queriesDuring(callable $callback): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $log;
    }
}
