<?php

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\District;
use App\Models\Permission;
use App\Models\Region;
use App\Services\Blog\BlogFeedService;
use App\Services\Blog\BlogPostService;
use App\Services\Blog\BlogVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin: the route middleware, BlogVisibility (through BlogFeedService) and the Livewire components do the real access
 * checks (module, permission, region). An article the user may not read answers 404, the same as one that does not exist,
 * so a staff member of one region cannot find out what another region has published.
 */
class BlogController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(
        protected BlogVisibility $visibility,
        protected BlogPostService $posts,
        protected BlogFeedService $feed,
    ) {}

    public function home(Request $request): View
    {
        $context = $this->context($request);

        $front = $context['hasRegion'] ? $this->feed->front($request->user(), $context['regionId']) : ['lead' => null, 'secondary' => collect()];
        $shown = collect([$front['lead']])->merge($front['secondary'])->filter()->pluck('id')->all();

        return view('blog.home', $context + $front + [
            'stream' => $this->feed->stream($request->user(), $context['regionId'], [], $shown),
            'sidebar' => $this->feed->sidebar($request->user(), $context['regionId']),
        ]);
    }

    public function category(Request $request, string $category): View
    {
        abort_unless(array_key_exists($category, BlogPost::CATEGORIES), 404);

        return $this->listing($request, 'category', BlogPost::CATEGORY_PLURALS[$category], ['category' => $category], $category);
    }

    public function tag(Request $request, string $tag): View
    {
        return $this->listing($request, 'tag', '#'.$tag, ['tag' => mb_strtolower($tag)]);
    }

    public function district(Request $request, District $district): View
    {
        $context = $this->context($request);

        // A district of another region answers 404 like an article would (the id alone must not reveal other regions).
        abort_unless($this->visibility->inRegion($request->user(), (int) $district->region_id), 404);

        return $this->listing($request, 'district', $district->district_name, ['district' => $district->id]);
    }

    public function search(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        return $this->listing($request, 'search', $term === '' ? 'Search' : 'Results for “'.$term.'”', ['q' => $term]);
    }

    public function gallery(Request $request): View
    {
        $context = $this->context($request);

        return view('blog.gallery', $context + [
            'albums' => $context['hasRegion'] ? $this->feed->gallery($request->user(), $context['regionId']) : null,
        ]);
    }

    public function show(Request $request, BlogPost $post, ?string $slug = null): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);
        abort_unless($this->visibility->canRead($request->user(), $post), 404);

        $post->loadMissing(['region', 'district', 'author', 'coverMedia']);

        $context = $this->context($request, (int) $post->region_id);
        $user = $request->user();

        return view('blog.show', $context + [
            'post' => $post,
            'canManage' => $this->visibility->canManagePost($user, $post),
            'related' => $post->isPublished() ? $this->feed->related($user, $post) : collect(),
            'neighbours' => $post->isPublished() ? $this->feed->neighbours($user, $post) : ['older' => null, 'newer' => null],
            'fromDistrict' => $post->isPublished() ? $this->feed->moreFromDistrict($user, $post) : collect(),
        ]);
    }

    /** An older cover (a single private file from before covers became pictures in several sizes). */
    public function cover(Request $request, BlogPost $post): StreamedResponse
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);
        abort_unless($this->visibility->canRead($request->user(), $post), 404);
        abort_unless(filled($post->cover_path) && $this->posts->disk()->exists($post->cover_path), 404);

        return $this->posts->disk()->response($post->cover_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    public function manage(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);

        return view('blog.manage', $this->context($request));
    }

    public function create(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);

        return view('blog.create', $this->context($request));
    }

    public function edit(Request $request, BlogPost $post): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);
        abort_unless($this->visibility->canManagePost($request->user(), $post), 404);

        return view('blog.edit', $this->context($request, (int) $post->region_id) + ['post' => $post]);
    }

    // ---------------------------------------------------------------- shared

    /** One list page (category, tag, district, search) over the same stream, with the same sidebar. */
    protected function listing(Request $request, string $kind, string $heading, array $filters, ?string $activeCategory = null): View
    {
        $context = $this->context($request);

        return view('blog.listing', $context + [
            'kind' => $kind,
            'heading' => $heading,
            'activeCategory' => $activeCategory,
            'filters' => $filters,
            'stream' => $context['hasRegion'] ? $this->feed->stream($request->user(), $context['regionId'], $filters) : null,
            'sidebar' => $this->feed->sidebar($request->user(), $context['regionId']),
        ]);
    }

    /**
     * What every blog page needs: whose region it shows. An ordinary reader sees their own; super_admin sees all regions or
     * the one chosen with ?region= (the id of an article's region is passed for an article page).
     *
     * @return array{regionId: ?int, regionName: ?string, hasRegion: bool, regions: Collection}
     */
    protected function context(Request $request, ?int $articleRegion = null): array
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);

        $user = $request->user();
        $all = $this->visibility->seesAllRegions($user);
        $regions = $this->visibility->regionsFor($user);

        $regionId = $all
            ? ($articleRegion ?? ($request->filled('region') && $regions->contains('id', (int) $request->query('region')) ? (int) $request->query('region') : null))
            : $this->visibility->regionIdOf($user);

        $region = $regionId !== null ? Region::query()->find($regionId) : null;

        return [
            'regionId' => $regionId,
            'regionName' => $region?->region_name,
            'hasRegion' => $all || $regionId !== null,
            'regions' => $all ? $regions : collect(),
        ];
    }
}
