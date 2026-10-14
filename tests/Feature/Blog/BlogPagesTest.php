<?php

namespace Tests\Feature\Blog;

use App\Models\BlogMedia;
use App\Models\BlogPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The reader pages of the standalone blog: front page, lists reached through real URLs, the article page and the photo gallery.
 * Whatever a page shows is limited to the published articles of the reader's own region.
 */
class BlogPagesTest extends BlogTestCase
{
    protected int $days = 0;

    /** A published article of the region, dated a little earlier each time it is made. */
    protected function story(string $title, array $attributes = [], $region = null): BlogPost
    {
        return $this->article($region ?? $this->accraWest, [
            'title' => $title,
            'slug' => Str::slug($title),
            'body_text' => 'Text of '.$title,
            'published_at' => now()->subDays(++$this->days),
            ...$attributes,
        ]);
    }

    protected function picture($post, bool $inUse = true, ?string $caption = null): BlogMedia
    {
        $variant = fn (int $w) => ['path' => "blog/media/p-{$w}.jpg", 'webp' => "blog/media/p-{$w}.webp", 'w' => $w, 'h' => (int) ($w * 2 / 3)];

        return BlogMedia::query()->create([
            'region_id' => $post->region_id,
            'blog_post_id' => $post->id,
            'kind' => 'inline',
            'mime' => 'image/jpeg',
            'width' => 1600,
            'height' => 1067,
            'bytes' => 1000,
            'path' => 'blog/media/p-original.jpg',
            'variants' => ['thumb' => $variant(400), 'medium' => $variant(900), 'large' => $variant(1600)],
            'caption' => $caption,
            'in_use' => $inUse,
        ]);
    }

    // ---------------------------------------------------------------- front page

    public function test_the_front_page_leads_with_the_pinned_story_then_the_newest_and_never_repeats_a_story(): void
    {
        $oldPinned = $this->story('Pinned notice', ['is_pinned' => true, 'published_at' => now()->subDays(30)]);
        $newest = $this->story('Newest story');
        $second = $this->story('Second story');
        $third = $this->story('Third story');
        $older = $this->story('Older story');
        $other = $this->story('Ashanti story', [], $this->ashanti);

        $response = $this->actingAs($this->reader())->get(route('blog.home'))->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('#<article class="b-lead[^"]*">.*?Pinned notice#s', $html, 'the pinned story is the lead');
        $response->assertViewHas('lead', fn ($lead) => $lead->is($oldPinned));
        $response->assertViewHas('secondary', fn ($secondary) => $secondary->pluck('id')->all() === [$newest->id, $second->id, $third->id]);
        $response->assertViewHas('stream', fn ($stream) => collect($stream->items())->pluck('id')->all() === [$older->id], 'the stream holds only what is not above it');
        $response->assertDontSee('Ashanti story');
    }

    public function test_a_reader_never_sees_drafts_or_another_regions_stories_anywhere_on_the_front_page(): void
    {
        $this->story('Visible story', ['venue' => 'Odorkor depot', 'event_date' => now()->addDays(3)->toDateString(), 'tags' => ['safety']]);
        $this->story('Ashanti story', ['tags' => ['ashanti-only'], 'event_date' => now()->addDays(2)->toDateString(), 'is_pinned' => true], $this->ashanti);
        $this->draft($this->accraWest, ['title' => 'Secret draft', 'tags' => ['draft-tag'], 'event_date' => now()->addDay()->toDateString(), 'is_pinned' => true]);

        $this->actingAs($this->reader())->get(route('blog.home'))->assertOk()
            ->assertSee('Visible story')
            ->assertDontSee('Ashanti story')->assertDontSee('ashanti-only')
            ->assertDontSee('Secret draft')->assertDontSee('draft-tag');
    }

    public function test_a_login_with_no_region_sees_a_warning_and_no_articles(): void
    {
        $this->story('Some story');

        $this->actingAs($this->userWithoutEmployee('NOREG1', ['employee']))->get(route('blog.home'))->assertOk()
            ->assertSee('No region on your account')->assertDontSee('Some story');
    }

    public function test_a_region_with_nothing_published_shows_an_empty_state(): void
    {
        $this->actingAs($this->reader())->get(route('blog.home'))->assertOk()->assertSee('Nothing published yet');
    }

    public function test_super_admin_sees_every_region_and_can_narrow_to_one(): void
    {
        $this->story('Accra story');
        $this->story('Ashanti story', [], $this->ashanti);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('blog.home'))->assertOk()->assertSee('Accra story')->assertSee('Ashanti story')->assertSee('All regions');
        $this->actingAs($admin)->get(route('blog.home', ['region' => $this->ashanti->id]))->assertOk()->assertDontSee('Accra story')->assertSee('Ashanti story');

        // The region in the address means nothing to an ordinary reader.
        $this->actingAs($this->reader())->get(route('blog.home', ['region' => $this->ashanti->id]))->assertOk()->assertSee('Accra story')->assertDontSee('Ashanti story');
    }

    public function test_the_stream_is_paginated_and_the_links_keep_the_filters(): void
    {
        foreach (range(1, 16) as $n) {
            $this->story("Workshop story {$n}", ['category' => 'workshop']);
        }

        $reader = $this->reader();
        $page = $this->actingAs($reader)->get(route('blog.category', ['category' => 'workshop']))->assertOk();

        $page->assertViewHas('stream', fn ($stream) => $stream->total() === 16 && $stream->count() === 10);
        $page->assertSee('page=2', false);
        $this->actingAs($reader)->get(route('blog.category', ['category' => 'workshop', 'page' => 2]))->assertOk()->assertSee('Page 2 of 2');
    }

    // ---------------------------------------------------------------- lists through real URLs

    public function test_the_category_tag_district_and_search_pages_filter_inside_the_region(): void
    {
        $this->story('Leak training', ['category' => 'training', 'tags' => ['safety'], 'district_id' => $this->odorkor->id, 'summary' => 'Hands-on pipe repair', 'venue' => 'Odorkor depot']);
        $this->story('Open day', ['category' => 'activity', 'tags' => ['safety-first'], 'district_id' => $this->sowutuom->id, 'body_text' => 'Customers visited the kiosk']);
        $this->story('Ashanti training', ['category' => 'training', 'tags' => ['safety']], $this->ashanti);
        $reader = $this->reader();

        $this->actingAs($reader)->get(route('blog.category', 'training'))->assertOk()->assertSee('Leak training')->assertDontSee('Open day')->assertDontSee('Ashanti training');
        $this->actingAs($reader)->get(route('blog.tag', 'safety'))->assertOk()->assertSee('Leak training')->assertDontSee('Open day', false)->assertDontSee('Ashanti training');
        $this->actingAs($reader)->get(route('blog.district', $this->sowutuom->id))->assertOk()->assertSee('Open day')->assertDontSee('Leak training');

        foreach (['pipe repair' => 'Leak training', 'kiosk' => 'Open day', 'odorkor depot' => 'Leak training', 'leak' => 'Leak training'] as $term => $found) {
            $this->actingAs($reader)->get(route('blog.search', ['q' => $term]))->assertOk()->assertSee($found)->assertDontSee('Ashanti training');
        }

        $this->actingAs($reader)->get(route('blog.search', ['q' => 'nothing matches this']))->assertOk()->assertSee('No articles found');
    }

    public function test_an_unknown_section_or_another_regions_district_is_a_404(): void
    {
        $this->actingAs($this->reader())->get('/blog/category/nonsense')->assertNotFound();
        $this->actingAs($this->reader())->get(route('blog.district', $this->kumasi->id))->assertNotFound();
        $this->actingAs($this->reader())->get(route('blog.district', 999999))->assertNotFound();
    }

    public function test_search_input_is_escaped_on_the_page(): void
    {
        $this->actingAs($this->reader())->get(route('blog.search', ['q' => '"><script>alert(1)</script>']))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ---------------------------------------------------------------- sidebar

    public function test_upcoming_events_are_future_only_soonest_first_with_date_venue_and_district(): void
    {
        $this->story('Past event', ['event_date' => now()->subDays(2)->toDateString()]);
        $later = $this->story('Later event', ['event_date' => now()->addDays(20)->toDateString(), 'venue' => 'Regional hall', 'district_id' => $this->odorkor->id]);
        $soon = $this->story('Soon event', ['event_date' => now()->addDays(4)->toDateString()]);
        $this->story('Foreign event', ['event_date' => now()->addDays(1)->toDateString()], $this->ashanti);
        $this->draft($this->accraWest, ['title' => 'Draft event', 'event_date' => now()->addDays(2)->toDateString()]);

        $response = $this->actingAs($this->reader())->get(route('blog.home'))->assertOk();

        $response->assertViewHas('sidebar', fn ($sidebar) => $sidebar['events']->pluck('id')->all() === [$soon->id, $later->id]);
        $response->assertSee('Upcoming events')->assertSee('Regional hall')->assertSee('Odorkor');
        $response->assertDontSee('Foreign event')->assertDontSee('Draft event');
    }

    public function test_the_sidebar_lists_this_regions_districts_and_tags_only(): void
    {
        $this->story('One', ['district_id' => $this->odorkor->id, 'tags' => ['alpha', 'beta']]);
        $this->story('Two', ['district_id' => $this->odorkor->id, 'tags' => ['alpha']]);
        $this->story('Foreign', ['district_id' => $this->kumasi->id, 'tags' => ['zulu']], $this->ashanti);

        $response = $this->actingAs($this->reader())->get(route('blog.home'))->assertOk();

        $response->assertViewHas('sidebar', function ($sidebar) {
            return $sidebar['districts']->pluck('name')->all() === ['Odorkor']
                && $sidebar['districts']->first()->total === 2
                && $sidebar['tags']->keys()->all() === ['alpha', 'beta'];
        });
        $response->assertDontSee('Kumasi')->assertDontSee('zulu');
    }

    // ---------------------------------------------------------------- the article page

    public function test_the_article_page_shows_headline_standfirst_byline_event_box_and_tags(): void
    {
        $author = $this->prOfficer('300077');
        $post = $this->story('Customer care week', [
            'category' => 'workshop',
            'summary' => 'Five days of practice at the counter.',
            'author_user_id' => $author->id,
            'district_id' => $this->odorkor->id,
            'venue' => 'Regional conference room',
            'event_date' => '2026-11-03',
            'tags' => ['customer-care'],
            'reading_minutes' => 4,
            'body_html' => '<p>Day one began early.</p>',
        ]);

        $html = $this->actingAs($this->reader())->get($post->url())->assertOk()->getContent();

        $this->assertStringContainsString('<h1>Customer care week</h1>', $html);
        $this->assertStringContainsString('Five days of practice at the counter.', $html);
        $this->assertStringContainsString($author->full_name, $html);
        $this->assertStringContainsString('4 min read', $html);
        $this->assertStringContainsString('Event details', $html);
        $this->assertStringContainsString('Tuesday, 3 November 2026', $html);
        $this->assertStringContainsString('Regional conference room', $html);
        $this->assertStringContainsString('<p>Day one began early.</p>', $html);
        $this->assertStringContainsString('href="'.route('blog.tag', 'customer-care').'"', $html);
        $this->assertStringContainsString('<article class="b-article">', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('Copy link', $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString('@media print', file_get_contents(resource_path('css/blog.css')), 'a print stylesheet exists');
    }

    public function test_the_address_is_found_by_id_whatever_the_slug_says(): void
    {
        $post = $this->story('Real title');

        $this->actingAs($this->reader())->get('/blog/posts/'.$post->id)->assertOk()->assertSee('Real title');
        $this->actingAs($this->reader())->get('/blog/posts/'.$post->id.'/some-old-slug')->assertOk()->assertSee('Real title');
        $this->assertStringContainsString('/blog/posts/'.$post->id.'/real-title', $post->url());
    }

    public function test_related_articles_neighbours_and_more_from_the_district_stay_inside_the_region(): void
    {
        $post = $this->story('Middle story', ['tags' => ['water'], 'category' => 'training', 'district_id' => $this->odorkor->id, 'published_at' => now()->subDays(10)]);
        $newer = $this->story('Newer story', ['tags' => ['water'], 'published_at' => now()->subDays(5)]);
        $older = $this->story('Older story', ['tags' => ['water'], 'district_id' => $this->odorkor->id, 'published_at' => now()->subDays(20)]);
        $foreignNewer = $this->story('Foreign newer', ['tags' => ['water'], 'category' => 'training', 'district_id' => $this->kumasi->id, 'published_at' => now()->subDays(8)], $this->ashanti);
        $this->draft($this->accraWest, ['title' => 'Hidden draft', 'tags' => ['water'], 'published_at' => now()->subDays(9)]);

        $response = $this->actingAs($this->reader())->get($post->url())->assertOk();

        $response->assertViewHas('related', fn ($related) => $related->pluck('id')->sort()->values()->all() === collect([$newer->id, $older->id])->sort()->values()->all());
        $response->assertViewHas('neighbours', fn ($n) => $n['older']->is($older) && $n['newer']->is($newer));
        $response->assertViewHas('fromDistrict', fn ($more) => $more->pluck('id')->all() === [$older->id]);
        $response->assertSee('More from Odorkor')->assertDontSee('Foreign newer')->assertDontSee('Hidden draft');
    }

    public function test_a_draft_article_page_is_for_the_regions_writers_only(): void
    {
        $draft = $this->draft($this->accraWest, ['title' => 'Work in progress', 'body_html' => '<p>unfinished</p>']);

        $this->actingAs($this->reader())->get($draft->url())->assertNotFound();
        $this->actingAs($this->prOfficer())->get($draft->url())->assertOk()->assertSee('Work in progress')->assertSee('Draft: only your region');
        $this->actingAs($this->prOfficer('300055', $this->ashanti))->get($draft->url())->assertNotFound();
    }

    public function test_the_body_that_is_printed_is_the_stored_html_and_nothing_is_rendered_on_the_way(): void
    {
        $post = $this->story('Stored html', ['body_format' => 'rich', 'body' => 'ignored markdown **x**', 'body_html' => '<p>exactly this</p>']);

        $html = $this->actingAs($this->reader())->get($post->url())->getContent();

        $this->assertStringContainsString('<div class="bm-prose" data-lightbox-group><p>exactly this</p></div>', $html);
        $this->assertStringNotContainsString('<strong>x</strong>', $html);
    }

    // ---------------------------------------------------------------- the photo gallery

    public function test_the_gallery_groups_pictures_of_published_articles_in_use_of_the_readers_region(): void
    {
        $shown = $this->story('Open day photos');
        $hiddenDraft = $this->draft($this->accraWest, ['title' => 'Draft photos']);
        $foreign = $this->story('Ashanti photos', [], $this->ashanti);
        $a = $this->picture($shown, true, 'Crowd at the gate');
        $b = $this->picture($shown, true);
        $removed = $this->picture($shown, false);
        $this->picture($hiddenDraft);
        $this->picture($foreign);

        $response = $this->actingAs($this->reader())->get(route('blog.gallery'))->assertOk();

        $response->assertSee('Open day photos')->assertDontSee('Draft photos')->assertDontSee('Ashanti photos');
        $response->assertViewHas('albums', fn ($albums) => $albums->count() === 1 && $albums->first()->gallery->pluck('id')->all() === [$a->id, $b->id]);
        $response->assertSee('/blog/media/'.$a->id.'/large', false)->assertDontSee('/blog/media/'.$removed->id.'/', false);
        $response->assertSee('data-lightbox', false)->assertSee('Crowd at the gate');
        $response->assertSee('2 photos');
    }

    public function test_an_empty_gallery_says_so(): void
    {
        $this->actingAs($this->reader())->get(route('blog.gallery'))->assertOk()->assertSee('No photos yet');
    }

    // ---------------------------------------------------------------- cost

    public function test_the_front_page_costs_the_same_number_of_queries_whatever_the_number_of_stories(): void
    {
        $reader = $this->reader();
        $count = function () use ($reader): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($reader)->get(route('blog.home'))->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 6) as $n) {
            $this->story("Early {$n}", ['district_id' => $this->odorkor->id, 'author_user_id' => $this->prOfficer()->id, 'tags' => ['t'.$n]]);
        }

        $count(); // warm-up: the first request pays for per-user permission lookups once
        $few = $count();

        foreach (range(1, 12) as $n) {
            $this->story("Later {$n}", ['district_id' => $this->sowutuom->id, 'author_user_id' => $this->prOfficer()->id, 'tags' => ['u'.$n]]);
        }

        $this->assertSame($few, $count(), 'no query per story');
    }
}
