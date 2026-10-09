<?php

namespace App\Livewire\Blog;

use App\Livewire\Blog\Concerns\ScopesBlogByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\BlogPost;
use App\Models\District;
use App\Models\Permission;
use App\Models\Region;
use App\Services\Blog\BlogPostService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Write or edit an article. The post id is locked (the browser cannot swap it) and is resolved inside the actor's own
 * region on every call; the region of an existing article never changes. Saving and publishing both go through
 * BlogPostService.
 */
class PostForm extends Component
{
    use EnforcesModuleAccess;
    use ScopesBlogByActor;
    use WithFileUploads;

    #[Locked]
    public ?int $postId = null;

    /** Which region's blog a NEW article goes to. Fixed to the author's own region unless super_admin picks one. */
    public ?int $regionId = null;

    public string $title = '';

    public string $category = '';

    public string $summary = '';

    public string $body = '';

    public string $tags = '';

    public string $eventDate = '';

    public string $venue = '';

    public ?int $districtId = null;

    /** @var TemporaryUploadedFile|null a newly chosen cover photo, not yet saved */
    public $cover = null;

    public bool $removeCover = false;

    public ?string $existingCoverUrl = null;

    public string $status = BlogPost::STATUS_DRAFT;

    public bool $saved = false;

    public function mount(?int $postId = null): void
    {
        $this->enforceLivewireModule(Permission::MODULE_BLOG);
        $this->guardBlogManager();

        if ($postId === null) {
            $this->regionId = $this->visibility()->seesAllRegions($this->actor())
                ? null
                : $this->visibility()->regionIdOf($this->actor());

            return;
        }

        $post = $this->findPost($postId);

        $this->postId = $post->id;
        $this->regionId = (int) $post->region_id;
        $this->title = $post->title;
        $this->category = $post->category;
        $this->summary = (string) $post->summary;
        $this->body = $post->body;
        $this->tags = implode(', ', $post->tags ?? []);
        $this->eventDate = $post->event_date?->toDateString() ?? '';
        $this->venue = (string) $post->venue;
        $this->districtId = $post->district_id;
        $this->status = $post->status;
        $this->existingCoverUrl = $post->coverUrl();
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

    protected function persist(BlogPostService $posts, bool $publish): ?bool
    {
        $this->guardBlogManager();
        $this->validate($this->rules(), $this->messages(), $this->validationAttributes());

        $data = [
            'region_id' => $this->regionId,
            'title' => $this->title,
            'category' => $this->category,
            'summary' => $this->summary,
            'body' => $this->body,
            'tags' => $this->tags,
            'event_date' => $this->eventDate,
            'venue' => $this->venue,
            'district_id' => $this->districtId,
        ];

        // A Livewire temporary upload IS an UploadedFile, so the service takes it as it is.
        $file = $this->cover;

        try {
            if ($this->postId === null) {
                $post = $posts->create($this->actor(), $data, $file, $publish);
            } else {
                $post = $posts->update($this->findPost($this->postId), $this->actor(), $data, $file, $this->removeCover);

                if ($publish) {
                    $post = $posts->publish($post, $this->actor());
                }
            }
        } catch (ValidationException $e) {
            // Service-level refusals (a district from another region, an unknown category) show on the field they name.
            foreach ($e->errors() as $field => $messages) {
                $this->addError($this->fieldFor($field), $messages[0]);
            }

            return false;
        }

        session()->flash('blog_notice', match (true) {
            $publish => 'Published. Staff of the region can now read it.',
            $post->isPublished() => 'Changes saved.',
            default => 'Saved as a draft.',
        });

        $this->redirectRoute($publish ? 'blog.show' : 'blog.manage', $publish ? ['post' => $post->id] : []);

        return true;
    }

    /** The article, only if it is in a region the actor manages: any other id answers 404, as if it did not exist. */
    protected function findPost(int $postId): BlogPost
    {
        $post = $this->visibility()->scopeManageable(BlogPost::query(), $this->actor())->find($postId);

        abort_if($post === null, 404);

        return $post;
    }

    /** The form property a service error key belongs to. */
    protected function fieldFor(string $key): string
    {
        return match ($key) {
            'district_id' => 'districtId',
            'region_id' => 'regionId',
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
            'body' => ['required', 'string', 'max:50000'],
            'tags' => ['nullable', 'string', 'max:200'],
            'eventDate' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:160'],
            'districtId' => ['nullable', 'integer', 'exists:districts,id'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.((int) config('gwl.blog_cover_max_mb', 3) * 1024)],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'regionId.required' => 'Choose the region whose blog this article is for.',
            'category.required' => 'Choose what kind of article this is.',
            'body.required' => 'Write the article.',
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
        ]);
    }
}
