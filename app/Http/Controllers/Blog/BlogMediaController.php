<?php

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\BlogMedia;
use App\Models\BlogPost;
use App\Models\Permission;
use App\Services\Blog\BlogMediaService;
use App\Services\Blog\BlogVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only door to a blog picture. Pictures are on the private disk and have no public address: showing one needs the same
 * login, module and region checks as the article (BlogVisibility::canViewMedia), and anything else answers 404. Uploading
 * needs the writer's permission and a region the writer manages.
 */
class BlogMediaController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(
        protected BlogVisibility $visibility,
        protected BlogMediaService $media,
    ) {}

    public function show(Request $request, BlogMedia $media, string $size): Response|StreamedResponse
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);
        abort_unless($this->visibility->canViewMedia($request->user(), $media), 404);

        $variant = $media->variant($size);
        abort_if($variant === null, 404);

        // WebP where the browser says it takes it (the answer then differs by Accept), the re-encoded JPEG / PNG otherwise.
        $webp = filled($variant['webp'] ?? null) && str_contains((string) $request->header('Accept'), 'image/webp');
        $path = $webp ? $variant['webp'] : $variant['path'];
        $disk = $this->media->disk();

        abort_unless($disk->exists($path), 404);

        $response = $disk->response($path, null, [
            'Content-Type' => $webp ? 'image/webp' : $media->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Vary' => 'Accept',
        ]);

        $response->setPrivate();
        $response->setMaxAge(86400);
        $response->setEtag(md5($media->id.'|'.$size.'|'.($webp ? 'w' : 'o').'|'.$media->updated_at?->timestamp));
        $response->setLastModified($media->updated_at?->toDateTime() ?? now()->toDateTime());

        // A browser that already holds this picture is told so; a 304 carries no body and, here, no region data.
        $response->isNotModified($request);

        return $response;
    }

    public function store(Request $request): JsonResponse
    {
        $this->enforceModule($request, Permission::MODULE_BLOG);
        // A writer with no region (a PR officer whose account is not linked to one) has nowhere to put a picture.
        abort_unless($this->visibility->canManage($request->user()), 403, 'You cannot add pictures to the blog.');

        $data = $request->validate([
            'file' => ['required', 'file'],
            'kind' => ['nullable', 'in:inline,gallery'],
            'post_id' => ['nullable', 'integer'],
            'region_id' => ['nullable', 'integer'],
            'alt' => ['nullable', 'string', 'max:300'],
            'caption' => ['nullable', 'string', 'max:300'],
            'credit' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $post = null;

        if (filled($data['post_id'] ?? null)) {
            // Resolved inside the writer's own part of the blog: an id from another region is simply not found.
            $post = $this->visibility->scopeManageable(BlogPost::query(), $user)->findOrFail((int) $data['post_id']);
            $regionId = (int) $post->region_id;
        } else {
            $regionId = $this->visibility->seesAllRegions($user)
                ? (int) ($data['region_id'] ?? 0)
                : (int) $this->visibility->regionIdOf($user);
        }

        abort_unless($regionId > 0, 422, 'Choose the region first.');

        $media = $this->media->store($request->file('file'), $user, $regionId, $data['kind'] ?? BlogMedia::KIND_INLINE, $post, [
            'alt' => $data['alt'] ?? null,
            'caption' => $data['caption'] ?? null,
            'credit' => $data['credit'] ?? null,
        ]);

        return response()->json([
            'id' => $media->id,
            'kind' => $media->kind,
            'width' => $media->width,
            'height' => $media->height,
            'thumb' => $media->url('thumb'),
            'medium' => $media->url('medium'),
            'large' => $media->url('large'),
        ], 201);
    }
}
