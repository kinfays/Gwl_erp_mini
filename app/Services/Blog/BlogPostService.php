<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\District;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a blog article goes through here. Each action re-reads the article lockForUpdate inside a transaction,
 * authorises the actor on that copy (BlogVisibility) and writes the audit entry; the screens only collect input.
 *
 * Input is validated by the Livewire form; what is checked again here is what protects the data whatever calls this: the
 * actor may write to that region, the category is a known one, the district belongs to the region, tags are tidy.
 */
class BlogPostService
{
    public const COVER_DIRECTORY = 'blog/covers';

    /** The private disk covers live on. */
    public const DISK = 'local';

    public function __construct(protected BlogVisibility $visibility) {}

    /** The private disk covers live on. They are never given a URL: blog.cover serves them after a region check. */
    public function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    /**
     * @param  array{region_id: int, title: string, category: string, body: string, summary?: ?string, district_id?: ?int, event_date?: ?string, venue?: ?string, tags?: array|string|null}  $data
     */
    public function create(User $actor, array $data, ?UploadedFile $cover = null, bool $publish = false): BlogPost
    {
        $regionId = (int) ($data['region_id'] ?? 0);
        $this->authorise($actor, $regionId);

        $attributes = $this->attributes($data, $regionId);
        $coverPath = $cover ? $this->storeCover($cover) : null;

        try {
            $post = DB::transaction(function () use ($actor, $attributes, $coverPath, $publish) {
                $post = BlogPost::query()->create([
                    ...$attributes,
                    'author_user_id' => $actor->id,
                    'cover_path' => $coverPath,
                    'status' => BlogPost::STATUS_DRAFT,
                ]);

                Audit::log('blog_post_created', 'blog', 'BlogPost', $post->id, [
                    'title' => $post->title,
                    'region_id' => $post->region_id,
                ]);

                return $publish ? $this->publishLocked($post, $actor) : $post;
            });
        } catch (\Throwable $e) {
            $this->forget($coverPath);

            throw $e;
        }

        return $post->fresh();
    }

    /**
     * Edit an article. The region is fixed once written (an article never moves to another region's blog).
     * A $cover replaces the current photo; $removeCover drops it without a replacement.
     */
    public function update(BlogPost $post, User $actor, array $data, ?UploadedFile $cover = null, bool $removeCover = false): BlogPost
    {
        $newCover = $cover ? $this->storeCover($cover) : null;
        $oldCover = null;

        try {
            DB::transaction(function () use ($post, $actor, $data, $newCover, $removeCover, &$oldCover) {
                $locked = $this->lock($post, $actor);

                $changes = $this->attributes($data, (int) $locked->region_id);

                if ($newCover !== null) {
                    $oldCover = $locked->cover_path;
                    $changes['cover_path'] = $newCover;
                } elseif ($removeCover && $locked->hasCover()) {
                    $oldCover = $locked->cover_path;
                    $changes['cover_path'] = null;
                }

                $locked->fill($changes);
                $changed = array_keys($locked->getDirty());
                $locked->save();

                Audit::log('blog_post_updated', 'blog', 'BlogPost', $locked->id, [
                    'title' => $locked->title,
                    'region_id' => $locked->region_id,
                    'fields' => $changed,
                ]);
            });
        } catch (\Throwable $e) {
            $this->forget($newCover);

            throw $e;
        }

        $this->forget($oldCover);

        return $post->fresh();
    }

    public function publish(BlogPost $post, User $actor): BlogPost
    {
        return DB::transaction(fn () => $this->publishLocked($this->lock($post, $actor), $actor))->fresh();
    }

    /** Back to a draft: readers stop seeing it at once, and a pinned article is unpinned. */
    public function unpublish(BlogPost $post, User $actor): BlogPost
    {
        DB::transaction(function () use ($post, $actor) {
            $locked = $this->lock($post, $actor);

            if (! $locked->isPublished()) {
                return;
            }

            $locked->forceFill(['status' => BlogPost::STATUS_DRAFT, 'is_pinned' => false])->save();

            Audit::log('blog_post_unpublished', 'blog', 'BlogPost', $locked->id, [
                'title' => $locked->title,
                'region_id' => $locked->region_id,
            ]);
        });

        return $post->fresh();
    }

    /** Pin to (or unpin from) the top of the region's feed. Only a published article can be pinned. */
    public function setPinned(BlogPost $post, User $actor, bool $pinned): BlogPost
    {
        DB::transaction(function () use ($post, $actor, $pinned) {
            $locked = $this->lock($post, $actor);

            if ($pinned && ! $locked->isPublished()) {
                throw ValidationException::withMessages(['pinned' => 'Publish the article before pinning it.']);
            }

            if ($locked->is_pinned === $pinned) {
                return;
            }

            $locked->forceFill(['is_pinned' => $pinned])->save();

            Audit::log($pinned ? 'blog_post_pinned' : 'blog_post_unpinned', 'blog', 'BlogPost', $locked->id, [
                'title' => $locked->title,
                'region_id' => $locked->region_id,
            ]);
        });

        return $post->fresh();
    }

    public function delete(BlogPost $post, User $actor): void
    {
        $cover = null;

        DB::transaction(function () use ($post, $actor, &$cover) {
            $locked = $this->lock($post, $actor);
            $cover = $locked->cover_path;

            Audit::log('blog_post_deleted', 'blog', 'BlogPost', $locked->id, [
                'title' => $locked->title,
                'region_id' => $locked->region_id,
                'was_published' => $locked->isPublished(),
            ]);

            $locked->delete();
        });

        $this->forget($cover);
    }

    // ---------------------------------------------------------------- internals

    protected function publishLocked(BlogPost $post, User $actor): BlogPost
    {
        if ($post->isPublished()) {
            return $post;
        }

        // The date a reader sees is the first publication; republishing after a pause to edit keeps it.
        $post->forceFill([
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => $post->published_at ?? now(),
        ])->save();

        Audit::log('blog_post_published', 'blog', 'BlogPost', $post->id, [
            'title' => $post->title,
            'region_id' => $post->region_id,
        ]);

        return $post;
    }

    /** Re-read the article under a row lock and authorise the actor against that copy. */
    protected function lock(BlogPost $post, User $actor): BlogPost
    {
        $locked = BlogPost::query()->lockForUpdate()->findOrFail($post->getKey());

        abort_unless($this->visibility->canManagePost($actor, $locked), 403, 'You cannot manage this article.');

        return $locked;
    }

    protected function authorise(User $actor, int $regionId): void
    {
        abort_unless($regionId > 0 && $this->visibility->canManageIn($actor, $regionId), 403, 'You cannot write to this region\'s blog.');
    }

    /** The article's own fields, cleaned and checked. @return array<string, mixed> */
    protected function attributes(array $data, int $regionId): array
    {
        $category = (string) ($data['category'] ?? '');

        if (! array_key_exists($category, BlogPost::CATEGORIES)) {
            throw ValidationException::withMessages(['category' => 'Choose one of the listed categories.']);
        }

        $districtId = filled($data['district_id'] ?? null) ? (int) $data['district_id'] : null;

        if ($districtId !== null && ! District::query()->whereKey($districtId)->where('region_id', $regionId)->exists()) {
            throw ValidationException::withMessages(['district_id' => 'That district is not in this region.']);
        }

        return [
            'region_id' => $regionId,
            'district_id' => $districtId,
            'title' => trim((string) $data['title']),
            'category' => $category,
            'summary' => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : null,
            'body' => trim((string) $data['body']),
            'tags' => $this->cleanTags($data['tags'] ?? []) ?: null,
            'event_date' => filled($data['event_date'] ?? null) ? $data['event_date'] : null,
            'venue' => filled($data['venue'] ?? null) ? trim((string) $data['venue']) : null,
        ];
    }

    /**
     * Tags are typed as one comma-separated line. Lower-cased, trimmed, de-duplicated and capped, so the same tag is
     * always spelled the same way.
     *
     * @return list<string>
     */
    public function cleanTags(array|string|null $tags): array
    {
        $list = is_array($tags) ? $tags : explode(',', (string) $tags);

        return collect($list)
            ->map(fn ($tag) => Str::limit(Str::lower(trim((string) $tag)), 30, ''))
            ->filter()
            ->unique()
            ->take(BlogPost::MAX_TAGS)
            ->values()
            ->all();
    }

    protected function storeCover(UploadedFile $file): string
    {
        // store() (not putFile()) because a Livewire temporary upload keeps its own way of reading the file.
        $path = $file->store(self::COVER_DIRECTORY, self::DISK);

        abort_if($path === false, 500, 'The cover photo could not be saved.');

        return $path;
    }

    protected function forget(?string $path): void
    {
        if ($path && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }
}
