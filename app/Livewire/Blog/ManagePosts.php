<?php

namespace App\Livewire\Blog;

use App\Livewire\Blog\Concerns\ScopesBlogByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\BlogPost;
use App\Models\Permission;
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

    public function render()
    {
        $term = trim($this->search);

        $posts = $this->visibility()->scopeManageable(BlogPost::query(), $this->actor())
            ->with(['region', 'district', 'author'])
            ->when(array_key_exists($this->status, BlogPost::STATUSES), fn (Builder $query) => $query->where('blog_posts.status', $this->status))
            ->when(array_key_exists($this->category, BlogPost::CATEGORIES), fn (Builder $query) => $query->where('blog_posts.category', $this->category))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('blog_posts.title', 'like', '%'.$term.'%')
                ->orWhere('blog_posts.venue', 'like', '%'.$term.'%')
                ->orWhere('blog_posts.tags', 'like', '%'.$term.'%')))
            ->orderByDesc('blog_posts.updated_at')
            ->orderByDesc('blog_posts.id')
            ->paginate(15);

        return view('livewire.blog.manage-posts', [
            'posts' => $posts,
            'statuses' => BlogPost::STATUSES,
            'categories' => BlogPost::CATEGORIES,
            'showRegion' => $this->visibility()->seesAllRegions($this->actor()),
        ]);
    }
}
