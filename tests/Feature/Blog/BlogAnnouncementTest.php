<?php

namespace Tests\Feature\Blog;

use App\Listeners\Blog\ArmLoginAnnouncements;
use App\Livewire\Blog\AnnouncementStrip;
use App\Livewire\Blog\LoginAnnouncements;
use App\Livewire\Blog\ManagePosts;
use App\Livewire\Blog\PostForm;
use App\Models\AuditLog;
use App\Models\BlogAnnouncementDismissal;
use App\Models\BlogPost;
use App\Models\Region;
use App\Models\User;
use App\Services\Blog\BlogAnnouncementService;
use App\Services\Blog\BlogPostService;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Login announcements (docs/14-module-regional-blog.md, "Login announcements"): when an announcement is active (the whole last
 * day, in the app's timezone), who is given it, the once-per-login window, "Close" against "Don't show again", the rules the
 * form and the service enforce, the manage list, and the audit trail.
 */
class BlogAnnouncementTest extends BlogTestCase
{
    protected const PASSWORD = 'Secret-pass-1';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function service(): BlogAnnouncementService
    {
        return app(BlogAnnouncementService::class);
    }

    /** A published announcement shown until the given day (default: three days from now). */
    protected function announcement(Region $region, ?string $until = null, array $attributes = []): BlogPost
    {
        return $this->article($region, [
            'category' => 'announcement',
            'title' => 'Notice '.uniqid(),
            'published_at' => now()->subDay(),
            'announce_until' => $until ?? now()->addDays(3)->toDateString(),
            ...$attributes,
        ]);
    }

    /** @return Collection<int, BlogPost> */
    protected function active(User $user): Collection
    {
        return $this->service()->activeFor($user, 50);
    }

    /** Sign in through the real login form (so the Login event and the redirect run as they do for a person). */
    protected function signIn(User $user)
    {
        $user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

        return $this->post('/login', ['staff_id' => $user->staff_id, 'password' => self::PASSWORD]);
    }

    protected function arm(): void
    {
        session()->put(BlogAnnouncementService::SESSION_ARMED, true);
    }

    protected function window(User $user)
    {
        return Livewire::actingAs($user)->test(LoginAnnouncements::class);
    }

    // ---------------------------------------------------------------- when it is active

    public function test_it_is_active_through_the_whole_last_day_and_gone_the_day_after(): void
    {
        $reader = $this->reader();
        $this->announcement($this->accraWest, '2026-11-30', ['published_at' => '2026-11-01 09:00:00']);

        foreach (['2026-11-29 08:00:00' => 1, '2026-11-30 00:00:00' => 1, '2026-11-30 12:00:00' => 1, '2026-11-30 23:59:59' => 1, '2026-12-01 00:00:00' => 0, '2026-12-05 10:00:00' => 0] as $moment => $expected) {
            Carbon::setTestNow(Carbon::parse($moment));
            $this->assertCount($expected, $this->active($reader), $moment);
        }
    }

    public function test_the_day_is_the_app_timezone_day(): void
    {
        $reader = $this->reader();
        $this->announcement($this->accraWest, '2026-11-30', ['published_at' => '2026-11-01 09:00:00']);

        // 12:00 UTC on the 30th is already the 1st of December in Auckland (UTC+13): over there it has ended.
        Carbon::setTestNow(Carbon::parse('2026-11-30 12:00:00', 'UTC'));

        config(['app.timezone' => 'UTC']);
        $this->assertCount(1, $this->active($reader));

        config(['app.timezone' => 'Pacific/Auckland']);
        $this->assertCount(0, $this->active($reader));
    }

    public function test_the_first_day_is_respected(): void
    {
        $reader = $this->reader();
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));

        $later = $this->announcement($this->accraWest, '2026-12-31', ['announce_from' => '2026-11-21']);
        $today = $this->announcement($this->accraWest, '2026-12-31', ['announce_from' => '2026-11-20']);
        $earlier = $this->announcement($this->accraWest, '2026-12-31', ['announce_from' => '2026-11-01']);
        $blank = $this->announcement($this->accraWest, '2026-12-31', ['announce_from' => null]);

        $ids = $this->active($reader)->modelKeys();

        $this->assertNotContains($later->id, $ids, 'starts tomorrow');
        $this->assertEqualsCanonicalizing([$today->id, $earlier->id, $blank->id], $ids);

        Carbon::setTestNow(Carbon::parse('2026-11-21 00:00:00'));
        $this->assertContains($later->id, $this->active($reader)->modelKeys(), 'from its first day');
    }

    public function test_only_published_articles_are_ever_given(): void
    {
        $reader = $this->reader();
        $this->draft($this->accraWest, ['announce_until' => now()->addDays(3)->toDateString(), 'category' => 'announcement']);
        $live = $this->announcement($this->accraWest);

        $this->assertSame([$live->id], $this->active($reader)->modelKeys());
        $this->assertNull($this->draft($this->accraWest, ['announce_until' => now()->addDay()->toDateString()])->announcementState());
    }

    public function test_an_article_that_is_not_an_announcement_is_not_given(): void
    {
        $this->article($this->accraWest);

        $this->assertCount(0, $this->active($this->reader()));
    }

    public function test_the_announcement_state_names_active_scheduled_and_expired(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));

        $this->assertSame('active', $this->announcement($this->accraWest, '2026-11-20')->announcementState());
        $this->assertSame('active', $this->announcement($this->accraWest, '2026-11-25', ['announce_from' => '2026-11-20'])->announcementState());
        $this->assertSame('scheduled', $this->announcement($this->accraWest, '2026-11-25', ['announce_from' => '2026-11-21'])->announcementState());
        $this->assertSame('expired', $this->announcement($this->accraWest, '2026-11-19')->announcementState());
        $this->assertNull($this->article($this->accraWest)->announcementState());
    }

    public function test_it_is_region_isolated(): void
    {
        $mine = $this->announcement($this->accraWest);
        $theirs = $this->announcement($this->ashanti);

        $this->assertSame([$mine->id], $this->active($this->reader())->modelKeys());
        $this->assertSame([$theirs->id], $this->active($this->reader('200001', $this->ashanti))->modelKeys());
        $this->assertSame([], $this->active($this->userWithoutEmployee('NOREGION', ['employee']))->modelKeys(), 'no region, no announcements');
    }

    // ---------------------------------------------------------------- once per login

    public function test_it_shows_once_after_the_login_form_and_not_on_a_refresh_or_the_next_page(): void
    {
        $reader = $this->reader();
        $notice = $this->announcement($this->accraWest, null, ['title' => 'Water week starts Monday']);

        $this->signIn($reader)->assertRedirect(route('dashboard'));

        $first = $this->get(route('dashboard'))->assertOk();
        $first->assertSee('Water week starts Monday')->assertSee('role="dialog"', false)->assertSee('aria-modal="true"', false)->assertSee('aria-labelledby="ann-title"', false);
        $first->assertSee('Read full article')->assertSee("Don't show again")->assertSee('Close');
        $first->assertSee($notice->url(), false);

        $this->get(route('dashboard'))->assertOk()->assertDontSee('Water week starts Monday')->assertDontSee('ann-dialog', false);
        $this->get(route('leave.home'))->assertOk()->assertDontSee('ann-dialog', false);
    }

    public function test_it_follows_the_intended_url_and_is_not_shown_on_the_blog_tab(): void
    {
        $reader = $this->reader();
        $this->announcement($this->accraWest, null, ['title' => 'Held for the ERP page']);

        // A guest opens a blog page, signs in, and lands back on it: the blog tab never shows the window...
        $this->get(route('blog.home'))->assertRedirect(route('login'));
        $this->signIn($reader)->assertRedirect(route('blog.home'));

        $this->get(route('blog.home'))->assertOk()->assertDontSee('ann-dialog', false);

        // ...the first ERP page after that does, once.
        $this->get(route('dashboard'))->assertOk()->assertSee('Held for the ERP page')->assertSee('ann-dialog', false);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('ann-dialog', false);
    }

    public function test_a_login_shows_only_the_own_regions_announcements(): void
    {
        $this->announcement($this->accraWest, null, ['title' => 'For Accra West']);
        $this->announcement($this->ashanti, null, ['title' => 'For Ashanti']);

        $this->signIn($this->reader('200001', $this->ashanti));

        $this->get(route('dashboard'))->assertOk()->assertSee('For Ashanti')->assertDontSee('For Accra West');
    }

    public function test_nothing_is_shown_when_there_is_nothing_active_and_the_flag_is_still_used_up(): void
    {
        $this->announcement($this->accraWest, now()->subDay()->toDateString());

        $this->signIn($this->reader());

        $this->get(route('dashboard'))->assertOk()->assertDontSee('ann-dialog', false);
        $this->assertFalse(session()->has(BlogAnnouncementService::SESSION_ARMED));
    }

    public function test_a_page_view_that_is_not_after_a_login_asks_the_database_nothing(): void
    {
        $this->announcement($this->accraWest);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->window($this->reader())->assertSet('open', false);
        $blogQueries = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => stripos($sql, 'blog_') !== false);
        DB::disableQueryLog();

        $this->assertSame([], $blogQueries);
    }

    // ---------------------------------------------------------------- remember-me re-authentication

    public function test_the_login_form_always_arms_and_a_remember_me_return_only_once_per_window(): void
    {
        $reader = $this->reader();

        $this->assertTrue($this->service()->arm($reader, true));
        $this->service()->consumeArmed();

        // Straight after a real login a remember-me re-authentication (same person, no password) does not arm it again.
        $this->assertFalse($this->service()->arm($reader, false));
        $this->assertFalse($this->service()->consumeArmed());

        // A form login always arms.
        $this->assertTrue($this->service()->arm($reader, true));
        $this->service()->consumeArmed();

        // Once the window has passed, the re-authentication may.
        Carbon::setTestNow(now()->addHours(13));
        $this->assertTrue($this->service()->arm($reader, false));
        $this->service()->consumeArmed();
        $this->assertFalse($this->service()->arm($reader, false), 'and then not again for another window');
    }

    public function test_a_remember_me_return_never_arms_when_the_setting_is_zero(): void
    {
        config(['gwl.blog_login_announcements_recall_hours' => 0]);
        $reader = $this->reader();

        $this->assertFalse($this->service()->arm($reader, false));
        $this->assertTrue($this->service()->arm($reader, true), 'the login form still does');
    }

    public function test_the_login_event_arms_for_the_form_and_throttles_a_cookie_return(): void
    {
        $reader = $this->reader();

        // A re-authentication from a remember-me cookie happens on any ordinary request (not POST /login).
        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        event(new Login('web', $reader, true));
        $this->assertTrue(session()->pull(BlogAnnouncementService::SESSION_ARMED, false), 'the first return in a window arms it');

        event(new Login('web', $reader, true));
        $this->assertFalse(session()->has(BlogAnnouncementService::SESSION_ARMED), 'the next one does not');

        // The login form is a POST to /login: always.
        $form = Request::create('/login', 'POST');
        $form->setLaravelSession(app('session.store'));
        app()->instance('request', $form);

        event(new Login('web', $reader, false));
        $this->assertTrue(session()->has(BlogAnnouncementService::SESSION_ARMED));
    }

    public function test_the_listener_does_nothing_without_a_session(): void
    {
        $reader = $this->reader();
        app()->instance('request', Request::create('/dashboard', 'GET')); // no session attached

        app(ArmLoginAnnouncements::class)->handle(new Login('web', $reader, false));

        $this->assertFalse(session()->has(BlogAnnouncementService::SESSION_ARMED));
    }

    // ---------------------------------------------------------------- who is shown it

    public function test_an_account_without_the_blog_module_is_never_armed_or_given_anything(): void
    {
        $nobody = $this->userWithoutEmployee('NOROLE7', []);
        $this->announcement($this->accraWest);

        $this->assertFalse($this->service()->arm($nobody, true));
        $this->assertCount(0, $this->service()->activeFor($nobody));
    }

    public function test_a_switched_off_blog_arms_and_shows_nothing(): void
    {
        $reader = $this->reader();
        $this->announcement($this->accraWest);
        config(['gwl.blog_module_enabled' => false]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse($this->service()->arm($reader, true));
        $this->assertCount(0, $this->service()->activeFor($reader));
        $blogQueries = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => stripos($sql, 'blog_') !== false);
        DB::disableQueryLog();

        $this->assertSame([], $blogQueries);
    }

    // ---------------------------------------------------------------- the window itself

    public function test_it_steps_through_several_newest_first_at_most_the_configured_number(): void
    {
        config(['gwl.blog_login_announcements_max' => 3]);
        $reader = $this->reader();
        $oldest = $this->announcement($this->accraWest, null, ['title' => 'Oldest', 'published_at' => now()->subDays(9)]);
        $this->announcement($this->accraWest, null, ['title' => 'Third', 'published_at' => now()->subDays(8)]);
        $this->announcement($this->accraWest, null, ['title' => 'Second', 'published_at' => now()->subDays(2)]);
        $this->announcement($this->accraWest, null, ['title' => 'Newest', 'published_at' => now()->subHour()]);

        $this->arm();
        $component = $this->window($reader);

        $items = $component->get('items');
        $this->assertSame(['Newest', 'Second', 'Third'], array_column($items, 'title'));
        $this->assertNotContains($oldest->id, array_column($items, 'id'));

        $component->assertSee('Newest')->assertSee('1 of 3')->assertSee('Next');
        $component->call('next')->assertSee('Second')->assertSee('2 of 3');
        $component->call('next')->assertSee('Third')->assertSee('3 of 3')->assertDontSee('Next');
        $component->call('next')->assertSet('open', false);
    }

    public function test_the_window_prints_the_title_teaser_dates_and_until_day(): void
    {
        $reader = $this->reader();
        $this->announcement($this->accraWest, '2026-12-24', [
            'title' => 'Annual staff durbar',
            'summary' => 'Join us for the durbar.',
            'event_date' => '2026-12-12',
            'venue' => 'Regional conference room',
            'district_id' => $this->sowutuom->id,
        ]);

        $this->arm();
        $this->window($reader)
            ->assertSee('Annual staff durbar')
            ->assertSee('Join us for the durbar.')
            ->assertSee('12 Dec 2026')
            ->assertSee('Regional conference room')
            ->assertSee('Shown until 24 Dec 2026')
            ->assertSee('Sowutuom');
    }

    public function test_close_hides_them_for_this_login_session_only(): void
    {
        $reader = $this->reader();
        $a = $this->announcement($this->accraWest, null, ['published_at' => now()->subDays(3)]);
        $b = $this->announcement($this->accraWest, null, ['published_at' => now()->subDays(2)]);

        $this->arm();
        $this->window($reader)->assertSet('open', true)->call('close')->assertSet('open', false);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], session(BlogAnnouncementService::SESSION_HIDDEN));
        $this->assertSame(0, BlogAnnouncementDismissal::query()->count(), 'closing is not a dismissal');
        $this->assertCount(0, $this->active($reader), 'hidden for the rest of this session, also in the strip');

        // A new login session starts clean: they come back.
        session()->flush();
        $this->assertCount(2, $this->active($reader));
    }

    public function test_dont_show_again_is_forever_for_that_person_and_that_announcement(): void
    {
        $reader = $this->reader();
        $other = $this->reader('100002');
        $a = $this->announcement($this->accraWest, null, ['published_at' => now()->subDays(3)]);
        $b = $this->announcement($this->accraWest, null, ['published_at' => now()->subDays(2)]);

        $this->arm();
        $component = $this->window($reader);
        $component->call('dismissCurrent')->assertSet('index', 1)->assertSet('open', true); // dismissed the newest (B)

        $this->assertSame([$b->id], BlogAnnouncementDismissal::query()->where('user_id', $reader->id)->pluck('blog_post_id')->all());

        $component->call('dismissCurrent')->assertSet('open', false);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], BlogAnnouncementDismissal::query()->where('user_id', $reader->id)->pluck('blog_post_id')->all());

        // A new session, a new login: still gone for this person...
        session()->flush();
        $this->assertCount(0, $this->active($reader));
        // ...and untouched for everyone else.
        $this->assertCount(2, $this->active($other));
    }

    public function test_dont_show_again_on_one_leaves_the_others_to_be_seen(): void
    {
        $reader = $this->reader();
        $this->announcement($this->accraWest, null, ['title' => 'Keep me', 'published_at' => now()->subDays(3)]);
        $this->announcement($this->accraWest, null, ['title' => 'Drop me', 'published_at' => now()->subDays(2)]);

        $this->arm();
        $this->window($reader)->call('dismissCurrent');
        session()->flush();

        $this->assertSame(['Keep me'], $this->active($reader)->pluck('title')->all());
    }

    public function test_read_full_article_opens_the_article_which_marks_it_read(): void
    {
        $reader = $this->reader();
        $post = $this->announcement($this->accraWest, null, ['title' => 'Read me']);

        $this->arm();
        $component = $this->window($reader)->assertSee('target="_blank"', false)->assertSee($post->url(), false);
        $component->call('opened')->assertSet('open', false);

        $this->actingAs($reader)->get($post->url())->assertOk();
        $this->assertDatabaseHas('blog_post_reads', ['user_id' => $reader->id, 'blog_post_id' => $post->id]);
    }

    public function test_the_window_is_a_labelled_modal_with_escape_and_a_focus_trap(): void
    {
        $this->announcement($this->accraWest);
        $this->arm();

        $html = $this->window($this->reader())->html();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="ann-title"', $html);
        $this->assertStringContainsString('id="ann-title"', $html);
        $this->assertStringContainsString('x-trap', $html, 'focus is kept inside');
        $this->assertStringContainsString('x-on:keydown.escape', $html, 'Esc closes it');
    }

    public function test_a_dismissal_can_only_name_an_article_the_person_may_read(): void
    {
        $reader = $this->reader();
        $foreign = $this->announcement($this->ashanti);
        $draft = $this->draft($this->accraWest);

        $this->assertFalse($this->service()->dismiss($reader, $foreign->id));
        $this->assertFalse($this->service()->dismiss($reader, $draft->id));
        $this->assertFalse($this->service()->dismiss($reader, 999999));
        $this->assertSame(0, BlogAnnouncementDismissal::query()->count());
    }

    public function test_dismissing_twice_is_harmless_and_dismissals_are_not_audited(): void
    {
        $reader = $this->reader();
        $post = $this->announcement($this->accraWest);
        $before = AuditLog::query()->count();

        $this->assertTrue($this->service()->dismiss($reader, $post->id));
        $this->assertTrue($this->service()->dismiss($reader, $post->id));

        $this->assertSame(1, BlogAnnouncementDismissal::query()->count());
        $this->assertSame($before, AuditLog::query()->count());
    }

    // ---------------------------------------------------------------- the strip on the blog home

    public function test_the_blog_home_shows_the_active_announcements_as_a_dismissible_strip(): void
    {
        $reader = $this->reader();
        $this->announcement($this->accraWest, now()->addDays(2)->toDateString(), ['title' => 'Strip headline']);
        $this->announcement($this->ashanti, null, ['title' => 'Other region headline']);
        $this->announcement($this->accraWest, now()->subDay()->toDateString(), ['title' => 'Expired headline']);

        $html = $this->actingAs($reader)->get(route('blog.home'))->assertOk()->assertSee('b-annstrip', false)->getContent();

        // The articles themselves are still in the feed below; what is asserted is what the STRIP lists.
        preg_match('#<section class="b-annstrip".*?</section>#s', $html, $strip);
        $this->assertStringContainsString('Strip headline', $strip[0]);
        $this->assertStringContainsString('until '.now()->addDays(2)->format('j M'), $strip[0]);
        $this->assertStringNotContainsString('Other region headline', $html);
        $this->assertStringNotContainsString('Expired headline', $strip[0]);
    }

    public function test_dismissing_a_strip_item_hides_it_for_the_session_and_does_not_remember_it(): void
    {
        $reader = $this->reader();
        $post = $this->announcement($this->accraWest, null, ['title' => 'Dismiss from the strip']);

        Livewire::actingAs($reader)->test(AnnouncementStrip::class)
            ->assertSee('Dismiss from the strip')
            ->call('dismiss', $post->id)
            ->assertDontSee('Dismiss from the strip');

        $this->assertSame(0, BlogAnnouncementDismissal::query()->count());
        session()->flush();
        Livewire::actingAs($reader)->test(AnnouncementStrip::class)->assertSee('Dismiss from the strip');
    }

    public function test_the_strip_respects_a_dont_show_again(): void
    {
        $reader = $this->reader();
        $post = $this->announcement($this->accraWest, null, ['title' => 'Never again']);
        $this->service()->dismiss($reader, $post->id);
        session()->flush();

        Livewire::actingAs($reader)->test(AnnouncementStrip::class)->assertDontSee('Never again');
    }

    public function test_no_strip_when_nothing_is_active(): void
    {
        $this->actingAs($this->reader())->get(route('blog.home'))->assertOk()->assertDontSee('b-annstrip', false);
    }

    // ---------------------------------------------------------------- the rules (service)

    protected function save(BlogPost $post, array $extra, ?User $by = null): BlogPost
    {
        $by ??= $this->prOfficer();
        $this->actingAs($by);

        return app(BlogPostService::class)->update($post, $by, $extra + ['title' => $post->title, 'category' => $post->category]);
    }

    /** @return array<string, list<string>> */
    protected function errorsOf(callable $callback): array
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Expected a ValidationException.');
    }

    public function test_the_last_day_is_required_when_ticked(): void
    {
        $post = $this->article($this->accraWest);

        $this->assertArrayHasKey('announce_until', $this->errorsOf(fn () => $this->save($post, ['announce' => true])));
        $this->assertArrayHasKey('announce_until', $this->errorsOf(fn () => $this->save($post, ['announce' => true, 'announce_until' => ''])));
        $this->assertArrayHasKey('announce_until', $this->errorsOf(fn () => $this->save($post, ['announce' => true, 'announce_until' => 'next friday'])));
        $this->assertNull($post->fresh()->announce_until);
    }

    public function test_the_last_day_cannot_be_before_today_but_today_is_fine(): void
    {
        $post = $this->article($this->accraWest);

        $errors = $this->errorsOf(fn () => $this->save($post, ['announce' => true, 'announce_until' => now()->subDay()->toDateString()]));
        $this->assertSame('The last day cannot be in the past.', $errors['announce_until'][0]);

        $saved = $this->save($post, ['announce' => true, 'announce_until' => now()->toDateString()]);
        $this->assertSame(now()->toDateString(), $saved->announce_until->toDateString());
    }

    public function test_the_first_day_cannot_come_after_the_last_day(): void
    {
        $post = $this->article($this->accraWest);

        $errors = $this->errorsOf(fn () => $this->save($post, ['announce' => true, 'announce_from' => now()->addDays(5)->toDateString(), 'announce_until' => now()->addDays(2)->toDateString()]));
        $this->assertArrayHasKey('announce_from', $errors);

        $saved = $this->save($post, ['announce' => true, 'announce_from' => now()->addDay()->toDateString(), 'announce_until' => now()->addDays(2)->toDateString()]);
        $this->assertSame(now()->addDay()->toDateString(), $saved->announce_from->toDateString());
        $this->assertSame('scheduled', $saved->announcementState());
    }

    public function test_an_unpublished_article_cannot_be_an_announcement(): void
    {
        $draft = $this->draft($this->accraWest);

        $errors = $this->errorsOf(fn () => $this->save($draft, ['announce' => true, 'announce_until' => now()->addDays(2)->toDateString()]));

        $this->assertStringContainsString('Publish the article first', $errors['announce'][0]);
        $this->assertNull($draft->fresh()->announce_until);
    }

    public function test_a_new_article_can_be_an_announcement_only_when_it_is_published_by_the_same_call(): void
    {
        $officer = $this->prOfficer();
        $this->actingAs($officer);
        $data = ['region_id' => $this->accraWest->id, 'title' => 'New notice', 'category' => 'announcement', 'body_json' => $this->paragraphsDoc('Hello'), 'announce' => true, 'announce_until' => now()->addDays(4)->toDateString()];

        $this->assertArrayHasKey('announce', $this->errorsOf(fn () => app(BlogPostService::class)->create($officer, $data)));
        $this->assertSame(0, BlogPost::query()->count(), 'nothing was created');

        $post = app(BlogPostService::class)->create($officer, $data, null, true);

        $this->assertTrue($post->isPublished());
        $this->assertSame('active', $post->announcementState());
        $this->assertCount(1, $this->active($this->reader()));
    }

    public function test_ticking_then_unticking_sets_and_clears_both_days(): void
    {
        $post = $this->article($this->accraWest);

        $set = $this->save($post, ['announce' => true, 'announce_from' => now()->toDateString(), 'announce_until' => now()->addDays(2)->toDateString()]);
        $this->assertNotNull($set->announce_from);
        $this->assertNotNull($set->announce_until);

        $cleared = $this->save($post->fresh(), ['announce' => false]);
        $this->assertNull($cleared->announce_from);
        $this->assertNull($cleared->announce_until);
    }

    public function test_a_save_that_does_not_mention_the_announcement_leaves_it_alone(): void
    {
        $post = $this->announcement($this->accraWest, now()->addDays(5)->toDateString());

        $saved = $this->save($post, ['title' => 'A better headline']);

        $this->assertSame('A better headline', $saved->title);
        $this->assertSame(now()->addDays(5)->toDateString(), $saved->announce_until->toDateString());
    }

    public function test_an_expired_announcement_can_still_be_saved_unchanged_but_not_moved_to_another_past_day(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $post = $this->announcement($this->accraWest, $yesterday);
        $this->assertSame('expired', $post->announcementState());

        // The form sends the dates it loaded: that must not stop the officer saving anything else.
        $saved = $this->save($post, ['title' => 'Typo fixed', 'announce' => true, 'announce_until' => $yesterday]);
        $this->assertSame('Typo fixed', $saved->title);
        $this->assertSame($yesterday, $saved->announce_until->toDateString());

        // Picking a different past day is refused; a future day revives it.
        $this->assertArrayHasKey('announce_until', $this->errorsOf(fn () => $this->save($post->fresh(), ['announce' => true, 'announce_until' => now()->subDays(3)->toDateString()])));
        $this->assertSame('active', $this->save($post->fresh(), ['announce' => true, 'announce_until' => now()->addDays(3)->toDateString()])->announcementState());
    }

    public function test_only_a_writer_of_that_region_can_change_an_announcement(): void
    {
        $post = $this->announcement($this->accraWest);

        foreach ([$this->reader(), $this->prOfficer('300009', $this->ashanti)] as $stranger) {
            try {
                $this->save($post, ['announce' => false], $stranger);
                $this->fail('A person who may not manage this article changed it.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->assertNotNull($post->fresh()->announce_until);
    }

    public function test_unpublishing_ends_the_announcement_and_publishing_again_does_not_bring_it_back(): void
    {
        $officer = $this->prOfficer();
        $this->actingAs($officer);
        $post = $this->announcement($this->accraWest);
        $service = app(BlogPostService::class);

        $service->unpublish($post, $officer);
        $post->refresh();

        $this->assertNull($post->announce_until);
        $this->assertNull($post->announce_from);
        $this->assertCount(0, $this->active($this->reader()));

        $row = AuditLog::query()->where('action', 'blog_post_unpublished')->where('target_id', $post->id)->latest('id')->firstOrFail();
        $this->assertTrue($row->metadata['announcement_ended']);

        $service->publish($post, $officer);
        $this->assertCount(0, $this->active($this->reader()));
    }

    public function test_deleting_the_article_ends_it_and_removes_its_dismissals(): void
    {
        $officer = $this->prOfficer();
        $this->actingAs($officer);
        $reader = $this->reader();
        $post = $this->announcement($this->accraWest);
        $this->service()->dismiss($reader, $post->id);
        $this->assertSame(1, BlogAnnouncementDismissal::query()->count());

        app(BlogPostService::class)->delete($post, $officer);

        $this->assertCount(0, $this->active($this->reader('100002')));
        $this->assertSame(0, BlogAnnouncementDismissal::query()->count());
    }

    public function test_ending_an_announcement_keeps_the_article_published_and_is_audited(): void
    {
        $officer = $this->prOfficer();
        $this->actingAs($officer);
        $post = $this->announcement($this->accraWest);

        app(BlogPostService::class)->endAnnouncement($post, $officer);
        $post->refresh();

        $this->assertTrue($post->isPublished());
        $this->assertNull($post->announce_until);
        $this->assertCount(0, $this->active($this->reader()));
        $row = AuditLog::query()->where('action', 'blog_announcement_ended')->where('target_id', $post->id)->firstOrFail();
        $this->assertSame('active', $row->metadata['was']);

        // Ending something that is not an announcement writes nothing more.
        app(BlogPostService::class)->endAnnouncement($post, $officer);
        $this->assertSame(1, AuditLog::query()->where('action', 'blog_announcement_ended')->count());
    }

    public function test_setting_and_changing_an_announcement_is_audited_by_field_name_only(): void
    {
        $post = $this->article($this->accraWest);
        $until = now()->addDays(3)->toDateString();

        $this->save($post, ['announce' => true, 'announce_until' => $until]);

        $row = AuditLog::query()->where('action', 'blog_post_updated')->where('target_id', $post->id)->latest('id')->firstOrFail();
        $this->assertContains('announce_until', $row->metadata['fields']);
    }

    public function test_restoring_a_version_leaves_the_announcement_alone(): void
    {
        $officer = $this->prOfficer();
        $this->actingAs($officer);
        $service = app(BlogPostService::class);
        $post = $service->create($officer, ['region_id' => $this->accraWest->id, 'title' => 'Versioned', 'category' => 'announcement', 'body_json' => $this->paragraphsDoc('v1')], null, true);
        $this->save($post, ['body_json' => $this->paragraphsDoc('v2'), 'announce' => true, 'announce_until' => now()->addDays(4)->toDateString()]);
        $revision = $post->revisions()->orderBy('id')->first();

        $service->restoreRevision($post->fresh(), $officer, $revision->id);

        $this->assertSame('active', $post->fresh()->announcementState());
    }

    // ---------------------------------------------------------------- the form

    public function test_the_form_sets_the_announcement_of_a_published_article(): void
    {
        $post = $this->article($this->accraWest);
        $until = now()->addDays(6)->toDateString();

        Livewire::actingAs($this->prOfficer())->test(PostForm::class, ['postId' => $post->id])
            ->assertSee('Show as announcement at login')
            ->set('announce', true)
            ->assertSee('Show until')
            ->set('announceUntil', $until)
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame($until, $post->fresh()->announce_until->toDateString());
    }

    public function test_the_form_needs_a_last_day_when_ticked_and_shows_service_refusals_on_the_field(): void
    {
        $post = $this->article($this->accraWest);
        $form = Livewire::actingAs($this->prOfficer())->test(PostForm::class, ['postId' => $post->id])->set('announce', true);

        $form->call('saveDraft')->assertHasErrors(['announceUntil' => 'required']);

        $form->set('announceUntil', now()->subDays(2)->toDateString())->call('saveDraft')->assertHasErrors('announceUntil');
        $this->assertNull($post->fresh()->announce_until);

        $form->set('announceUntil', now()->addDays(2)->toDateString())->set('announceFrom', now()->addDays(4)->toDateString())->call('saveDraft')->assertHasErrors('announceFrom');
    }

    public function test_the_form_offers_the_box_disabled_on_a_draft(): void
    {
        $draft = $this->draft($this->accraWest);

        Livewire::actingAs($this->prOfficer())->test(PostForm::class, ['postId' => $draft->id])
            ->assertSee('Publish the article first, then come back here')
            ->assertSeeHtml('wire:model.live="announce" disabled')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertNull($draft->fresh()->announce_until);
    }

    public function test_an_autosave_of_a_draft_never_touches_the_announcement_columns(): void
    {
        $draft = $this->draft($this->accraWest);

        Livewire::actingAs($this->prOfficer())->test(PostForm::class, ['postId' => $draft->id])
            ->set('bodyJson', json_encode($this->paragraphsDoc('Typing')))
            ->call('autosave')
            ->assertHasNoErrors();

        $this->assertNull($draft->fresh()->announce_until);
    }

    public function test_an_expired_announcement_is_flagged_in_the_form_and_saves_unchanged(): void
    {
        $post = $this->announcement($this->accraWest, now()->subDays(2)->toDateString());

        $form = Livewire::actingAs($this->prOfficer())->test(PostForm::class, ['postId' => $post->id])
            ->assertSet('announce', true)
            ->assertSee('This announcement has ended');

        $form->set('title', 'Retitled')->call('saveDraft')->assertHasNoErrors();
        $this->assertSame('Retitled', $post->fresh()->title);
    }

    // ---------------------------------------------------------------- the manage list

    public function test_the_manage_list_shows_the_announcement_state_and_an_end_now_action(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));
        $active = $this->announcement($this->accraWest, '2026-11-30', ['title' => 'Active one']);
        $scheduled = $this->announcement($this->accraWest, '2026-12-10', ['title' => 'Scheduled one', 'announce_from' => '2026-12-01']);
        $expired = $this->announcement($this->accraWest, '2026-11-10', ['title' => 'Expired one']);
        $plain = $this->article($this->accraWest, ['title' => 'Plain one']);

        $page = Livewire::actingAs($this->prOfficer())->test(ManagePosts::class);

        $page->assertSee('Announcement until 30 Nov')
            ->assertSee('Announcement from 1 Dec to 10 Dec')
            ->assertSee('Expired')
            ->assertSeeHtml('wire:click="endAnnouncement('.$active->id.')"')
            ->assertSeeHtml('wire:click="endAnnouncement('.$scheduled->id.')"')
            ->assertDontSeeHtml('wire:click="endAnnouncement('.$expired->id.')"')
            ->assertDontSeeHtml('wire:click="endAnnouncement('.$plain->id.')"');
    }

    public function test_end_announcement_now_from_the_manage_list(): void
    {
        $post = $this->announcement($this->accraWest, now()->addDays(4)->toDateString());
        $officer = $this->prOfficer();

        Livewire::actingAs($officer)->test(ManagePosts::class)
            ->call('endAnnouncement', $post->id)
            ->assertSet('notice', 'The announcement has ended. The article is still published.')
            ->assertDontSeeHtml('wire:click="endAnnouncement('.$post->id.')"');

        $this->assertNull($post->fresh()->announce_until);
        $this->assertTrue($post->fresh()->isPublished());
        $this->assertCount(0, $this->active($this->reader()));
    }

    public function test_another_regions_announcement_cannot_be_ended_from_the_manage_list(): void
    {
        $theirs = $this->announcement($this->ashanti);

        Livewire::actingAs($this->prOfficer())->test(ManagePosts::class)->call('endAnnouncement', $theirs->id)->assertNotFound();

        $this->assertNotNull($theirs->fresh()->announce_until);
    }

    // ---------------------------------------------------------------- the schema

    public function test_the_migration_adds_the_columns_and_indexes_and_can_be_rolled_back(): void
    {
        $this->assertTrue(\Schema::hasColumns('blog_posts', ['announce_from', 'announce_until']));
        $this->assertTrue(\Schema::hasIndex('blog_posts', 'blog_posts_unread_index'));
        $this->assertTrue(\Schema::hasIndex('blog_posts', 'blog_posts_announce_index'));
        $this->assertTrue(\Schema::hasTable('blog_post_reads'));
        $this->assertTrue(\Schema::hasTable('blog_announcement_dismissals'));

        $migration = require database_path('migrations/2026_11_26_000003_add_announcements_and_unread_index_to_blog_posts_table.php');
        $migration->up(); // running it again changes nothing
        $migration->down();

        $this->assertFalse(\Schema::hasColumn('blog_posts', 'announce_until'));
        $this->assertFalse(\Schema::hasIndex('blog_posts', 'blog_posts_unread_index'));

        $migration->up();
        $this->assertTrue(\Schema::hasColumns('blog_posts', ['announce_from', 'announce_until']));
    }

    public function test_a_read_and_a_dismissal_are_unique_per_person_and_article(): void
    {
        $reader = $this->reader();
        $post = $this->announcement($this->accraWest);

        DB::table('blog_post_reads')->insert(['user_id' => $reader->id, 'blog_post_id' => $post->id, 'read_at' => now()]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('blog_post_reads')->insert(['user_id' => $reader->id, 'blog_post_id' => $post->id, 'read_at' => now()]);
    }
}
