<?php

namespace Tests\Feature\Blog;

use App\Livewire\Blog\HeaderLink;
use App\Models\AuditLog;
use App\Models\BlogPost;
use App\Models\BlogPostRead;
use App\Models\User;
use App\Services\Blog\BlogPostService;
use App\Services\Blog\BlogReadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The unread-articles badge (docs/14-module-regional-blog.md, "Unread articles"): what counts, how an article becomes read, the
 * set-based "mark all", the cache and its invalidation, and the cheap path when the blog is off or the account may not enter it.
 */
class BlogUnreadTest extends BlogTestCase
{
    protected function reads(): BlogReadService
    {
        return app(BlogReadService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The header link as it renders once loaded (lazy loading is switched off per component: Livewire resets that flag between tests). */
    protected function headerLink(User $user)
    {
        Livewire::withoutLazyLoading();

        return Livewire::actingAs($user)->test(HeaderLink::class, ['url' => route('blog.home'), 'icon' => 'scroll-text']);
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

    // ---------------------------------------------------------------- what counts

    public function test_only_published_articles_of_the_own_region_within_the_window_count(): void
    {
        $reader = $this->reader();
        $officer = $this->prOfficer();

        $this->article($this->accraWest, ['author_user_id' => $officer->id]);                                 // counts
        $this->article($this->accraWest, ['author_user_id' => $officer->id, 'published_at' => now()->subDays(29)]); // counts: inside the window
        $this->article($this->accraWest, ['author_user_id' => $officer->id, 'published_at' => now()->subDays(31)]); // too old
        $this->draft($this->accraWest, ['author_user_id' => $officer->id]);                                    // never
        $this->article($this->ashanti);                                                                        // another region

        $this->assertSame(2, $this->reads()->unreadCount($reader));
    }

    public function test_the_window_is_a_setting(): void
    {
        $this->article($this->accraWest, ['published_at' => now()->subDays(10)]);

        config(['gwl.blog_unread_lookback_days' => 5]);
        $this->assertSame(0, $this->reads()->unreadCount($this->reader()));

        config(['gwl.blog_unread_lookback_days' => 15]);
        $this->reads()->forgetEveryone();
        $this->assertSame(1, $this->reads()->unreadCount($this->reader()));
    }

    public function test_an_author_counts_their_own_articles_as_read(): void
    {
        $officer = $this->prOfficer();
        $reader = $this->reader();
        $own = $this->article($this->accraWest, ['author_user_id' => $officer->id]);

        $this->assertSame(0, $this->reads()->unreadCount($officer));
        $this->assertSame(1, $this->reads()->unreadCount($reader));

        // Opening their own article writes nothing either.
        $this->assertFalse($this->reads()->markRead($officer, $own));
        $this->assertSame(0, BlogPostRead::query()->count());
    }

    public function test_super_admin_has_no_region_so_the_count_is_what_the_feed_lets_it_read(): void
    {
        $this->article($this->accraWest);
        $this->article($this->ashanti);
        $this->draft($this->ashanti);

        $this->assertSame(2, $this->reads()->unreadCount($this->superAdmin()));
    }

    // ---------------------------------------------------------------- reading

    public function test_opening_an_article_marks_it_read_once_and_a_refresh_changes_nothing(): void
    {
        $reader = $this->reader();
        $other = $this->reader('100002');
        $post = $this->article($this->accraWest);
        $this->article($this->accraWest);

        $this->assertSame(2, $this->reads()->unreadCount($reader));

        $this->actingAs($reader)->get($post->url())->assertOk();
        $this->actingAs($reader)->get($post->url())->assertOk();   // a refresh
        $this->actingAs($reader)->get($post->url().'?again=1')->assertOk(); // a double click

        $this->assertSame(1, BlogPostRead::query()->where('user_id', $reader->id)->where('blog_post_id', $post->id)->count());
        $this->assertSame(1, $this->reads()->unreadCount($reader));
        $this->assertSame(2, $this->reads()->unreadCount($other), 'reading is personal');
    }

    public function test_marking_read_twice_is_not_an_error_and_writes_one_row(): void
    {
        $reader = $this->reader();
        $post = $this->article($this->accraWest);

        $this->assertTrue($this->reads()->markRead($reader, $post));
        $this->assertFalse($this->reads()->markRead($reader, $post), 'the second time writes nothing');
        $this->assertSame(1, BlogPostRead::query()->count());
    }

    public function test_only_a_published_article_the_person_may_read_is_marked(): void
    {
        $reader = $this->reader();

        $this->assertFalse($this->reads()->markRead($reader, $this->draft($this->accraWest)));
        $this->assertFalse($this->reads()->markRead($reader, $this->article($this->ashanti)));
        $this->assertSame(0, BlogPostRead::query()->count());

        // The pages agree: a draft or another region's article is a 404 and writes nothing.
        $this->actingAs($reader)->get($this->article($this->ashanti)->url())->assertNotFound();
        $this->assertSame(0, BlogPostRead::query()->count());
    }

    public function test_a_writer_previewing_a_draft_does_not_create_a_read(): void
    {
        $officer = $this->prOfficer();
        $draft = $this->draft($this->accraWest);

        $this->actingAs($officer)->get($draft->url())->assertOk();

        $this->assertSame(0, BlogPostRead::query()->count());
    }

    public function test_republishing_does_not_make_a_read_article_unread_again(): void
    {
        $officer = $this->prOfficer();
        $reader = $this->reader();
        $newcomer = $this->reader('100002');
        $post = $this->article($this->accraWest, ['author_user_id' => $officer->id]);
        $publishedAt = $post->published_at->toDateTimeString();

        $this->actingAs($reader)->get($post->url())->assertOk();
        $this->assertSame(0, $this->reads()->unreadCount($reader));
        $this->assertSame(1, $this->reads()->unreadCount($newcomer));

        $service = app(BlogPostService::class);
        $service->unpublish($post, $officer);
        $this->assertSame(0, $this->reads()->unreadCount($newcomer), 'unpublishing removes it from the count');

        $service->publish($post->fresh(), $officer);

        $this->assertSame($publishedAt, $post->fresh()->published_at->toDateTimeString(), 'the first publication date is kept');
        $this->assertSame(0, $this->reads()->unreadCount($reader), 'the person who read it is not asked to read it again');
        $this->assertSame(1, $this->reads()->unreadCount($newcomer), 'someone who never read it still has it unread');
    }

    // ---------------------------------------------------------------- mark all as read

    public function test_mark_all_as_read_marks_only_this_persons_unread_articles_in_one_statement(): void
    {
        $reader = $this->reader();
        $other = $this->reader('100002');

        $mine = collect(range(1, 5))->map(fn () => $this->article($this->accraWest));
        $this->article($this->accraWest, ['published_at' => now()->subDays(40)]); // outside the window
        $this->draft($this->accraWest);
        $this->article($this->ashanti);
        $this->reads()->markRead($reader, $mine->first());                          // already read: not inserted twice

        $inserts = [];
        $queries = $this->queriesDuring(function () use ($reader, &$inserts) {
            $inserts = $this->reads()->markAllRead($reader);
        });

        $this->assertSame(4, $inserts);
        $this->assertCount(1, array_filter($queries, fn (string $sql) => stripos($sql, 'blog_post_reads') !== false && stripos($sql, 'insert') !== false), 'one INSERT ... SELECT, not a loop');
        $this->assertSame(5, BlogPostRead::query()->where('user_id', $reader->id)->count());
        $this->assertSame(0, $this->reads()->unreadCount($reader));
        $this->assertSame(5, $this->reads()->unreadCount($other), 'nobody else is touched');

        // Doing it again changes nothing.
        $this->assertSame(0, $this->reads()->markAllRead($reader));
        $this->assertSame(5, BlogPostRead::query()->where('user_id', $reader->id)->count());
    }

    public function test_the_mark_all_button_posts_and_the_notice_says_how_many(): void
    {
        $reader = $this->reader();
        $this->article($this->accraWest);
        $this->article($this->accraWest);

        $this->actingAs($reader)->get(route('blog.home'))->assertOk()->assertSee('Mark all as read')->assertSee(route('blog.read-all'), false);

        $this->actingAs($reader)->post(route('blog.read-all'))->assertRedirect(route('blog.home'))->assertSessionHas('blog_notice', '2 articles marked as read.');
        $this->assertSame(0, $this->reads()->unreadCount($reader));

        $this->actingAs($reader)->get(route('blog.home'))->assertOk()->assertDontSee('Mark all as read');
        $this->actingAs($reader)->post(route('blog.read-all'))->assertSessionHas('blog_notice', 'You are all caught up: there was nothing unread.');
    }

    public function test_marking_all_needs_a_login(): void
    {
        $this->post(route('blog.read-all'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- the number, the display and the cache

    public function test_the_display_is_capped_and_empty_at_zero(): void
    {
        $this->assertSame('', $this->reads()->display(0));
        $this->assertSame('3', $this->reads()->display(3));
        $this->assertSame('99', $this->reads()->display(99));
        $this->assertSame('99+', $this->reads()->display(100));
        $this->assertSame('99+', $this->reads()->display(5000));
    }

    public function test_the_header_badge_is_hidden_at_zero_and_labelled_with_the_count(): void
    {
        $reader = $this->reader();

        $zero = $this->headerLink($reader);
        $zero->assertDontSee('count-badge', false)->assertSee('aria-label="Regional Blog (opens in a new tab)"', false);

        foreach (range(1, 3) as $i) {
            $this->article($this->accraWest);
        }
        $this->reads()->forgetEveryone();

        $this->headerLink($reader)
            ->assertSee('count-badge', false)
            ->assertSee('Regional Blog, 3 unread articles (opens in a new tab)', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('wire:poll', false);
    }

    public function test_the_header_badge_shows_99_plus(): void
    {
        $rows = [];
        foreach (range(1, 101) as $i) {
            $rows[] = ['region_id' => $this->accraWest->id, 'title' => 'Bulk '.$i, 'category' => 'activity', 'body' => 'x', 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
        BlogPost::query()->insert($rows);

        $this->headerLink($this->reader())->assertSee('>99+<', false);
    }

    public function test_the_erp_header_defers_the_badge_so_the_page_never_waits_for_it(): void
    {
        $this->article($this->accraWest);

        $html = $this->actingAs($this->reader())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('class="icon-btn blog-link"', $html);
        $this->assertStringContainsString('__lazyLoad', $html, 'the number is fetched after the page has loaded');
        $this->assertStringNotContainsString('count-badge blog-badge', $html, 'the placeholder carries no number');
    }

    public function test_the_number_is_cached_per_person_and_a_publish_read_unpublish_or_delete_refreshes_it(): void
    {
        $officer = $this->prOfficer();
        $reader = $this->reader();
        $other = $this->reader('100002');
        $service = app(BlogPostService::class);

        $first = $this->article($this->accraWest, ['author_user_id' => $officer->id]);
        $this->assertSame(1, $this->reads()->unreadCount($reader));
        $this->assertSame(1, $this->reads()->unreadCount($other));

        // A row written behind the service's back is not seen until the cache expires or is cleared: it IS cached.
        $this->article($this->accraWest);
        $this->assertSame(1, $this->reads()->unreadCount($reader));
        $this->reads()->forgetEveryone();
        $this->assertSame(2, $this->reads()->unreadCount($reader));

        // Publish: everyone's number moves at once.
        $draft = $this->draft($this->accraWest, ['author_user_id' => $officer->id]);
        $service->publish($draft, $officer);
        $this->assertSame(3, $this->reads()->unreadCount($reader));
        $this->assertSame(3, $this->reads()->unreadCount($other));

        // Read: only the reader's own number moves.
        $this->actingAs($reader)->get($first->url())->assertOk();
        $this->assertSame(2, $this->reads()->unreadCount($reader));
        $this->assertSame(3, $this->reads()->unreadCount($other));

        // Unpublish and delete: gone from everyone's count straight away.
        $service->unpublish($draft->fresh(), $officer);
        $this->assertSame(2, $this->reads()->unreadCount($other));

        $service->delete($first->fresh(), $officer);
        $this->assertSame(1, $this->reads()->unreadCount($other));
        $this->assertSame(1, $this->reads()->unreadCount($reader));
    }

    public function test_mark_all_read_clears_the_persons_cached_number_at_once(): void
    {
        $reader = $this->reader();
        $this->article($this->accraWest);
        $this->assertSame(1, $this->reads()->unreadCount($reader));

        $this->reads()->markAllRead($reader);

        $this->assertSame(0, $this->reads()->unreadCount($reader));
    }

    public function test_the_count_is_one_query_on_a_cache_miss_and_none_on_a_hit(): void
    {
        $reader = $this->reader();
        $reader->getAccessibleModules(); // the role lookup is not a blog query; warm it so only the count is measured
        $this->article($this->accraWest);

        $miss = $this->queriesDuring(fn () => $this->reads()->unreadCount($reader));
        $this->assertCount(1, array_filter($miss, fn (string $sql) => stripos($sql, 'from "blog_posts"') !== false || stripos($sql, 'from `blog_posts`') !== false));

        $hit = $this->queriesDuring(fn () => $this->reads()->unreadCount($reader));
        $this->assertSame([], $hit, 'the cached number costs no query');
    }

    public function test_the_unread_query_is_one_not_exists_over_the_region_window(): void
    {
        $reader = $this->reader();
        $sql = strtolower($this->reads()->unreadQuery($reader)->toSql());

        $this->assertStringContainsString('not exists', $sql);
        $this->assertStringContainsString('blog_post_reads', $sql);
        $this->assertStringContainsString('"region_id" = ?', str_replace('`', '"', $sql));
        $this->assertStringContainsString('"published_at" >= ?', str_replace('`', '"', $sql));
    }

    // ---------------------------------------------------------------- off, and no access: no work

    public function test_a_switched_off_blog_costs_no_query_at_all(): void
    {
        $reader = $this->reader();
        $this->article($this->accraWest);
        config(['gwl.blog_module_enabled' => false]);

        $queries = $this->queriesDuring(function () use ($reader) {
            $this->assertSame(0, $this->reads()->unreadCount($reader));
        });

        $this->assertSame([], $queries);
    }

    public function test_an_account_without_the_blog_module_never_touches_a_blog_table(): void
    {
        $nobody = $this->userWithoutEmployee('NOROLE9', []);
        $this->article($this->accraWest);

        $queries = $this->queriesDuring(function () use ($nobody) {
            $this->assertSame(0, $this->reads()->unreadCount($nobody));
            $this->assertSame([], $this->reads()->unreadAmong($nobody, [1, 2]));
            $this->assertSame(0, $this->reads()->markAllRead($nobody));
        });

        $this->assertSame([], array_filter($queries, fn (string $sql) => stripos($sql, 'blog_') !== false));
    }

    public function test_a_login_with_no_region_has_nothing_unread(): void
    {
        $this->article($this->accraWest);

        $this->assertSame(0, $this->reads()->unreadCount($this->userWithoutEmployee('NOREGION', ['employee'])));
    }

    // ---------------------------------------------------------------- the blog pages

    public function test_the_masthead_shows_the_same_count_and_unread_cards_carry_a_new_marker(): void
    {
        $reader = $this->reader();
        $read = $this->article($this->accraWest, ['title' => 'Already read']);
        $this->article($this->accraWest, ['title' => 'Brand new one']);
        $this->article($this->accraWest, ['title' => 'Another new one']);
        $this->reads()->markRead($reader, $read);

        $html = $this->actingAs($reader)->get(route('blog.home'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="2 unread articles"', $html, 'the masthead count');
        $this->assertSame(2, substr_count($html, 'class="b-new"'), 'one marker per unread article, none on the read one');
        $this->assertStringContainsString('2 unread articles', $html);

        // Another region's reader sees none of it.
        $html = $this->actingAs($this->reader('200001', $this->ashanti))->get(route('blog.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('b-unread', $html);
        $this->assertStringNotContainsString('class="b-new"', $html);
    }

    public function test_the_masthead_count_and_markers_are_gone_once_everything_is_read(): void
    {
        $reader = $this->reader();
        $this->article($this->accraWest);
        $this->reads()->markAllRead($reader);

        $html = $this->actingAs($reader)->get(route('blog.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="b-unread"', $html);
        $this->assertStringNotContainsString('class="b-new"', $html);
    }

    public function test_list_pages_mark_unread_rows_too(): void
    {
        $reader = $this->reader();
        $this->article($this->accraWest, ['category' => 'training', 'title' => 'Unread training']);

        $html = $this->actingAs($reader)->get(route('blog.category', 'training'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="b-new"'));
    }

    public function test_marking_read_writes_no_audit_row(): void
    {
        $reader = $this->reader();
        $before = AuditLog::query()->count();

        $this->actingAs($reader)->get($this->article($this->accraWest)->url())->assertOk();
        $this->actingAs($reader)->post(route('blog.read-all'));

        $this->assertSame($before, AuditLog::query()->count(), 'reading is not an audited action');
    }
}
