<?php

namespace Tests\Feature\Blog;

use App\Models\BlogMedia;
use App\Services\Blog\BlogMediaService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class BlogPruneCommandTest extends BlogTestCase
{
    public function test_the_command_removes_old_unused_uploads_and_reports_it(): void
    {
        $old = app(BlogMediaService::class)->store(UploadedFile::fake()->image('a.jpg', 300, 200), $this->prOfficer(), $this->accraWest->id, 'inline');
        $fresh = app(BlogMediaService::class)->store(UploadedFile::fake()->image('b.jpg', 300, 200), $this->prOfficer(), $this->accraWest->id, 'inline');
        BlogMedia::query()->whereKey($old->id)->update(['created_at' => now()->subHours(30)]);

        $this->artisan('blog:prune-media')->expectsOutputToContain('Removed 1 unattached upload(s) and 0 unused picture(s).')->assertSuccessful();

        $this->assertNull(BlogMedia::query()->find($old->id));
        $this->assertNotNull(BlogMedia::query()->find($fresh->id));
        Storage::disk('local')->assertMissing($old->path);
    }

    public function test_the_hours_are_a_setting(): void
    {
        $media = app(BlogMediaService::class)->store(UploadedFile::fake()->image('a.jpg', 300, 200), $this->prOfficer(), $this->accraWest->id, 'inline');
        BlogMedia::query()->whereKey($media->id)->update(['created_at' => now()->subHours(30)]);

        config(['gwl.blog_media_orphan_hours' => 48]);
        $this->artisan('blog:prune-media')->assertSuccessful();
        $this->assertNotNull(BlogMedia::query()->find($media->id));

        config(['gwl.blog_media_orphan_hours' => 24]);
        $this->artisan('blog:prune-media')->assertSuccessful();
        $this->assertNull(BlogMedia::query()->find($media->id));
    }

    public function test_it_does_nothing_while_the_module_is_off(): void
    {
        config(['gwl.blog_module_enabled' => false]);

        $this->artisan('blog:prune-media')->expectsOutputToContain('switched off')->assertSuccessful();
    }

    public function test_it_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'blog:prune-media'));

        $this->assertCount(1, $events);
        $this->assertSame('20 3 * * *', $events->first()->expression);
    }
}
