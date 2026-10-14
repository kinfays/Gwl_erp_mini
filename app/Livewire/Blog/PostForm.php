<?php

namespace App\Livewire\Blog;

use App\Livewire\Blog\Concerns\ScopesBlogByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\BlogPost;
use App\Models\BlogPostRevision;
use App\Models\District;
use App\Models\Permission;
use App\Models\Region;
use App\Services\Blog\BlogConflictException;
use App\Services\Blog\BlogContentService;
use App\Services\Blog\BlogPostService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Write or edit an article. The post id and the version the editor opened are locked (the browser cannot change them) and the
 * id is resolved inside the actor's own region on every call; the region of an existing article never changes. Saving,
 * autosaving, publishing and restoring a version all go through BlogPostService.
 *
 * The body is the TipTap document the browser keeps in `bodyJson`. It is only ever handed to the service, which cleans it and
 * renders the HTML: this component never prints it and never trusts it.
 */
class PostForm extends Component
{
    use EnforcesModuleAccess;
    use ScopesBlogByActor;
    use WithFileUploads;

    #[Locked]
    public ?int $postId = null;

    /** The version of the article this editor last saw or saved; a newer one on the server means someone else saved. */
    #[Locked]
    public int $lockVersion = 0;

    /** Which region's blog a NEW article goes to. Fixed to the author's own region unless super_admin picks one. */
    public ?int $regionId = null;

    public string $title = '';

    public string $category = '';

    public string $summary = '';

    /** The editor document as JSON. Empty for a Markdown article that has not been opened in the editor yet. */
    public string $bodyJson = '';

    public string $tags = '';

    public string $eventDate = '';

    public string $venue = '';

    public ?int $districtId = null;

    /** @var TemporaryUploadedFile|null a newly chosen cover photo, not yet saved */
    public $cover = null;

    public bool $removeCover = false;

    public ?string $existingCoverUrl = null;

    public string $coverCaption = '';

    public string $coverCredit = '';

    public int $coverFocalX = 50;

    public int $coverFocalY = 50;

    public string $status = BlogPost::STATUS_DRAFT;

    /** Set when the article was saved by someone else after this editor opened it: who, when and which version. */
    public ?array $conflict = null;

    /** A message under the action bar (pictures that could not be kept, a restored version...). */
    public ?string $notice = null;

    /** What the editor starts with. Built on the first render only; the editor block is wire:ignore'd afterwards. */
    protected array $initial = [];

    public function mount(?int $postId = null): void
    {
        $this->enforceLivewireModule(Permission::MODULE_BLOG);
        $this->guardBlogManager();

        if ($postId === null) {
            $this->regionId = $this->visibility()->seesAllRegions($this->actor())
                ? null
                : $this->visibility()->regionIdOf($this->actor());
            $this->initial = ['json' => app(BlogContentService::class)->emptyDocument()];

            return;
        }

        $this->fillFrom($this->findPost($postId));
    }

    /** Check a photo as soon as it is chosen, so a wrong file is refused before the form is saved. */
    public function updatedCover(): void
    {
        try {
            $this->validateOnly('cover', $this->rules(), [], $this->validationAttributes());
        } catch (ValidationException $e) {
            $this->cover = null;

            throw $e;
        }

        $this->removeCover = false;
    }

    public function clearCover(): void
    {
        $this->cover = null;
        $this->removeCover = $this->existingCoverUrl !== null;
    }

    /** Save without changing the status: a new article stays a draft, a published one stays published. */
    public function saveDraft(BlogPostService $posts): void
    {
        $this->persist($posts, false);
    }

    /** Save and make it visible to the region. */
    public function publish(BlogPostService $posts): void
    {
        $this->persist($posts, true);
    }

    /**
     * The editor saving a DRAFT by itself. Quiet by design: it never shows validation errors, never touches a published
     * article, and a new article is only created once it has a region, a title and a kind.
     */
    public function autosave(BlogPostService $posts): void
    {
        $this->guardBlogManager();

        if ($this->status !== BlogPost::STATUS_DRAFT || $this->conflict !== null) {
            return;
        }

        $validator = validator(
            ['regionId' => $this->regionId, 'title' => $this->title, 'category' => $this->category],
            [
                'regionId' => ['required', 'integer', 'exists:regions,id'],
                'title' => ['required', 'string', 'max:180'],
                'category' => ['required', 'in:'.implode(',', array_keys(BlogPost::CATEGORIES))],
            ],
        );

        if ($validator->fails() || $this->districtInvalid()) {
            return;
        }

        try {
            if ($this->postId === null) {
                $post = $posts->create($this->actor(), $this->data(), null, false);
                $this->postId = $post->id;
                $this->js('window.history.replaceState({}, "", '.json_encode(route('blog.edit', $post, false)).')');
            } else {
                $post = $posts->autosave($this->findPost($this->postId), $this->actor(), $this->data(), $this->lockVersion);
            }
        } catch (BlogConflictException $e) {
            $this->conflict = $this->describe($e);

            return;
        } catch (ValidationException) {
            return;
        }

        $this->lockVersion = (int) $post->lock_version;
        $this->dispatch('blog-saved', label: 'Draft saved automatically at '.now()->format('H:i'));
    }

    /** The other person's version replaces what is on screen. */
    public function reloadTheirs(): void
    {
        $this->guardBlogManager();
        $this->fillFrom($this->findPost((int) $this->postId));
        $this->conflict = null;
        $this->notice = 'Loaded the latest saved version.';
        $this->dispatch('blog-document-replaced', document: $this->bodyJson);
    }

    /** Keep what is on screen and save it over the newer version (which stays in the list of saved versions). */
    public function saveOver(BlogPostService $posts): void
    {
        abort_if($this->conflict === null, 422);

        $this->lockVersion = (int) $this->conflict['version'];
        $this->conflict = null;
        $this->persist($posts, false);
    }

    public function restoreRevision(BlogPostService $posts, int $revisionId): void
    {
        $this->guardBlogManager();
        abort_if($this->postId === null, 404);

        try {
            $posts->restoreRevision($this->findPost($this->postId), $this->actor(), $revisionId, $this->lockVersion);
        } catch (BlogConflictException $e) {
            $this->conflict = $this->describe($e);

            return;
        }

        $this->fillFrom($this->findPost($this->postId));
        $this->notice = 'That version has been restored and saved.';
        $this->dispatch('blog-document-replaced', document: $this->bodyJson);
        $this->dispatch('blog-saved', label: 'Version restored at '.now()->format('H:i'));
    }

    /** The article exactly as readers will see it, rendered by the same partial the article page uses. */
    public function preview(BlogContentService $content, BlogPostService $posts): string
    {
        $this->guardBlogManager();

        $existing = $this->postId ? $this->findPost($this->postId) : null;

        if (trim($this->bodyJson) !== '') {
            $result = $content->process($this->bodyJson, (int) $this->regionId, $this->postId);
            $html = $result['html'];
            $minutes = $result['reading_minutes'];
        } else {
            $html = (string) $existing?->bodyHtml();
            $minutes = $existing?->reading_minutes;
        }

        $post = new BlogPost([
            'region_id' => $this->regionId,
            'district_id' => $this->districtId,
            'title' => trim($this->title) !== '' ? $this->title : 'Untitled article',
            'category' => array_key_exists($this->category, BlogPost::CATEGORIES) ? $this->category : 'other',
            'summary' => $this->summary,
            'tags' => $posts->cleanTags($this->tags),
            'event_date' => filled($this->eventDate) ? $this->eventDate : null,
            'venue' => $this->venue,
            'body_html' => $html,
            'reading_minutes' => $minutes,
            'status' => $this->status,
            'is_pinned' => $existing?->is_pinned ?? false,
            'published_at' => $existing?->published_at ?? now(),
            'cover_caption' => $this->coverCaption,
            'cover_credit' => $this->coverCredit,
            'cover_focal_x' => $this->coverFocalX,
            'cover_focal_y' => $this->coverFocalY,
            'cover_media_id' => $this->removeCover ? null : $existing?->cover_media_id,
            'cover_path' => $this->removeCover ? null : $existing?->cover_path,
        ]);

        $post->id = $existing?->id;
        $post->setRelation('author', $existing?->author ?? $this->actor());
        $post->setRelation('district', $this->districtId ? District::query()->find($this->districtId) : null);
        $post->setRelation('region', Region::query()->find($this->regionId));
        $post->setRelation('coverMedia', $this->removeCover ? null : $existing?->coverMedia);

        return view('blog.partials.article', ['post' => $post, 'canManage' => false, 'regions' => collect(), 'preview' => true])->render();
    }

    // ---------------------------------------------------------------- internals

    protected function persist(BlogPostService $posts, bool $publish): void
    {
        $this->guardBlogManager();
        $this->validate($this->rules(), $this->messages(), $this->validationAttributes());

        // A newly chosen cover is a Livewire temporary upload, which IS an UploadedFile: the service takes it as it is.
        $file = $this->cover;

        try {
            if ($this->postId === null) {
                $post = $posts->create($this->actor(), $this->data(), $file, $publish);
            } else {
                $post = $posts->update($this->findPost($this->postId), $this->actor(), $this->data(), $file, $this->removeCover, $this->lockVersion);

                if ($publish) {
                    $post = $posts->publish($post, $this->actor());
                }
            }
        } catch (BlogConflictException $e) {
            $this->conflict = $this->describe($e);

            return;
        } catch (ValidationException $e) {
            // Service-level refusals (a district from another region, an unknown category, an empty article, a bad cover) show on the field they name.
            foreach ($e->errors() as $field => $messages) {
                $this->addError($this->fieldFor($field), $messages[0]);
            }

            return;
        }

        if ($publish) {
            session()->flash('blog_notice', 'Published. Staff of the region can now read it.');
            $this->redirectRoute('blog.show', ['post' => $post->id]);

            return;
        }

        $this->postId = $post->id;
        $this->fillFrom($post, keepBody: true);
        $this->notice = $posts->droppedPictures > 0
            ? $posts->droppedPictures.' item(s) in the text could not be kept: a picture that is no longer available, or a picture, gallery or video placed inside a list, quotation or table. Add them again if you need them.'
            : null;
        $this->dispatch('blog-saved', label: ($post->isPublished() ? 'Changes saved at ' : 'Draft saved at ').now()->format('H:i'));
        $this->js('window.history.replaceState({}, "", '.json_encode(route('blog.edit', $post, false)).')');
    }

    /** What the service takes. The body travels only when the editor has sent one (a Markdown article stays as it is until then). */
    protected function data(): array
    {
        $data = [
            'region_id' => $this->regionId,
            'title' => $this->title,
            'category' => $this->category,
            'summary' => $this->summary,
            'tags' => $this->tags,
            'event_date' => $this->eventDate,
            'venue' => $this->venue,
            'district_id' => $this->districtId,
            'cover_caption' => $this->coverCaption,
            'cover_credit' => $this->coverCredit,
            'cover_focal_x' => $this->coverFocalX,
            'cover_focal_y' => $this->coverFocalY,
        ];

        if (trim($this->bodyJson) !== '') {
            $data['body_json'] = $this->bodyJson;
        }

        return $data;
    }

    protected function fillFrom(BlogPost $post, bool $keepBody = false): void
    {
        $post->loadMissing('coverMedia');

        $this->postId = $post->id;
        $this->lockVersion = (int) $post->lock_version;
        $this->regionId = (int) $post->region_id;
        $this->title = $post->title;
        $this->category = $post->category;
        $this->summary = (string) $post->summary;
        $this->tags = implode(', ', $post->tags ?? []);
        $this->eventDate = $post->event_date?->toDateString() ?? '';
        $this->venue = (string) $post->venue;
        $this->districtId = $post->district_id;
        $this->status = $post->status;
        $this->existingCoverUrl = $post->coverUrl('medium');
        $this->coverCaption = (string) $post->cover_caption;
        $this->coverCredit = (string) $post->cover_credit;
        $this->coverFocalX = $post->cover_focal_x ?? 50;
        $this->coverFocalY = $post->cover_focal_y ?? 50;
        $this->cover = null;
        $this->removeCover = false;

        if ($keepBody) {
            return;
        }

        if ($post->isRich() && $post->body_json) {
            $this->bodyJson = json_encode($post->body_json);
            $this->initial = ['json' => $post->body_json];
        } else {
            // A Markdown article: the editor loads its (already safe) HTML once; the first save makes it a rich article.
            $this->bodyJson = '';
            $this->initial = ['html' => (string) $post->bodyHtml()];
        }
    }

    /** The article, only if it is in a region the actor manages: any other id answers 404, as if it did not exist. */
    protected function findPost(int $postId): BlogPost
    {
        $post = $this->visibility()->scopeManageable(BlogPost::query(), $this->actor())->find($postId);

        abort_if($post === null, 404);

        return $post;
    }

    protected function districtInvalid(): bool
    {
        return $this->districtId !== null
            && ! District::query()->whereKey($this->districtId)->where('region_id', $this->regionId)->exists();
    }

    /** @return array{by: ?string, at: ?string, version: int} */
    protected function describe(BlogConflictException $e): array
    {
        return ['by' => $e->changedBy, 'at' => $e->changedAt?->format('H:i, j M'), 'version' => $e->currentVersion];
    }

    /** The form property a service error key belongs to. */
    protected function fieldFor(string $key): string
    {
        return match ($key) {
            'district_id' => 'districtId',
            'region_id' => 'regionId',
            'file' => 'cover',
            'body', 'body_json' => 'bodyJson',
            default => $key,
        };
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'regionId' => ['required', 'integer', 'exists:regions,id'],
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'in:'.implode(',', array_keys(BlogPost::CATEGORIES))],
            'summary' => ['nullable', 'string', 'max:300'],
            'bodyJson' => ['nullable', 'string', 'max:3000000'],
            'tags' => ['nullable', 'string', 'max:200'],
            'eventDate' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:160'],
            'districtId' => ['nullable', 'integer', 'exists:districts,id'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.((int) config('gwl.blog_cover_max_mb', 3) * 1024)],
            'coverCaption' => ['nullable', 'string', 'max:240'],
            'coverCredit' => ['nullable', 'string', 'max:120'],
            'coverFocalX' => ['integer', 'between:0,100'],
            'coverFocalY' => ['integer', 'between:0,100'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'regionId.required' => 'Choose the region whose blog this article is for.',
            'category.required' => 'Choose what kind of article this is.',
            'bodyJson.max' => 'The article is too large.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'regionId' => 'region',
            'eventDate' => 'event date',
            'districtId' => 'district',
            'cover' => 'cover photo',
            'coverCaption' => 'cover caption',
            'coverCredit' => 'cover credit',
            'bodyJson' => 'article',
        ];
    }

    public function render()
    {
        $visibility = $this->visibility();
        $editing = $this->postId !== null;

        return view('livewire.blog.post-form', [
            'editing' => $editing,
            'categories' => BlogPost::CATEGORIES,
            'regions' => $visibility->regionsFor($this->actor()),
            'pickRegion' => ! $editing && $visibility->seesAllRegions($this->actor()),
            'districts' => $this->regionId
                ? District::query()->where('region_id', $this->regionId)->orderBy('district_name')->get(['id', 'district_name'])
                : collect(),
            'regionName' => $this->regionId ? Region::query()->whereKey($this->regionId)->value('region_name') : null,
            'maxMb' => (int) config('gwl.blog_cover_max_mb', 3),
            'revisions' => $editing
                ? BlogPostRevision::query()->where('blog_post_id', $this->postId)->with('user:id,full_name')->latest('id')->limit(20)->get()
                : collect(),
            'editorConfig' => [
                'initial' => $this->initial ?: ['json' => app(BlogContentService::class)->emptyDocument()],
                'mediaBase' => route('blog.media', ['media' => 0, 'size' => 'thumb'], false),
                'uploadUrl' => route('blog.media.upload'),
                'autosaveSeconds' => (int) config('gwl.blog_autosave_seconds', 10),
            ],
        ]);
    }
}
