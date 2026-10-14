<?php

namespace App\Services\Blog;

use App\Models\BlogMedia;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Turns what the editor sends into what readers get, and is the only place that does it.
 *
 * The browser sends an editor document (ProseMirror JSON). It is NEVER stored or printed as it arrives:
 *  1. clean()    walks it against an allowlist of nodes, marks and attributes (anything else is dropped), checks every
 *                picture reference against the region's own blog_media rows and every link against https / mailto / tel;
 *  2. render()   builds the HTML from that cleaned document, escaping every text and attribute;
 *  3. sanitize() runs the HTML through symfony/html-sanitizer with a strict allowlist as a second layer, and a last check
 *                refuses any block whose pictures or links point anywhere but the blog.media route or an allowed scheme.
 * body_html, body_text and the reading time are therefore always regenerated together from the same cleaned document, on
 * every save. Nothing on the reading side renders anything.
 *
 * Pictures are referenced by media id only. The editor never stores a picture address, and an external or data: source
 * cannot survive (there is no node that carries one).
 */
class BlogContentService
{
    public const MAX_DEPTH = 12;

    public const MAX_NODES = 6000;

    public const MAX_TEXT_CHARS = 200000;

    public const MAX_GALLERY_ITEMS = 40;

    /** Words per minute for the reading time. */
    public const WORDS_PER_MINUTE = 200;

    protected const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    protected const FIGURE_ALIGNMENTS = ['left', 'center', 'right'];

    protected const FIGURE_SIZES = ['inline', 'wide', 'full'];

    protected const GALLERY_LAYOUTS = ['grid', 'mosaic', 'carousel'];

    protected int $nodeCount = 0;

    /** @var array<int, BlogMedia> */
    protected array $media = [];

    protected int $dropped = 0;

    /** @var list<int> */
    protected array $usedMedia = [];

    public function emptyDocument(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    /**
     * @param  array<string, mixed>|string|null  $document  the editor document as the browser sent it
     * @return array{json: array<string, mixed>, html: string, text: string, reading_minutes: int, media_ids: list<int>, dropped: int, empty: bool}
     */
    public function process(array|string|null $document, int $regionId, ?int $postId): array
    {
        $document = $this->decode($document);

        $this->nodeCount = 0;
        $this->dropped = 0;
        $this->usedMedia = [];
        $this->media = $this->loadMedia($this->mediaIdsIn($document), $regionId, $postId);

        $blocks = [];

        foreach ((array) ($document['content'] ?? []) as $node) {
            if (is_array($node) && ($clean = $this->clean($node, 1)) !== null) {
                $blocks[] = $clean;
            }
        }

        $cleaned = ['type' => 'doc', 'content' => $blocks ?: [['type' => 'paragraph']]];

        $html = '';

        foreach ($cleaned['content'] as $block) {
            $html .= $this->renderTopLevel($block);
        }

        $text = $this->plainText($cleaned);
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        return [
            'json' => $cleaned,
            'html' => $html,
            'text' => $text,
            'reading_minutes' => max(1, (int) ceil($words / self::WORDS_PER_MINUTE)),
            'media_ids' => array_values(array_unique($this->usedMedia)),
            'dropped' => $this->dropped,
            'empty' => $text === '' && $this->usedMedia === [],
        ];
    }

    /** Markdown (the original article format) as safe HTML: raw HTML stripped, unsafe links refused. */
    public function markdownToHtml(string $markdown): string
    {
        return (string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /** Plain text of some HTML (used for the teaser and search of a Markdown article). */
    public function textOfHtml(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public function readingMinutes(string $text): int
    {
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    /** The ids of the pictures a stored or incoming document refers to. @return list<int> */
    public function mediaIdsIn(array $document): array
    {
        $ids = [];

        $walk = function (mixed $node) use (&$walk, &$ids): void {
            if (! is_array($node)) {
                return;
            }

            $type = $node['type'] ?? null;
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

            if ($type === 'blogFigure' && is_numeric($attrs['mediaId'] ?? null)) {
                $ids[] = (int) $attrs['mediaId'];
            }

            if ($type === 'blogGallery') {
                foreach ((array) ($attrs['items'] ?? []) as $item) {
                    if (is_array($item) && is_numeric($item['mediaId'] ?? null)) {
                        $ids[] = (int) $item['mediaId'];
                    }
                }
            }

            foreach ((array) ($node['content'] ?? []) as $child) {
                $walk($child);
            }
        };

        $walk($document);

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    // ------------------------------------------------------------------ input

    protected function decode(array|string|null $document): array
    {
        if (is_string($document)) {
            if ($document === '') {
                return $this->emptyDocument();
            }

            if (strlen($document) > 3_000_000) {
                throw ValidationException::withMessages(['body' => 'The article is too large.']);
            }

            $decoded = json_decode($document, true, 64);

            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['body' => 'The article could not be read. Reload the page and try again.']);
            }

            $document = $decoded;
        }

        if ($document === null || ($document['type'] ?? null) !== 'doc') {
            return $this->emptyDocument();
        }

        return $document;
    }

    /**
     * The picture rows an article may use: this region's inline or gallery pictures that are not yet in another article.
     *
     * @param  list<int>  $ids
     * @return array<int, BlogMedia>
     */
    protected function loadMedia(array $ids, int $regionId, ?int $postId): array
    {
        if ($ids === []) {
            return [];
        }

        return BlogMedia::query()
            ->whereIn('id', $ids)
            ->where('region_id', $regionId)
            ->whereIn('kind', [BlogMedia::KIND_INLINE, BlogMedia::KIND_GALLERY])
            ->where(fn ($query) => $query->whereNull('blog_post_id')->when($postId !== null, fn ($q) => $q->orWhere('blog_post_id', $postId)))
            ->get()
            ->keyBy('id')
            ->all();
    }

    // ------------------------------------------------------------------ step 1: clean

    /** @return array<string, mixed>|null */
    protected function clean(array $node, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH || ++$this->nodeCount > self::MAX_NODES) {
            return null;
        }

        $type = $node['type'] ?? null;
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        switch ($type) {
            case 'text':
                $text = Str::limit((string) ($node['text'] ?? ''), self::MAX_TEXT_CHARS, '');

                if ($text === '') {
                    return null;
                }

                $marks = $this->cleanMarks($node['marks'] ?? []);

                return ['type' => 'text', 'text' => $text] + ($marks !== [] ? ['marks' => $marks] : []);

            case 'paragraph':
                return $this->container('paragraph', $node, $depth, $this->alignAttr($attrs));

            case 'heading':
                $level = max(2, min(4, (int) ($attrs['level'] ?? 2)));

                return $this->container('heading', $node, $depth, ['level' => $level] + $this->alignAttr($attrs));

            case 'bulletList':
                return $this->container('bulletList', $node, $depth, [], ['listItem']);

            case 'orderedList':
                $start = max(1, min(9999, (int) ($attrs['start'] ?? 1)));

                return $this->container('orderedList', $node, $depth, $start > 1 ? ['start' => $start] : [], ['listItem']);

            case 'listItem':
                return $this->container('listItem', $node, $depth);

            case 'blockquote':
                $cite = trim(Str::limit(strip_tags((string) ($attrs['cite'] ?? '')), 120, ''));

                return $this->container('blockquote', $node, $depth, $cite !== '' ? ['cite' => $cite] : []);

            case 'horizontalRule':
            case 'hardBreak':
                return ['type' => $type];

            case 'table':
                return $this->container('table', $node, $depth, [], ['tableRow']);

            case 'tableRow':
                return $this->container('tableRow', $node, $depth, [], ['tableCell', 'tableHeader']);

            case 'tableCell':
            case 'tableHeader':
                return $this->container($type, $node, $depth, [
                    'colspan' => max(1, min(12, (int) ($attrs['colspan'] ?? 1))),
                    'rowspan' => max(1, min(12, (int) ($attrs['rowspan'] ?? 1))),
                ]);

                // Pictures, galleries and videos are blocks of the page itself. Inside a list, quotation or table cell they would not be
                // rendered (the renderer treats them at the top level only), so they are not kept either: what is stored is what shows.
            case 'blogFigure':
            case 'blogGallery':
            case 'blogVideo':
                if ($depth > 1) {
                    $this->dropped++;

                    return null;
                }

                return match ($type) {
                    'blogFigure' => $this->cleanFigure($attrs),
                    'blogGallery' => $this->cleanGallery($attrs),
                    default => $this->cleanVideo($attrs),
                };
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<string>|null  $only  the child types this node may hold (others are dropped)
     * @return array<string, mixed>
     */
    protected function container(string $type, array $node, int $depth, array $attrs = [], ?array $only = null): array
    {
        $children = [];

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }

            if ($only !== null && ! in_array($child['type'] ?? null, $only, true)) {
                continue;
            }

            if (($clean = $this->clean($child, $depth + 1)) !== null) {
                $children[] = $clean;
            }
        }

        $result = ['type' => $type];

        if ($attrs !== []) {
            $result['attrs'] = $attrs;
        }

        if ($children !== []) {
            $result['content'] = $children;
        }

        return $result;
    }

    /** @return array<string, string> */
    protected function alignAttr(array $attrs): array
    {
        $align = (string) ($attrs['textAlign'] ?? 'left');

        return in_array($align, self::ALIGNMENTS, true) && $align !== 'left' ? ['textAlign' => $align] : [];
    }

    /** @return list<array<string, mixed>> */
    protected function cleanMarks(mixed $marks): array
    {
        $clean = [];

        foreach ((array) $marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if (in_array($type, ['bold', 'italic', 'underline', 'strike', 'code'], true)) {
                $clean[$type] = ['type' => $type];
            } elseif ($type === 'link') {
                $href = $this->safeLink((string) ($mark['attrs']['href'] ?? ''));

                if ($href !== null) {
                    $clean['link'] = ['type' => 'link', 'attrs' => array_filter([
                        'href' => $href,
                        'target' => ($mark['attrs']['target'] ?? null) === '_blank' ? '_blank' : null,
                    ])];
                }
            }
        }

        return array_values($clean);
    }

    /** https, mailto and tel only; nothing with control characters; nothing relative (an editor link points out of the site). */
    public function safeLink(string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || strlen($href) > 500 || preg_match('/[\x00-\x20\x7f<>"\'`\\\\]/', $href)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if (! in_array($scheme, ['https', 'mailto', 'tel'], true)) {
            return null;
        }

        if ($scheme === 'https' && (string) parse_url($href, PHP_URL_HOST) === '') {
            return null;
        }

        return $href;
    }

    /** @return array<string, mixed>|null */
    protected function cleanFigure(array $attrs): ?array
    {
        $media = $this->media[(int) ($attrs['mediaId'] ?? 0)] ?? null;

        if ($media === null) {
            $this->dropped++;

            return null;
        }

        $this->usedMedia[] = $media->id;

        $align = (string) ($attrs['align'] ?? 'center');
        $size = (string) ($attrs['size'] ?? 'inline');

        return ['type' => 'blogFigure', 'attrs' => [
            'mediaId' => $media->id,
            'alt' => $this->short($attrs['alt'] ?? '', 300),
            'caption' => $this->short($attrs['caption'] ?? '', 300),
            'credit' => $this->short($attrs['credit'] ?? '', 120),
            'align' => in_array($align, self::FIGURE_ALIGNMENTS, true) ? $align : 'center',
            'size' => in_array($size, self::FIGURE_SIZES, true) ? $size : 'inline',
        ]];
    }

    /** @return array<string, mixed>|null */
    protected function cleanGallery(array $attrs): ?array
    {
        $items = [];

        foreach (array_slice((array) ($attrs['items'] ?? []), 0, self::MAX_GALLERY_ITEMS) as $item) {
            $media = is_array($item) ? ($this->media[(int) ($item['mediaId'] ?? 0)] ?? null) : null;

            if ($media === null) {
                $this->dropped++;

                continue;
            }

            $this->usedMedia[] = $media->id;
            $items[] = [
                'mediaId' => $media->id,
                'alt' => $this->short($item['alt'] ?? '', 300),
                'caption' => $this->short($item['caption'] ?? '', 300),
            ];
        }

        if ($items === []) {
            return null;
        }

        $layout = (string) ($attrs['layout'] ?? 'grid');

        return ['type' => 'blogGallery', 'attrs' => [
            'layout' => in_array($layout, self::GALLERY_LAYOUTS, true) ? $layout : 'grid',
            'items' => $items,
        ]];
    }

    /** @return array<string, mixed>|null */
    protected function cleanVideo(array $attrs): ?array
    {
        $video = null;

        if (filled($attrs['url'] ?? null)) {
            $video = $this->parseVideoUrl((string) $attrs['url']);
        } elseif (filled($attrs['provider'] ?? null) && filled($attrs['videoId'] ?? null)) {
            $video = $this->videoFrom((string) $attrs['provider'], (string) $attrs['videoId']);
        }

        if ($video === null) {
            $this->dropped++;

            return null;
        }

        return ['type' => 'blogVideo', 'attrs' => $video + ['caption' => $this->short($attrs['caption'] ?? '', 300)]];
    }

    /**
     * Only YouTube and Vimeo, and only by the video's own id: the embed address is built here, never taken from the author.
     *
     * @return array{provider: string, videoId: string}|null
     */
    public function parseVideoUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return null;
        }

        $host = preg_replace('/^(www\.|m\.)/', '', $host);

        if ($host === 'youtu.be') {
            return $this->videoFrom('youtube', trim($path, '/'));
        }

        if (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if (preg_match('#^/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})#', $path, $m)) {
                return $this->videoFrom('youtube', $m[1]);
            }

            parse_str((string) ($parts['query'] ?? ''), $query);

            return $path === '/watch' ? $this->videoFrom('youtube', (string) ($query['v'] ?? '')) : null;
        }

        if ($host === 'vimeo.com' && preg_match('#^/(\d{5,12})#', $path, $m)) {
            return $this->videoFrom('vimeo', $m[1]);
        }

        if ($host === 'player.vimeo.com' && preg_match('#^/video/(\d{5,12})#', $path, $m)) {
            return $this->videoFrom('vimeo', $m[1]);
        }

        return null;
    }

    /** @return array{provider: string, videoId: string}|null */
    protected function videoFrom(string $provider, string $id): ?array
    {
        if ($provider === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return ['provider' => 'youtube', 'videoId' => $id];
        }

        if ($provider === 'vimeo' && preg_match('/^\d{5,12}$/', $id)) {
            return ['provider' => 'vimeo', 'videoId' => $id];
        }

        return null;
    }

    protected function short(mixed $value, int $limit): string
    {
        return trim(Str::limit(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags((string) $value)) ?? '', $limit, ''));
    }

    // ------------------------------------------------------------------ step 2: render

    protected function renderTopLevel(array $block): string
    {
        // A video is built from a validated id and holds no author text outside its escaped caption, so it skips the
        // sanitizer (which would refuse the iframe); everything else is sanitised and then checked.
        if ($block['type'] === 'blogVideo') {
            return $this->renderVideo($block['attrs']);
        }

        $html = $this->sanitize($this->render($block));

        return $this->isSafe($html) ? $html : '';
    }

    protected function render(array $node): string
    {
        $attrs = $node['attrs'] ?? [];
        $children = fn () => implode('', array_map(fn (array $child) => $this->render($child), $node['content'] ?? []));

        return match ($node['type']) {
            'text' => $this->renderText($node),
            'paragraph' => '<p'.$this->alignClass($attrs).'>'.$children().'</p>',
            'heading' => '<h'.$attrs['level'].$this->alignClass($attrs).'>'.$children().'</h'.$attrs['level'].'>',
            'bulletList' => '<ul>'.$children().'</ul>',
            'orderedList' => '<ol'.(isset($attrs['start']) ? ' start="'.(int) $attrs['start'].'"' : '').'>'.$children().'</ol>',
            'listItem' => '<li>'.$children().'</li>',
            'blockquote' => '<blockquote>'.$children().(isset($attrs['cite']) ? '<cite class="bm-cite">'.e($attrs['cite']).'</cite>' : '').'</blockquote>',
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'table' => '<div class="bm-table"><table><tbody>'.$children().'</tbody></table></div>',
            'tableRow' => '<tr>'.$children().'</tr>',
            'tableCell' => '<td'.$this->cellAttrs($attrs).'>'.$children().'</td>',
            'tableHeader' => '<th'.$this->cellAttrs($attrs).'>'.$children().'</th>',
            'blogFigure' => $this->renderFigure($attrs),
            'blogGallery' => $this->renderGallery($attrs),
            default => '',
        };
    }

    protected function alignClass(array $attrs): string
    {
        return isset($attrs['textAlign']) ? ' class="ta-'.e($attrs['textAlign']).'"' : '';
    }

    protected function cellAttrs(array $attrs): string
    {
        return (($attrs['colspan'] ?? 1) > 1 ? ' colspan="'.(int) $attrs['colspan'].'"' : '')
            .(($attrs['rowspan'] ?? 1) > 1 ? ' rowspan="'.(int) $attrs['rowspan'].'"' : '');
    }

    protected function renderText(array $node): string
    {
        $html = e($node['text']);
        $marks = array_column($node['marks'] ?? [], null, 'type');

        foreach (['code' => 'code', 'bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's'] as $mark => $tag) {
            if (isset($marks[$mark])) {
                $html = "<{$tag}>{$html}</{$tag}>";
            }
        }

        if (isset($marks['link'])) {
            $link = $marks['link']['attrs'];
            $html = '<a href="'.e($link['href']).'"'.(($link['target'] ?? null) === '_blank' ? ' target="_blank"' : '').' rel="noopener noreferrer">'.$html.'</a>';
        }

        return $html;
    }

    protected function renderFigure(array $attrs): string
    {
        $media = $this->media[$attrs['mediaId']];
        [$w, $h] = $media->dimensions('medium');
        [$lw, $lh] = $media->dimensions('large');

        $sizes = match ($attrs['size']) {
            'full' => '100vw',
            'wide' => '(min-width: 1100px) 960px, 100vw',
            default => '(min-width: 800px) 680px, 100vw',
        };

        $image = '<img src="'.e($media->url('medium')).'" srcset="'.e($media->srcset()).'" sizes="'.e($sizes).'"'
            .' width="'.$w.'" height="'.$h.'" alt="'.e($attrs['alt']).'" loading="lazy" decoding="async">';

        $caption = $this->captionHtml($attrs['caption'], $attrs['credit']);

        return '<figure class="bm-figure bm-align-'.$attrs['align'].' bm-size-'.$attrs['size'].'">'
            .'<a href="'.e($media->url('large')).'" class="bm-lightbox" data-lightbox data-w="'.$lw.'" data-h="'.$lh.'" aria-label="'.e($attrs['alt'] !== '' ? 'View larger: '.$attrs['alt'] : 'View larger image').'">'.$image.'</a>'
            .($caption !== '' ? '<figcaption>'.$caption.'</figcaption>' : '')
            .'</figure>';
    }

    protected function renderGallery(array $attrs): string
    {
        $items = '';

        foreach ($attrs['items'] as $index => $item) {
            $media = $this->media[$item['mediaId']];
            [$w, $h] = $media->dimensions('medium');
            [$lw, $lh] = $media->dimensions('large');
            $alt = $item['alt'] !== '' ? $item['alt'] : '';

            $items .= '<figure class="bm-gallery-item">'
                .'<a href="'.e($media->url('large')).'" class="bm-lightbox" data-lightbox data-w="'.$lw.'" data-h="'.$lh.'" aria-label="'.e($alt !== '' ? 'View larger: '.$alt : 'View larger image').'">'
                .'<img src="'.e($media->url('medium')).'" srcset="'.e($media->srcset()).'" sizes="(min-width: 800px) 340px, 50vw"'
                .' width="'.$w.'" height="'.$h.'" alt="'.e($alt).'" loading="lazy" decoding="async"></a>'
                .($item['caption'] !== '' ? '<figcaption>'.e($item['caption']).'</figcaption>' : '')
                .'</figure>';
        }

        return '<div class="bm-gallery bm-gallery-'.$attrs['layout'].'" data-gallery data-count="'.count($attrs['items']).'">'.$items.'</div>';
    }

    protected function renderVideo(array $attrs): string
    {
        $src = $attrs['provider'] === 'youtube'
            ? 'https://www.youtube-nocookie.com/embed/'.$attrs['videoId']
            : 'https://player.vimeo.com/video/'.$attrs['videoId'].'?dnt=1';

        return '<figure class="bm-figure bm-video bm-size-wide"><div class="bm-video-frame">'
            .'<iframe src="'.e($src).'" title="'.e($attrs['caption'] !== '' ? $attrs['caption'] : 'Video').'" loading="lazy" allowfullscreen'
            .' referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"></iframe></div>'
            .($attrs['caption'] !== '' ? '<figcaption>'.e($attrs['caption']).'</figcaption>' : '')
            .'</figure>';
    }

    protected function captionHtml(string $caption, string $credit): string
    {
        return trim(($caption !== '' ? e($caption) : '').($credit !== '' ? ' <span class="bm-credit">'.e($credit).'</span>' : ''));
    }

    // ------------------------------------------------------------------ step 3: sanitise and check

    /** The allowlist sanitiser: exactly the elements and attributes the renderer above produces, and nothing else. */
    public function sanitize(string $html): string
    {
        return $this->sanitizer()->sanitize($html);
    }

    protected function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(3_000_000)
            ->allowLinkSchemes(['https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['https'])
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        foreach (['p', 'h2', 'h3', 'h4', 'ul', 'li', 'blockquote', 'hr', 'br', 'strong', 'em', 'u', 's', 'code', 'cite', 'table', 'tbody', 'thead', 'tr', 'div', 'figure', 'figcaption', 'span'] as $element) {
            $config = $config->allowElement($element);
        }

        $config = $config
            ->allowElement('ol', ['start'])
            ->allowElement('a', ['href', 'target', 'aria-label'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('img', ['src', 'srcset', 'sizes', 'width', 'height', 'alt', 'loading', 'decoding'])
            ->allowAttribute('class', '*')
            ->allowAttribute('data-lightbox', 'a')
            ->allowAttribute('data-w', 'a')
            ->allowAttribute('data-h', 'a')
            ->allowAttribute('data-gallery', 'div')
            ->allowAttribute('data-count', 'div');

        return new HtmlSanitizer($config);
    }

    /**
     * The last check on a block that already went through the sanitiser: no picture, picture list or link may point
     * anywhere but the blog.media route (or, for a link, https / mailto / tel), and no active element may be left.
     */
    protected function isSafe(string $html): bool
    {
        if (preg_match('/<\s*(script|style|iframe|object|embed|form|input|link|meta|base|svg|math)\b/i', $html)) {
            return false;
        }

        // Text is escaped, so a "<" in the HTML always opens one of our own tags. Look at each tag with its quoted attribute
        // values blanked out, so words inside an alt text cannot be mistaken for attributes.
        preg_match_all('/<[^>]*>/', $html, $tags);

        foreach ($tags[0] as $tag) {
            if (preg_match('/\s(on[a-z]+|style|formaction|srcdoc)\s*=/i', preg_replace('/"[^"]*"/', '""', $tag) ?? '')) {
                return false;
            }
        }

        $mediaPath = '#^/blog/media/\d+/(thumb|medium|large)(\?v=\d*)?$#';

        preg_match_all('/<img\b[^>]*>/i', $html, $images);

        foreach ($images[0] as $image) {
            if (! preg_match('/\ssrc="([^"]*)"/i', $image, $src) || ! preg_match($mediaPath, html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5))) {
                return false;
            }

            if (preg_match('/\ssrcset="([^"]*)"/i', $image, $set)) {
                foreach (explode(',', html_entity_decode($set[1], ENT_QUOTES | ENT_HTML5)) as $candidate) {
                    if (! preg_match($mediaPath, trim(strtok(trim($candidate), ' ') ?: ''))) {
                        return false;
                    }
                }
            }
        }

        preg_match_all('/<a\b[^>]*\shref="([^"]*)"/i', $html, $links);

        foreach ($links[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);

            if (! preg_match($mediaPath, $href) && $this->safeLink($href) === null) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ text

    protected function plainText(array $document): string
    {
        $pieces = [];

        $walk = function (array $node) use (&$walk, &$pieces): void {
            $type = $node['type'] ?? '';
            $attrs = $node['attrs'] ?? [];

            if ($type === 'text') {
                $pieces[] = $node['text'];

                return;
            }

            if ($type === 'blogFigure') {
                $pieces[] = trim(($attrs['caption'] ?? '').' '.($attrs['credit'] ?? ''));
            } elseif ($type === 'blogGallery') {
                foreach ($attrs['items'] as $item) {
                    $pieces[] = $item['caption'];
                }
            } elseif ($type === 'blogVideo') {
                $pieces[] = $attrs['caption'] ?? '';
            }

            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }

            if (in_array($type, ['paragraph', 'heading', 'listItem', 'tableCell', 'tableHeader', 'blockquote'], true)) {
                $pieces[] = ' ';
            }
        };

        $walk($document);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $pieces)));
    }
}
