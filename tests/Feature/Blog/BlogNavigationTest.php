<?php

namespace Tests\Feature\Blog;

use App\Models\BlogPost;
use App\Models\Permission;
use App\Models\User;
use App\Support\ErpNavigation;

/**
 * The blog is not a module tab any more: it is a link at the right of the ERP header that opens the standalone blog in a new
 * tab, and the blog itself has its own layout (no ERP sidebar or module tabs).
 */
class BlogNavigationTest extends BlogTestCase
{
    protected function navigation(?User $user = null): array
    {
        return app(ErpNavigation::class)->build($user ?? $this->reader(), 'home');
    }

    // ---------------------------------------------------------------- Part A: out of the module bar

    public function test_the_blog_is_no_longer_a_module_tab_or_a_module_sidebar(): void
    {
        foreach ([$this->reader(), $this->prOfficer(), $this->superAdmin()] as $user) {
            $navigation = $this->navigation($user);

            $this->assertNotContains('blog', collect($navigation['modules'])->pluck('slug')->all());
            $this->assertNotContains('Regional Blog', collect($navigation['modules'])->pluck('title')->all());
        }

        // Asking for the old module's sidebar gives nothing.
        $this->assertSame([], app(ErpNavigation::class)->build($this->prOfficer(), 'blog')['sidebar']);
    }

    public function test_the_module_access_the_permission_and_the_flag_are_unchanged(): void
    {
        $this->assertContains(Permission::MODULE_BLOG, $this->reader()->getAccessibleModules());
        $this->assertTrue($this->prOfficer()->hasPermission('blog.manage_posts'));
        $this->assertFalse($this->reader()->hasPermission('blog.manage_posts'));
        $this->assertTrue(config('gwl.blog_module_enabled'));
    }

    public function test_the_header_link_is_offered_to_everyone_who_may_enter_the_blog(): void
    {
        foreach ([$this->reader(), $this->prOfficer(), $this->superAdmin()] as $user) {
            $link = $this->navigation($user)['blog'];

            $this->assertSame(route('blog.home'), $link['url']);
            $this->assertSame('Blog', $link['label']);
        }
    }

    public function test_the_header_link_is_absent_without_module_access_and_when_the_flag_is_off(): void
    {
        // A login with no role at all enters no module.
        $nobody = $this->userWithoutEmployee('NOROLE1', []);
        $this->assertNull($this->navigation($nobody)['blog']);
        $this->assertNull(app(ErpNavigation::class)->build(null, 'home')['blog']);

        config(['gwl.blog_module_enabled' => false]);
        $this->assertNull($this->navigation($this->reader())['blog']);
    }

    public function test_the_erp_header_shows_the_link_on_the_right_and_it_opens_in_a_new_tab(): void
    {
        $html = $this->actingAs($this->reader())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a\s[^>]*href="'.preg_quote(route('blog.home'), '#').'"[^>]*target="_blank"[^>]*rel="noopener"[^>]*>#', $html, 'opens in a new tab, without an opener');
        $this->assertStringContainsString('aria-label="Regional Blog (opens in a new tab)"', $html, 'the icon-only form has an accessible name');

        // On a module page it sits in the right-hand cluster, after the module tabs and before the bell / account menu, and not among them.
        $html = $this->actingAs($this->reader())->get(route('leave.home'))->assertOk()->getContent();
        $modules = strpos($html, 'class="app-modules"');
        $link = strpos($html, 'class="icon-btn blog-link"');
        $account = strpos($html, 'class="user-menu"');
        $this->assertGreaterThan($modules, $link);
        $this->assertLessThan($account, $link);
        $this->assertStringNotContainsString('>Blog<', substr($html, $modules, strpos($html, '</nav>', $modules) - $modules), 'not in the module tabs');
    }

    public function test_the_erp_header_has_no_link_for_a_user_without_access_or_with_the_flag_off(): void
    {
        $this->actingAs($this->reader())->get(route('dashboard'))->assertOk()->assertSee('blog-link', false);

        config(['gwl.blog_module_enabled' => false]);
        $this->actingAs($this->reader())->get(route('dashboard'))->assertOk()->assertDontSee('blog-link', false);
    }

    public function test_it_is_in_the_jump_to_a_page_list(): void
    {
        $this->actingAs($this->reader())->get(route('dashboard'))->assertOk()->assertSee('Regional Blog (new tab)', false);
    }

    // ---------------------------------------------------------------- the blog shares the login but is not public

    public function test_a_guest_is_sent_to_the_login_and_returned_to_the_blog_afterwards(): void
    {
        $post = $this->article($this->accraWest);

        foreach ([route('blog.home'), $post->url(), route('blog.gallery'), route('blog.search', ['q' => 'x'])] as $url) {
            $this->get($url)->assertRedirect(route('login'));
            $this->assertSame($url, session('url.intended'), 'the login brings the person back here');
        }
    }

    public function test_a_login_with_no_module_access_cannot_open_the_blog(): void
    {
        $this->actingAs($this->userWithoutEmployee('NOROLE2', []))->get(route('blog.home'))->assertRedirect();
    }

    // ---------------------------------------------------------------- Part B: the standalone layout

    public function test_blog_pages_have_their_own_layout_without_the_erp_sidebar_or_module_tabs(): void
    {
        $post = $this->article($this->accraWest, ['title' => 'Layout check']);
        $reader = $this->reader();

        foreach ([route('blog.home'), $post->url(), route('blog.gallery'), route('blog.category', 'activity'), route('blog.tag', 'safety'), route('blog.search', ['q' => 'layout'])] as $url) {
            $html = $this->actingAs($reader)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('class="b-mast"', $html, $url);
            $this->assertStringNotContainsString('app-shell', $html, $url);
            $this->assertStringNotContainsString('app-side', $html, $url);
            $this->assertStringNotContainsString('app-modules', $html, $url);
            $this->assertStringNotContainsString('jump-trigger', $html, $url);
        }
    }

    public function test_the_masthead_names_the_region_and_links_back_to_the_erp(): void
    {
        $html = $this->actingAs($this->reader())->get(route('blog.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Accra West', $html);
        $this->assertStringContainsString('Regional Blog', $html);
        $this->assertStringContainsString('href="'.route('dashboard').'"', $html);
        $this->assertStringContainsString('Back to ERP', $html);
        $this->assertStringContainsString('role="search"', $html);
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('name="robots" content="noindex', $html);
    }

    public function test_the_category_strip_lists_every_kind_and_the_photo_gallery(): void
    {
        $html = $this->actingAs($this->reader())->get(route('blog.home'))->getContent();

        foreach (['Activities', 'Meetings', 'Workshops', 'Training', 'Announcements', 'Other', 'Photo Gallery'] as $label) {
            $this->assertStringContainsString('>'.$label.'</a>', $html, $label);
        }

        foreach (array_keys(BlogPost::CATEGORIES) as $slug) {
            $this->assertStringContainsString('href="'.route('blog.category', $slug).'"', $html);
        }

        $this->assertStringContainsString('aria-current="page"', $html, 'the current section is marked');
    }

    public function test_the_manage_link_is_only_for_writers(): void
    {
        $this->actingAs($this->reader())->get(route('blog.home'))->assertDontSee(route('blog.manage'), false);
        $this->actingAs($this->prOfficer())->get(route('blog.home'))->assertSee(route('blog.manage'), false);
        $this->actingAs($this->superAdmin())->get(route('blog.home'))->assertSee(route('blog.manage'), false);
    }

    public function test_the_writers_screens_use_the_same_layout_and_load_the_editor_only_there(): void
    {
        $officer = $this->prOfficer();
        $post = $this->article($this->accraWest);

        $manage = $this->actingAs($officer)->get(route('blog.manage'))->assertOk()->getContent();
        $create = $this->actingAs($officer)->get(route('blog.create'))->assertOk()->getContent();
        $edit = $this->actingAs($officer)->get(route('blog.edit', $post))->assertOk()->getContent();
        $home = $this->actingAs($officer)->get(route('blog.home'))->getContent();
        $article = $this->actingAs($officer)->get($post->url())->getContent();

        foreach ([$manage, $create, $edit] as $html) {
            $this->assertStringContainsString('class="b-mast"', $html);
            $this->assertStringNotContainsString('app-side', $html);
        }

        $this->assertStringContainsString('blog-editor', $create);
        $this->assertStringContainsString('blog-editor', $edit);
        $this->assertStringNotContainsString('blog-editor', $manage, 'the editor bundle is not loaded on the list');
        $this->assertStringNotContainsString('blog-editor', $home);
        $this->assertStringNotContainsString('blog-editor', $article);
        $this->assertStringContainsString('blog-lightbox', $article, 'the lightbox loads on article pages');
        $this->assertStringNotContainsString('blog-lightbox', $home, 'but not on the front page');
    }

    public function test_ordinary_staff_cannot_open_the_writers_screens(): void
    {
        $this->actingAs($this->reader())->get(route('blog.manage'))->assertForbidden();
        $this->actingAs($this->reader())->get(route('blog.create'))->assertForbidden();
    }
}
