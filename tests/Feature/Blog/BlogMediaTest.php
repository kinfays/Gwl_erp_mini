<?php

namespace Tests\Feature\Blog;

use App\Models\AuditLog;
use App\Models\BlogMedia;
use App\Models\BlogPost;
use App\Models\BlogPostRevision;
use App\Models\Region;
use App\Models\User;
use App\Services\Blog\BlogMediaService;
use App\Services\Blog\BlogPostService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BlogMediaTest extends BlogTestCase
{
    protected BlogMediaService $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = app(BlogMediaService::class);
    }

    // ---------------------------------------------------------------- helpers

    protected function jpeg(int $width = 1200, int $height = 800, ?int $orientation = null, string $extra = ''): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 160));
        imagefilledrectangle($image, 0, 0, (int) ($width / 3), (int) ($height / 2), imagecolorallocate($image, 240, 200, 40));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        if ($orientation !== null) {
            // A real EXIF segment: the orientation tag, plus a GPS-looking string that must not survive.
            $tiff = "II\x2A\x00\x08\x00\x00\x00".pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\x00\x00"."\x00\x00\x00\x00";
            $exif = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff)).'Exif'."\x00\x00".$tiff;
            $comment = "\xFF\xFE".pack('n', 2 + strlen('GPS-LATITUDE-5.6037N-LONGITUDE-0.1870W')).'GPS-LATITUDE-5.6037N-LONGITUDE-0.1870W';
            $bytes = substr($bytes, 0, 2).$exif.$comment.substr($bytes, 2);
        }

        return $bytes.$extra;
    }

    protected function upload(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function store(UploadedFile $file, ?User $actor = null, ?Region $region = null, string $kind = 'inline', ?BlogPost $post = null): BlogMedia
    {
        return $this->media->store($file, $actor ?? $this->prOfficer(), ($region ?? $this->accraWest)->id, $kind, $post);
    }

    protected function filesOf(BlogMedia $media): array
    {
        return $media->allPaths();
    }

    /** A picture attached to an article, ready to be served. */
    protected function shown(BlogPost $post, bool $inUse = true): BlogMedia
    {
        // Written by super_admin, who may write to any region (the helper has to reach Ashanti too).
        $media = $this->store($this->upload('p.jpg', $this->jpeg()), $this->superAdmin(), $post->region);
        $media->forceFill(['blog_post_id' => $post->id, 'in_use' => $inUse])->save();

        return $media->fresh();
    }

    // ---------------------------------------------------------------- the pipeline

    public function test_an_upload_is_rebuilt_in_three_sizes_and_webp_on_the_private_disk_only(): void
    {
        Storage::fake('public');

        $media = $this->store($this->upload('holiday.jpg', $this->jpeg(2000, 1000)));

        $this->assertSame('image/jpeg', $media->mime);
        $this->assertSame([2000, 1000], [$media->width, $media->height]);
        $this->assertSame(400, $media->variant('thumb')['w']);
        $this->assertSame(900, $media->variant('medium')['w']);
        $this->assertSame(1600, $media->variant('large')['w']);
        $this->assertSame(800, $media->variant('large')['h']);

        foreach ($this->filesOf($media) as $path) {
            Storage::disk('local')->assertExists($path);
            $this->assertStringStartsWith('blog/media/'.$this->accraWest->id.'/', $path);
        }

        $this->assertCount(7, $this->filesOf($media), 'original + 3 sizes + 3 webp');
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('local')->get($media->variant('medium')['webp'])));
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing is ever put on the public disk');
        $this->assertStringNotContainsString('holiday', $media->path, 'the client file name is not kept');
        $this->assertTrue(AuditLog::query()->where('action', 'blog_media_uploaded')->where('target_id', $media->id)->exists());
    }

    public function test_a_small_picture_is_never_enlarged(): void
    {
        $media = $this->store($this->upload('small.jpg', $this->jpeg(500, 300)));

        $this->assertSame(400, $media->variant('thumb')['w']);
        $this->assertSame(500, $media->variant('medium')['w']);
        $this->assertSame(500, $media->variant('large')['w']);
        $this->assertSame($media->variant('medium')['path'], $media->variant('large')['path'], 'the larger names reuse the one real size');
        $this->assertSame('', ltrim(str_replace(',', '', $media->srcset()), '/blog/media/') === '' ? '' : '');
        $this->assertSame(2, substr_count($media->srcset(), 'w'), 'srcset lists each real width once');
    }

    public function test_exif_including_gps_is_stripped_and_the_picture_is_turned_upright(): void
    {
        $media = $this->store($this->upload('phone.jpg', $this->jpeg(300, 200, 6)));

        $this->assertSame([200, 300], [$media->width, $media->height], 'orientation 6 means turn it a quarter; width and height swap');

        foreach ($this->filesOf($media) as $path) {
            $bytes = Storage::disk('local')->get($path);

            $this->assertStringNotContainsString('GPS-LATITUDE', $bytes, $path);
            $this->assertStringNotContainsString('Exif', $bytes, $path);
        }
    }

    public function test_the_orientation_reader_handles_each_value_and_broken_files(): void
    {
        foreach ([1, 2, 3, 6, 8] as $value) {
            $this->assertSame($value, $this->media->jpegOrientation($this->jpeg(40, 30, $value)));
        }

        $this->assertSame(1, $this->media->jpegOrientation($this->jpeg(40, 30)));
        $this->assertSame(1, $this->media->jpegOrientation(''));
        $this->assertSame(1, $this->media->jpegOrientation("\xFF\xD8\xFF\xE1\xFF\xFFExif\x00\x00II"));
        $this->assertSame(1, $this->media->jpegOrientation('not an image at all'));
    }

    public function test_a_php_payload_hidden_in_an_image_does_not_survive(): void
    {
        $media = $this->store($this->upload('shell.jpg', $this->jpeg(400, 300, null, "\n<?php system(\$_GET['c']); ?>")));

        foreach ($this->filesOf($media) as $path) {
            $this->assertStringNotContainsString('<?php', Storage::disk('local')->get($path), $path);
        }
    }

    public function test_a_transparent_png_stays_a_png_and_an_opaque_one_becomes_a_jpeg(): void
    {
        $see = imagecreatetruecolor(300, 200);
        imagealphablending($see, false);
        imagesavealpha($see, true);
        imagefill($see, 0, 0, imagecolorallocatealpha($see, 0, 0, 0, 127));
        imagefilledrectangle($see, 20, 20, 120, 120, imagecolorallocatealpha($see, 200, 30, 30, 0));
        ob_start();
        imagepng($see);
        $transparent = (string) ob_get_clean();

        $flat = imagecreatetruecolor(300, 200);
        ob_start();
        imagepng($flat);
        $opaque = (string) ob_get_clean();

        $this->assertSame('image/png', $this->store($this->upload('logo.png', $transparent))->mime);
        $this->assertSame('image/jpeg', $this->store($this->upload('flat.png', $opaque))->mime);
    }

    public function test_an_animated_gif_keeps_its_first_frame_only(): void
    {
        $frame = imagecreatetruecolor(120, 80);
        ob_start();
        imagegif($frame);
        $gif = (string) ob_get_clean();

        $media = $this->store($this->upload('anim.gif', $gif));

        $this->assertContains($media->mime, ['image/jpeg', 'image/png']);
        $this->assertSame([120, 80], [$media->width, $media->height]);
    }

    // ---------------------------------------------------------------- what is refused

    public function test_files_that_are_not_real_images_are_refused_whatever_they_are_called(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';

        $cases = [
            'a php file named .jpg' => $this->upload('x.jpg', '<?php echo 1;'),
            'plain text named .png' => $this->upload('x.png', 'just some words'),
            'an svg named .svg' => $this->upload('x.svg', $svg),
            'an svg named .jpg' => $this->upload('x.jpg', $svg),
            'html named .webp' => $this->upload('x.webp', '<html><script>alert(1)</script></html>'),
            'a pdf' => $this->upload('x.pdf', "%PDF-1.4\n%fake"),
            'an empty file' => $this->upload('x.jpg', ''),
            'a truncated jpeg' => $this->upload('x.jpg', substr($this->jpeg(), 0, 40)),
        ];

        foreach ($cases as $label => $file) {
            try {
                $this->store($file);
                $this->fail("{$label} was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors(), $label);
            }
        }

        $this->assertSame(0, BlogMedia::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        config(['gwl.blog_media_max_mb' => 1]);

        $noise = random_bytes(1_200_000);
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagejpeg($image);
        $valid = (string) ob_get_clean();

        $this->expectException(ValidationException::class);
        $this->store($this->upload('big.jpg', $valid.$noise));
    }

    public function test_a_picture_with_too_many_pixels_is_refused(): void
    {
        config(['gwl.blog_media_max_dimension' => 500]);

        $this->expectException(ValidationException::class);
        $this->store($this->upload('wide.jpg', $this->jpeg(900, 100)));
    }

    public function test_the_megapixel_limit_is_enforced_separately(): void
    {
        config(['gwl.blog_media_max_dimension' => 5000, 'gwl.blog_media_max_megapixels' => 1]);

        $this->expectException(ValidationException::class);
        $this->store($this->upload('big.jpg', $this->jpeg(1500, 1000)));
    }

    public function test_an_article_holds_only_so_many_pictures(): void
    {
        config(['gwl.blog_media_max_per_article' => 2]);
        $post = $this->article($this->accraWest);
        $officer = $this->prOfficer();

        $this->store($this->upload('a.jpg', $this->jpeg(100, 100)), $officer, null, 'inline', $post);
        $this->store($this->upload('b.jpg', $this->jpeg(100, 100)), $officer, null, 'inline', $post);

        $this->expectException(ValidationException::class);
        $this->store($this->upload('c.jpg', $this->jpeg(100, 100)), $officer, null, 'inline', $post);
    }

    public function test_a_failed_upload_leaves_no_file_behind(): void
    {
        try {
            $this->store($this->upload('x.jpg', 'junk'));
        } catch (ValidationException) {
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ---------------------------------------------------------------- the upload endpoint

    public function test_a_pr_officer_uploads_through_the_endpoint(): void
    {
        $response = $this->actingAs($this->prOfficer())
            ->postJson(route('blog.media.upload'), ['file' => $this->upload('a.jpg', $this->jpeg())])
            ->assertCreated()
            ->assertJsonStructure(['id', 'kind', 'width', 'height', 'thumb', 'medium', 'large']);

        $media = BlogMedia::query()->findOrFail($response->json('id'));

        $this->assertSame($this->accraWest->id, $media->region_id);
        $this->assertNull($media->blog_post_id);
        $this->assertFalse($media->in_use);
        $this->assertStringStartsWith('/blog/media/'.$media->id.'/medium', $response->json('medium'), 'relative links, never a public file');
    }

    public function test_the_endpoint_needs_login_and_the_writers_permission(): void
    {
        $file = $this->upload('a.jpg', $this->jpeg());

        $this->postJson(route('blog.media.upload'), ['file' => $file])->assertUnauthorized();
        $this->actingAs($this->reader())->postJson(route('blog.media.upload'), ['file' => $file])->assertForbidden();
        $this->actingAs($this->userWithoutEmployee('PR0001', ['pr_officer']))->postJson(route('blog.media.upload'), ['file' => $file])->assertForbidden();

        $this->assertSame(0, BlogMedia::query()->count());
    }

    public function test_a_pr_officer_cannot_upload_into_another_regions_article_or_region(): void
    {
        $officer = $this->prOfficer();
        $foreign = $this->article($this->ashanti);

        $this->actingAs($officer)->postJson(route('blog.media.upload'), ['file' => $this->upload('a.jpg', $this->jpeg()), 'post_id' => $foreign->id])->assertNotFound();

        // A region id in the form is ignored for an officer: the picture goes to their own region.
        $this->actingAs($officer)->postJson(route('blog.media.upload'), ['file' => $this->upload('a.jpg', $this->jpeg()), 'region_id' => $this->ashanti->id])->assertCreated();
        $this->assertSame($this->accraWest->id, BlogMedia::query()->latest('id')->value('region_id'));
    }

    public function test_super_admin_must_say_which_region_and_a_bad_file_is_a_422(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson(route('blog.media.upload'), ['file' => $this->upload('a.jpg', $this->jpeg())])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('blog.media.upload'), ['file' => $this->upload('a.jpg', $this->jpeg()), 'region_id' => $this->ashanti->id])->assertCreated();
        $this->actingAs($admin)->postJson(route('blog.media.upload'), ['file' => $this->upload('a.jpg', '<?php echo 1;'), 'region_id' => $this->ashanti->id])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    // ---------------------------------------------------------------- who is given a picture

    public function test_a_reader_gets_the_pictures_of_published_articles_of_their_own_region(): void
    {
        $media = $this->shown($this->article($this->accraWest));

        $response = $this->actingAs($this->reader())->get($media->url('medium'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertNotEmpty($response->headers->get('Last-Modified'));
    }

    public function test_a_second_request_with_the_etag_gets_a_304(): void
    {
        $media = $this->shown($this->article($this->accraWest));
        $reader = $this->reader();

        $etag = $this->actingAs($reader)->get($media->url('thumb'))->assertOk()->headers->get('ETag');

        $this->actingAs($reader)->get($media->url('thumb'), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_webp_is_sent_to_browsers_that_ask_for_it(): void
    {
        $media = $this->shown($this->article($this->accraWest));

        $this->actingAs($this->reader())->get($media->url('medium'), ['Accept' => 'image/avif,image/webp,*/*'])
            ->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Vary', 'Accept');
    }

    public function test_a_reader_of_another_region_gets_a_404_for_every_size(): void
    {
        $media = $this->shown($this->article($this->accraWest));
        $outsider = $this->reader('100009', $this->ashanti);

        foreach (['thumb', 'medium', 'large'] as $size) {
            $this->actingAs($outsider)->get($media->url($size))->assertNotFound();
        }
    }

    public function test_the_pictures_of_a_draft_or_of_an_unused_or_unattached_upload_are_hidden_from_readers(): void
    {
        $reader = $this->reader();

        $draftPicture = $this->shown($this->draft($this->accraWest));
        $removedPicture = $this->shown($this->article($this->accraWest), false);
        $unattached = $this->store($this->upload('u.jpg', $this->jpeg()));

        foreach ([$draftPicture, $removedPicture, $unattached] as $media) {
            $this->actingAs($reader)->get($media->url('medium'))->assertNotFound();
        }
    }

    public function test_writers_of_the_region_see_drafts_and_uploads_but_not_another_regions(): void
    {
        $officer = $this->prOfficer();
        $draftPicture = $this->shown($this->draft($this->accraWest));
        $unattached = $this->store($this->upload('u.jpg', $this->jpeg()));
        $foreign = $this->shown($this->article($this->ashanti));

        $this->actingAs($officer)->get($draftPicture->url('large'))->assertOk();
        $this->actingAs($officer)->get($unattached->url('large'))->assertOk();
        $this->actingAs($officer)->get($foreign->url('large'))->assertNotFound();
        $this->actingAs($this->prOfficer('300002', $this->ashanti))->get($draftPicture->url('large'))->assertNotFound();
    }

    public function test_super_admin_gets_every_regions_pictures(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get($this->shown($this->draft($this->accraWest))->url('medium'))->assertOk();
        $this->actingAs($admin)->get($this->shown($this->article($this->ashanti))->url('medium'))->assertOk();
    }

    public function test_a_guest_is_sent_to_the_login_and_odd_sizes_do_not_exist(): void
    {
        $media = $this->shown($this->article($this->accraWest));

        $this->get($media->url('medium'))->assertRedirect(route('login'));
        $this->actingAs($this->reader())->get('/blog/media/'.$media->id.'/original')->assertNotFound();
        $this->actingAs($this->reader())->get('/blog/media/'.$media->id.'/../../.env')->assertNotFound();
        $this->actingAs($this->reader())->get('/blog/media/999999/medium')->assertNotFound();
    }

    // ---------------------------------------------------------------- clean-up

    public function test_deleting_an_article_deletes_every_picture_file(): void
    {
        $officer = $this->prOfficer();
        $post = $this->article($this->accraWest);
        $media = $this->shown($post);
        $paths = $this->filesOf($media);

        app(BlogPostService::class)->delete($post, $officer);

        $this->assertNull(BlogMedia::query()->find($media->id));

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_removing_a_picture_deletes_its_files_and_is_audited(): void
    {
        $media = $this->store($this->upload('a.jpg', $this->jpeg()));
        $paths = $this->filesOf($media);

        $this->media->remove($media, $this->prOfficer());

        $this->assertSame(0, BlogMedia::query()->count());
        Storage::disk('local')->assertMissing($paths[0]);
        $this->assertTrue(AuditLog::query()->where('action', 'blog_media_removed')->where('target_id', $media->id)->exists());
    }

    public function test_the_cleanup_removes_old_unattached_uploads_and_keeps_fresh_ones(): void
    {
        $old = $this->store($this->upload('old.jpg', $this->jpeg(200, 100)));
        $fresh = $this->store($this->upload('new.jpg', $this->jpeg(200, 100)));
        $attached = $this->shown($this->article($this->accraWest));

        BlogMedia::query()->whereKey($old->id)->update(['created_at' => now()->subHours(25)]);
        BlogMedia::query()->whereKey($attached->id)->update(['created_at' => now()->subDays(5)]);

        $counts = $this->media->pruneOrphans();

        $this->assertSame(1, $counts['unattached']);
        $this->assertNull(BlogMedia::query()->find($old->id));
        $this->assertNotNull(BlogMedia::query()->find($fresh->id));
        $this->assertNotNull(BlogMedia::query()->find($attached->id));

        foreach ($this->filesOf($old) as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_the_cleanup_removes_pictures_an_article_stopped_showing_unless_a_saved_version_still_does(): void
    {
        $post = $this->article($this->accraWest);
        $unused = $this->shown($post, false);
        $keptForVersion = $this->shown($post, false);

        BlogPostRevision::query()->create([
            'blog_post_id' => $post->id,
            'title' => 'Old',
            'body_format' => 'rich',
            'body_json' => ['type' => 'doc', 'content' => [['type' => 'blogFigure', 'attrs' => ['mediaId' => $keptForVersion->id]]]],
            'lock_version' => 1,
            'reason' => 'save',
        ]);

        BlogMedia::query()->whereIn('id', [$unused->id, $keptForVersion->id])->update(['updated_at' => now()->subHours(30)]);

        $counts = $this->media->pruneOrphans();

        $this->assertSame(1, $counts['unused']);
        $this->assertNull(BlogMedia::query()->find($unused->id));
        $this->assertNotNull(BlogMedia::query()->find($keptForVersion->id));
    }
}
