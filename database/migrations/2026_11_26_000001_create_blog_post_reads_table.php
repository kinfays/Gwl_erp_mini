<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which articles of the Regional Blog a person has opened (docs/14-module-regional-blog.md, "Unread articles"). One row per
 * person and article; the composite primary key is the uniqueness rule, so marking an article read twice (a refresh, a
 * double click, two tabs) can never make a second row. The header badge counts published articles WITHOUT a row here.
 *
 * Both links cascade: deleting an article or a user removes the rows with it (nothing here is history worth keeping).
 * The columns are foreignId() (BIGINT) because blog_posts.id and users.id are BIGINT.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_post_reads')) {
            return;
        }

        Schema::create('blog_post_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();

            $table->primary(['user_id', 'blog_post_id']);
            // "Who has read this article" and the cascade when an article is deleted.
            $table->index('blog_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_reads');
    }
};
