<?php

namespace Tests\Feature\Blog;

use App\Livewire\Blog\PostStats;
use App\Models\AuditLog;
use App\Models\BlogPost;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Likes on Regional Blog articles: one per person, idempotent, region-scoped, counted in a column kept in step with the rows,
 * and visible by name only to the managers of the article's region.
 */
class BlogLikesTest extends BlogEngagementTestCase
{
    public function test_a_reader_likes_and_unlikes_and_gets_the_final_state_and_count(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        $this->like($reader, $post)->assertOk()->assertExactJson(['liked' => true, 'count' => 1]);
        $this->assertSame(1, $post->fresh()->like_count);
        $this->assertSame(1, $this->likeRows($post));

        $this->unlike($reader, $post)->assertOk()->assertExactJson(['liked' => false, 'count' => 0]);
        $this->assertSame(0, $post->fresh()->like_count);
        $this->assertSame(0, $this->likeRows($post));
    }

    public function test_a_double_click_is_safe_in_both_directions(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        $this->like($reader, $post);
        $this->like($reader, $post)->assertOk()->assertExactJson(['liked' => true, 'count' => 1]);
        $this->assertSame(1, $post->fresh()->like_count);
        $this->assertSame(1, $this->likeRows($post));

        $this->unlike($reader, $post);
        $this->unlike($reader, $post)->assertOk()->assertExactJson(['liked' => false, 'count' => 0]);
        $this->assertSame(0, $post->fresh()->like_count);
    }

    public function test_two_quick_likes_from_one_person_count_once_even_when_the_other_request_got_there_first(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        // The other request has already written its row and moved the counter when this one runs.
        DB::table('blog_post_likes')->insert(['user_id' => $reader->id, 'blog_post_id' => $post->id, 'created_at' => now()]);
        $this->setCounters($post, likes: 1);

        $this->assertSame(['liked' => true, 'count' => 1], $this->engagement()->like($reader, $post->fresh()));
        $this->assertSame(1, $post->fresh()->like_count);
        $this->assertSame(1, $this->likeRows($post));
    }

    public function test_one_like_per_person_and_the_count_matches_the_rows(): void
    {
        $post = $this->article($this->accraWest);
        $people = [$this->reader('100001'), $this->reader('100002'), $this->prOfficer('300001'), $this->reader('100003')];

        foreach ($people as $person) {
            $this->like($person, $post)->assertOk();
            $this->like($person, $post)->assertOk();
        }

        $this->unlike($people[1], $post)->assertOk();

        $this->assertSame(3, $this->likeRows($post));
        $this->assertSame(3, $post->fresh()->like_count);
    }

    public function test_the_count_never_goes_below_zero(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        // A like row exists but the counter was left at 0 (damage a recount would repair): unliking must not underflow.
        DB::table('blog_post_likes')->insert(['user_id' => $reader->id, 'blog_post_id' => $post->id, 'created_at' => now()]);

        $this->unlike($reader, $post)->assertOk()->assertExactJson(['liked' => false, 'count' => 0]);
        $this->assertSame(0, $post->fresh()->like_count);

        // Unliking something never liked changes nothing.
        $this->unlike($reader, $post)->assertOk();
        $this->assertSame(0, $post->fresh()->like_count);
    }

    public function test_an_article_of_another_region_cannot_be_liked_or_unliked(): void
    {
        $foreign = $this->article($this->ashanti);
        $reader = $this->reader();

        $this->like($reader, $foreign)->assertNotFound();
        $this->unlike($reader, $foreign)->assertNotFound();
        $this->assertSame(0, $this->likeRows($foreign));
        $this->assertSame(0, $foreign->fresh()->like_count);

        // The same answer as for an article that does not exist.
        $this->actingAs($reader)->postJson(route('blog.like', 999999))->assertNotFound();
    }

    public function test_a_draft_and_an_unpublished_article_cannot_be_liked_even_by_the_team_that_can_read_it(): void
    {
        $draft = $this->draft($this->accraWest);
        $officer = $this->prOfficer();

        $this->like($this->reader(), $draft)->assertNotFound();
        $this->like($officer, $draft)->assertNotFound();
        $this->assertSame(0, $this->likeRows($draft));

        $post = $this->article($this->accraWest);
        $this->like($this->reader(), $post)->assertOk();
        DB::table('blog_posts')->where('id', $post->id)->update(['status' => BlogPost::STATUS_DRAFT]);
        $this->like($this->reader('100002'), $post)->assertNotFound();
        $this->unlike($this->reader(), $post)->assertNotFound();
    }

    public function test_the_author_and_a_super_admin_may_like(): void
    {
        $officer = $this->prOfficer();
        $post = $this->article($this->accraWest, ['author_user_id' => $officer->id]);

        $this->like($officer, $post)->assertOk()->assertExactJson(['liked' => true, 'count' => 1]);
        $this->like($this->superAdmin(), $post)->assertOk()->assertExactJson(['liked' => true, 'count' => 2]);
    }

    public function test_a_guest_cannot_like(): void
    {
        $post = $this->article($this->accraWest);

        $this->postJson(route('blog.like', $post))->assertUnauthorized();
        $this->assertSame(0, $this->likeRows($post));
    }

    public function test_the_endpoints_are_rate_limited(): void
    {
        $post = $this->article($this->accraWest);
        $reader = $this->reader();

        foreach (range(1, 30) as $i) {
            $this->like($reader, $post)->assertOk();
        }

        $this->like($reader, $post)->assertStatus(429);
    }

    public function test_a_like_is_not_audited(): void
    {
        $post = $this->article($this->accraWest);
        $before = AuditLog::query()->count();

        $this->like($this->reader(), $post)->assertOk();
        $this->unlike($this->reader(), $post)->assertOk();

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_deleting_the_article_removes_its_likes_and_deleting_a_user_removes_theirs(): void
    {
        $post = $this->article($this->accraWest);
        $other = $this->article($this->accraWest);
        $reader = $this->reader();
        $this->like($reader, $post);
        $this->like($reader, $other);

        $post->delete();

        $this->assertSame(0, DB::table('blog_post_likes')->where('blog_post_id', $post->id)->count());
        $this->assertSame(1, DB::table('blog_post_likes')->count());

        $reader->delete();
        $this->assertSame(0, DB::table('blog_post_likes')->count());
    }

    // ---------------------------------------------------------------- who sees who liked it

    public function test_a_manager_sees_who_liked_it_and_a_reader_never_does(): void
    {
        $post = $this->article($this->accraWest);
        $fan = $this->reader('100001', null, $this->odorkor);
        $this->like($fan, $post)->assertOk();
        $viewer = $this->reader('100002');

        // The reader's own pages show a count, never a name.
        $page = $this->actingAs($viewer)->get($post->url())->assertOk();
        $page->assertDontSee($fan->full_name);
        $page->assertSee('1 like');

        // The service refuses a reader outright, and Livewire refuses the panel.
        try {
            $this->engagement()->likers($viewer, $post);
            $this->fail('a reader was given the list of likers');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        Livewire::actingAs($viewer)->test(PostStats::class, ['postId' => $post->id])->assertForbidden();

        // The PR officer of the region sees name, district and date.
        Livewire::actingAs($this->prOfficer())
            ->test(PostStats::class, ['postId' => $post->id])
            ->assertDontSee($fan->full_name)
            ->call('toggleLikers')
            ->assertSee($fan->full_name)
            ->assertSee('Odorkor')
            ->assertSee('20 Nov 2026');
    }

    public function test_an_officer_of_another_region_cannot_open_the_stats_or_the_likers(): void
    {
        $post = $this->article($this->accraWest);
        $this->like($this->reader(), $post);
        $ashantiOfficer = $this->prOfficer('300002', $this->ashanti);

        Livewire::actingAs($ashantiOfficer)->test(PostStats::class, ['postId' => $post->id])->assertNotFound();

        $this->expectException(HttpException::class);
        $this->engagement()->likers($ashantiOfficer, $post);
    }
}
