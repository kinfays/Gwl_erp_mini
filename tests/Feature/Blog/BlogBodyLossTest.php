<?php

namespace Tests\Feature\Blog;

use App\Livewire\Blog\PostForm;
use App\Models\BlogPost;
use App\Models\BlogPostRevision;
use App\Services\Blog\BlogContentService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/** The article body must survive a cover upload, a failed cover, a save, and an editor that sends an empty document. */
class BlogBodyLossTest extends BlogTestCase
{
    protected function form(BlogPost $post)
    {
        return Livewire::actingAs($this->prOfficer())->test(PostForm::class, ['postId' => $post->id]);
    }

    protected function written(string $text = 'Important written text'): BlogPost
    {
        $post = $this->draft($this->accraWest);
        $this->form($post)->set('bodyJson', json_encode($this->paragraphsDoc($text)))->call('saveDraft')->assertHasNoErrors();

        return $post->fresh();
    }

    public function test_saving_with_a_new_cover_keeps_the_body(): void
    {
        $post = $this->written();

        $this->form($post)
            ->set('bodyJson', json_encode($this->paragraphsDoc('Important written text')))
            ->set('cover', UploadedFile::fake()->image('c.jpg', 800, 500))
            ->call('saveDraft')
            ->assertHasNoErrors();

        $post->refresh();
        $this->assertStringContainsString('Important written text', $post->body_html);
        $this->assertNotNull($post->cover_media_id);
    }

    public function test_a_refused_cover_leaves_the_typed_body_on_the_form_and_saved(): void
    {
        $post = $this->written();

        $component = $this->form($post)
            ->set('bodyJson', json_encode($this->paragraphsDoc('Typed after loading')))
            ->set('cover', UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf'))
            ->assertHasErrors('cover');

        $this->assertStringContainsString('Typed after loading', $component->get('bodyJson'));

        $component->call('saveDraft')->assertHasNoErrors();
        $this->assertStringContainsString('Typed after loading', $post->fresh()->body_html);
    }

    public function test_the_editor_config_never_depends_on_the_server_copy_of_the_document(): void
    {
        // The starting document is read once from a JSON block inside wire:ignore; the root element has a constant x-data.
        $post = $this->written();
        $html = $this->form($post)->html();

        $this->assertStringContainsString('x-data="blogEditor"', $html);
        $this->assertStringContainsString('"hadBody":true', $html);
    }

    public function test_an_empty_document_cannot_replace_a_saved_body(): void
    {
        $post = $this->written();
        $empty = json_encode(app(BlogContentService::class)->emptyDocument());
        $before = $post->lock_version;

        $this->form($post)->set('bodyJson', $empty)->call('saveDraft')->assertHasErrors('bodyJson');

        $post->refresh();
        $this->assertStringContainsString('Important written text', $post->body_html);
        $this->assertSame($before, $post->lock_version);
    }

    public function test_autosave_cannot_overwrite_with_an_empty_document(): void
    {
        $post = $this->written();
        $empty = json_encode(app(BlogContentService::class)->emptyDocument());

        $this->form($post)->set('bodyJson', $empty)->call('autosave');

        $this->assertStringContainsString('Important written text', $post->fresh()->body_html);
    }

    public function test_an_explicit_signal_allows_an_empty_body(): void
    {
        $post = $this->written();

        app(\App\Services\Blog\BlogPostService::class)->update($post, $this->prOfficer(), [
            'region_id' => $post->region_id, 'title' => $post->title, 'category' => $post->category,
            'body_json' => app(BlogContentService::class)->emptyDocument(), 'allow_empty_body' => true,
        ]);

        $this->assertSame('', $post->fresh()->body_text);
    }

    public function test_every_update_leaves_the_previous_text_restorable(): void
    {
        $post = $this->written('First text');
        $this->form($post)->set('bodyJson', json_encode($this->paragraphsDoc('Second text')))->call('saveDraft');
        $this->form($post->fresh())->set('bodyJson', json_encode($this->paragraphsDoc('Third text')))->call('saveDraft');

        $texts = BlogPostRevision::query()->where('blog_post_id', $post->id)->get()
            ->map(fn ($r) => $r->body_json['content'][0]['content'][0]['text'] ?? '')->all();

        $this->assertContains('First text', $texts);
        $this->assertContains('Second text', $texts);
        $this->assertContains('Third text', $texts);
    }

    public function test_the_sanitiser_round_trip_keeps_tables_and_galleries(): void
    {
        $post = $this->written();
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before the table']]],
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Crew']]]]],
                ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Twelve']]]]],
            ]]]],
        ]];

        $this->form($post)->set('bodyJson', json_encode($doc))->call('saveDraft')->assertHasNoErrors();

        $post->refresh();
        $this->assertStringContainsString('<table', $post->body_html);
        $this->assertStringContainsString('Twelve', $post->body_text);
        $this->assertStringContainsString('Twelve', $this->form($post)->get('bodyJson'));
    }
}
