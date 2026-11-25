<?php

namespace App\Livewire\Blog;

use App\Livewire\Blog\Concerns\ScopesBlogByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\BlogPost;
use App\Models\Permission;
use App\Services\Blog\BlogEngagementService;
use App\Services\Blog\BlogPostService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The PR Officer's list: every article of their region, drafts included, with publish / unpublish / pin / delete. Every
 * action goes through BlogPostService, which re-checks the region on a locked copy; the id from the browser is never
 * trusted on its own.
 */
class ManagePosts extends Component
{
    use EnforcesModuleAccess;
    use ScopesBlogByActor;
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $search = '';

    /** Sort by an engagement figure: views, readers or likes (blank = last updated first). Only these three words are accepted. */
    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'desc')]
    public string $dir = 'desc';

    /** Feedback for the last action, shown above the table. */
    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_BLOG);
        $this->guardBlogManager();
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    /** Click a figure's heading: sort by it (largest first), click again to flip the direction. */
    public function sortBy(string $column): void
    {
        if (! in_array($column, ['views', 'readers', 'likes'], true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->dir = $this->dir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sort = $column;
            $this->dir = 'desc';
        }

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['status', 'category', 'search']);
        $this->resetPage();
    }

    public function publish(int $postId, BlogPostService $posts): void
    {
        $this->act(fn (BlogPost $post) => $posts->publish($post, $this->actor()), $postId, 'Published. Staff of the region can now read it.');
    }

    public function unpublish(int $postId, BlogPostService $posts): void
    {
        $this->act(fn (BlogPost $post) => $posts->unpublish($post, $this->actor()), $postId, 'Moved back to drafts. Readers no longer see it.');
    }

    public function pin(int $postId, BlogPostService $posts): void
    {
        $this->act(fn (BlogPost $post) => $posts->setPinned($post, $this->actor(), true), $postId, 'Pinned to the top of the blog.');
    }

    public function unpin(int $postId, BlogPostService $posts): void
    {
        $this->act(fn (BlogPost $post) => $posts->setPinned($post, $this->actor(), false), $postId, 'Unpinned.');
    }

    /** The article stays published; it just stops being shown at login and in the announcement strip. */
    public function endAnnouncement(int $postId, BlogPostService $posts): void
    {
        $this->act(fn (BlogPost $post) => $posts->endAnnouncement($post, $this->actor()), $postId, 'The announcement has ended. The article is still published.');
    }

    public function delete(int $postId, BlogPostService $posts): void
    {
        $this->act(fn (BlogPost $post) => $posts->delete($post, $this->actor()), $postId, 'Article deleted.');
    }

    /** Resolve the id inside the actor's own part of the blog (a foreign id is simply not found), run the action, report. */
    protected function act(\Closure $action, int $postId, string $success): void
    {
        $this->guardBlogManager();
        $this->notice = $this->problem = null;

        $post = $this->visibility()->scopeManageable(BlogPost::query(), $this->actor())->find($postId);

        abort_if($post === null, 404);

        try {
            $action($post);
            $this->notice = $success;
        } catch (ValidationException $e) {
            $this->problem = collect($e->errors())->flatten()->first();
        }
    }

    public function render(BlogEngagementService $engagement)
    {
        $term = trim($this->search);
        $direction = $this->dir === 'asc' ? 'asc' : 'desc';
        // Whitelisted columns only; "readers" is the number of rows in blog_post_reads (withCount, an indexed subquery).
        $sortColumn = ['views' => 'blog_posts.view_count', 'likes' => 'blog_posts.like_count', 'readers' => 'reads_count'][$this->sort] ?? null;

        $posts = $this->visibility()->scopeManageable(BlogPost::query(), $this->actor())
            ->with(['region', 'district', 'author'])
            ->withCount('reads')
            ->when(array_key_exists($this->status, BlogPost::STATUSES), fn (Builder $query) => $query->where('blog_posts.status', $this->status))
            ->when(array_key_exists($this->category, BlogPost::CATEGORIES), fn (Builder $query) => $query->where('blog_posts.category', $this->category))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('blog_posts.title', 'like', '%'.$term.'%')
                ->orWhere('blog_posts.venue', 'like', '%'.$term.'%')
                ->orWhere('blog_posts.tags', 'like', '%'.$term.'%')))
            ->when($sortColumn !== null, fn (Builder $query) => $query->orderBy($sortColumn, $direction))
            ->orderByDesc('blog_posts.updated_at')
            ->orderByDesc('blog_posts.id')
            ->paginate(15);

        return view('livewire.blog.manage-posts', [
            'posts' => $posts,
            // One query for the whole page: the last 14 days of views of each row.
            'series' => $engagement->dailyViews($posts->getCollection()->modelKeys()),
            'statuses' => BlogPost::STATUSES,
            'categories' => BlogPost::CATEGORIES,
            'showRegion' => $this->visibility()->seesAllRegions($this->actor()),
        ]);
    }
}
