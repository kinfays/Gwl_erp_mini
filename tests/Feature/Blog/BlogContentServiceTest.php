<?php

namespace Tests\Feature\Blog;

use App\Models\BlogMedia;
use App\Models\Region;
use App\Services\Blog\BlogContentService;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;

class BlogContentServiceTest extends BlogTestCase
{
    protected BlogContentService $content;

    protected function setUp(): void
    {
        parent::setUp();

        $this->content = app(BlogContentService::class);
    }

    // ---------------------------------------------------------------- helpers

    protected function doc(array ...$blocks): array
    {
        return ['type' => 'doc', 'content' => $blocks];
    }

    protected function p(string $text, array $marks = []): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text] + ($marks ? ['marks' => $marks] : [])]];
    }

    protected function render(array $document, ?Region $region = null, ?int $postId = null): array
    {
        return $this->content->process($document, ($region ?? $this->accraWest)->id, $postId);
    }

    protected function mediaRow(?Region $region = null, array $attributes = []): BlogMedia
    {
        $region ??= $this->accraWest;
        $variant = fn (int $w) => ['path' => "blog/media/x-{$w}.jpg", 'webp' => "blog/media/x-{$w}.webp", 'w' => $w, 'h' => (int) ($w * 2 / 3)];

        return BlogMedia::query()->create([
            'region_id' => $region->id,
            'kind' => BlogMedia::KIND_INLINE,
            'mime' => 'image/jpeg',
            'width' => 1600,
            'height' => 1067,
            'bytes' => 1000,
            'path' => 'blog/media/x-original.jpg',
            'variants' => ['thumb' => $variant(400), 'medium' => $variant(900), 'large' => $variant(1600)],
            ...$attributes,
        ]);
    }

    // ---------------------------------------------------------------- hostile input

    public function test_markup_typed_as_text_is_shown_as_text_never_run(): void
    {
        $result = $this->render($this->doc($this->p('<script>alert(1)</script> and <img src=x onerror=alert(2)>')));

        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('<img', $result['html']);
        $this->assertStringContainsString('&lt;script&gt;', $result['html']);
        $this->assertStringContainsString('alert(1)', $result['text']);
    }

    public function test_unsafe_links_are_dropped_and_safe_ones_keep_noopener(): void
    {
        $html = $this->render($this->doc(
            $this->p('js', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]),
            $this->p('data', [['type' => 'link', 'attrs' => ['href' => 'data:text/html,<script>alert(1)</script>']]]),
            $this->p('relative', [['type' => 'link', 'attrs' => ['href' => '/admin']]]),
            $this->p('plain http', [['type' => 'link', 'attrs' => ['href' => 'http://example.org']]]),
            $this->p('spaced', [['type' => 'link', 'attrs' => ['href' => 'java script:alert(1)']]]),
            $this->p('ok', [['type' => 'link', 'attrs' => ['href' => 'https://example.org/page?a=1&b=2', 'target' => '_blank']]]),
            $this->p('mail', [['type' => 'link', 'attrs' => ['href' => 'mailto:pr@example.org']]]),
            $this->p('phone', [['type' => 'link', 'attrs' => ['href' => 'tel:+233200000000']]]),
        ))['html'];

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
        $this->assertStringNotContainsString('href="/admin"', $html);
        $this->assertStringNotContainsString('http://example.org', $html);
        $this->assertSame(3, substr_count($html, '<a '), 'only https, mailto and tel links survive');
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        // The sanitiser writes some characters of an attribute as numeric entities (= as &#61;); a browser reads them back.
        $this->assertStringContainsString('href="https://example.org/page?a=1&b=2"', html_entity_decode($html));
    }

    public function test_attributes_the_editor_never_produces_are_ignored(): void
    {
        $document = $this->doc(
            ['type' => 'heading', 'attrs' => ['level' => 2, 'onclick' => 'alert(1)', 'style' => 'position:fixed', 'class' => 'x'], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'a', 'onmouseover' => 'alert(1)', 'textAlign' => 'center; background:url(x)'], 'content' => [['type' => 'text', 'text' => 'Body']]],
            ['type' => 'image', 'attrs' => ['src' => 'https://evil.example/pixel.png']],
            ['type' => 'iframe', 'attrs' => ['src' => 'https://evil.example/']],
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
        );

        $html = $this->render($document)['html'];

        $this->assertSame('<h2>Title</h2><p>Body</p>', $html);
    }

    public function test_a_figure_cannot_carry_an_external_or_data_image_and_its_text_is_escaped(): void
    {
        $media = $this->mediaRow();

        $html = $this->render($this->doc(['type' => 'blogFigure', 'attrs' => [
            'mediaId' => $media->id,
            'src' => 'https://evil.example/pixel.png',
            'alt' => '"><img src=x onerror=alert(1)>',
            'caption' => '<b onclick=alert(1)>cap</b>',
            'credit' => 'Me',
            'align' => 'center" onclick="x',
            'size' => 'huge',
        ]]))['html'];

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('onerror=alert', str_replace('&quot;', '"', preg_replace('/alt="[^"]*"/', '', $html)));
        $this->assertStringContainsString('src="/blog/media/'.$media->id.'/medium', $html);
        $this->assertStringContainsString('bm-align-center', $html);
        $this->assertStringContainsString('bm-size-inline', $html, 'an unknown size falls back to the default');
        $this->assertStringContainsString('width="900"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringNotContainsString('<b onclick', $html);
    }

    public function test_a_picture_that_is_missing_or_from_another_region_is_dropped(): void
    {
        $other = $this->mediaRow($this->ashanti);

        $result = $this->render($this->doc(
            ['type' => 'blogFigure', 'attrs' => ['mediaId' => $other->id, 'alt' => 'x']],
            ['type' => 'blogFigure', 'attrs' => ['mediaId' => 99999, 'alt' => 'x']],
            $this->p('kept'),
        ));

        $this->assertStringNotContainsString('<figure', $result['html']);
        $this->assertSame(2, $result['dropped']);
        $this->assertSame([], $result['media_ids']);
    }

    public function test_a_picture_already_in_another_article_cannot_be_borrowed(): void
    {
        $post = $this->article($this->accraWest);
        $other = $this->article($this->accraWest);
        $media = $this->mediaRow(null, ['blog_post_id' => $other->id]);

        $this->assertSame(1, $this->render($this->doc(['type' => 'blogFigure', 'attrs' => ['mediaId' => $media->id]]), null, $post->id)['dropped']);
        $this->assertSame(0, $this->render($this->doc(['type' => 'blogFigure', 'attrs' => ['mediaId' => $media->id]]), null, $other->id)['dropped']);
    }

    public function test_pictures_galleries_and_videos_are_kept_only_as_blocks_of_the_page(): void
    {
        $media = $this->mediaRow();
        $figure = ['type' => 'blogFigure', 'attrs' => ['mediaId' => $media->id, 'alt' => 'x']];
        $video = ['type' => 'blogVideo', 'attrs' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']];

        $result = $this->render($this->doc(
            ['type' => 'blockquote', 'content' => [$figure, $this->p('inside a quote')]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$video]]]],
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [$figure, $video, $this->p('cell text')]]]]]],
            $figure,
        ));

        $this->assertSame(1, substr_count($result['html'], '<figure'), 'only the top-level picture is kept');
        $this->assertStringNotContainsString('<iframe', $result['html']);
        $this->assertStringContainsString('inside a quote', $result['html']);
        $this->assertStringContainsString('cell text', $result['html']);
        $this->assertSame(4, $result['dropped'], 'the four nested ones are reported as left out');
        $this->assertStringNotContainsString('blogFigure', json_encode($result['json']['content'][0]), 'and are not stored');
        $this->assertStringNotContainsString('blogVideo', json_encode($result['json']));
    }

    public function test_videos_come_only_from_the_allowlisted_hosts_by_id(): void
    {
        $ok = $this->render($this->doc(
            ['type' => 'blogVideo', 'attrs' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'A <b>talk</b>']],
            ['type' => 'blogVideo', 'attrs' => ['url' => 'https://vimeo.com/123456789']],
        ))['html'];

        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $ok);
        $this->assertStringContainsString('src="https://player.vimeo.com/video/123456789?dnt=1"', $ok);
        $this->assertStringNotContainsString('<b>talk</b>', $ok);

        foreach (['https://evil.example/embed/dQw4w9WgXcQ', 'http://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v="><script>', 'javascript:alert(1)', 'https://vimeo.com/abc'] as $url) {
            $this->assertStringNotContainsString('<iframe', $this->render($this->doc(['type' => 'blogVideo', 'attrs' => ['url' => $url]]))['html'], $url);
        }

        $this->assertStringNotContainsString('<iframe', $this->render($this->doc(['type' => 'blogVideo', 'attrs' => ['provider' => 'youtube', 'videoId' => '"><script>x']]))['html']);
    }

    public function test_deep_or_huge_documents_are_cut_off_without_failing(): void
    {
        $node = $this->p('deep');

        for ($i = 0; $i < 60; $i++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        $result = $this->render($this->doc($node));

        $this->assertStringNotContainsString('deep', $result['html']);

        $many = array_fill(0, 8000, $this->p('x'));
        $paragraphs = substr_count($this->render(['type' => 'doc', 'content' => $many])['html'], '<p>');
        $this->assertGreaterThan(0, $paragraphs);
        $this->assertLessThanOrEqual(BlogContentService::MAX_NODES / 2, $paragraphs, 'a paragraph and its text are two nodes each');
    }

    public function test_garbage_documents_become_an_empty_one_or_a_clear_error(): void
    {
        $this->assertTrue($this->render(['type' => 'nonsense'])['empty']);
        $this->assertTrue($this->content->process(null, $this->accraWest->id, null)['empty']);

        $this->expectException(ValidationException::class);
        $this->content->process('{not json', $this->accraWest->id, null);
    }

    // ---------------------------------------------------------------- the sanitiser itself

    public function test_the_allowlist_sanitiser_removes_active_content(): void
    {
        $dirty = '<p onclick="x()" style="color:red">Hi<script>alert(1)</script></p>'
            .'<iframe src="https://evil.example/"></iframe>'
            .'<a href="javascript:alert(1)">a</a><a href="data:text/html;base64,AAAA">b</a><a href="https://ok.example" onmouseover="x()">c</a>'
            .'<img src="x" onerror="alert(1)"><style>body{display:none}</style><svg onload="alert(1)"></svg><form action="x"><input></form>'
            .'<b><i>bad nesting</b></i><table><td>cell';

        $clean = $this->content->sanitize($dirty);

        foreach (['<script', 'onclick', 'onerror', 'onload', 'onmouseover', 'style=', '<style', '<iframe', '<svg', '<form', '<input', 'javascript:', 'data:text'] as $needle) {
            $this->assertStringNotContainsString($needle, $clean, $needle);
        }

        $this->assertStringContainsString('https://ok.example', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
        $this->assertStringContainsString('cell', $clean);
    }

    public function test_the_last_check_refuses_a_block_that_points_anywhere_but_the_media_route(): void
    {
        $isSafe = new ReflectionMethod(BlogContentService::class, 'isSafe');

        $good = '<figure><a href="/blog/media/4/large?v=1" data-lightbox><img src="/blog/media/4/medium?v=1" srcset="/blog/media/4/thumb?v=1 400w, /blog/media/4/large?v=1 1600w" alt="a onload=b"></a></figure><p>Send the data: now, onclick= is fine as text</p>';
        $this->assertTrue($isSafe->invoke($this->content, $good));

        foreach ([
            '<img src="https://evil.example/p.png">',
            '<img src="data:image/gif;base64,R0lGOD">',
            '<img src="/blog/media/4/medium" srcset="https://evil.example/p.png 1x">',
            '<img src="/blog/media/4/original">',
            '<a href="javascript:alert(1)">x</a>',
            '<a href="/admin/users">x</a>',
            '<p onclick="x()">x</p>',
            '<p style="color:red">x</p>',
            '<script>x</script>',
            '<iframe src="https://evil.example"></iframe>',
        ] as $bad) {
            $this->assertFalse($isSafe->invoke($this->content, $bad), $bad);
        }
    }

    // ---------------------------------------------------------------- what survives

    public function test_the_formatting_the_editor_offers_survives(): void
    {
        $result = $this->render($this->doc(
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Two']]],
            ['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [['type' => 'text', 'text' => 'Four']]],
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'One becomes two']]],
            ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [
                ['type' => 'text', 'text' => 'all', 'marks' => [['type' => 'bold'], ['type' => 'italic'], ['type' => 'underline'], ['type' => 'strike'], ['type' => 'code']]],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => '0'],
            ]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$this->p('a')]], ['type' => 'listItem', 'content' => [$this->p('b')]]]],
            ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [['type' => 'listItem', 'content' => [$this->p('c')]]]],
            ['type' => 'blockquote', 'attrs' => ['cite' => 'The Regional Chief Manager'], 'content' => [$this->p('Quote')]],
            ['type' => 'horizontalRule'],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [['type' => 'tableHeader', 'attrs' => ['colspan' => 2], 'content' => [$this->p('Head')]]]],
                ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [$this->p('Cell')]], ['type' => 'tableCell', 'content' => [$this->p('Two')]]]],
            ]],
        ));

        $html = $result['html'];

        $this->assertStringContainsString('<h2>Two</h2>', $html);
        $this->assertStringContainsString('<h4>Four</h4>', $html);
        $this->assertStringContainsString('<h2>One becomes two</h2>', $html);
        $this->assertStringContainsString('<p class="ta-center">', $html);
        $this->assertStringContainsString('<code>', $html);
        $this->assertStringContainsString('<br />', $html);
        $this->assertStringContainsString('>0</p>', $html, 'a text node of "0" is kept');
        $this->assertStringContainsString('<ul><li><p>a</p></li><li><p>b</p></li></ul>', $html);
        $this->assertStringContainsString('<ol start="3">', $html);
        $this->assertStringContainsString('<cite class="bm-cite">The Regional Chief Manager</cite>', $html);
        $this->assertStringContainsString('<hr />', $html);
        $this->assertStringContainsString('<th colspan="2">', $html);
        $this->assertStringContainsString('<div class="bm-table"><table>', $html);
    }

    public function test_figures_and_galleries_render_with_captions_alt_text_and_lightbox_hooks(): void
    {
        $a = $this->mediaRow();
        $b = $this->mediaRow(null, ['kind' => BlogMedia::KIND_GALLERY]);

        $html = $this->render($this->doc(
            ['type' => 'blogFigure', 'attrs' => ['mediaId' => $a->id, 'alt' => 'Staff at the workshop', 'caption' => 'Day one', 'credit' => 'PR team', 'align' => 'right', 'size' => 'wide']],
            ['type' => 'blogGallery', 'attrs' => ['layout' => 'mosaic', 'items' => [
                ['mediaId' => $a->id, 'alt' => 'First', 'caption' => 'One'],
                ['mediaId' => $b->id, 'alt' => '', 'caption' => ''],
                ['mediaId' => 777, 'alt' => 'gone'],
            ]]],
        ))['html'];

        $this->assertStringContainsString('class="bm-figure bm-align-right bm-size-wide"', $html);
        $this->assertStringContainsString('alt="Staff at the workshop"', $html);
        $this->assertStringContainsString('<figcaption>Day one <span class="bm-credit">PR team</span></figcaption>', $html);
        $this->assertStringContainsString('data-lightbox', $html);
        $this->assertStringContainsString('class="bm-gallery bm-gallery-mosaic" data-gallery data-count="2"', $html);
        $this->assertStringContainsString('<figcaption>One</figcaption>', $html);
        $this->assertStringContainsString('srcset="/blog/media/'.$a->id.'/thumb?v=', html_entity_decode($html));
        $this->assertSame(3, substr_count($html, '<figure'), 'one figure plus two gallery items');
    }

    public function test_text_reading_time_and_picture_ids_come_out_with_the_html(): void
    {
        $a = $this->mediaRow();
        $words = implode(' ', array_fill(0, 450, 'word'));

        $result = $this->render($this->doc(
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'A heading']]],
            $this->p($words),
            ['type' => 'blogFigure', 'attrs' => ['mediaId' => $a->id, 'alt' => 'x', 'caption' => 'A caption']],
        ));

        $this->assertStringStartsWith('A heading word word', $result['text']);
        $this->assertStringContainsString('A caption', $result['text']);
        $this->assertSame(3, $result['reading_minutes']);
        $this->assertSame([$a->id], $result['media_ids']);
        $this->assertFalse($result['empty']);

        $this->assertSame(1, $this->render($this->doc($this->p('Short')))['reading_minutes']);
    }

    public function test_the_cleaned_document_is_what_gets_stored_not_the_one_the_browser_sent(): void
    {
        $result = $this->render($this->doc(
            ['type' => 'paragraph', 'attrs' => ['onclick' => 'x'], 'evil' => true, 'content' => [['type' => 'text', 'text' => 'Hi', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:x']]]]]],
        ));

        $this->assertSame(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi']]]]], $result['json']);
    }

    public function test_markdown_articles_still_render_the_old_way(): void
    {
        $html = $this->content->markdownToHtml("## Title\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1)) and **bold**");

        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }
}
