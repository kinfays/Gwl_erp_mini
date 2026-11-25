<?php

namespace Tests\Feature\Blog;

use App\Models\AuditLog;
use App\Models\BlogPost;
use App\Models\BlogPostRead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * View counts: who counts, the throttle window, and that the total and the day's row always go up together.
 */
class BlogViewsTest extends BlogEngagementTestCase
{
    protected function open(\App\Models\User $user, BlogPost $post, array $headers = [])
    {
        // withHeaders() is sticky on a test case, so start clean every time.
        return $this->flushHeaders()->actingAs($user)->withHeaders($headers)->get($post->url());
    }

    protected function daily(BlogPost $post): array
    {
        return DB::table('blog_post_view_daily')->where('blog_post_id', $post->id)->orderBy('view_date')->get()
            ->mapWithKeys(fn ($row) => [substr((string) $row->view_date, 0, 10) => (int) $row->views])
            ->all();
    }

    public function test_a_reader_opening_an_article_counts_one_view_in_the_total_and_in_todays_row(): void
    {
        $post = $this->article($this->accraWest);

        $this->open($this->reader(), $post)->assertOk()->assertSee('1 view');

        $this->assertSame(1, $post->fresh()->view_count);
        $this->assertSame(['2026-11-20' => 1], $this->daily($post));
    }

    public function test_a_refresh_a_double_request_and_a_second_tab_within_the_window_count_once_and_it_counts_again_after_it(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        $this->open($reader, $post);
        $this->open($reader, $post);
        $this->open($reader, $post);
        $this->assertSame(1, $post->fresh()->view_count);

        Carbon::setTestNow(Carbon::now()->addMinutes(29));
        $this->open($reader, $post);
        $this->assertSame(1, $post->fresh()->view_count, 'still inside the 30 minute window');

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->open($reader, $post);
        $this->assertSame(2, $post->fresh()->view_count);
        $this->assertSame([2], array_values($this->daily($post)), 'the same day, so the same row went up');
    }

    public function test_two_different_readers_each_count(): void
    {
        $post = $this->article($this->accraWest);

        $this->open($this->reader('100001'), $post);
        $this->open($this->reader('100002'), $post);

        $this->assertSame(2, $post->fresh()->view_count);
    }

    public function test_the_author_a_manager_and_a_super_admin_never_count(): void
    {
        $author = $this->userWithRoles('100777', ['employee']);
        $post = $this->article($this->accraWest, ['author_user_id' => $author->id]);

        $this->open($author, $post)->assertOk();
        $this->open($this->prOfficer(), $post)->assertOk();
        $this->open($this->superAdmin(), $post)->assertOk();

        $this->assertSame(0, $post->fresh()->view_count);
        $this->assertSame([], $this->daily($post));
    }

    public function test_an_article_of_another_region_a_missing_one_and_a_draft_never_count(): void
    {
        $foreign = $this->article($this->ashanti);
        $draft = $this->draft($this->accraWest);
        $reader = $this->reader();

        $this->open($reader, $foreign)->assertNotFound();
        $this->open($reader, $draft)->assertNotFound();
        $this->actingAs($reader)->get(route('blog.show', 999999))->assertNotFound();
        // The manager can read the draft, and it still counts for nothing.
        $this->open($this->prOfficer(), $draft)->assertOk();

        $this->assertSame(0, $foreign->fresh()->view_count);
        $this->assertSame(0, $draft->fresh()->view_count);
        $this->assertSame(0, DB::table('blog_post_view_daily')->count());
    }

    public function test_a_guest_does_not_count(): void
    {
        $post = $this->article($this->accraWest);

        $this->get($post->url())->assertRedirect(route('login'));

        $this->assertSame(0, $post->fresh()->view_count);
    }

    public function test_a_prefetch_and_a_head_request_do_not_count(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        $this->open($reader, $post, ['Sec-Purpose' => 'prefetch;prerender'])->assertOk();
        $this->open($reader, $post, ['Purpose' => 'prefetch'])->assertOk();
        $this->open($reader, $post, ['X-Moz' => 'prefetch'])->assertOk();
        $this->actingAs($reader)->call('HEAD', $post->url());

        $this->assertSame(0, $post->fresh()->view_count);

        // The real visit afterwards still counts: the prefetch did not use up the throttle window.
        $this->open($reader, $post)->assertOk();
        $this->assertSame(1, $post->fresh()->view_count);
    }

    public function test_a_throttle_of_zero_counts_every_open(): void
    {
        config(['gwl.blog_view_throttle_minutes' => 0]);
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        $this->open($reader, $post);
        $this->open($reader, $post);

        $this->assertSame(2, $post->fresh()->view_count);
    }

    public function test_the_total_and_the_daily_rows_go_up_together_across_days(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        foreach ([0, 0, 1, 1, 1, 3] as $dayOffset) {
            Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00', config('app.timezone'))->addDays($dayOffset));
            config(['gwl.blog_view_throttle_minutes' => 0]);
            $this->open($reader, $post);
        }

        $rows = DB::table('blog_post_view_daily')->where('blog_post_id', $post->id)->get();

        $this->assertSame(6, $post->fresh()->view_count);
        $this->assertSame(6, (int) $rows->sum('views'));
        $this->assertCount(3, $rows);
        $this->assertSame([2, 3, 1], $rows->sortBy('view_date')->pluck('views')->map(fn ($v) => (int) $v)->values()->all());
    }

    public function test_views_are_not_audited(): void
    {
        $post = $this->article($this->accraWest);
        $before = AuditLog::query()->count();

        $this->open($this->reader(), $post);

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_the_page_shows_the_views_and_only_a_manager_sees_the_readers(): void
    {
        $post = $this->article($this->accraWest);

        foreach (['100001', '100002', '100003'] as $staffId) {
            $this->open($this->reader($staffId), $post);
        }
        $this->open($this->reader('100001'), $post); // a repeat visit by the first reader

        $this->assertSame(3, $post->fresh()->view_count);

        $this->open($this->reader('100004'), $post)->assertOk()->assertSee('4 views')->assertDontSee('readers');
        // A manager is not counted as a view, but opening the article does write a read row (the unread badge), so they are a reader.
        $this->open($this->prOfficer(), $post)->assertOk()->assertSee('4 views')->assertSee('5 readers');
    }

    public function test_the_readers_figure_is_the_number_of_rows_in_blog_post_reads(): void
    {
        $post = $this->article($this->accraWest);

        $this->open($this->reader('100001'), $post);
        $this->open($this->reader('100002'), $post);
        $this->open($this->reader('100001'), $post);
        $this->open($this->userWithRoles('100777', ['employee']), $this->article($this->accraWest)); // another article

        $rows = BlogPostRead::query()->where('blog_post_id', $post->id)->distinct()->count('user_id');

        $this->assertSame(2, $rows);
        $this->assertSame($rows, $this->engagement()->readersOf($post));
    }

    public function test_a_throttled_repeat_writes_nothing(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();
        $this->open($reader, $post);

        $queries = $this->queriesDuring(fn () => $this->open($reader, $post)->assertOk());

        $this->assertEmpty(array_filter($queries, fn ($sql) => str_contains($sql, 'blog_post_view_daily') || str_contains($sql, 'view_count')), 'a throttled repeat writes nothing');
    }
}
