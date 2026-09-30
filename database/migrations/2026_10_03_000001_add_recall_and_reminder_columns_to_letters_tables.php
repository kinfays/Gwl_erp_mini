<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recall and reject must delete exactly the log a hop created for its recipient, so a log now remembers the hop that
 * made it (letter_status_logs.routing_history_id). routing_histories.reminded_at throttles "remind the recipient".
 *
 * Backfill: only hops that are still awaiting confirmation can ever be recalled or rejected, so only they are linked:
 * to the recipient's most recent Received log on that letter created at or after the hop and not already linked to
 * another hop. Confirmed hops are left alone (their recipient's log has moved on and is never deleted). Re-running only
 * looks at hops that are still unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letter_status_logs')
            && Schema::hasTable('routing_histories')
            && ! Schema::hasColumn('letter_status_logs', 'routing_history_id')) {
            Schema::table('letter_status_logs', function (Blueprint $table) {
                $table->foreignId('routing_history_id')
                    ->nullable()
                    ->after('secretariat_id')
                    ->constrained('routing_histories')
                    ->nullOnDelete();
                $table->index('routing_history_id');
            });
        }

        if (Schema::hasTable('routing_histories') && ! Schema::hasColumn('routing_histories', 'reminded_at')) {
            Schema::table('routing_histories', function (Blueprint $table) {
                $table->timestamp('reminded_at')->nullable()->after('resolution_note');
            });
        }

        $this->linkAwaitingHops();
    }

    public function down(): void
    {
        if (Schema::hasColumn('letter_status_logs', 'routing_history_id')) {
            Schema::table('letter_status_logs', function (Blueprint $table) {
                $table->dropIndex(['routing_history_id']);
                $table->dropConstrainedForeignId('routing_history_id');
            });
        }

        if (Schema::hasColumn('routing_histories', 'reminded_at')) {
            Schema::table('routing_histories', function (Blueprint $table) {
                $table->dropColumn('reminded_at');
            });
        }
    }

    private function linkAwaitingHops(): void
    {
        if (! Schema::hasColumn('letter_status_logs', 'routing_history_id')) {
            return;
        }

        $hops = DB::table('routing_histories')
            ->where('received_confirm', false)
            ->whereNull('resolution')
            ->whereNotExists(fn ($linked) => $linked->select(DB::raw(1))
                ->from('letter_status_logs')
                ->whereColumn('letter_status_logs.routing_history_id', 'routing_histories.id'))
            ->orderBy('id')
            ->get(['id', 'letter_id', 'to_secretariat_id', 'created_at']);

        foreach ($hops as $hop) {
            $logId = DB::table('letter_status_logs')
                ->where('letter_id', $hop->letter_id)
                ->where('secretariat_id', $hop->to_secretariat_id)
                ->where('status', 'Received')
                ->whereNull('routing_history_id')
                ->where('created_at', '>=', $hop->created_at)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('id');

            if ($logId) {
                DB::table('letter_status_logs')->where('id', $logId)->update(['routing_history_id' => $hop->id]);
            }
        }
    }
};
