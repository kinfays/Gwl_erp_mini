<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Closed" becomes a state of the letter (mail_letters.closed_at / closed_by_id) instead of a flag that close()
 * copied onto every holder's letter_status_logs row, which overwrote their Dispatched status and made a reopened
 * letter show a stale Closed pill on every past holder's desk. letter_status_logs.is_closed is no longer written.
 *
 * Backfill: close() used to mark every log of the letter, and only the creator could close, so any letter with a
 * closed log is closed, by its creator, at the time the logs were last touched. The logs themselves are left as
 * they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_letters')) {
            return;
        }

        if (! Schema::hasColumn('mail_letters', 'closed_at')) {
            Schema::table('mail_letters', function (Blueprint $table) {
                $table->timestamp('closed_at')->nullable()->after('created_by_id');
            });
        }

        if (! Schema::hasColumn('mail_letters', 'closed_by_id')) {
            Schema::table('mail_letters', function (Blueprint $table) {
                $table->foreignId('closed_by_id')
                    ->nullable()
                    ->after('closed_at')
                    ->constrained('employees')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('letter_status_logs') && Schema::hasColumn('letter_status_logs', 'is_closed')) {
            DB::table('mail_letters')
                ->whereNull('closed_at')
                ->whereExists(fn ($logs) => $logs->select(DB::raw(1))
                    ->from('letter_status_logs')
                    ->whereColumn('letter_status_logs.letter_id', 'mail_letters.id')
                    ->where('letter_status_logs.is_closed', true))
                ->update([
                    'closed_at' => DB::raw('(select max(updated_at) from letter_status_logs where letter_status_logs.letter_id = mail_letters.id and letter_status_logs.is_closed = 1)'),
                    'closed_by_id' => DB::raw('created_by_id'),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mail_letters', 'closed_by_id')) {
            Schema::table('mail_letters', function (Blueprint $table) {
                $table->dropConstrainedForeignId('closed_by_id');
            });
        }

        if (Schema::hasColumn('mail_letters', 'closed_at')) {
            Schema::table('mail_letters', function (Blueprint $table) {
                $table->dropColumn('closed_at');
            });
        }
    }
};
