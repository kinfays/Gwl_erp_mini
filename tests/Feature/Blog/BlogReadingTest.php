<?php

namespace Tests\Feature\Blog;

use App\Livewire\Blog\Feed;
use App\Models\Permission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class BlogReadingTest extends BlogTestCase
{
    public function test_every_role_may_enter_the_blog(): void
    {
        foreach (['employee', 'manager', 'hr_headoffice', 'hr_region', 'secretary', 'driver', 'ict_team', 'admin', 'receptionist', 'managing_director', 'transport_manager', 'district_manager', 'regional_chief_manager', 'pr_officer'] as $index => $role) {
            $user = $this->userWithRoles('10010'.$index, [$role]);

            $this->assertContains(Permission::MODULE_BLOG, $user->getAccessibleModules(), "{$role} should reach the blog");
            $this->actingAs($user)->get(route('blog.home'))->assertOk();
        }
    }

    public function test_staff_read_only_the_published_articles_of_their_own_region(): void
    {
        $mine = $this->article($this->accraWest, ['title' => 'Customer care workshop at Sowutuom']);
        $other = $this->article($this->ashanti, ['title' => 'Ashanti quarterly review meeting']);
        $draft = $this->draft($this->accraWest, ['title' => 'Unfinished Accra draft']);

        Livewire::actingAs($this->reader())
            ->test(Feed::class)
            ->assertSee($mine->title)
            ->assertDontSee($other->title)
            ->assertDontSee($draft->title);
    }

    public function test_staff_in_another_region_see_theirs_and_not_the_first_regions(): void
    {
        $accra = $this->article($this->accraWest, ['title' => 'Accra West safety training']);
        $kumasi = $this->article($this->ashanti, ['title' => 'Kumasi stakeholder meeting']);

        Livewire::actingAs($this->reader('100002', $this->ashanti))
            ->test(Feed::class)
            ->assertSee($kumasi->title)
            ->assertDontSee($accra->title);
    }

    public function test_head_office_staff_and_managers_get_no_exception(): void
    {
        $accra = $this->article($this->accraWest, ['title' => 'Accra West open day']);
        $kumasi = $this->article($this->ashanti, ['title' => 'Ashanti district visit']);

        foreach ([['hr_headoffice', $this->headOffice], ['regional_chief_manager', $this->sowutuom], ['admin', $this->headOffice], ['managing_director', $this->headOffice]] as $index => [$role, $district]) {
            $user = $this->userWithRoles('20010'.$index, [$role], $this->accraWest, $district);

            Livewire::actingAs($user)
                ->test(Feed::class)
                ->assertSee($accra->title)
                ->assertDontSee($kumasi->title);
        }
    }

    public function test_an_article_of_another_region_is_not_found_by_its_address(): void
    {
        $other = $this->article($this->ashanti, ['cover_path' => 'blog/covers/x.jpg']);
        Storage::disk('local')->put('blog/covers/x.jpg', 'fake');

        $reader = $this->reader();

        $this->actingAs($reader)->get(route('blog.show', $other))->assertNotFound();
        $this->actingAs($reader)->get(route('blog.cover', $other))->assertNotFound();
    }

    public function test_a_draft_is_not_found_by_readers_even_in_their_own_region(): void
    {
        $draft = $this->draft($this->accraWest);

        $this->actingAs($this->reader())->get(route('blog.show', $draft))->assertNotFound();
    }

    public function test_an_article_of_the_readers_region_opens_and_shows_its_details(): void
    {
        $post = $this->article($this->accraWest, [
            'title' => 'Training on the new billing screens',
            'category' => 'training',
            'venue' => 'Regional conference room',
            'district_id' => $this->odorkor->id,
            'event_date' => '2026-10-05',
            'tags' => ['billing', 'training'],
            'body' => "First paragraph.\n\n- one\n- two",
        ]);

        $this->actingAs($this->reader())
            ->get(route('blog.show', $post))
            ->assertOk()
            ->assertSee('Training on the new billing screens')
            ->assertSee('Training')
            ->assertSee('Regional conference room')
            ->assertSee('Odorkor')
            ->assertSee('05 Oct 2026')
            ->assertSee('First paragraph.')
            ->assertSee('<li>one</li>', false);
    }

    public function test_the_cover_photo_is_served_to_the_region_only_through_the_route(): void
    {
        $file = UploadedFile::fake()->image('cover.jpg', 800, 450);
        $path = Storage::disk('local')->putFile('blog/covers', $file);
        $post = $this->article($this->accraWest, ['cover_path' => $path]);

        $this->actingAs($this->reader())->get(route('blog.cover', $post))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->reader('100003', $this->ashanti))->get(route('blog.cover', $post))->assertNotFound();
    }

    public function test_a_login_with_no_employee_record_sees_nothing(): void
    {
        $this->article($this->accraWest, ['title' => 'Should stay hidden']);
        $user = $this->userWithoutEmployee('100090', ['employee']);

        Livewire::actingAs($user)
            ->test(Feed::class)
            ->assertSee('not linked to one yet')
            ->assertDontSee('Should stay hidden');

        $post = $this->article($this->accraWest);
        $this->actingAs($user)->get(route('blog.show', $post))->assertNotFound();
    }

    public function test_super_admin_reads_every_region_and_can_narrow_to_one(): void
    {
        $accra = $this->article($this->accraWest, ['title' => 'Accra West durbar']);
        $kumasi = $this->article($this->ashanti, ['title' => 'Ashanti durbar']);

        Livewire::actingAs($this->superAdmin())
            ->test(Feed::class)
            ->assertSee($accra->title)
            ->assertSee($kumasi->title)
            ->set('regionId', (string) $this->ashanti->id)
            ->assertSee($kumasi->title)
            ->assertDontSee($accra->title);
    }

    public function test_a_region_filter_does_nothing_for_an_ordinary_reader(): void
    {
        $accra = $this->article($this->accraWest, ['title' => 'Accra West visit']);
        $kumasi = $this->article($this->ashanti, ['title' => 'Ashanti visit']);

        Livewire::actingAs($this->reader())
            ->test(Feed::class)
            ->set('regionId', (string) $this->ashanti->id)
            ->assertSee($accra->title)
            ->assertDontSee($kumasi->title);
    }

    public function test_pinned_articles_come_first_then_the_newest(): void
    {
        $this->article($this->accraWest, ['title' => 'Oldest pinned notice', 'is_pinned' => true, 'published_at' => now()->subDays(10)]);
        $this->article($this->accraWest, ['title' => 'Middle article', 'published_at' => now()->subDays(2)]);
        $this->article($this->accraWest, ['title' => 'Newest article', 'published_at' => now()->subDay()]);

        Livewire::actingAs($this->reader())
            ->test(Feed::class)
            ->assertSeeInOrder(['Oldest pinned notice', 'Newest article', 'Middle article']);
    }

    public function test_the_feed_filters_by_kind_and_by_search_term_including_tags_and_venue(): void
    {
        $this->article($this->accraWest, ['title' => 'Quarterly staff meeting', 'category' => 'meeting']);
        $this->article($this->accraWest, ['title' => 'Meter reading workshop', 'category' => 'workshop', 'tags' => ['meters'], 'venue' => 'Odorkor depot']);

        Livewire::actingAs($this->reader())
            ->test(Feed::class)
            ->set('category', 'meeting')
            ->assertSee('Quarterly staff meeting')
            ->assertDontSee('Meter reading workshop')
            ->set('category', '')
            ->set('search', 'meters')
            ->assertSee('Meter reading workshop')
            ->assertDontSee('Quarterly staff meeting')
            ->set('search', 'depot')
            ->assertSee('Meter reading workshop');
    }

    public function test_the_body_is_rendered_without_raw_html_or_unsafe_links(): void
    {
        $post = $this->article($this->accraWest, [
            'body' => "Hello <script>alert('x')</script> **bold**\n\n[click me](javascript:alert(1))\n\n<img src=x onerror=alert(2)>",
        ]);

        // The whole page carries scripts of its own, so the article's HTML is checked on its own.
        $html = $post->bodyHtml()->toHtml();

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror', $html);

        $this->actingAs($this->reader())->get(route('blog.show', $post))->assertOk()->assertSee('<strong>bold</strong>', false);
    }

    public function test_the_blog_is_hidden_when_the_module_is_switched_off(): void
    {
        config(['gwl.blog_module_enabled' => false]);

        $this->assertNotContains(
            'blog',
            collect(app(\App\Support\ErpNavigation::class)->build($this->reader(), 'home')['modules'])->pluck('slug')->all()
        );
    }

    public function test_the_blog_tab_and_sidebar_show_for_readers_and_the_manage_links_only_for_writers(): void
    {
        $navigation = app(\App\Support\ErpNavigation::class);

        $reader = $navigation->build($this->reader(), 'blog');
        $this->assertContains('blog', collect($reader['modules'])->pluck('slug')->all());
        $this->assertSame(['blog.home'], collect($reader['sidebar'])->pluck('route')->all());

        $officer = $navigation->build($this->prOfficer(), 'blog');
        $this->assertSame(['blog.home', 'blog.manage', 'blog.create'], collect($officer['sidebar'])->pluck('route')->all());
    }
}
