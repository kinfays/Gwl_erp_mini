<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regional Blog (docs/14-module-regional-blog.md): articles the Public Relations officers of a region write about the
 * activities, meetings, workshops and training going on there. Every article belongs to ONE region and is read by the
 * staff of that region only.
 *
 * The region is restricted (it is the access boundary and should never silently vanish); the district an activity took
 * place in and the author are optional links, nulled if the district or the user is ever removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_posts')) {
            return;
        }

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            // Where in the region it happened (optional); must belong to region_id (enforced in BlogPostService).
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 180);
            // activity | meeting | workshop | training | announcement | other (BlogPost::CATEGORIES)
            $table->string('category', 20);
            $table->string('summary', 300)->nullable();
            // Markdown, rendered with raw HTML stripped (BlogPost::bodyHtml()).
            $table->longText('body');
            $table->json('tags')->nullable();

            // When the activity took place and where (both optional: an announcement has neither).
            $table->date('event_date')->nullable();
            $table->string('venue', 160)->nullable();

            // Private disk path of the cover photo; served only through blog.cover after a region check.
            $table->string('cover_path')->nullable();

            // draft | published
            $table->string('status', 15)->default('draft');
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['region_id', 'status', 'is_pinned', 'published_at'], 'blog_posts_feed_index');
            $table->index(['region_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
