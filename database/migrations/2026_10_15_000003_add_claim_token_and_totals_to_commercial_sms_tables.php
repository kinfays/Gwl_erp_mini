<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: a recipient row is claimed with a token so a worker knows exactly which rows it claimed (the claim itself is one atomic
 * UPDATE ... WHERE status = 'pending'); and a finished campaign keeps its totals (status => count) so the report survives the
 * retention purge that deletes the recipient rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_sms_recipients') && ! Schema::hasColumn('commercial_sms_recipients', 'claim_token')) {
            Schema::table('commercial_sms_recipients', function (Blueprint $table): void {
                $table->string('claim_token', 24)->nullable()->after('status');
                $table->index(['campaign_id', 'claim_token']);
            });
        }

        if (Schema::hasTable('commercial_sms_campaigns') && ! Schema::hasColumn('commercial_sms_campaigns', 'totals')) {
            Schema::table('commercial_sms_campaigns', function (Blueprint $table): void {
                $table->json('totals')->nullable()->after('credits_used');
                $table->timestamp('details_purged_at')->nullable()->after('cancelled_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('commercial_sms_recipients', 'claim_token')) {
            Schema::table('commercial_sms_recipients', function (Blueprint $table): void {
                $table->dropIndex(['campaign_id', 'claim_token']);
                $table->dropColumn('claim_token');
            });
        }

        if (Schema::hasColumn('commercial_sms_campaigns', 'totals')) {
            Schema::table('commercial_sms_campaigns', function (Blueprint $table): void {
                $table->dropColumn(['totals', 'details_purged_at']);
            });
        }
    }
};
