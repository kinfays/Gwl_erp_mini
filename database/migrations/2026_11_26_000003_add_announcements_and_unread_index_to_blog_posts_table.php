<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login announcements and the unread-articles badge on blog_posts (docs/14-module-regional-blog.md).
 *
 *  - announce_from / announce_until: a published article is an active login announcement from announce_from (blank = from the
 *    day it was published) up to and including announce_until. Both blank = not an announcement. Nothing flips them when the
 *    date passes: "active" is worked out at query time (BlogAnnouncementService), so an expired announcement just stops showing.
 *  - blog_posts_unread_index (region_id, status, published_at): the header badge counts published articles of one region
 *    inside a date window on every page for every user. The older feed index has is_pinned between status and published_at,
 *    so it cannot serve a range on published_at; this one can.
 *  - blog_posts_announce_index (region_id, status, announce_until): the active-announcement lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blog_posts')) {
            return;
        }

        if (! Schema::hasColumn('blog_posts', 'announce_from')) {
            Schema::table('blog_posts', function (Blueprint $table) {
                $table->date('announce_from')->nullable()->after('is_pinned');
            });
        }

        if (! Schema::hasColumn('blog_posts', 'announce_until')) {
            Schema::table('blog_posts', function (Blueprint $table) {
                $table->date('announce_until')->nullable()->after('announce_from');
            });
        }

        if (! Schema::hasIndex('blog_posts', 'blog_posts_unread_index')) {
            Schema::table('blog_posts', function (Blueprint $table) {
                $table->index(['region_id', 'status', 'published_at'], 'blog_posts_unread_index');
            });
        }

        if (! Schema::hasIndex('blog_posts', 'blog_posts_announce_index')) {
            Schema::table('blog_posts', function (Blueprint $table) {
                $table->index(['region_id', 'status', 'announce_until'], 'blog_posts_announce_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('blog_posts')) {
            return;
        }

        if (Schema::hasIndex('blog_posts', 'blog_posts_announce_index')) {
            Schema::table('blog_posts', fn (Blueprint $table) => $table->dropIndex('blog_posts_announce_index'));
        }

        if (Schema::hasIndex('blog_posts', 'blog_posts_unread_index')) {
            Schema::table('blog_posts', fn (Blueprint $table) => $table->dropIndex('blog_posts_unread_index'));
        }

        foreach (['announce_until', 'announce_from'] as $column) {
            if (Schema::hasColumn('blog_posts', $column)) {
                Schema::table('blog_posts', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
