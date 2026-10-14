<?php

namespace App\Services\Blog;

use App\Models\BlogMedia;
use App\Models\BlogPost;
use App\Models\BlogPostRevision;
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
 * actor may write to that region, the category is a known one, the district belongs to the region, tags are tidy, and the
 * body is rebuilt from the editor document by BlogContentService (the browser never supplies HTML).
 *
 * A body arrives as `body_json` (the editor document) or, from older callers, as `body` (Markdown). An article stays in
 * the format it was written in until it is saved from the editor, which makes it a rich one.
 *
 * Optimistic concurrency: `lock_version` rises with every change of the content. A caller that opened the article at
 * version N passes N; if the article moved on, BlogConflictException is raised instead of overwriting the other save.
 */
class BlogPostService
{
    /** Where covers lived before they became blog_media pictures. Old covers there are still served and still cleaned up. */
    public const COVER_DIRECTORY = 'blog/covers';

    /** The private disk covers and pictures live on. */
    public const DISK = 'local';

    /** Pictures the last create / update / restore had to leave out (removed or from another article). */
    public int $droppedPictures = 0;

    public function __construct(
        protected BlogVisibility $visibility,
        protected BlogContentService $content,
        protected BlogMediaService $media,
    ) {}

    /** The private disk covers live on. They are never given a URL: blog.cover and blog.media serve them after a region check. */
    public function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    /**
     * @param  array<string, mixed>  $data  region_id, title, category, and body_json (or Markdown body); optional summary, district_id,
     *                                      event_date, venue, tags, cover_caption, cover_credit, cover_focal_x / _y
     */
    public function create(User $actor, array $data, ?UploadedFile $cover = null, bool $publish = false): BlogPost
    {
        $regionId = (int) ($data['region_id'] ?? 0);
        $this->authorise($actor, $regionId);

        // A new draft may exist before it has any text (the editor autosaves as soon as it has a title and a kind).
        if (! array_key_exists('body_json', $data) && ! array_key_exists('body', $data)) {
            $data['body_json'] = $this->content->emptyDocument();
        }

        $attributes = $this->attributes($data, $regionId);
        $body = $this->bodyAttributes($data, $regionId, null);
        $coverMedia = $cover ? $this->media->store($cover, $actor, $regionId, BlogMedia::KIND_COVER) : null;

        try {
            $post = DB::transaction(function () use ($actor, $attributes, $body, $coverMedia, $publish) {
                $post = BlogPost::query()->create([
                    ...$attributes,
                    ...$body['attributes'],
                    'author_user_id' => $actor->id,
                    'cover_media_id' => $coverMedia?->id,
                    'status' => BlogPost::STATUS_DRAFT,
                    'lock_version' => 1,
                ]);

                $this->media->syncUsage($post, $body['media_ids'], $coverMedia?->id);
                $this->recordRevision($post, $actor, 'save');

                Audit::log('blog_post_created', 'blog', 'BlogPost', $post->id, [
                    'title' => $post->title,
                    'region_id' => $post->region_id,
                ]);

                return $publish ? $this->publishLocked($post, $actor) : $post;
            });
        } catch (\Throwable $e) {
            if ($coverMedia) {
                $this->media->remove($coverMedia, null, 'rolled back');
            }

            throw $e;
        }

        return $post->fresh(['coverMedia']);
    }

    /**
     * Edit an article. The region is fixed once written (an article never moves to another region's blog).
     * A $cover replaces the current photo; $removeCover drops it without a replacement. Pass the version the editor opened
     * as $expectedVersion to refuse overwriting a newer save.
     */
    public function update(BlogPost $post, User $actor, array $data, ?UploadedFile $cover = null, bool $removeCover = false, ?int $expectedVersion = null): BlogPost
    {
        $newCover = $cover ? $this->media->store($cover, $actor, (int) $post->region_id, BlogMedia::KIND_COVER, $post) : null;
        $oldCoverMedia = null;
        $oldCoverPath = null;

        try {
            DB::transaction(function () use ($post, $actor, $data, $newCover, $removeCover, $expectedVersion, &$oldCoverMedia, &$oldCoverPath) {
                $locked = $this->lock($post, $actor);
                $this->guardVersion($locked, $expectedVersion);

                [$oldCoverMedia, $oldCoverPath] = $this->apply($locked, $actor, $data, $newCover, $removeCover);

                $this->recordRevision($locked, $actor, 'save');
            });
        } catch (\Throwable $e) {
            if ($newCover) {
                $this->media->remove($newCover, null, 'rolled back');
            }

            throw $e;
        }

        $this->discardCover($oldCoverMedia, $oldCoverPath);

        return $post->fresh(['coverMedia']);
    }

    /**
     * The editor saving a DRAFT by itself. Only ever touches a draft: a published article changes only by an explicit
     * save. It keeps one rolling "autosave" version instead of a new one at every tick.
     */
    public function autosave(BlogPost $post, User $actor, array $data, ?int $expectedVersion): BlogPost
    {
        DB::transaction(function () use ($post, $actor, $data, $expectedVersion) {
            $locked = $this->lock($post, $actor);

            if ($locked->isPublished()) {
                throw ValidationException::withMessages(['status' => 'A published article is saved with "Save changes", never automatically.']);
            }

            $this->guardVersion($locked, $expectedVersion);
            $this->apply($locked, $actor, $data, null, false);
            $this->recordRevision($locked, $actor, 'autosave');
        });

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

    /** Bring a saved version back as a new save (the version being replaced stays in the list). */
    public function restoreRevision(BlogPost $post, User $actor, int $revisionId, ?int $expectedVersion = null): BlogPost
    {
        DB::transaction(function () use ($post, $actor, $revisionId, $expectedVersion) {
            $locked = $this->lock($post, $actor);
            $this->guardVersion($locked, $expectedVersion);

            $revision = BlogPostRevision::query()->where('blog_post_id', $locked->id)->findOrFail($revisionId);

            $data = ['title' => $revision->title] + ($revision->body_format === BlogPost::FORMAT_RICH && $revision->body_json
                ? ['body_json' => $revision->body_json]
                : ['body' => (string) $revision->body_markdown]);

            $this->apply($locked, $actor, $data, null, false, audit: false);
            $this->recordRevision($locked, $actor, 'restore');

            Audit::log('blog_revision_restored', 'blog', 'BlogPost', $locked->id, [
                'revision_id' => $revision->id,
                'region_id' => $locked->region_id,
            ]);
        });

        return $post->fresh();
    }

    public function delete(BlogPost $post, User $actor): void
    {
        $legacyCover = null;

        DB::transaction(function () use ($post, $actor, &$legacyCover) {
            $locked = $this->lock($post, $actor);
            $legacyCover = $locked->cover_path;

            Audit::log('blog_post_deleted', 'blog', 'BlogPost', $locked->id, [
                'title' => $locked->title,
                'region_id' => $locked->region_id,
                'was_published' => $locked->isPublished(),
            ]);

            // The files go with the article (the rows follow through the foreign keys).
            $this->media->deleteFilesForPost($locked);

            $locked->delete();
        });

        $this->forgetLegacyCover($legacyCover);
    }

    /** Whether a draft has anything to publish (text, or at least one picture or video). */
    public function hasContent(BlogPost $post): bool
    {
        return filled(trim((string) $post->body_text)) || (bool) preg_match('/<(img|iframe)\b/i', (string) $post->body_html);
    }

    // ---------------------------------------------------------------- internals

    /**
     * Write the new content and metadata onto a locked article, keep its pictures in step, and bump the version.
     *
     * @return array{0: ?BlogMedia, 1: ?string} the cover that was replaced or removed (a picture or an old file), to delete after the commit
     */
    protected function apply(BlogPost $locked, User $actor, array $data, ?BlogMedia $newCover, bool $removeCover, bool $audit = true): array
    {
        $changes = $this->attributes($data + $this->currentMeta($locked), (int) $locked->region_id);
        $oldCoverMedia = null;
        $oldCoverPath = null;

        $body = $this->bodyAttributes($data, (int) $locked->region_id, $locked->id);

        if ($body['attributes'] !== []) {
            $changes += $body['attributes'];
        }

        if ($newCover !== null) {
            $oldCoverMedia = $locked->cover_media_id ? $locked->coverMedia : null;
            $oldCoverPath = $locked->cover_path;
            $changes['cover_media_id'] = $newCover->id;
            $changes['cover_path'] = null;
        } elseif ($removeCover && $locked->hasCover()) {
            $oldCoverMedia = $locked->cover_media_id ? $locked->coverMedia : null;
            $oldCoverPath = $locked->cover_path;
            $changes['cover_media_id'] = null;
            $changes['cover_path'] = null;
        }

        $locked->fill($changes);
        $changed = array_keys($locked->getDirty());

        if ($changed !== []) {
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();
        }

        $mediaIds = $body['attributes'] !== [] ? $body['media_ids'] : $this->content->mediaIdsIn((array) $locked->body_json);
        $this->media->syncUsage($locked, $mediaIds, $locked->cover_media_id);

        if ($audit) {
            Audit::log('blog_post_updated', 'blog', 'BlogPost', $locked->id, [
                'title' => $locked->title,
                'region_id' => $locked->region_id,
                'fields' => array_values(array_diff($changed, ['lock_version'])),
            ]);
        }

        return [$oldCoverMedia, $oldCoverPath];
    }

    /** The metadata an update that only sends some fields (the restore) keeps as it is. @return array<string, mixed> */
    protected function currentMeta(BlogPost $post): array
    {
        return [
            'title' => $post->title,
            'category' => $post->category,
            'summary' => $post->summary,
            'district_id' => $post->district_id,
            'event_date' => $post->event_date?->toDateString(),
            'venue' => $post->venue,
            'tags' => $post->tags ?? [],
            'cover_caption' => $post->cover_caption,
            'cover_credit' => $post->cover_credit,
            'cover_focal_x' => $post->cover_focal_x,
            'cover_focal_y' => $post->cover_focal_y,
        ];
    }

    protected function publishLocked(BlogPost $post, User $actor): BlogPost
    {
        if ($post->isPublished()) {
            return $post;
        }

        if (! $this->hasContent($post) && ! filled(trim((string) $post->body))) {
            throw ValidationException::withMessages(['body' => 'Write the article before publishing it.']);
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

    protected function guardVersion(BlogPost $locked, ?int $expectedVersion): void
    {
        if ($expectedVersion === null || (int) $locked->lock_version === $expectedVersion) {
            return;
        }

        $last = BlogPostRevision::query()->where('blog_post_id', $locked->id)->with('user:id,full_name')->latest('id')->first();

        throw new BlogConflictException((int) $locked->lock_version, $last?->user?->full_name, $last?->created_at ?? $locked->updated_at);
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

        $title = trim((string) ($data['title'] ?? ''));

        return [
            'region_id' => $regionId,
            'district_id' => $districtId,
            'title' => $title,
            'slug' => Str::limit(Str::slug($title), 80, '') ?: 'article',
            'category' => $category,
            'summary' => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : null,
            'tags' => $this->cleanTags($data['tags'] ?? []) ?: null,
            'event_date' => filled($data['event_date'] ?? null) ? $data['event_date'] : null,
            'venue' => filled($data['venue'] ?? null) ? trim((string) $data['venue']) : null,
            'cover_caption' => filled($data['cover_caption'] ?? null) ? Str::limit(trim(strip_tags((string) $data['cover_caption'])), 240, '') : null,
            'cover_credit' => filled($data['cover_credit'] ?? null) ? Str::limit(trim(strip_tags((string) $data['cover_credit'])), 120, '') : null,
            'cover_focal_x' => $this->percent($data['cover_focal_x'] ?? null),
            'cover_focal_y' => $this->percent($data['cover_focal_y'] ?? null),
        ];
    }

    protected function percent(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int) round((float) $value))) : null;
    }

    /**
     * The body columns for what the caller sent: an editor document (rendered and sanitised here) or Markdown. Nothing
     * sent means nothing to change.
     *
     * @return array{attributes: array<string, mixed>, media_ids: list<int>}
     */
    protected function bodyAttributes(array $data, int $regionId, ?int $postId): array
    {
        if (array_key_exists('body_json', $data)) {
            $result = $this->content->process($data['body_json'], $regionId, $postId);
            $this->droppedPictures = $result['dropped'];

            return [
                'attributes' => [
                    'body_format' => BlogPost::FORMAT_RICH,
                    'body_json' => $result['json'],
                    'body_html' => $result['html'],
                    'body_text' => $result['text'],
                    'reading_minutes' => $result['reading_minutes'],
                    // The old column mirrors the plain text, so anything still reading it (search, exports) finds the words.
                    'body' => $result['text'],
                ],
                'media_ids' => $result['media_ids'],
            ];
        }

        if (array_key_exists('body', $data)) {
            $markdown = trim((string) $data['body']);
            $html = $this->content->markdownToHtml($markdown);
            $text = $this->content->textOfHtml($html);

            return [
                'attributes' => [
                    'body_format' => BlogPost::FORMAT_MARKDOWN,
                    'body_json' => null,
                    'body' => $markdown,
                    'body_html' => $html,
                    'body_text' => $text,
                    'reading_minutes' => $this->content->readingMinutes($text),
                ],
                'media_ids' => [],
            ];
        }

        return ['attributes' => [], 'media_ids' => []];
    }

    /** Save the article as it now stands as a version, and keep only the newest few. */
    protected function recordRevision(BlogPost $post, ?User $actor, string $reason): void
    {
        $fields = [
            'blog_post_id' => $post->id,
            'user_id' => $actor?->id,
            'title' => Str::limit($post->title, 200, ''),
            'body_format' => $post->body_format,
            'body_json' => $post->isRich() ? $post->body_json : null,
            'body_markdown' => $post->isRich() ? null : $post->body,
            'lock_version' => (int) $post->lock_version,
            'reason' => $reason,
        ];

        // An automatic save rewrites the previous automatic save (same person, recent) instead of piling up versions.
        if ($reason === 'autosave') {
            $latest = BlogPostRevision::query()->where('blog_post_id', $post->id)->latest('id')->first();

            if ($latest && $latest->reason === 'autosave' && $latest->user_id === $actor?->id && $latest->created_at?->gt(now()->subMinutes(10))) {
                $latest->forceFill($fields)->save();

                return;
            }
        }

        BlogPostRevision::query()->create($fields);

        $keep = max(1, (int) config('gwl.blog_revisions_keep', 20));
        $stale = BlogPostRevision::query()->where('blog_post_id', $post->id)->orderByDesc('id')->skip($keep)->take(PHP_INT_MAX)->pluck('id');

        if ($stale->isNotEmpty()) {
            BlogPostRevision::query()->whereIn('id', $stale)->delete();
        }
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

    protected function discardCover(?BlogMedia $media, ?string $legacyPath): void
    {
        if ($media !== null) {
            $this->media->remove($media, null, 'cover replaced');
        }

        $this->forgetLegacyCover($legacyPath);
    }

    protected function forgetLegacyCover(?string $path): void
    {
        if ($path && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }
}
