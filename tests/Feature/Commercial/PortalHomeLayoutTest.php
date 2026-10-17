<?php

namespace Tests\Feature\Commercial;

/**
 * The portal home is a page of module cards with no sidebar and no module tabs; inside a module the header shows the
 * module tabs and the sidebar menu is that module's own.
 */
class PortalHomeLayoutTest extends CommercialTestCase
{
    public function test_home_lists_every_module_the_user_can_open_including_the_newer_ones(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertOk()->getContent();

        foreach ([route('commercial.home'), route('health_safety.home'), route('gwater.home'), route('transport.home'), route('uac.index')] as $url) {
            $this->assertStringContainsString('href="'.$url.'" class="dashboard-card"', $html, $url);
        }

        config(['gwl.gwater_module_enabled' => false]);
        $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertOk()
            ->assertDontSee('href="'.route('gwater.home').'" class="dashboard-card"', false);
    }

    public function test_home_has_no_sidebar_and_no_module_tabs(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="app-sidebar"', $html);
        $this->assertStringNotContainsString('app-menu-btn', $html);
        $this->assertStringNotContainsString('app-modules', $html);
        $this->assertStringNotContainsString('side-modules', $html);
    }

    public function test_a_module_page_has_the_module_tabs_and_a_sidebar_with_only_its_own_menu(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('transport.home'))->assertOk()->getContent();

        // The header tabs list the user's modules, and the current one is marked.
        $tabs = substr($html, strpos($html, 'class="app-modules"'), strpos($html, '</nav>', strpos($html, 'class="app-modules"')) - strpos($html, 'class="app-modules"'));
        foreach ([route('leave.home'), route('commercial.home'), route('gwater.home'), route('transport.home')] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $tabs, $url);
        }
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('transport.home'), '#').'"[^>]*aria-current="page"#', $tabs);

        // The sidebar menu itself is that module's own.
        $this->assertStringContainsString('aria-label="Transport"', $html);
        $menu = substr($html, strpos($html, 'class="side-nav"'), strpos($html, '</nav>', strpos($html, 'class="side-nav"')) - strpos($html, 'class="side-nav"'));
        $this->assertStringContainsString('>Vehicles<', $menu);
        $this->assertStringNotContainsString(route('commercial.home'), $menu);
        $this->assertStringNotContainsString(route('leave.home'), $menu);
    }

    public function test_the_profile_page_keeps_a_way_back_home_but_its_menu_lists_no_modules(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('profile.edit'))->assertOk()->getContent();

        $menu = substr($html, strpos($html, 'class="side-nav"'), strpos($html, '</nav>', strpos($html, 'class="side-nav"')) - strpos($html, 'class="side-nav"'));
        $this->assertStringContainsString(route('dashboard'), $menu);
        $this->assertStringNotContainsString(route('commercial.home'), $menu);
    }
}
