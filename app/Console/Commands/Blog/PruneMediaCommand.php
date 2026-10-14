<?php

namespace App\Console\Commands\Blog;

use App\Services\Blog\BlogMediaService;
use Illuminate\Console\Command;

/**
 * Deletes Regional Blog pictures nobody uses any more: uploads that never reached an article, and pictures an article stopped
 * showing (and none of its saved versions shows either), once they are older than gwl.blog_media_orphan_hours. Files and rows go
 * together. Scheduled daily while the blog module is on (routes/console.php).
 */
class PruneMediaCommand extends Command
{
    protected $signature = 'blog:prune-media';

    protected $description = 'Delete Regional Blog pictures that were uploaded but never used, or are no longer used by any article or saved version';

    public function handle(BlogMediaService $media): int
    {
        if (! config('gwl.blog_module_enabled')) {
            $this->info('The Regional Blog module is switched off; nothing to do.');

            return self::SUCCESS;
        }

        $counts = $media->pruneOrphans();

        $this->info("Removed {$counts['unattached']} unattached upload(s) and {$counts['unused']} unused picture(s).");

        return self::SUCCESS;
    }
}
