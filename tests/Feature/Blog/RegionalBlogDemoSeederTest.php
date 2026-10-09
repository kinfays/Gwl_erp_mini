<?php

namespace Tests\Feature\Blog;

use App\Models\AuditLog;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\Demo\RegionalBlogDemoSeeder;
use Illuminate\Support\Facades\Storage;

class RegionalBlogDemoSeederTest extends BlogTestCase
{
    public function test_the_seeder_runs_twice_without_duplicates(): void
    {
        $this->seed(RegionalBlogDemoSeeder::class);

        $counts = fn () => [
            'posts' => BlogPost::query()->count(),
            'users' => User::query()->count(),
            'covers' => count(Storage::disk('local')->allFiles('blog/covers')),
            'audit' => AuditLog::query()->where('module', 'blog')->count(),
        ];
        $first = $counts();

        $this->seed(RegionalBlogDemoSeeder::class);

        $this->assertSame($first, $counts());
        $this->assertSame(12, $first['posts']);
        $this->assertSame(1, BlogPost::query()->where('title', 'Planned service interruption in Darkuman this weekend')->count());
    }

    public function test_the_demo_data_has_the_agreed_shape(): void
    {
        $this->seed(RegionalBlogDemoSeeder::class);

        $accra = BlogPost::query()->where('region_id', $this->accraWest->id);

        $this->assertSame(12, (clone $accra)->count());
        $this->assertSame(2, (clone $accra)->where('status', BlogPost::STATUS_DRAFT)->count());
        $this->assertSame(2, (clone $accra)->where('is_pinned', true)->where('status', BlogPost::STATUS_PUBLISHED)->count());
        $this->assertCount(6, (clone $accra)->pluck('category')->unique(), 'every category is used');
        $this->assertGreaterThan(0, (clone $accra)->whereDate('event_date', '>', now()->toDateString())->count());

        // Published within the last 90 days, written by the PR officer.
        $officer = User::query()->where('staff_id', 'BLA001')->firstOrFail();
        (clone $accra)->published()->get()->each(function (BlogPost $post) use ($officer) {
            $this->assertTrue($post->published_at->gt(now()->subDays(90)) && $post->published_at->lt(now()));
            $this->assertSame($officer->id, $post->author_user_id);
            $this->assertLessThanOrEqual(5, count($post->tags));
            $this->assertSame($post->tags, array_map('strtolower', $post->tags));
        });

        if (extension_loaded('gd')) {
            $covered = (clone $accra)->whereNotNull('cover_path')->get();
            $this->assertGreaterThanOrEqual(4, $covered->count());
            $covered->each(function (BlogPost $post) {
                Storage::disk('local')->assertExists($post->cover_path);
                $this->assertSame([1200, 675], array_slice(getimagesizefromstring(Storage::disk('local')->get($post->cover_path)), 0, 2));
            });
        }

        // The articles were written through the service, so the audit trail names the PR officer.
        $this->assertTrue(AuditLog::query()->where('action', 'blog_post_created')->where('user_id', $officer->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'blog_post_published')->where('user_id', $officer->id)->exists());
    }

    public function test_the_raw_html_in_the_demo_article_is_stripped(): void
    {
        $this->seed(RegionalBlogDemoSeeder::class);

        $html = BlogPost::query()->where('title', 'like', 'Staff durbar%')->firstOrFail()->bodyHtml()->toHtml();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function test_visibility_rules_hold_for_the_seeded_data(): void
    {
        $this->seed(RegionalBlogDemoSeeder::class);

        $published = BlogPost::query()->published()->firstOrFail();
        $draft = BlogPost::query()->where('status', BlogPost::STATUS_DRAFT)->firstOrFail();
        $ashantiReader = $this->reader('ASH001', $this->ashanti);

        $reader = User::query()->where('staff_id', 'BLA101')->firstOrFail();
        $this->actingAs($reader)->get(route('blog.show', $published))->assertOk();
        $this->actingAs($reader)->get(route('blog.show', $draft))->assertNotFound();

        $officer = User::query()->where('staff_id', 'BLA001')->firstOrFail();
        $this->actingAs($officer)->get(route('blog.show', $draft))->assertOk();

        $this->actingAs(User::query()->where('staff_id', 'BLSA01')->firstOrFail())->get(route('blog.show', $draft))->assertOk();

        // A reader in another region cannot open an Accra West article, published or not: 404, not 403.
        $this->actingAs($ashantiReader)->get(route('blog.show', $published))->assertNotFound();
        $this->actingAs($ashantiReader)->get(route('blog.show', $draft))->assertNotFound();
    }

    public function test_the_unlinked_login_has_no_region(): void
    {
        $this->seed(RegionalBlogDemoSeeder::class);

        $noRegion = User::query()->where('staff_id', 'BLNR01')->firstOrFail();

        $this->assertNull($noRegion->employee ?? $noRegion->employeeByStaffId);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        // Called directly: `db:seed` itself asks for a confirmation in production before the seeder's own guard is reached.
        (new RegionalBlogDemoSeeder)->run();

        $this->assertSame(0, BlogPost::query()->count());
    }
}
