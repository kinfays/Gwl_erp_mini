<?php

namespace App\Services\Blog;

use App\Models\BlogMedia;
use App\Models\BlogPost;
use App\Models\BlogPostRevision;
use App\Models\User;
use App\Support\Audit;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every picture of the Regional Blog goes through here: stored, replaced, described, counted, removed.
 *
 * What an upload may be is decided by its CONTENT, never by the name or the type the browser claims: it must decode as a JPEG,
 * PNG, WebP or GIF (first frame) within the configured size and pixel limits. SVG and everything else are refused. The
 * picture is then rebuilt with GD (auto-oriented, then re-encoded), which drops EXIF (GPS included), comments and any
 * polyglot payload, and the thumb / medium / large sizes and a WebP of each are generated. All of it goes to the private
 * `local` disk; nothing here has a public URL (BlogMediaController serves it after a BlogVisibility check).
 */
class BlogMediaService
{
    public const DIRECTORY = 'blog/media';

    /** The biggest the stored "original" gets, on its longest side. Larger uploads are scaled down to this. */
    public const ORIGINAL_MAX = 2560;

    protected const TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_GIF => 'image/gif',
    ];

    public function __construct(
        protected BlogVisibility $visibility,
        protected BlogContentService $content,
    ) {}

    public function disk(): FilesystemAdapter
    {
        return Storage::disk(BlogPostService::DISK);
    }

    /**
     * Check, rebuild and store one picture. Throws a ValidationException on `file` for anything the author can fix.
     *
     * @param  array{alt?: ?string, caption?: ?string, credit?: ?string}  $meta
     */
    public function store(UploadedFile $file, User $actor, int $regionId, string $kind, ?BlogPost $post = null, array $meta = []): BlogMedia
    {
        abort_unless($this->visibility->canManageIn($actor, $regionId), 403, 'You cannot add pictures to this region\'s blog.');
        abort_unless(in_array($kind, BlogMedia::KINDS, true), 422);

        if ($post !== null) {
            abort_unless((int) $post->region_id === $regionId && $this->visibility->canManagePost($actor, $post), 403, 'You cannot manage this article.');
        }

        $this->guardCount($actor, $regionId, $post);

        $bytes = $this->readUpload($file);
        [$image, $type, $mime] = $this->decode($bytes);

        $written = [];

        try {
            $image = $this->orient($image, $type === IMAGETYPE_JPEG ? $this->jpegOrientation($bytes) : 1);
            $alpha = $type !== IMAGETYPE_JPEG && $this->hasTransparency($image);
            $format = $alpha ? 'png' : 'jpeg';
            $extension = $alpha ? 'png' : 'jpg';

            $width = imagesx($image);
            $height = imagesy($image);

            $base = self::DIRECTORY.'/'.$regionId.'/'.now()->format('Y/m').'/'.Str::uuid();

            // The stored original: the same picture re-encoded, no bigger than ORIGINAL_MAX.
            $original = $this->fit($image, self::ORIGINAL_MAX, $alpha);
            $originalPath = $base.'-original.'.$extension;
            $this->put($originalPath, $this->encode($original, $format), $written);
            $originalWidth = imagesx($original);
            $originalHeight = imagesy($original);

            $variants = [];
            $previous = null;

            foreach (BlogMedia::SIZES as $name => $target) {
                $targetWidth = min($target, $originalWidth);

                // A small picture has one real size: the larger names point at the same files instead of copies.
                if ($previous !== null && $previous['w'] === $targetWidth) {
                    $variants[$name] = $previous;

                    continue;
                }

                $resized = $targetWidth === $originalWidth ? $original : $this->resize($original, $targetWidth, $alpha);
                $path = $base.'-'.$name.'.'.$extension;
                $webp = $base.'-'.$name.'.webp';

                $this->put($path, $this->encode($resized, $format), $written);
                $this->put($webp, $this->encode($resized, 'webp'), $written);

                $variants[$name] = $previous = ['path' => $path, 'webp' => $webp, 'w' => imagesx($resized), 'h' => imagesy($resized)];

                if ($resized !== $original) {
                    imagedestroy($resized);
                }
            }

            $media = BlogMedia::query()->create([
                'region_id' => $regionId,
                'blog_post_id' => $post?->id,
                'uploaded_by' => $actor->id,
                'kind' => $kind,
                'mime' => $alpha ? 'image/png' : 'image/jpeg',
                'width' => $originalWidth,
                'height' => $originalHeight,
                'bytes' => $this->disk()->size($originalPath),
                'path' => $originalPath,
                'variants' => $variants,
                'alt' => $this->text($meta['alt'] ?? null, 300),
                'caption' => $this->text($meta['caption'] ?? null, 300),
                'credit' => $this->text($meta['credit'] ?? null, 120),
                'sort_order' => 0,
                'in_use' => false,
            ]);

            imagedestroy($original);
            imagedestroy($image);
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                $this->disk()->delete($path);
            }

            throw $e;
        }

        // Ids and sizes only: never the file name, the contents or the caption.
        Audit::log('blog_media_uploaded', 'blog', 'BlogMedia', $media->id, [
            'region_id' => $regionId,
            'post_id' => $post?->id,
            'kind' => $kind,
            'bytes' => $media->bytes,
            'width' => $media->width,
            'height' => $media->height,
        ]);

        return $media;
    }

    /** Change the description of a picture (alt text, caption, credit). */
    public function describe(BlogMedia $media, User $actor, array $meta): BlogMedia
    {
        abort_unless($this->visibility->canManageIn($actor, (int) $media->region_id), 403);

        $media->forceFill([
            'alt' => $this->text($meta['alt'] ?? $media->alt, 300),
            'caption' => $this->text($meta['caption'] ?? $media->caption, 300),
            'credit' => $this->text($meta['credit'] ?? $media->credit, 120),
        ])->save();

        return $media;
    }

    /** Remove a picture and all its files. */
    public function remove(BlogMedia $media, ?User $actor = null, string $reason = 'removed'): void
    {
        if ($actor !== null) {
            abort_unless($this->visibility->canManageIn($actor, (int) $media->region_id), 403);
        }

        $id = $media->id;
        $postId = $media->blog_post_id;
        $regionId = $media->region_id;

        $this->deleteFiles($media);
        $media->delete();

        Audit::log('blog_media_removed', 'blog', 'BlogMedia', $id, ['region_id' => $regionId, 'post_id' => $postId, 'reason' => $reason]);
    }

    /** Delete the files of every picture of an article (the rows go with the article). */
    public function deleteFilesForPost(BlogPost $post): void
    {
        BlogMedia::query()->where('blog_post_id', $post->id)->get()->each(fn (BlogMedia $media) => $this->deleteFiles($media));
    }

    public function deleteFiles(BlogMedia $media): void
    {
        foreach ($media->allPaths() as $path) {
            if ($this->disk()->exists($path)) {
                $this->disk()->delete($path);
            }
        }
    }

    /**
     * After an article is saved: pictures it now shows belong to it and are "in use" (so readers may be given them), in the
     * order they appear; the others it holds are marked not in use (they stay for the saved versions until pruned).
     *
     * @param  list<int>  $orderedIds  pictures in the body, in reading order
     */
    public function syncUsage(BlogPost $post, array $orderedIds, ?int $coverMediaId): void
    {
        BlogMedia::query()->where('blog_post_id', $post->id)->update(['in_use' => false]);

        $ids = $coverMediaId !== null ? [...$orderedIds, $coverMediaId] : $orderedIds;

        if ($ids === []) {
            return;
        }

        BlogMedia::query()
            ->whereIn('id', $ids)
            ->where('region_id', $post->region_id)
            ->where(fn ($query) => $query->whereNull('blog_post_id')->orWhere('blog_post_id', $post->id))
            ->update(['blog_post_id' => $post->id, 'in_use' => true]);

        foreach (array_values($orderedIds) as $order => $id) {
            BlogMedia::query()->whereKey($id)->where('blog_post_id', $post->id)->where('sort_order', '!=', $order)->update(['sort_order' => $order]);
        }
    }

    /**
     * The clean-up behind `blog:prune-media`: pictures uploaded but never put in an article, and pictures an article no
     * longer shows (and none of its saved versions do either), once they are older than the configured hours.
     *
     * @return array{unattached: int, unused: int}
     */
    public function pruneOrphans(): array
    {
        $cutoff = now()->subHours(max(1, (int) config('gwl.blog_media_orphan_hours', 24)));
        $counts = ['unattached' => 0, 'unused' => 0];

        BlogMedia::query()->whereNull('blog_post_id')->where('created_at', '<', $cutoff)->each(function (BlogMedia $media) use (&$counts) {
            $this->remove($media, null, 'orphan');
            $counts['unattached']++;
        });

        $candidates = BlogMedia::query()->whereNotNull('blog_post_id')->where('in_use', false)->where('updated_at', '<', $cutoff)->get()->groupBy('blog_post_id');

        foreach ($candidates as $postId => $items) {
            $kept = [];

            foreach (BlogPostRevision::query()->where('blog_post_id', $postId)->whereNotNull('body_json')->pluck('body_json') as $document) {
                array_push($kept, ...$this->content->mediaIdsIn((array) $document));
            }

            foreach ($items as $media) {
                if (! in_array($media->id, $kept, true)) {
                    $this->remove($media, null, 'unused');
                    $counts['unused']++;
                }
            }
        }

        return $counts;
    }

    // ------------------------------------------------------------------ checks

    protected function guardCount(User $actor, int $regionId, ?BlogPost $post): void
    {
        $max = max(1, (int) config('gwl.blog_media_max_per_article', 60));

        $count = $post !== null
            ? BlogMedia::query()->where('blog_post_id', $post->id)->count()
            : BlogMedia::query()->whereNull('blog_post_id')->where('uploaded_by', $actor->id)->where('region_id', $regionId)->count();

        if ($count >= $max) {
            throw ValidationException::withMessages(['file' => "An article can hold at most {$max} pictures."]);
        }
    }

    protected function readUpload(UploadedFile $file): string
    {
        $maxBytes = max(1, (int) config('gwl.blog_media_max_mb', 8)) * 1024 * 1024;

        if (! $file->isValid() || $file->getRealPath() === false) {
            throw ValidationException::withMessages(['file' => 'The picture could not be uploaded. Try again.']);
        }

        if ((int) $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'The picture is too large. The most allowed is '.(int) config('gwl.blog_media_max_mb', 8).' MB.']);
        }

        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['file' => 'The picture could not be read.']);
        }

        return $bytes;
    }

    /** @return array{0: GdImage, 1: int, 2: string} */
    protected function decode(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            throw ValidationException::withMessages(['file' => 'Only JPG, PNG, WebP or GIF pictures are accepted.']);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($mime !== self::TYPES[$info[2]]) {
            throw ValidationException::withMessages(['file' => 'Only JPG, PNG, WebP or GIF pictures are accepted.']);
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $maxSide = max(1, (int) config('gwl.blog_media_max_dimension', 6000));
        $maxPixels = max(1, (int) config('gwl.blog_media_max_megapixels', 24)) * 1_000_000;

        if ($width < 1 || $height < 1 || $width > $maxSide || $height > $maxSide || $width * $height > $maxPixels) {
            throw ValidationException::withMessages(['file' => "The picture is too big: at most {$maxSide} pixels on a side and ".(int) config('gwl.blog_media_max_megapixels', 24).' megapixels.']);
        }

        // GD holds about 4 bytes a pixel and the work makes copies: refuse what would exhaust the memory instead of crashing.
        $limit = $this->memoryLimit();

        if ($limit > 0 && memory_get_usage(true) + $width * $height * 10 > $limit) {
            throw ValidationException::withMessages(['file' => 'The picture is too big to be processed here. Reduce its size and try again.']);
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages(['file' => 'The picture could not be read. It may be damaged.']);
        }

        return [$image, $info[2], $mime];
    }

    protected function memoryLimit(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $number = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    // ------------------------------------------------------------------ image work

    /**
     * The EXIF orientation of a JPEG (1 when there is none). The exif extension is not required: the tag is read from the
     * file's own bytes. Only used to turn the picture upright, the data itself is dropped when it is re-encoded.
     */
    public function jpegOrientation(string $bytes): int
    {
        $length = strlen($bytes);

        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $offset = 2;

        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xD9 || $marker === 0xDA) {
                break;
            }

            $size = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);

            if ($marker === 0xE1 && substr($bytes, $offset + 4, 6) === "Exif\0\0") {
                return $this->orientationFromTiff($bytes, $offset + 10, min($length, $offset + 2 + $size));
            }

            $offset += 2 + $size;
        }

        return 1;
    }

    protected function orientationFromTiff(string $bytes, int $tiff, int $end): int
    {
        $little = substr($bytes, $tiff, 2) === 'II';

        $u16 = function (int $at) use ($bytes, $tiff, $end, $little): ?int {
            if ($tiff + $at + 2 > $end) {
                return null;
            }

            $pair = substr($bytes, $tiff + $at, 2);

            return $little ? (ord($pair[1]) << 8 | ord($pair[0])) : (ord($pair[0]) << 8 | ord($pair[1]));
        };

        $u32 = function (int $at) use ($bytes, $tiff, $end, $little): ?int {
            if ($tiff + $at + 4 > $end) {
                return null;
            }

            $quad = substr($bytes, $tiff + $at, 4);

            return $little
                ? (ord($quad[3]) << 24 | ord($quad[2]) << 16 | ord($quad[1]) << 8 | ord($quad[0]))
                : (ord($quad[0]) << 24 | ord($quad[1]) << 16 | ord($quad[2]) << 8 | ord($quad[3]));
        };

        $ifd = $u32(4);
        $entries = $ifd === null ? null : $u16($ifd);

        if ($entries === null || $entries > 200) {
            return 1;
        }

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + 12 * $i;

            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    protected function orient(GdImage $image, int $orientation): GdImage
    {
        $white = 0;

        return match ($orientation) {
            2 => $this->flipped($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, $white) ?: $image,
            4 => $this->flipped($image, IMG_FLIP_VERTICAL),
            5 => $this->flipped(imagerotate($image, -90, $white) ?: $image, IMG_FLIP_HORIZONTAL),
            6 => imagerotate($image, -90, $white) ?: $image,
            7 => $this->flipped(imagerotate($image, 90, $white) ?: $image, IMG_FLIP_HORIZONTAL),
            8 => imagerotate($image, 90, $white) ?: $image,
            default => $image,
        };
    }

    protected function flipped(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    /** Whether any part of a picture is see-through (checked on a 64 x 64 copy, which is enough to choose PNG over JPEG). */
    protected function hasTransparency(GdImage $image): bool
    {
        $probe = imagecreatetruecolor(64, 64);
        imagealphablending($probe, false);
        imagesavealpha($probe, true);
        imagefill($probe, 0, 0, imagecolorallocatealpha($probe, 0, 0, 0, 0));
        imagecopyresampled($probe, $image, 0, 0, 0, 0, 64, 64, imagesx($image), imagesy($image));

        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 64; $y++) {
                if (((imagecolorat($probe, $x, $y) >> 24) & 0x7F) > 0) {
                    imagedestroy($probe);

                    return true;
                }
            }
        }

        imagedestroy($probe);

        return false;
    }

    /** No longer than $longest on its longest side; the picture itself when it is already smaller. */
    protected function fit(GdImage $image, int $longest, bool $alpha): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $longest) {
            return $image;
        }

        return $this->resize($image, (int) max(1, round($width * $longest / max($width, $height))), $alpha);
    }

    protected function resize(GdImage $image, int $newWidth, bool $alpha): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $newHeight = (int) max(1, round($height * $newWidth / $width));

        $copy = imagecreatetruecolor($newWidth, $newHeight);

        if ($alpha) {
            imagealphablending($copy, false);
            imagesavealpha($copy, true);
            imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, 127));
        } else {
            imagefill($copy, 0, 0, imagecolorallocate($copy, 255, 255, 255));
        }

        imagecopyresampled($copy, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $copy;
    }

    protected function encode(GdImage $image, string $format): string
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        ob_start();

        match ($format) {
            'png' => (function () use ($image) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagepng($image, null, 6);
            })(),
            'webp' => imagewebp($image, null, 80),
            default => (function () use ($image) {
                imageinterlace($image, true);
                imagejpeg($image, null, 82);
            })(),
        };

        return (string) ob_get_clean();
    }

    /** @param  list<string>  $written */
    protected function put(string $path, string $data, array &$written): void
    {
        if ($data === '' || ! $this->disk()->put($path, $data)) {
            throw ValidationException::withMessages(['file' => 'The picture could not be saved.']);
        }

        $written[] = $path;
    }

    protected function text(?string $value, int $limit): ?string
    {
        $value = trim(Str::limit((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags((string) $value)), $limit, ''));

        return $value === '' ? null : $value;
    }
}
