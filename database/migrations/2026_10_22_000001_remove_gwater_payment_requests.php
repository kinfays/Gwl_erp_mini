<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-Water payments are no longer requested first and paid later: a payment is recorded when it is paid. Tidies what the old
 * flow left behind, without losing anything:
 *  - a payment that was only ever requested (never paid) never reduced what is owed, so it is marked voided with the reason
 *    below (it stays in the books, and the owed balance does not move);
 *  - the "payment request overdue after" setting and its reminder rows no longer mean anything and are removed.
 * Paid payments are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gw_supplier_payments') && Schema::hasColumn('gw_supplier_payments', 'status')) {
            DB::table('gw_supplier_payments')->where('status', 'requested')->update([
                'status' => 'voided',
                'voided_at' => now(),
                'void_reason' => 'Payment requests were removed: record a payment when it is paid.',
            ]);
        }

        if (Schema::hasTable('gw_settings')) {
            DB::table('gw_settings')->where('key', 'payment_overdue_days')->delete();
        }

        if (Schema::hasTable('gw_reminder_states')) {
            DB::table('gw_reminder_states')->where('kind', 'payment_overdue')->delete();
        }
    }

    public function down(): void
    {
        // The voided requests and the removed setting are not restored: nothing was paid or owed through them.
    }
};
