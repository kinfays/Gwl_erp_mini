<?php

namespace App\Livewire\Blog;

use App\Livewire\Blog\Concerns\ScopesBlogByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\BlogPost;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The region's blog as its staff read it: published articles of their own region, pinned first, then newest. Which
 * articles appear is decided only by BlogVisibility::scopeFeed(). super_admin (no region of its own) may pick a region.
 */
class Feed extends Component
{
    use EnforcesModuleAccess;
    use ScopesBlogByActor;
    use WithPagination;

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $search = '';

    /** Only offered to (and honoured for) someone who sees every region. */
    #[Url(except: '')]
    public string $regionId = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_BLOG);

        if (! array_key_exists($this->category, BlogPost::CATEGORIES)) {
            $this->category = '';
        }
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function searchTag(string $tag): void
    {
        $this->search = $tag;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['category', 'search', 'regionId']);
        $this->resetPage();
    }

    public function render()
    {
        $actor = $this->actor();
        $visibility = $this->visibility();
        $term = trim($this->search);

        $posts = $visibility->scopeFeed(BlogPost::query(), $actor)
            ->with(['region', 'district'])
            ->when(
                $visibility->seesAllRegions($actor) && ctype_digit($this->regionId),
                fn (Builder $query) => $query->where('blog_posts.region_id', (int) $this->regionId)
            )
            ->when(array_key_exists($this->category, BlogPost::CATEGORIES), fn (Builder $query) => $query->where('blog_posts.category', $this->category))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('blog_posts.title', 'like', '%'.$term.'%')
                ->orWhere('blog_posts.summary', 'like', '%'.$term.'%')
                ->orWhere('blog_posts.body', 'like', '%'.$term.'%')
                ->orWhere('blog_posts.venue', 'like', '%'.$term.'%')
                ->orWhere('blog_posts.tags', 'like', '%'.$term.'%')))
            ->feedOrder()
            ->paginate(9);

        $regions = $visibility->regionsFor($actor);

        return view('livewire.blog.feed', [
            'posts' => $posts,
            'categories' => BlogPost::CATEGORIES,
            'regions' => $regions,
            'pickRegion' => $visibility->seesAllRegions($actor),
            'hasRegion' => $visibility->seesAllRegions($actor) || $visibility->regionIdOf($actor) !== null,
            'regionName' => $visibility->seesAllRegions($actor) ? null : $regions->first()?->region_name,
            'canManage' => $visibility->canManage($actor),
        ]);
    }
}
