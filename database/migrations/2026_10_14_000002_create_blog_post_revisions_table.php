<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved versions of an article (the last N, see gwl.blog_revisions_keep). A rich article keeps its editor document in
 * body_json; the Markdown source of a legacy article is kept in body_markdown, so the version before its first rich save
 * can be brought back too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blog_post_revisions')) {
            return;
        }

        Schema::create('blog_post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->string('body_format', 10)->default('rich');
            $table->longText('body_json')->nullable();
            $table->longText('body_markdown')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->string('reason', 16)->default('save');
            $table->timestamp('created_at')->nullable();

            $table->index(['blog_post_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_revisions');
    }
};
