<?php

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Permission;
use App\Services\Blog\BlogPostService;
use App\Services\Blog\BlogVisibility;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin: the route middleware, the Livewire components and BlogVisibility do the real access checks (module, permission,
 * region). An article the user may not read answers 404, the same as one that does not exist, so a staff member of one
 * region cannot find out what another region has published.
 */
class BlogController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(
        protected BlogVisibility $visibility,
        protected BlogPostService $posts,
    ) {}

    public function home(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);

        return view('blog.home');
    }

    public function show(Request $request, BlogPost $post): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);
        abort_unless($this->visibility->canRead($request->user(), $post), 404);

        $post->loadMissing(['region', 'district', 'author']);

        return view('blog.show', [
            'post' => $post,
            'canManage' => $this->visibility->canManagePost($request->user(), $post),
            'showRegion' => $this->visibility->seesAllRegions($request->user()),
        ]);
    }

    /** The cover photo. Private disk, so it is only ever reachable through here, after the same check as the article. */
    public function cover(Request $request, BlogPost $post): StreamedResponse
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);
        abort_unless($this->visibility->canRead($request->user(), $post), 404);
        abort_unless($post->hasCover() && $this->posts->disk()->exists($post->cover_path), 404);

        return $this->posts->disk()->response($post->cover_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    public function manage(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);

        return view('blog.manage');
    }

    public function create(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);

        return view('blog.create');
    }

    public function edit(Request $request, BlogPost $post): View
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);
        abort_unless($this->visibility->canManagePost($request->user(), $post), 404);

        return view('blog.edit', ['post' => $post]);
    }
}
