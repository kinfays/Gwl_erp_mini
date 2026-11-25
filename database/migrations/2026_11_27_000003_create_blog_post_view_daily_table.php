<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Views per article per day (docs/14-module-regional-blog.md, "Likes and views"): feeds "Most read this week" and the 14-day
 * sparkline. One row per article and day, incremented with an upsert in the same transaction as blog_posts.view_count, so the two
 * always agree. The composite primary key is the only index: "most read" starts from one region's published articles and reads
 * each one's recent days by primary-key range. The FK cascades with the article. Nothing here identifies a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_post_view_daily')) {
            return;
        }

        Schema::create('blog_post_view_daily', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->date('view_date');
            $table->unsignedInteger('views')->default(0);

            $table->primary(['blog_post_id', 'view_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_view_daily');
    }
};
