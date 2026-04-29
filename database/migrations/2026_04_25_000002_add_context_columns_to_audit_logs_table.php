<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $needsUserName = ! Schema::hasColumn('audit_logs', 'user_name');
        $needsMetadata = ! Schema::hasColumn('audit_logs', 'metadata');

        if (! $needsUserName && ! $needsMetadata) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) use ($needsUserName, $needsMetadata) {
            if ($needsUserName) {
                $table->string('user_name')->nullable()->after('user_id');
            }

            if ($needsMetadata) {
                $table->json('metadata')->nullable()->after('ip_address');
            }
        });
    }

    public function down(): void
    {
        $hasMetadata = Schema::hasColumn('audit_logs', 'metadata');
        $hasUserName = Schema::hasColumn('audit_logs', 'user_name');

        if (! $hasMetadata && ! $hasUserName) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) use ($hasMetadata, $hasUserName) {
            if ($hasMetadata) {
                $table->dropColumn('metadata');
            }

            if ($hasUserName) {
                $table->dropColumn('user_name');
            }
        });
    }
};
