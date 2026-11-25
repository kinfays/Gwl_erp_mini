<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likes and view counts on Regional Blog articles (docs/14-module-regional-blog.md, "Likes and views").
 *
 *  - view_count / like_count: counters kept as COLUMNS because they are printed on every feed card (a COUNT(*) per card is not
 *    acceptable). They are not mass-assignable and are written only by BlogEngagementService with atomic UPDATEs that leave
 *    updated_at alone, so a like or a view can never make an article look "edited by someone else".
 *  - blog_posts_liked_index (region_id, status, like_count): the "Most liked" sidebar block reads one region's published
 *    articles ordered by like_count (ties by id, which InnoDB appends to every secondary index); with the two equality columns first
 *    the database walks this index backwards and stops after the limit. (The unread index has published_at in that position and
 *    cannot serve it.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blog_posts')) {
            return;
        }

        if (! Schema::hasColumn('blog_posts', 'view_count')) {
            Schema::table('blog_posts', function (Blueprint $table) {
                $table->unsignedInteger('view_count')->default(0)->after('lock_version');
            });
        }

        if (! Schema::hasColumn('blog_posts', 'like_count')) {
            Schema::table('blog_posts', function (Blueprint $table) {
                $table->unsignedInteger('like_count')->default(0)->after('view_count');
            });
        }

        if (! Schema::hasIndex('blog_posts', 'blog_posts_liked_index')) {
            Schema::table('blog_posts', function (Blueprint $table) {
                $table->index(['region_id', 'status', 'like_count'], 'blog_posts_liked_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('blog_posts')) {
            return;
        }

        if (Schema::hasIndex('blog_posts', 'blog_posts_liked_index')) {
            Schema::table('blog_posts', fn (Blueprint $table) => $table->dropIndex('blog_posts_liked_index'));
        }

        foreach (['like_count', 'view_count'] as $column) {
            if (Schema::hasColumn('blog_posts', $column)) {
                Schema::table('blog_posts', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
