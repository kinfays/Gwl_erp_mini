<?php

namespace Tests\Feature\Blog;

use App\Livewire\Blog\PostForm;
use App\Models\AuditLog;
use App\Models\BlogMedia;
use App\Models\BlogPost;
use App\Models\BlogPostRevision;
use App\Models\User;
use App\Services\Blog\BlogConflictException;
use App\Services\Blog\BlogMediaService;
use App\Services\Blog\BlogPostService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What happens between the editor and the database: the rich document round trip, saved versions, the warning when someone
 * else saved first, autosave of drafts only, older Markdown articles, and the pictures an article uses.
 */
class BlogEditorFlowTest extends BlogTestCase
{
    protected User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer = $this->prOfficer();
    }

    protected function service(): BlogPostService
    {
        return app(BlogPostService::class);
    }

    protected function data(array $overrides = []): array
    {
        return [
            'region_id' => $this->accraWest->id,
            'title' => 'Leak detection course',
            'category' => 'training',
            'body_json' => $this->paragraphsDoc('Twenty-four crews took part.'),
            ...$overrides,
        ];
    }

    protected function picture(?User $actor = null): BlogMedia
    {
        return app(BlogMediaService::class)->store(UploadedFile::fake()->image('p.jpg', 900, 600), $actor ?? $this->officer, $this->accraWest->id, 'inline');
    }

    protected function figure(BlogMedia $media, string $alt = 'A crew at work'): array
    {
        return ['type' => 'blogFigure', 'attrs' => ['mediaId' => $media->id, 'alt' => $alt, 'caption' => 'Day two', 'credit' => '', 'align' => 'center', 'size' => 'wide']];
    }

    // ---------------------------------------------------------------- round trip

    public function test_a_rich_article_round_trips_from_the_editor_document_to_the_html_readers_get(): void
    {
        $words = implode(' ', array_fill(0, 420, 'water'));
        $media = $this->picture();
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'What we did']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $words]]],
            $this->figure($media),
        ]];

        $post = $this->service()->create($this->officer, $this->data(['body_json' => $document]), null, true);

        $this->assertSame('rich', $post->body_format);
        $this->assertSame($document['content'][0], $post->body_json['content'][0], 'the cleaned document is stored');
        $this->assertStringContainsString('<h2>What we did</h2>', $post->body_html);
        $this->assertStringContainsString('<figure class="bm-figure bm-align-center bm-size-wide">', $post->body_html);
        $this->assertStringContainsString('<figcaption>Day two</figcaption>', $post->body_html);
        $this->assertStringStartsWith('What we did water', $post->body_text);
        $this->assertSame($post->body_text, $post->body, 'the old column mirrors the text for anything that still reads it');
        $this->assertSame(3, $post->reading_minutes);
        $this->assertSame('leak-detection-course', $post->slug);
        $this->assertSame(1, $post->lock_version);

        $reader = $this->reader();
        $this->actingAs($reader)->get($post->url())->assertOk()->assertSee('What we did')->assertSee('3 min read');
    }

    public function test_the_html_is_rebuilt_on_every_save_from_the_document_never_taken_from_the_browser(): void
    {
        $post = $this->service()->create($this->officer, $this->data() + ['body_html' => '<script>alert(1)</script>'], null, true);

        $this->assertStringNotContainsString('<script', $post->body_html);

        $post = $this->service()->update($post, $this->officer, $this->data(['body_json' => $this->paragraphsDoc('Second version')]));
        $this->assertStringContainsString('Second version', $post->body_html);
        $this->assertStringNotContainsString('Twenty-four', $post->body_html);
    }

    // ---------------------------------------------------------------- saved versions

    public function test_the_last_twenty_versions_are_kept_and_the_list_shows_them(): void
    {
        $post = $this->service()->create($this->officer, $this->data());

        foreach (range(1, 25) as $n) {
            $post = $this->service()->update($post, $this->officer, $this->data(['body_json' => $this->paragraphsDoc("Version {$n}")]));
        }

        $this->assertSame(20, BlogPostRevision::query()->where('blog_post_id', $post->id)->count());
        $this->assertSame('Version 25', BlogPostRevision::query()->where('blog_post_id', $post->id)->latest('id')->first()->body_json['content'][0]['content'][0]['text']);
        $this->assertFalse(BlogPostRevision::query()->where('blog_post_id', $post->id)->get()->contains(fn ($r) => ($r->body_json['content'][0]['content'][0]['text'] ?? '') === 'Version 3'), 'older ones are gone');

        Livewire::actingAs($this->officer)->test(PostForm::class, ['postId' => $post->id])->assertViewHas('revisions', fn ($revisions) => $revisions->count() === 20);
    }

    public function test_an_older_version_can_be_restored_and_it_is_audited(): void
    {
        $post = $this->service()->create($this->officer, $this->data(['title' => 'First title', 'body_json' => $this->paragraphsDoc('First text')]), null, true);
        $first = BlogPostRevision::query()->where('blog_post_id', $post->id)->firstOrFail();
        $post = $this->service()->update($post, $this->officer, $this->data(['title' => 'Second title', 'body_json' => $this->paragraphsDoc('Second text')]));

        Livewire::actingAs($this->officer)
            ->test(PostForm::class, ['postId' => $post->id])
            ->call('restoreRevision', $first->id)
            ->assertHasNoErrors()
            ->assertSet('title', 'First title')
            ->assertDispatched('blog-document-replaced');

        $post->refresh();
        $this->assertSame('First title', $post->title);
        $this->assertStringContainsString('First text', $post->body_html);
        $this->assertSame('published', $post->status, 'restoring changes the text, not the status');
        $this->assertSame('restore', $post->revisions()->first()->reason);
        $this->assertSame(3, $post->revisions()->count(), 'the version it replaced stays in the list');

        $audit = AuditLog::query()->where('action', 'blog_revision_restored')->where('target_id', $post->id)->firstOrFail();
        $this->assertSame($first->id, $audit->new_values['revision_id'] ?? $audit->metadata['revision_id'] ?? null);
    }

    public function test_a_legacy_markdown_version_can_be_restored_too(): void
    {
        $post = $this->article($this->accraWest, ['title' => 'Old', 'category' => 'activity', 'body' => 'Plain **markdown** text', 'body_html' => null]);
        $post = $this->service()->update($post, $this->officer, ['title' => 'Old', 'category' => 'activity', 'body' => 'Plain **markdown** text']);
        $markdownVersion = $post->revisions()->firstOrFail();
        $this->assertSame('markdown', $markdownVersion->body_format);

        $post = $this->service()->update($post, $this->officer, $this->data(['title' => 'Now rich']));
        $this->assertSame('rich', $post->body_format);

        $post = $this->service()->restoreRevision($post, $this->officer, $markdownVersion->id);

        $this->assertSame('markdown', $post->body_format);
        $this->assertStringContainsString('<strong>markdown</strong>', $post->body_html);
    }

    public function test_versions_of_another_article_or_region_cannot_be_restored(): void
    {
        $mine = $this->service()->create($this->officer, $this->data());
        $foreign = $this->service()->create($this->superAdmin(), $this->data(['region_id' => $this->ashanti->id]));
        $foreignVersion = $foreign->revisions()->firstOrFail();

        $this->expectException(ModelNotFoundException::class);
        $this->service()->restoreRevision($mine, $this->officer, $foreignVersion->id);
    }

    public function test_only_the_regions_writers_can_restore(): void
    {
        $post = $this->service()->create($this->officer, $this->data());
        $revision = $post->revisions()->firstOrFail();

        $this->expectException(HttpException::class);
        $this->service()->restoreRevision($post, $this->reader(), $revision->id);
    }

    // ---------------------------------------------------------------- someone else saved first

    public function test_saving_over_a_newer_version_is_refused_with_who_and_when(): void
    {
        $post = $this->service()->create($this->officer, $this->data());
        $opened = $post->lock_version;

        $other = $this->prOfficer('300088');
        $this->service()->update($post, $other, $this->data(['body_json' => $this->paragraphsDoc('Colleague wrote this')]));

        try {
            $this->service()->update($post, $this->officer, $this->data(['body_json' => $this->paragraphsDoc('Mine')]), null, false, $opened);
            $this->fail('the newer save was overwritten');
        } catch (BlogConflictException $e) {
            $this->assertSame($opened + 1, $e->currentVersion);
            $this->assertSame($other->full_name, $e->changedBy);
        }

        $this->assertStringContainsString('Colleague wrote this', $post->fresh()->body_html);
    }

    public function test_the_editor_warns_instead_of_overwriting_and_offers_both_ways_out(): void
    {
        $post = $this->service()->create($this->officer, $this->data());
        $component = Livewire::actingAs($this->officer)->test(PostForm::class, ['postId' => $post->id]);

        $this->service()->update($post, $this->prOfficer('300088'), $this->data(['body_json' => $this->paragraphsDoc('Colleague wrote this')]));

        $component->set('bodyJson', $this->docJson('Mine'))->call('saveDraft')->assertSet('conflict.by', fn ($by) => $by !== null);
        $this->assertStringContainsString('Colleague wrote this', $post->fresh()->body_html, 'nothing was overwritten');

        // Take their version ...
        $component->call('reloadTheirs')->assertSet('conflict', null)->assertDispatched('blog-document-replaced');
        $this->assertStringContainsString('Colleague wrote this', $component->get('bodyJson'));

        // ... or keep mine and save it over theirs.
        $component->set('bodyJson', $this->docJson('Mine again'))->call('saveDraft')->assertSet('conflict', null);
        $this->assertStringContainsString('Mine again', $post->fresh()->body_html);

        $other = $this->prOfficer('300088');
        $this->service()->update($post->fresh(), $other, $this->data(['body_json' => $this->paragraphsDoc('Colleague again')]));
        $component->set('bodyJson', $this->docJson('Mine, final'))->call('saveDraft')->assertSet('conflict.version', fn ($v) => $v > 0)->call('saveOver');
        $this->assertStringContainsString('Mine, final', $post->fresh()->body_html);
        $this->assertTrue($post->fresh()->revisions()->get()->contains(fn ($r) => str_contains(json_encode($r->body_json), 'Colleague again')), 'theirs is still in the versions');
    }

    public function test_the_version_the_editor_opened_cannot_be_changed_from_the_browser(): void
    {
        $post = $this->service()->create($this->officer, $this->data());

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->officer)->test(PostForm::class, ['postId' => $post->id])->set('lockVersion', 0);
    }

    // ---------------------------------------------------------------- autosave

    public function test_autosave_creates_a_draft_once_there_is_a_title_and_a_kind_and_never_before(): void
    {
        $component = Livewire::actingAs($this->officer)->test(PostForm::class);

        $component->set('bodyJson', $this->docJson('typing...'))->call('autosave');
        $this->assertSame(0, BlogPost::query()->count());

        $component->set(['title' => 'Draft in progress', 'category' => 'activity'])->call('autosave')->assertDispatched('blog-saved');

        $post = BlogPost::query()->firstOrFail();
        $this->assertSame('draft', $post->status);
        $this->assertStringContainsString('typing...', $post->body_html);
        $this->assertSame($post->id, $component->get('postId'));
        $this->assertNull($post->published_at);
    }

    public function test_autosave_only_ever_touches_drafts(): void
    {
        $published = $this->service()->create($this->officer, $this->data(), null, true);

        // The component does nothing for a published article ...
        Livewire::actingAs($this->officer)
            ->test(PostForm::class, ['postId' => $published->id])
            ->set('bodyJson', $this->docJson('Sneaky automatic change'))
            ->call('autosave');

        $this->assertStringNotContainsString('Sneaky', $published->fresh()->body_html);

        // ... and the service itself refuses.
        try {
            $this->service()->autosave($published, $this->officer, $this->data(['body_json' => $this->paragraphsDoc('Sneaky')]), $published->lock_version);
            $this->fail('autosave changed a published article');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertStringNotContainsString('Sneaky', $published->fresh()->body_html);

        // An explicit save is how a published article changes.
        $this->service()->update($published, $this->officer, $this->data(['body_json' => $this->paragraphsDoc('Deliberate change')]));
        $this->assertStringContainsString('Deliberate change', $published->fresh()->body_html);
        $this->assertSame('published', $published->fresh()->status);
    }

    public function test_autosave_keeps_one_rolling_version_instead_of_one_per_tick(): void
    {
        $draft = $this->service()->create($this->officer, $this->data());

        foreach (range(1, 6) as $n) {
            $draft = $this->service()->autosave($draft, $this->officer, $this->data(['body_json' => $this->paragraphsDoc("Tick {$n}")]), $draft->lock_version);
        }

        $reasons = $draft->revisions()->pluck('reason')->all();
        $this->assertSame(['autosave', 'save'], $reasons, 'the creation and one rolling automatic version');
        $this->assertStringContainsString('Tick 6', json_encode($draft->revisions()->first()->body_json));

        $draft = $this->service()->update($draft, $this->officer, $this->data(['body_json' => $this->paragraphsDoc('Explicit')]));
        $this->assertSame(['save', 'autosave', 'save'], $draft->revisions()->pluck('reason')->all());
    }

    public function test_autosave_does_not_run_over_a_newer_save(): void
    {
        $draft = $this->service()->create($this->officer, $this->data());
        $this->service()->update($draft, $this->prOfficer('300088'), $this->data(['body_json' => $this->paragraphsDoc('Theirs')]));

        $this->expectException(BlogConflictException::class);
        $this->service()->autosave($draft, $this->officer, $this->data(['body_json' => $this->paragraphsDoc('Mine')]), $draft->lock_version);
    }

    // ---------------------------------------------------------------- articles written in Markdown

    public function test_a_markdown_article_reads_exactly_as_before_and_stays_markdown_until_saved_from_the_editor(): void
    {
        $post = $this->service()->create($this->officer, ['region_id' => $this->accraWest->id, 'title' => 'Old way', 'category' => 'meeting', 'body' => "## Agenda\n\n- one\n- two\n\n<script>alert(1)</script>"], null, true);

        $this->assertSame('markdown', $post->body_format);
        $this->assertNull($post->body_json);

        $page = $this->actingAs($this->reader())->get($post->url())->assertOk()->assertSee('<h2>Agenda</h2>', false)->assertSee('<li>one</li>', false);
        $this->assertStringNotContainsString('<script>alert', $page->getContent());

        // Opening it in the editor loads its HTML into the editor ...
        $component = Livewire::actingAs($this->officer)->test(PostForm::class, ['postId' => $post->id]);
        $component->assertViewHas('editorConfig', fn ($config) => str_contains($config['initial']['html'] ?? '', '<h2>Agenda</h2>') && ! isset($config['initial']['json']));

        // ... and saving without the editor having said anything changes nothing about the body.
        $component->set('title', 'Old way, new title')->call('saveDraft')->assertHasNoErrors();
        $post->refresh();
        $this->assertSame('markdown', $post->body_format);
        $this->assertStringContainsString('## Agenda', $post->body);
        $this->assertStringContainsString('<h2>Agenda</h2>', $post->body_html);

        // The first save that carries the editor's document converts it, once.
        $component->set('bodyJson', $this->docJson('Rewritten in the editor'))->call('saveDraft')->assertHasNoErrors();
        $post->refresh();
        $this->assertSame('rich', $post->body_format);
        $this->assertStringContainsString('Rewritten in the editor', $post->body_html);
        $this->assertNotNull($post->body_json);
    }

    // ---------------------------------------------------------------- the pictures of an article

    public function test_the_pictures_an_article_shows_belong_to_it_and_are_in_use_in_reading_order(): void
    {
        $a = $this->picture();
        $b = $this->picture();
        $unused = $this->picture();

        $post = $this->service()->create($this->officer, $this->data(['body_json' => ['type' => 'doc', 'content' => [
            $this->figure($b),
            ['type' => 'blogGallery', 'attrs' => ['layout' => 'grid', 'items' => [['mediaId' => $a->id, 'alt' => '', 'caption' => '']]]],
        ]]]));

        $this->assertSame([$post->id, $post->id], [$a->fresh()->blog_post_id, $b->fresh()->blog_post_id]);
        $this->assertTrue($a->fresh()->in_use && $b->fresh()->in_use);
        $this->assertSame([0, 1], [$b->fresh()->sort_order, $a->fresh()->sort_order], 'reading order');
        $this->assertNull($unused->fresh()->blog_post_id);
        $this->assertFalse($unused->fresh()->in_use);

        // Taking a picture out of the text keeps the file for the saved versions but stops readers being given it.
        $this->service()->update($post, $this->officer, $this->data(['body_json' => $this->paragraphsDoc('No pictures now')]));
        $this->assertFalse($b->fresh()->in_use);
        $this->assertNotNull(BlogMedia::query()->find($b->id));
    }

    public function test_a_picture_that_cannot_be_kept_is_reported_to_the_author(): void
    {
        $gone = $this->picture();
        $post = $this->service()->create($this->officer, $this->data());

        app(BlogMediaService::class)->remove($gone, $this->officer);

        $component = Livewire::actingAs($this->officer)
            ->test(PostForm::class, ['postId' => $post->id])
            ->set('bodyJson', json_encode(['type' => 'doc', 'content' => [$this->figure($gone), ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Text stays']]]]]))
            ->call('saveDraft');

        $this->assertStringContainsString('1 item(s) in the text could not be kept', $component->get('notice'));
        $this->assertStringContainsString('Text stays', $post->fresh()->body_html);
    }

    public function test_publishing_needs_something_to_show_but_a_picture_alone_is_enough(): void
    {
        $empty = $this->service()->create($this->officer, $this->data(['body_json' => $this->paragraphsDoc('')]));

        try {
            $this->service()->publish($empty, $this->officer);
            $this->fail('an empty article was published');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }

        $withPicture = $this->service()->create($this->officer, $this->data(['title' => 'Pictures only', 'body_json' => ['type' => 'doc', 'content' => [$this->figure($this->picture())]]]));
        $this->assertSame('published', $this->service()->publish($withPicture, $this->officer)->status);
    }

    // ---------------------------------------------------------------- preview and audit

    public function test_the_preview_renders_the_reader_template_for_what_is_on_screen_and_saves_nothing(): void
    {
        $component = Livewire::actingAs($this->officer)->test(PostForm::class)->set([
            'title' => 'Preview me',
            'category' => 'workshop',
            'summary' => 'A standfirst',
            'venue' => 'Conference room',
            'eventDate' => '2026-12-01',
            'bodyJson' => $this->docJson('Body <script>alert(1)</script> text'),
        ]);

        $html = $component->call('preview')->effects['returns'][0] ?? null;

        $this->assertIsString($html);
        $this->assertStringContainsString('<header class="b-art-head">', $html, 'the same partial the article page uses');
        $this->assertStringContainsString('<h1>Preview me</h1>', $html);
        $this->assertStringContainsString('A standfirst', $html);
        $this->assertStringContainsString('Conference room', $html);
        $this->assertStringContainsString('Body &lt;script&gt;alert(1)&lt;/script&gt; text', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('Copy link', $html, 'no reader actions inside the editor');
        $this->assertSame(0, BlogPost::query()->count());
    }

    public function test_audit_rows_name_what_changed_but_never_hold_the_text_or_the_files(): void
    {
        $secret = 'Confidential paragraph text';
        $post = $this->service()->create($this->officer, $this->data(['body_json' => $this->paragraphsDoc($secret)]));
        $this->service()->update($post, $this->officer, $this->data(['title' => 'Renamed', 'body_json' => $this->paragraphsDoc($secret.' v2')]));
        $this->picture();

        foreach (AuditLog::query()->where('module', 'blog')->get() as $row) {
            $this->assertStringNotContainsString($secret, json_encode($row->toArray()), $row->action);
            $this->assertStringNotContainsString('blog/media', json_encode($row->toArray()), $row->action);
        }

        $this->assertTrue(AuditLog::query()->where('action', 'blog_post_updated')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'blog_media_uploaded')->exists());
    }

    public function test_a_reader_cannot_use_any_editor_action(): void
    {
        $post = $this->service()->create($this->officer, $this->data(), null, true);

        foreach (['autosave', 'saveDraft', 'preview'] as $method) {
            Livewire::actingAs($this->reader())->test(PostForm::class, ['postId' => $post->id])->assertForbidden();
        }

        $this->actingAs($this->prOfficer('300077', $this->ashanti))->get(route('blog.edit', $post))->assertNotFound();
    }
}
