<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance and Audit change request, step D: the reminder settings become TWO independent sets (Finance and Audit), each with its own
 * on/off switch and repeat interval, stored per location in fin_settings (the key says which set it belongs to).
 *
 * The one shared key before, `remind_repeat_days`, is copied into BOTH sets (`finance_repeat_days` and `audit_repeat_days`) for
 * every location that had changed it, so no location's behaviour moves, and the old rows are removed. The other keys keep their names:
 * `audit_wait_days` now belongs to the Audit set, `queried_wait_days` and `acknowledge_wait_days` to the Finance set. Nothing else
 * changes: fin_reminder_states already holds any kind of wait (a `draft` kind needs no new column). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_settings')) {
            return;
        }

        $now = now();

        foreach (DB::table('fin_settings')->where('key', 'remind_repeat_days')->get() as $row) {
            foreach (['finance_repeat_days', 'audit_repeat_days'] as $key) {
                DB::table('fin_settings')->updateOrInsert(
                    ['unit' => $row->unit, 'key' => $key],
                    ['value' => $row->value, 'updated_by' => $row->updated_by, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        DB::table('fin_settings')->where('key', 'remind_repeat_days')->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_settings')) {
            return;
        }

        foreach (DB::table('fin_settings')->where('key', 'finance_repeat_days')->get() as $row) {
            DB::table('fin_settings')->updateOrInsert(
                ['unit' => $row->unit, 'key' => 'remind_repeat_days'],
                ['value' => $row->value, 'updated_by' => $row->updated_by, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        DB::table('fin_settings')->whereIn('key', ['finance_repeat_days', 'audit_repeat_days', 'finance_remind_enabled', 'audit_remind_enabled', 'draft_wait_days'])->delete();
    }
};
