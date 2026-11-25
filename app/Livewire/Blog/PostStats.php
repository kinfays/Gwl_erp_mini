<?php

namespace App\Livewire\Blog;

use App\Livewire\Blog\Concerns\ScopesBlogByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\BlogPost;
use App\Models\Permission;
use App\Services\Blog\BlogEngagementService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The "Stats" panel on the editor page: views, distinct readers, likes, the last 14 days of views and, on request, who liked the
 * article. For the managers of the article's region only: the article is resolved inside BlogVisibility::scopeManageable() on
 * every render (another region's id is simply not found), and the likers list is checked again in the service. Read-only: nothing here
 * changes an article, so it never touches the editor's concurrency version.
 */
class PostStats extends Component
{
    use EnforcesModuleAccess;
    use ScopesBlogByActor;
    use WithPagination;

    public int $postId;

    public bool $showLikers = false;

    public function mount(int $postId): void
    {
        $this->enforceLivewireModule(Permission::MODULE_BLOG);
        $this->guardBlogManager();
        $this->postId = $postId;
        $this->post();
    }

    public function toggleLikers(): void
    {
        $this->post();
        $this->showLikers = ! $this->showLikers;
        $this->resetPage();
    }

    protected function post(): BlogPost
    {
        $this->guardBlogManager();

        $post = $this->visibility()->scopeManageable(BlogPost::query(), $this->actor())->find($this->postId);

        abort_if($post === null, 404);

        return $post;
    }

    public function render(BlogEngagementService $engagement)
    {
        $post = $this->post();

        return view('livewire.blog.post-stats', [
            'post' => $post,
            'readers' => $engagement->readersOf($post),
            'series' => $engagement->dailyViews([$post->id])[$post->id] ?? [],
            'likers' => $this->showLikers ? $engagement->likers($this->actor(), $post) : null,
        ]);
    }
}
