<?php

namespace App\Services\Blog;

use App\Models\BlogMedia;
use App\Models\BlogPost;
use App\Models\District;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every query behind the reader pages of the standalone blog (home, lists, article, gallery, sidebar). Nothing here decides who
 * may see what: each query starts from BlogVisibility::scopeFeed(), so the pages cannot show more than the feed would.
 * super_admin (no region of its own) may narrow to one region with $regionId; for everyone else the id is ignored.
 */
class BlogFeedService
{
    /** What a card or row needs, eager loaded so a page costs a fixed number of queries. */
    protected const CARD_RELATIONS = ['district:id,district_name', 'author:id,full_name', 'coverMedia', 'region:id,region_name'];

    public function __construct(protected BlogVisibility $visibility) {}

    /** The published articles the user may read, optionally narrowed to one region (super_admin only). */
    public function base(User $user, ?int $regionId = null): Builder
    {
        return $this->visibility->scopeFeed(BlogPost::query(), $user)
            ->when(
                $regionId !== null && $this->visibility->seesAllRegions($user),
                fn (Builder $query) => $query->where('blog_posts.region_id', $regionId)
            );
    }

    /**
     * The front page: a lead story (pinned first, then newest) and up to three secondary stories.
     *
     * @return array{lead: ?BlogPost, secondary: Collection<int, BlogPost>}
     */
    public function front(User $user, ?int $regionId): array
    {
        $stories = $this->base($user, $regionId)->with(self::CARD_RELATIONS)->feedOrder()->limit(4)->get();

        return ['lead' => $stories->first(), 'secondary' => $stories->slice(1)->values()];
    }

    /**
     * The "latest" stream. Filters: category, district (id), tag, q (search). $exclude hides the stories already shown above.
     *
     * @param  array{category?: ?string, district?: ?int, tag?: ?string, q?: ?string}  $filters
     * @param  list<int>  $exclude
     */
    public function stream(User $user, ?int $regionId, array $filters = [], array $exclude = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->base($user, $regionId)->with(self::CARD_RELATIONS);

        if (filled($filters['category'] ?? null) && array_key_exists($filters['category'], BlogPost::CATEGORIES)) {
            $query->where('blog_posts.category', $filters['category']);
        }

        if (filled($filters['district'] ?? null)) {
            $query->where('blog_posts.district_id', (int) $filters['district']);
        }

        if (filled($filters['tag'] ?? null)) {
            // Tags are stored lower-cased as a JSON list; the quoted form matches a whole tag, never part of another.
            $query->where('blog_posts.tags', 'like', '%"'.str_replace(['%', '_', '"', '\\'], '', $filters['tag']).'"%');
        }

        if (filled($term = trim((string) ($filters['q'] ?? '')))) {
            // Wildcard characters typed by the reader are taken out rather than escaped: LIKE escaping differs between MySQL and SQLite.
            $like = '%'.str_replace(['\\', '%', '_'], '', $term).'%';

            $query->where(fn (Builder $match) => $match
                ->where('blog_posts.title', 'like', $like)
                ->orWhere('blog_posts.summary', 'like', $like)
                ->orWhere('blog_posts.body_text', 'like', $like)
                ->orWhere('blog_posts.venue', 'like', $like)
                ->orWhere('blog_posts.tags', 'like', $like));
        }

        if ($exclude !== []) {
            $query->whereNotIn('blog_posts.id', $exclude);
        }

        return $query->feedOrder()->paginate($perPage)->withQueryString();
    }

    /**
     * The sidebar: what is coming up, what is pinned, the districts with articles, the commonest tags.
     *
     * @return array{events: Collection<int, BlogPost>, pinned: Collection<int, BlogPost>, districts: Collection<int, object>, tags: Collection<string, int>}
     */
    public function sidebar(User $user, ?int $regionId): array
    {
        $events = $this->base($user, $regionId)
            ->with(['district:id,district_name'])
            ->whereDate('blog_posts.event_date', '>=', now()->toDateString())
            ->orderBy('blog_posts.event_date')
            ->orderBy('blog_posts.id')
            ->limit(5)
            ->get();

        $pinned = $this->base($user, $regionId)->where('blog_posts.is_pinned', true)->newestFirst()->limit(4)->get(['id', 'title', 'slug', 'published_at', 'region_id']);

        $counts = $this->base($user, $regionId)->whereNotNull('blog_posts.district_id')->selectRaw('blog_posts.district_id as district_id, count(*) as total')
            ->groupBy('blog_posts.district_id')->pluck('total', 'district_id');

        $districts = District::query()->whereIn('id', $counts->keys())->orderBy('district_name')->get(['id', 'district_name'])
            ->map(fn (District $district) => (object) ['id' => $district->id, 'name' => $district->district_name, 'total' => (int) $counts[$district->id]]);

        $tags = $this->base($user, $regionId)->whereNotNull('blog_posts.tags')->newestFirst()->limit(300)->pluck('tags')
            ->flatten()->filter()->countBy()->sortDesc()->take(14);

        return ['events' => $events, 'pinned' => $pinned, 'districts' => $districts, 'tags' => $tags];
    }

    /**
     * Articles of the SAME region on the same subject: shared tags first, then the same kind, then the newest.
     *
     * @return Collection<int, BlogPost>
     */
    public function related(User $user, BlogPost $post, int $limit = 4): Collection
    {
        $candidates = $this->base($user, (int) $post->region_id)
            ->with(self::CARD_RELATIONS)
            ->where('blog_posts.id', '!=', $post->id)
            ->newestFirst()
            ->limit(40)
            ->get();

        $tags = $post->tags ?? [];

        return $candidates
            ->sortByDesc(fn (BlogPost $other) => count(array_intersect($tags, $other->tags ?? [])) * 10 + ($other->category === $post->category ? 3 : 0) + ($other->district_id && $other->district_id === $post->district_id ? 2 : 0))
            ->take($limit)
            ->values();
    }

    /** @return array{older: ?BlogPost, newer: ?BlogPost} the neighbours of an article in its region's timeline */
    public function neighbours(User $user, BlogPost $post): array
    {
        $region = $this->base($user, (int) $post->region_id)->select(['id', 'title', 'slug', 'published_at', 'region_id']);
        $at = $post->published_at ?? $post->created_at;

        $older = (clone $region)->where(fn (Builder $q) => $q
            ->where('blog_posts.published_at', '<', $at)
            ->orWhere(fn (Builder $tie) => $tie->where('blog_posts.published_at', $at)->where('blog_posts.id', '<', $post->id)))
            ->newestFirst()->first();

        $newer = (clone $region)->where(fn (Builder $q) => $q
            ->where('blog_posts.published_at', '>', $at)
            ->orWhere(fn (Builder $tie) => $tie->where('blog_posts.published_at', $at)->where('blog_posts.id', '>', $post->id)))
            ->orderBy('blog_posts.published_at')->orderBy('blog_posts.id')->first();

        return ['older' => $older, 'newer' => $newer];
    }

    /** @return Collection<int, BlogPost> */
    public function moreFromDistrict(User $user, BlogPost $post, int $limit = 3): Collection
    {
        if ($post->district_id === null) {
            return collect();
        }

        return $this->base($user, (int) $post->region_id)
            ->where('blog_posts.district_id', $post->district_id)
            ->where('blog_posts.id', '!=', $post->id)
            ->with(['district:id,district_name', 'coverMedia'])
            ->newestFirst()
            ->limit($limit)
            ->get();
    }

    /**
     * The photo gallery: pictures of published articles, grouped by article (newest article first).
     *
     * @return LengthAwarePaginator<int, BlogPost> articles that have pictures, each with its `gallery` collection of BlogMedia
     */
    public function gallery(User $user, ?int $regionId, int $perPage = 8): LengthAwarePaginator
    {
        $posts = $this->base($user, $regionId)
            ->whereHas('media', fn (Builder $media) => $this->visibility->scopeGallery($media, $user))
            ->with(['district:id,district_name', 'region:id,region_name'])
            ->newestFirst()
            ->paginate($perPage)
            ->withQueryString();

        $media = $this->visibility->scopeGallery(BlogMedia::query(), $user)
            ->whereIn('blog_post_id', $posts->getCollection()->modelKeys())
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('blog_post_id');

        $posts->getCollection()->each(fn (BlogPost $post) => $post->setAttribute('gallery', $media->get($post->id, collect())));

        return $posts;
    }
}
