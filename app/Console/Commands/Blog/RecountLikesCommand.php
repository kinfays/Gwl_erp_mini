<?php

namespace App\Console\Commands\Blog;

use App\Models\BlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs blog_posts.like_count from the rows of blog_post_likes (the rows are the truth, the column is a cache of their count).
 * Normally the two never differ, because a like or unlike moves the counter in the same transaction, but a restore, a manual edit
 * or a crash mid-way could leave one off. Idempotent and chunked by article id; each chunk is ONE UPDATE that counts and compares in
 * the database, so a like that lands while it runs can never be overwritten with an older count. Leaves updated_at and lock_version
 * alone. view_count is NOT touched: a view is an event that left no row to recount (blog_post_view_daily is incremented together
 * with it, so it is a second copy of the same counter, not a source). Scheduled weekly while the blog module is on (routes/console.php).
 */
class RecountLikesCommand extends Command
{
    protected $signature = 'blog:recount-likes {--dry-run : Report the articles whose count is wrong without changing them} {--chunk=500 : Articles per statement}';

    protected $description = 'Repair the like counter of every Regional Blog article from the like rows';

    public function handle(): int
    {
        if (! config('gwl.blog_module_enabled')) {
            $this->info('The Regional Blog module is switched off; nothing to do.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $count = 'select count(*) from blog_post_likes where blog_post_likes.blog_post_id = blog_posts.id';
        $wrong = 0;

        BlogPost::query()->toBase()->select('id')->orderBy('id')->chunkById($chunk, function ($rows) use ($dry, $count, &$wrong) {
            $first = (int) $rows->first()->id;
            $last = (int) $rows->last()->id;

            $query = DB::table('blog_posts')
                ->whereBetween('id', [$first, $last])
                ->whereRaw("like_count <> ({$count})");

            if ($dry) {
                $wrong += $query->count();

                return;
            }

            $wrong += $query->update(['like_count' => DB::raw("({$count})")]);
        });

        $this->info($dry
            ? "{$wrong} article(s) have a wrong like count (dry run, nothing changed)."
            : "Repaired the like count of {$wrong} article(s).");

        return self::SUCCESS;
    }
}
