<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images of the Regional Blog: inline pictures, gallery pictures and covers. One row per upload; the files (a re-encoded
 * original plus thumb / medium / large, each also as WebP) live on the private `local` disk and are served only by the
 * region-checked blog.media route. `blog_post_id` is empty until the article is first saved; `in_use` says whether the
 * article's current body or cover still shows the picture (readers are only ever given pictures that are in use).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_media')) {
            return;
        }

        Schema::create('blog_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignId('blog_post_id')->nullable()->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 12);
            $table->string('mime', 40);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes');
            $table->string('path', 255);
            $table->json('variants')->nullable();
            $table->string('alt', 300)->nullable();
            $table->string('caption', 300)->nullable();
            $table->string('credit', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('in_use')->default(false);
            $table->timestamps();

            $table->index(['blog_post_id', 'sort_order']);
            $table->index('region_id');
            $table->index(['blog_post_id', 'in_use']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_media');
    }
};
