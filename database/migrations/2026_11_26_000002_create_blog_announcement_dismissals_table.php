<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Don't show again" on a login announcement (docs/14-module-regional-blog.md, "Login announcements"): one row per person and
 * article, so that announcement is never put in front of that person again. Closing the window only hides it for the login
 * session (kept in the session, not here). Dismissals are personal preferences and are deliberately not audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_announcement_dismissals')) {
            return;
        }

        Schema::create('blog_announcement_dismissals', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->timestamp('dismissed_at')->useCurrent();

            $table->primary(['user_id', 'blog_post_id']);
            $table->index('blog_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_announcement_dismissals');
    }
};
