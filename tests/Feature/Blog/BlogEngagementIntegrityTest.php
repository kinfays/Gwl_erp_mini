<?php

namespace Tests\Feature\Blog;

use App\Livewire\Blog\ManagePosts;
use App\Livewire\Blog\PostForm;
use App\Livewire\Blog\PostStats;
use App\Models\BlogPost;
use App\Models\BlogPostRevision;
use App\Models\User;
use App\Services\Blog\BlogFeedService;
use App\Services\Blog\BlogPostService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * What likes and views must NOT disturb (the article's own bookkeeping), what a page costs, the sidebar blocks, the recount command
 * and what the pages print.
 */
class BlogEngagementIntegrityTest extends BlogEngagementTestCase
{
    protected function service(): BlogPostService
    {
        return app(BlogPostService::class);
    }

    protected function open(User $user, BlogPost $post)
    {
        return $this->actingAs($user)->get($post->url());
    }

    // ---------------------------------------------------------------- the article's own bookkeeping

    public function test_a_like_and_a_view_change_neither_updated_at_nor_the_version_nor_make_a_saved_version(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $post = $this->service()->create($this->prOfficer(), ['region_id' => $this->accraWest->id, 'title' => 'Stable', 'category' => 'activity', 'body_json' => $this->paragraphsDoc('Text')], null, true);
        $before = DB::table('blog_posts')->where('id', $post->id)->first();
        $versions = BlogPostRevision::query()->count();

        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->like($this->reader('100001'), $post)->assertOk();
        $this->open($this->reader('100002'), $post)->assertOk();
        $this->unlike($this->reader('100001'), $post)->assertOk();
        $this->like($this->reader('100001'), $post)->assertOk();

        $after = DB::table('blog_posts')->where('id', $post->id)->first();

        $this->assertSame(1, (int) $after->view_count);
        $this->assertSame(1, (int) $after->like_count);
        $this->assertSame($before->updated_at, $after->updated_at, 'a like or a view must never look like an edit');
        $this->assertSame($before->lock_version, $after->lock_version);
        $this->assertSame($versions, BlogPostRevision::query()->count());
    }

    public function test_an_editor_who_opened_the_article_before_the_likes_and_views_saves_without_a_conflict_and_keeps_the_counters(): void
    {
        $officer = $this->prOfficer();
        $post = $this->service()->create($officer, ['region_id' => $this->accraWest->id, 'title' => 'Busy article', 'category' => 'activity', 'body_json' => $this->paragraphsDoc('Text')], null, true);
        $opened = $post->lock_version;

        $this->open($this->reader('100001'), $post);
        $this->open($this->reader('100002'), $post);
        $this->like($this->reader('100001'), $post);

        // Through the service, with the version the editor opened...
        $saved = $this->service()->update($post, $officer, ['region_id' => $this->accraWest->id, 'title' => 'Busy article, edited', 'category' => 'activity', 'body_json' => $this->paragraphsDoc('New text')], null, false, $opened);

        $this->assertSame($opened + 1, $saved->lock_version, 'only the edit moved the version');
        $this->assertSame(2, $saved->fresh()->view_count);
        $this->assertSame(1, $saved->fresh()->like_count);
    }

    public function test_the_editor_form_keeps_its_place_and_the_counters_when_readers_arrive_while_it_is_open(): void
    {
        $officer = $this->prOfficer();
        $post = $this->article($this->accraWest, ['title' => 'Open in the editor']);

        $form = Livewire::actingAs($officer)->test(PostForm::class, ['postId' => $post->id]);

        // Straight through the service: a Livewire test must not be interleaved with HTTP requests.
        $this->engagement()->recordView($this->reader('100001'), $post);
        $this->engagement()->like($this->reader('100001'), $post);
        $this->engagement()->like($this->reader('100002'), $post);

        $form->set('title', 'Retitled while people read')->call('saveDraft')->assertHasNoErrors()->assertSet('conflict', null);

        $post->refresh();
        $this->assertSame('Retitled while people read', $post->title);
        $this->assertSame(1, $post->view_count);
        $this->assertSame(2, $post->like_count);
        $this->assertSame(BlogPost::STATUS_PUBLISHED, $post->status);
    }

    public function test_the_counters_are_not_mass_assignable_and_a_restore_does_not_touch_them(): void
    {
        $this->assertFalse((new BlogPost)->isFillable('view_count'));
        $this->assertFalse((new BlogPost)->isFillable('like_count'));

        $made = BlogPost::query()->create(['region_id' => $this->accraWest->id, 'title' => 'Sneaky', 'category' => 'activity', 'body' => 'x', 'view_count' => 99, 'like_count' => 99]);
        $this->assertSame(0, $made->fresh()->view_count);
        $this->assertSame(0, $made->fresh()->like_count);

        $officer = $this->prOfficer();
        $post = $this->service()->create($officer, ['region_id' => $this->accraWest->id, 'title' => 'Versioned', 'category' => 'activity', 'body_json' => $this->paragraphsDoc('First')], null, true);
        $first = BlogPostRevision::query()->where('blog_post_id', $post->id)->firstOrFail();
        $post = $this->service()->update($post, $officer, ['region_id' => $this->accraWest->id, 'title' => 'Versioned', 'category' => 'activity', 'body_json' => $this->paragraphsDoc('Second')]);
        $this->setCounters($post, views: 7, likes: 3);

        $restored = $this->service()->restoreRevision($post->fresh(), $officer, $first->id);

        $this->assertSame(7, $restored->fresh()->view_count);
        $this->assertSame(3, $restored->fresh()->like_count);
    }

    public function test_saved_versions_hold_no_counters(): void
    {
        $columns = Schema::getColumnListing('blog_post_revisions');

        $this->assertNotContains('view_count', $columns);
        $this->assertNotContains('like_count', $columns);

        $post = $this->service()->create($this->prOfficer(), ['region_id' => $this->accraWest->id, 'title' => 'Versioned', 'category' => 'activity', 'body_json' => $this->paragraphsDoc('First')], null, true);
        $this->setCounters($post, views: 5, likes: 2);
        $post = $this->service()->update($post->fresh(), $this->prOfficer(), ['region_id' => $this->accraWest->id, 'title' => 'Versioned again', 'category' => 'activity', 'body_json' => $this->paragraphsDoc('Second')]);

        foreach (BlogPostRevision::query()->where('blog_post_id', $post->id)->get() as $revision) {
            $this->assertArrayNotHasKey('view_count', $revision->getAttributes());
            $this->assertArrayNotHasKey('like_count', $revision->getAttributes());
        }

        $this->assertSame(5, $post->fresh()->view_count);
    }

    public function test_a_like_does_not_reorder_the_manage_list_which_sorts_by_last_update(): void
    {
        $older = $this->article($this->accraWest, ['title' => 'Edited long ago']);
        $newer = $this->article($this->accraWest, ['title' => 'Edited yesterday']);
        DB::table('blog_posts')->where('id', $older->id)->update(['updated_at' => '2026-10-01 09:00:00']);
        DB::table('blog_posts')->where('id', $newer->id)->update(['updated_at' => '2026-11-19 09:00:00']);

        $this->like($this->reader(), $older);
        $this->open($this->reader('100002'), $older);

        Livewire::actingAs($this->prOfficer())->test(ManagePosts::class)->assertSeeInOrder(['Edited yesterday', 'Edited long ago']);
    }

    // ---------------------------------------------------------------- the manage list and the stats panel

    public function test_the_manage_list_shows_views_readers_and_likes_and_sorts_by_each(): void
    {
        $low = $this->article($this->accraWest, ['title' => 'Quiet piece']);
        $high = $this->article($this->accraWest, ['title' => 'Busy piece']);
        $mid = $this->article($this->accraWest, ['title' => 'Middling piece']);
        $this->setCounters($low, views: 1, likes: 9);
        $this->setCounters($high, views: 50, likes: 1);
        $this->setCounters($mid, views: 20, likes: 4);
        foreach ([['100001', $mid], ['100002', $mid], ['100003', $mid], ['100001', $low], ['100002', $high], ['100003', $high]] as [$staff, $post]) {
            DB::table('blog_post_reads')->insert(['user_id' => $this->reader($staff)->id, 'blog_post_id' => $post->id, 'read_at' => now()]);
        }

        $list = Livewire::actingAs($this->prOfficer())->test(ManagePosts::class)
            ->assertSee('Views')->assertSee('Readers')->assertSee('Likes');

        $list->call('sortBy', 'views')->assertSet('sort', 'views')->assertSet('dir', 'desc')->assertSeeInOrder(['Busy piece', 'Middling piece', 'Quiet piece']);
        $list->call('sortBy', 'views')->assertSet('dir', 'asc')->assertSeeInOrder(['Quiet piece', 'Middling piece', 'Busy piece']);
        $list->call('sortBy', 'likes')->assertSet('dir', 'desc')->assertSeeInOrder(['Quiet piece', 'Middling piece', 'Busy piece']);
        $list->call('sortBy', 'readers')->assertSeeInOrder(['Middling piece', 'Busy piece', 'Quiet piece']);

        // Only the three figures can be sorted by; anything else is ignored.
        $list->call('sortBy', 'title; drop table blog_posts')->assertSet('sort', 'readers');
    }

    public function test_the_stats_panel_shows_the_figures_and_a_sparkline_to_a_manager_of_the_region(): void
    {
        $post = $this->article($this->accraWest);
        DB::table('blog_post_reads')->insert(['user_id' => $this->reader('100001')->id, 'blog_post_id' => $post->id, 'read_at' => now()]);
        DB::table('blog_post_reads')->insert(['user_id' => $this->reader('100002')->id, 'blog_post_id' => $post->id, 'read_at' => now()]);
        $this->setCounters($post, views: 12, likes: 3);
        DB::table('blog_post_view_daily')->insert([
            ['blog_post_id' => $post->id, 'view_date' => '2026-11-20', 'views' => 5],
            ['blog_post_id' => $post->id, 'view_date' => '2026-11-18', 'views' => 7],
        ]);

        Livewire::actingAs($this->prOfficer())->test(PostStats::class, ['postId' => $post->id])
            ->assertSee('Stats')
            ->assertSeeHtml('<dd>12</dd>')
            ->assertSeeHtml('<dd>2</dd>')
            ->assertSeeHtml('<dd>3</dd>')
            ->assertSeeHtml('b-spark')
            ->assertViewHas('series', fn (array $series) => count($series) === 14 && $series[13] === 5 && $series[11] === 7 && array_sum($series) === 12);
    }

    // ---------------------------------------------------------------- what a page costs

    public function test_the_feed_runs_the_same_number_of_queries_whatever_the_page_size_and_asks_for_likes_once(): void
    {
        $reader = $this->reader();
        $made = [];
        foreach (range(1, 5) as $n) {
            $made[] = $this->article($this->accraWest, ['title' => "Story {$n}", 'district_id' => $this->sowutuom->id, 'published_at' => now()->subMinutes(100 - $n)]);
        }
        $this->like($reader, $made[0]);
        $this->like($reader, $made[4]);

        $measure = function (string $route) use ($reader) {
            $this->actingAs($reader)->get($route)->assertOk(); // warm-up: the per-person caches

            return $this->queriesDuring(fn () => $this->actingAs($reader)->get($route)->assertOk());
        };

        $smallHome = $measure(route('blog.home'));
        $smallList = $measure(route('blog.category', 'activity'));

        foreach (range(6, 24) as $n) {
            $post = $this->article($this->accraWest, ['title' => "Story {$n}", 'district_id' => $this->odorkor->id, 'published_at' => now()->subMinutes(100 - $n)]);
            if ($n % 3 === 0) {
                $this->like($reader, $post);
            }
        }

        $bigHome = $measure(route('blog.home'));
        $bigList = $measure(route('blog.category', 'activity'));

        $this->assertCount(count($smallHome), $bigHome, 'the home page costs the same with 5 or 24 articles');
        $this->assertCount(count($smallList), $bigList, 'a list page costs the same too');

        $likeQueries = fn (array $log) => count(array_filter($log, fn ($sql) => str_contains($sql, 'blog_post_likes')));
        $this->assertSame(1, $likeQueries($bigHome), 'one query for the whole page');
        $this->assertSame(1, $likeQueries($bigList));
    }

    // ---------------------------------------------------------------- the sidebar blocks

    protected function daily(BlogPost $post, string $date, int $views): void
    {
        DB::table('blog_post_view_daily')->insert(['blog_post_id' => $post->id, 'view_date' => $date, 'views' => $views]);
    }

    public function test_most_read_this_week_counts_the_last_seven_days_of_the_readers_own_region_and_published_articles_only(): void
    {
        $a = $this->article($this->accraWest, ['title' => 'A two days', 'published_at' => now()->subDays(20)]);
        $b = $this->article($this->accraWest, ['title' => 'B only old views']);
        $c = $this->article($this->accraWest, ['title' => 'C today']);
        $foreign = $this->article($this->ashanti, ['title' => 'D other region']);
        $draft = $this->draft($this->accraWest, ['title' => 'E a draft']);
        $none = $this->article($this->accraWest, ['title' => 'F no views']);

        $this->daily($a, '2026-11-20', 3);
        $this->daily($a, '2026-11-14', 4);   // six days ago: still inside "this week"
        $this->daily($b, '2026-11-13', 100); // seven days ago: outside
        $this->daily($c, '2026-11-20', 5);
        $this->daily($foreign, '2026-11-20', 500);
        $this->daily($draft, '2026-11-20', 400);

        $top = app(BlogFeedService::class)->mostRead($this->reader(), $this->accraWest->id);

        $this->assertSame(['A two days', 'C today'], $top->pluck('title')->all());
        $this->assertSame([7, 5], $top->pluck('week_views')->map(fn ($v) => (int) $v)->all());

        $page = $this->actingAs($this->reader())->get(route('blog.home'))->assertOk();
        $page->assertSee('Most read this week')->assertSee('7 views');
        $page->assertDontSee('D other region')->assertDontSee('E a draft');
    }

    public function test_the_most_read_list_is_capped_at_five(): void
    {
        foreach (range(1, 8) as $n) {
            $this->daily($this->article($this->accraWest, ['title' => "Popular {$n}"]), '2026-11-20', $n);
        }

        $top = app(BlogFeedService::class)->mostRead($this->reader(), $this->accraWest->id);

        $this->assertSame(['Popular 8', 'Popular 7', 'Popular 6', 'Popular 5', 'Popular 4'], $top->pluck('title')->all());
    }

    public function test_most_liked_is_all_time_scoped_to_the_region_and_published_articles(): void
    {
        $a = $this->article($this->accraWest, ['title' => 'A liked a little', 'published_at' => now()->subYear()]);
        $b = $this->article($this->accraWest, ['title' => 'B liked a lot']);
        $this->article($this->accraWest, ['title' => 'C nobody liked it']);
        $foreign = $this->article($this->ashanti, ['title' => 'D other region']);
        $draft = $this->draft($this->accraWest, ['title' => 'E a draft']);
        $this->setCounters($a, likes: 2);
        $this->setCounters($b, likes: 9);
        $this->setCounters($foreign, likes: 50);
        $this->setCounters($draft, likes: 30);

        $top = app(BlogFeedService::class)->mostLiked($this->reader(), $this->accraWest->id);

        $this->assertSame(['B liked a lot', 'A liked a little'], $top->pluck('title')->all());

        $this->actingAs($this->reader())->get(route('blog.home'))->assertOk()
            ->assertSee('Most liked')->assertSee('9 likes')
            ->assertDontSee('D other region')->assertDontSee('E a draft');
    }

    // ---------------------------------------------------------------- the recount command

    public function test_the_recount_command_repairs_a_corrupted_count_and_nothing_else(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $wrongHigh = $this->article($this->accraWest);
        $wrongLow = $this->article($this->accraWest);
        $right = $this->article($this->accraWest);
        $none = $this->article($this->accraWest);
        $stamp = DB::table('blog_posts')->where('id', $wrongHigh->id)->value('updated_at');

        foreach ([['100001', $wrongHigh], ['100002', $wrongLow], ['100003', $wrongLow], ['100004', $wrongLow], ['100001', $right]] as [$staff, $post]) {
            DB::table('blog_post_likes')->insert(['user_id' => $this->reader($staff)->id, 'blog_post_id' => $post->id, 'created_at' => now()]);
        }
        $this->setCounters($wrongHigh, views: 11, likes: 40);
        $this->setCounters($wrongLow, views: 22, likes: 0);
        $this->setCounters($right, views: 33, likes: 1);
        $this->setCounters($none, views: 44, likes: 6);

        Carbon::setTestNow('2026-11-20 10:00:00');

        $this->artisan('blog:recount-likes', ['--dry-run' => true])->expectsOutputToContain('3 article(s) have a wrong like count')->assertSuccessful();
        $this->assertSame(40, $wrongHigh->fresh()->like_count, 'a dry run changes nothing');

        $this->artisan('blog:recount-likes', ['--chunk' => 2])->expectsOutputToContain('Repaired the like count of 3 article(s)')->assertSuccessful();

        $this->assertSame([1, 3, 1, 0], array_map(fn ($p) => $p->fresh()->like_count, [$wrongHigh, $wrongLow, $right, $none]));
        $this->assertSame([11, 22, 33, 44], array_map(fn ($p) => $p->fresh()->view_count, [$wrongHigh, $wrongLow, $right, $none]), 'views cannot be rebuilt and are never touched');
        $this->assertSame($stamp, DB::table('blog_posts')->where('id', $wrongHigh->id)->value('updated_at'), 'updated_at is left alone');

        $this->artisan('blog:recount-likes')->expectsOutputToContain('Repaired the like count of 0 article(s)')->assertSuccessful();
    }

    public function test_the_recount_is_scheduled_weekly_while_the_blog_is_on(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'blog:recount-likes'));

        $this->assertNotNull($event);
        $this->assertSame('40 3 * * 0', $event->expression);
    }

    // ---------------------------------------------------------------- what the pages print

    public function test_the_feed_prints_a_heart_with_its_count_on_the_hero_the_secondary_stories_and_the_rows(): void
    {
        $reader = $this->reader();
        $posts = [];
        foreach (range(1, 6) as $n) {
            $posts[] = $this->article($this->accraWest, ['title' => "Story {$n}", 'published_at' => now()->subMinutes(100 - $n)]);
        }
        // Story 6 is the lead, 5/4/3 the secondary stories, 2 and 1 the stream.
        $this->like($reader, $posts[5]);
        $this->like($this->reader('100002'), $posts[5]);
        $this->setCounters($posts[5]->fresh(), views: 3, likes: 2);

        $html = $this->actingAs($reader)->get(route('blog.home'))->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'class="b-like-btn"'), 'a heart on the lead, three secondary stories and two rows');
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'), 'pressed only on the one this reader liked');
        $this->assertStringContainsString('aria-label="Like this article, 2 likes"', $html);
        $this->assertStringContainsString('aria-label="Like this article, 0 likes"', $html);
        $this->assertStringContainsString('3 views', $html);
        $this->assertStringContainsString('data-like-url="'.route('blog.like', $posts[5]).'"', $html);
    }

    public function test_the_article_page_has_the_heart_under_the_headline_and_at_the_end_with_you_and_others(): void
    {
        $post = $this->article($this->accraWest);
        $fan = $this->reader();
        $this->like($fan, $post);
        $this->like($this->reader('100002'), $post);

        $html = $this->actingAs($fan)->get($post->url())->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'b-like-full'), 'under the headline and at the end');
        $this->assertSame(2, substr_count($html, 'aria-pressed="true"'));
        $this->assertStringContainsString('aria-label="Like this article, 2 likes"', $html);
        $this->assertStringContainsString('You and 1 other', $html);
        $this->assertSame(1, substr_count($html, 'aria-live="polite"'), 'only one summary announces changes');

        $this->actingAs($this->reader('100003'))->get($post->url())->assertOk()->assertSee('2 likes')->assertDontSee('You and');
    }

    public function test_neither_the_preview_nor_a_draft_shows_a_like_button(): void
    {
        $draft = $this->draft($this->accraWest);

        $preview = view('blog.partials.article', ['post' => $draft, 'preview' => true, 'canManage' => false, 'regions' => collect()])->render();
        $this->assertStringNotContainsString('b-like', $preview);

        $this->actingAs($this->prOfficer())->get($draft->url())->assertOk()->assertDontSee('b-like-btn');
    }
}
