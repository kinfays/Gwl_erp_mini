<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who liked which Regional Blog article. One row per person and article: the composite primary key is the uniqueness rule, so a
 * double click or two tabs can never make a second row (the writer uses insertOrIgnore and adjusts blog_posts.like_count only when
 * a row was really inserted or deleted). The second index starts with blog_post_id: it serves the cascade when an article is
 * deleted and the managers' "who liked this" list (newest first). Both links cascade. No IP address or user agent is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_post_likes')) {
            return;
        }

        Schema::create('blog_post_likes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'blog_post_id']);
            $table->index(['blog_post_id', 'created_at'], 'blog_post_likes_post_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_likes');
    }
};
