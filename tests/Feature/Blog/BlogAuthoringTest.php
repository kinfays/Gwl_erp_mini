<?php

namespace Tests\Feature\Blog;

use App\Livewire\Blog\Feed;
use App\Livewire\Blog\ManagePosts;
use App\Livewire\Blog\PostForm;
use App\Models\AuditLog;
use App\Models\BlogPost;
use App\Services\Blog\BlogPostService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class BlogAuthoringTest extends BlogTestCase
{
    private function fill($component, array $overrides = [])
    {
        return $component->set([
            'category' => 'workshop',
            'title' => 'Customer care workshop',
            'body' => 'Forty staff attended the customer care workshop at the regional office.',
            ...$overrides,
        ]);
    }

    // ---------------------------------------------------------------- who may write

    public function test_ordinary_staff_cannot_reach_the_manage_screens(): void
    {
        $reader = $this->reader();
        $post = $this->article($this->accraWest);

        $this->actingAs($reader)->get(route('blog.manage'))->assertForbidden();
        $this->actingAs($reader)->get(route('blog.create'))->assertForbidden();
        $this->actingAs($reader)->get(route('blog.edit', $post))->assertForbidden();

        Livewire::actingAs($reader)->test(ManagePosts::class)->assertForbidden();
        Livewire::actingAs($reader)->test(PostForm::class)->assertForbidden();
    }

    public function test_a_pr_officer_reaches_the_manage_screens(): void
    {
        $officer = $this->prOfficer();
        $post = $this->article($this->accraWest);

        $this->actingAs($officer)->get(route('blog.manage'))->assertOk();
        $this->actingAs($officer)->get(route('blog.create'))->assertOk();
        $this->actingAs($officer)->get(route('blog.edit', $post))->assertOk();
    }

    public function test_a_pr_officer_with_no_region_cannot_write(): void
    {
        $officer = $this->userWithoutEmployee('300090', ['employee', 'pr_officer']);

        $this->actingAs($officer)->get(route('blog.create'))->assertForbidden();
        Livewire::actingAs($officer)->test(PostForm::class)->assertForbidden();
    }

    // ---------------------------------------------------------------- creating

    public function test_a_pr_officer_saves_a_draft_for_their_own_region_that_readers_cannot_see(): void
    {
        $officer = $this->prOfficer();

        $this->fill(Livewire::actingAs($officer)->test(PostForm::class))
            ->set('eventDate', '2026-10-02')
            ->set('venue', 'Regional conference room')
            ->set('districtId', $this->odorkor->id)
            ->set('tags', 'Customer-Care, workshop, customer-care')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertRedirect(route('blog.manage'));

        $post = BlogPost::query()->firstOrFail();

        $this->assertSame($this->accraWest->id, $post->region_id);
        $this->assertSame($officer->id, $post->author_user_id);
        $this->assertSame(BlogPost::STATUS_DRAFT, $post->status);
        $this->assertNull($post->published_at);
        $this->assertSame(['customer-care', 'workshop'], $post->tags);
        $this->assertSame('2026-10-02', $post->event_date->toDateString());
        $this->assertSame($this->odorkor->id, $post->district_id);

        Livewire::actingAs($this->reader())->test(Feed::class)->assertDontSee('Customer care workshop');
        $this->assertTrue(AuditLog::query()->where('action', 'blog_post_created')->where('target_id', $post->id)->exists());
    }

    public function test_publishing_makes_it_visible_to_the_region_and_to_nobody_else(): void
    {
        $officer = $this->prOfficer();

        $this->fill(Livewire::actingAs($officer)->test(PostForm::class))->call('publish')->assertHasNoErrors();

        $post = BlogPost::query()->firstOrFail();

        $this->assertSame(BlogPost::STATUS_PUBLISHED, $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertTrue(AuditLog::query()->where('action', 'blog_post_published')->where('target_id', $post->id)->exists());

        Livewire::actingAs($this->reader())->test(Feed::class)->assertSee('Customer care workshop');
        Livewire::actingAs($this->reader('100002', $this->ashanti))->test(Feed::class)->assertDontSee('Customer care workshop');
    }

    public function test_the_form_requires_a_title_a_kind_and_a_body(): void
    {
        Livewire::actingAs($this->prOfficer())
            ->test(PostForm::class)
            ->call('saveDraft')
            ->assertHasErrors(['title', 'category', 'body']);

        $this->assertSame(0, BlogPost::query()->count());
    }

    public function test_a_district_from_another_region_is_refused(): void
    {
        $this->fill(Livewire::actingAs($this->prOfficer())->test(PostForm::class))
            ->set('districtId', $this->kumasi->id)
            ->call('saveDraft')
            ->assertHasErrors(['districtId']);

        $this->assertSame(0, BlogPost::query()->count());
    }

    public function test_a_pr_officer_cannot_post_to_another_regions_blog_by_changing_the_form(): void
    {
        $this->fill(Livewire::actingAs($this->prOfficer())->test(PostForm::class))
            ->set('regionId', $this->ashanti->id)
            ->call('saveDraft')
            ->assertForbidden();

        $this->assertSame(0, BlogPost::query()->count());
    }

    public function test_super_admin_chooses_the_region(): void
    {
        $this->fill(Livewire::actingAs($this->superAdmin())->test(PostForm::class))
            ->call('saveDraft')
            ->assertHasErrors(['regionId']);

        $this->fill(Livewire::actingAs($this->superAdmin())->test(PostForm::class))
            ->set('regionId', $this->ashanti->id)
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame($this->ashanti->id, BlogPost::query()->firstOrFail()->region_id);
    }

    // ---------------------------------------------------------------- editing

    public function test_a_pr_officer_edits_an_article_of_their_region_and_it_stays_published(): void
    {
        $officer = $this->prOfficer();
        $post = $this->article($this->accraWest, ['title' => 'Old title', 'category' => 'meeting']);

        Livewire::actingAs($officer)
            ->test(PostForm::class, ['postId' => $post->id])
            ->assertSet('title', 'Old title')
            ->set('title', 'New title')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $post->refresh();

        $this->assertSame('New title', $post->title);
        $this->assertSame(BlogPost::STATUS_PUBLISHED, $post->status);
        $this->assertSame($this->accraWest->id, $post->region_id);
        $this->assertTrue(AuditLog::query()->where('action', 'blog_post_updated')->where('target_id', $post->id)->exists());
    }

    public function test_an_article_of_another_region_cannot_be_opened_or_edited(): void
    {
        $officer = $this->prOfficer();
        $other = $this->article($this->ashanti, ['title' => 'Ashanti article']);

        $this->actingAs($officer)->get(route('blog.edit', $other))->assertNotFound();
        Livewire::actingAs($officer)->test(PostForm::class, ['postId' => $other->id])->assertNotFound();
    }

    public function test_the_service_refuses_an_officer_from_another_region_even_when_called_directly(): void
    {
        $other = $this->article($this->ashanti);
        $officer = $this->prOfficer();
        $service = app(BlogPostService::class);

        foreach ([
            fn () => $service->update($other, $officer, ['title' => 'x', 'category' => 'activity', 'body' => 'x']),
            fn () => $service->publish($other, $officer),
            fn () => $service->unpublish($other, $officer),
            fn () => $service->setPinned($other, $officer, true),
            fn () => $service->delete($other, $officer),
            fn () => $service->create($officer, ['region_id' => $this->ashanti->id, 'title' => 'x', 'category' => 'activity', 'body' => 'x']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The service should have refused.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->assertSame(1, BlogPost::query()->count());
        $this->assertSame(BlogPost::STATUS_PUBLISHED, $other->fresh()->status);
    }

    // ---------------------------------------------------------------- manage list

    public function test_the_manage_list_shows_drafts_and_published_of_the_officers_region_only(): void
    {
        $this->article($this->accraWest, ['title' => 'Accra published piece']);
        $this->draft($this->accraWest, ['title' => 'Accra draft piece']);
        $this->article($this->ashanti, ['title' => 'Ashanti published piece']);
        $this->draft($this->ashanti, ['title' => 'Ashanti draft piece']);

        Livewire::actingAs($this->prOfficer())
            ->test(ManagePosts::class)
            ->assertSee('Accra published piece')
            ->assertSee('Accra draft piece')
            ->assertDontSee('Ashanti published piece')
            ->assertDontSee('Ashanti draft piece');

        Livewire::actingAs($this->superAdmin())
            ->test(ManagePosts::class)
            ->assertSee('Accra draft piece')
            ->assertSee('Ashanti draft piece');
    }

    public function test_publish_unpublish_pin_and_delete_from_the_list(): void
    {
        $officer = $this->prOfficer();
        $post = $this->draft($this->accraWest, ['title' => 'Going live']);

        $list = Livewire::actingAs($officer)->test(ManagePosts::class);

        $list->call('publish', $post->id)->assertSet('notice', 'Published. Staff of the region can now read it.');
        $this->assertTrue($post->fresh()->isPublished());

        $list->call('pin', $post->id);
        $this->assertTrue($post->fresh()->is_pinned);

        // Taking it back to a draft also takes it off the top.
        $list->call('unpublish', $post->id);
        $this->assertFalse($post->fresh()->isPublished());
        $this->assertFalse($post->fresh()->is_pinned);

        Livewire::actingAs($this->reader())->test(Feed::class)->assertDontSee('Going live');

        // Livewire::actingAs() above signed the reader in for the rest of the test; the officer deletes.
        $this->actingAs($officer);
        $list->call('delete', $post->id);
        $this->assertNull(BlogPost::query()->find($post->id));

        foreach (['blog_post_published', 'blog_post_pinned', 'blog_post_unpublished', 'blog_post_deleted'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->where('target_id', $post->id)->exists(), $action);
        }
    }

    public function test_a_draft_cannot_be_pinned(): void
    {
        $draft = $this->draft($this->accraWest);

        Livewire::actingAs($this->prOfficer())
            ->test(ManagePosts::class)
            ->call('pin', $draft->id)
            ->assertSet('problem', 'Publish the article before pinning it.');

        $this->assertFalse($draft->fresh()->is_pinned);
    }

    public function test_the_list_actions_do_not_find_an_id_from_another_region(): void
    {
        $other = $this->draft($this->ashanti);

        Livewire::actingAs($this->prOfficer())->test(ManagePosts::class)->call('publish', $other->id)->assertNotFound();
        Livewire::actingAs($this->prOfficer())->test(ManagePosts::class)->call('delete', $other->id)->assertNotFound();

        $this->assertTrue($other->fresh()->exists);
        $this->assertFalse($other->fresh()->isPublished());
    }

    public function test_the_first_publication_date_is_kept_when_an_article_is_republished(): void
    {
        $officer = $this->prOfficer();
        $post = $this->article($this->accraWest, ['published_at' => now()->subDays(5)]);
        $first = $post->published_at->toDateTimeString();

        $service = app(BlogPostService::class);
        $service->unpublish($post, $officer);
        $service->publish($post, $officer);

        $this->assertSame($first, $post->fresh()->published_at->toDateTimeString());
    }

    // ---------------------------------------------------------------- cover photos

    public function test_a_cover_photo_is_stored_privately_and_replaced_and_removed_cleanly(): void
    {
        $officer = $this->prOfficer();
        $disk = Storage::disk('local');

        $this->fill(Livewire::actingAs($officer)->test(PostForm::class))
            ->set('cover', UploadedFile::fake()->image('first.jpg', 800, 450))
            ->call('saveDraft')
            ->assertHasNoErrors();

        $post = BlogPost::query()->firstOrFail();
        $first = $post->cover_path;
        $this->assertStringStartsWith('blog/covers/', $first);
        $disk->assertExists($first);

        // Replacing the photo deletes the old file.
        Livewire::actingAs($officer)
            ->test(PostForm::class, ['postId' => $post->id])
            ->set('cover', UploadedFile::fake()->image('second.png', 800, 450))
            ->call('saveDraft')
            ->assertHasNoErrors();

        $second = $post->fresh()->cover_path;
        $this->assertNotSame($first, $second);
        $disk->assertMissing($first);
        $disk->assertExists($second);

        // Removing it without a replacement does too.
        Livewire::actingAs($officer)
            ->test(PostForm::class, ['postId' => $post->id])
            ->call('clearCover')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertNull($post->fresh()->cover_path);
        $disk->assertMissing($second);
    }

    public function test_deleting_an_article_deletes_its_cover_photo(): void
    {
        $officer = $this->prOfficer();
        $path = Storage::disk('local')->putFile('blog/covers', UploadedFile::fake()->image('c.jpg'));
        $post = $this->article($this->accraWest, ['cover_path' => $path]);

        app(BlogPostService::class)->delete($post, $officer);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_only_images_are_accepted_as_a_cover(): void
    {
        Livewire::actingAs($this->prOfficer())
            ->test(PostForm::class)
            ->set('cover', UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'))
            ->assertHasErrors(['cover']);

        Livewire::actingAs($this->prOfficer())
            ->test(PostForm::class)
            ->set('cover', UploadedFile::fake()->image('huge.jpg')->size(((int) config('gwl.blog_cover_max_mb') * 1024) + 1))
            ->assertHasErrors(['cover']);
    }

    // ---------------------------------------------------------------- tags

    public function test_tags_are_tidied_lower_cased_and_capped(): void
    {
        $tags = app(BlogPostService::class)->cleanTags(' Safety, safety ,New-Staff,,a,b,c,d');

        $this->assertSame(['safety', 'new-staff', 'a', 'b', 'c'], $tags);
        $this->assertSame([], app(BlogPostService::class)->cleanTags(null));
    }
}
