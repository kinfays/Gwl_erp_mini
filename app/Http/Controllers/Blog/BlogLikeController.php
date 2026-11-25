<?php

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Permission;
use App\Services\Blog\BlogEngagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The like button's two endpoints. Thin: BlogEngagementService decides (a published article of the person's own region, or a 404 the
 * same as for a missing one) and keeps the counter in step. Two explicit, idempotent actions rather than a toggle, so a double click
 * or a request that arrives out of order can never flip the state the person asked for. Each answers the final state and count.
 */
class BlogLikeController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(protected BlogEngagementService $engagement) {}

    public function store(Request $request, BlogPost $post): JsonResponse
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);

        return $this->answer($this->engagement->like($request->user(), $post));
    }

    public function destroy(Request $request, BlogPost $post): JsonResponse
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);

        return $this->answer($this->engagement->unlike($request->user(), $post));
    }

    /** @param  array{liked: bool, count: int}  $state */
    protected function answer(array $state): JsonResponse
    {
        return response()->json($state)->header('Cache-Control', 'no-store, private');
    }
}
