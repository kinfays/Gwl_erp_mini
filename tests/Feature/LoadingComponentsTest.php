<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * The water loaders: <x-loading.drop-fill> (B, page/panel level) and <x-loading.drops> (D, small/inline).
 * Both must announce themselves to assistive tech and keep their decorative parts out of its way.
 */
class LoadingComponentsTest extends TestCase
{
    protected function render(string $template): string
    {
        return Blade::render($template, ['errors' => new ViewErrorBag()]);
    }

    public function test_drop_fill_has_a_status_role_and_a_hidden_label(): void
    {
        $html = $this->render('<x-loading.drop-fill />');

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('>Loading</span>', $html);
        $this->assertStringContainsString('sr-only-text', $html);
        $this->assertMatchesRegularExpression('/<svg[^>]*viewBox="0 0 64 80"[^>]*aria-hidden="true"/', $html);
        $this->assertStringContainsString('wl-fill-md', $html);
    }

    public function test_drop_fill_takes_a_custom_label_and_size(): void
    {
        $html = $this->render('<x-loading.drop-fill size="lg" label="Importing the file" />');

        $this->assertStringContainsString('>Importing the file</span>', $html);
        $this->assertStringContainsString('wl-fill-lg', $html);
        $this->assertStringContainsString('role="status"', $html);
    }

    public function test_drop_fill_overlay_shows_its_label_as_a_visible_caption(): void
    {
        $html = $this->render('<x-loading.drop-fill overlay label="Working…" />');

        $this->assertStringContainsString('wl-overlay', $html);
        $this->assertStringContainsString('class="wl-caption">Working…</span>', $html);
        $this->assertStringContainsString('role="status"', $html);
    }

    public function test_every_drop_fill_gets_its_own_clip_path_id(): void
    {
        $html = $this->render('<x-loading.drop-fill /><x-loading.drop-fill /><x-loading.drop-fill />');

        preg_match_all('/<clipPath id="([^"]+)"/', $html, $defined);
        preg_match_all('/clip-path="url\(#([^)]+)\)"/', $html, $used);

        $this->assertCount(3, $defined[1]);
        $this->assertCount(3, array_unique($defined[1]), 'clipPath ids must be unique per instance');
        $this->assertSame($defined[1], $used[1], 'each drop clips with its own id');
    }

    public function test_drops_have_a_status_role_and_a_hidden_label(): void
    {
        $html = $this->render('<x-loading.drops />');

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('>Loading</span>', $html);
        $this->assertStringContainsString('wl-drops-md', $html);
        $this->assertSame(3, substr_count($html, 'class="wl-drop" aria-hidden="true"'));
    }

    public function test_drops_take_a_size_and_a_label_and_pass_attributes_through(): void
    {
        $html = $this->render('<x-loading.drops size="sm" label="Saving" class="wl-in-btn" data-probe="1" />');

        $this->assertStringContainsString('wl-drops-sm', $html);
        $this->assertStringContainsString('wl-in-btn', $html);
        $this->assertStringContainsString('data-probe="1"', $html);
        $this->assertStringContainsString('>Saving</span>', $html);
    }

    public function test_decorative_drops_are_hidden_from_assistive_tech(): void
    {
        $html = $this->render('<x-loading.drops size="sm" decorative />');

        $this->assertStringNotContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringNotContainsString('>Loading</span>', $html);
    }

    public function test_an_unknown_size_falls_back_to_medium(): void
    {
        $this->assertStringContainsString('wl-drops-md', $this->render('<x-loading.drops size="huge" />'));
        $this->assertStringContainsString('wl-fill-md', $this->render('<x-loading.drop-fill size="huge" />'));
    }

    public function test_the_loading_button_swaps_its_spinner_for_drops_that_keep_the_button_width(): void
    {
        $html = $this->render('<x-ui.button wire:click="save" loading="save">Save</x-ui.button>');

        $this->assertStringContainsString('wl-drops-sm', $html);
        $this->assertStringContainsString('wl-in-btn', $html);
        $this->assertStringContainsString('wire:loading.class="is-loading"', $html);
        $this->assertStringNotContainsString('btn-spinner', $html);

        $plain = $this->render('<x-ui.button wire:click="save">Save</x-ui.button>');
        $this->assertStringNotContainsString('wl-drops', $plain);
    }
}
