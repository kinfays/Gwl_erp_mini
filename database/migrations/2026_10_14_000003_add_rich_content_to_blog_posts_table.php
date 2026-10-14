<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rich articles. body_json is the editor document (canonical for a rich article), body_html what readers get (rendered and
 * sanitised on save by BlogContentService, never per request), body_text the plain text used for search and teasers.
 * Existing Markdown articles stay `markdown`: this fills their body_html / body_text / reading time once, with the same
 * Markdown options BlogPost::bodyHtml() always used (raw HTML stripped, unsafe links refused), so nothing changes for readers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blog_posts')) {
            return;
        }

        Schema::table('blog_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('blog_posts', 'body_format')) {
                $table->string('body_format', 10)->default('markdown')->after('body');
            }
            if (! Schema::hasColumn('blog_posts', 'body_json')) {
                $table->longText('body_json')->nullable()->after('body_format');
            }
            if (! Schema::hasColumn('blog_posts', 'body_html')) {
                $table->longText('body_html')->nullable()->after('body_json');
            }
            if (! Schema::hasColumn('blog_posts', 'body_text')) {
                $table->longText('body_text')->nullable()->after('body_html');
            }
            if (! Schema::hasColumn('blog_posts', 'reading_minutes')) {
                $table->unsignedSmallInteger('reading_minutes')->nullable()->after('body_text');
            }
            if (! Schema::hasColumn('blog_posts', 'slug')) {
                $table->string('slug', 160)->nullable()->after('title');
            }
            if (! Schema::hasColumn('blog_posts', 'cover_media_id')) {
                $table->foreignId('cover_media_id')->nullable()->after('cover_path')->constrained('blog_media')->nullOnDelete();
            }
            if (! Schema::hasColumn('blog_posts', 'cover_caption')) {
                $table->string('cover_caption', 240)->nullable()->after('cover_media_id');
            }
            if (! Schema::hasColumn('blog_posts', 'cover_credit')) {
                $table->string('cover_credit', 120)->nullable()->after('cover_caption');
            }
            if (! Schema::hasColumn('blog_posts', 'cover_focal_x')) {
                $table->unsignedTinyInteger('cover_focal_x')->nullable()->after('cover_credit');
            }
            if (! Schema::hasColumn('blog_posts', 'cover_focal_y')) {
                $table->unsignedTinyInteger('cover_focal_y')->nullable()->after('cover_focal_x');
            }
            if (! Schema::hasColumn('blog_posts', 'lock_version')) {
                $table->unsignedInteger('lock_version')->default(0)->after('is_pinned');
            }
        });

        // Backfill the Markdown articles that exist today (a few hundred rows at most, so a plain loop is fine).
        DB::table('blog_posts')->whereNull('body_html')->orderBy('id')->each(function ($row) {
            $html = Str::markdown((string) $row->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
            $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

            DB::table('blog_posts')->where('id', $row->id)->update([
                'body_html' => $html,
                'body_text' => $text,
                'reading_minutes' => max(1, (int) ceil($words / 200)),
                'slug' => $row->slug ?? Str::limit(Str::slug((string) $row->title), 80, ''),
            ]);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('blog_posts')) {
            return;
        }

        Schema::table('blog_posts', function (Blueprint $table) {
            if (Schema::hasColumn('blog_posts', 'cover_media_id')) {
                $table->dropConstrainedForeignId('cover_media_id');
            }
        });

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter([
                'body_format', 'body_json', 'body_html', 'body_text', 'reading_minutes', 'slug',
                'cover_caption', 'cover_credit', 'cover_focal_x', 'cover_focal_y', 'lock_version',
            ], fn (string $column) => Schema::hasColumn('blog_posts', $column))));
        });
    }
};
