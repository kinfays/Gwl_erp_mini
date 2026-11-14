<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8a, the codes the three full exports showed that the lookups did not know (design 8.30).
 *
 * Confirmed by the Commercial team (flags chosen to match the neighbouring statuses):
 *   HOLD  holding billing action: billing is paused, so it is NOT a billing status; the customer is still connected, so it is
 *         active (like ACTN)                                              active = yes, billing = no
 *   DISN  disconnected, no payment: the same group as DISC             active = no,  billing = no
 *   NSC   new service connection: new, not yet billing (like ACTN)     active = yes, billing = no
 *
 * Still unexplained, so seeded PENDING (shown in the "to classify" list; nothing guessed): status CNTL, category 999, and a blank
 * status, which now maps to the status UNKNOWN (it used to create a pending "?" row; an existing "?" row is renamed, so the
 * accounts that point at it keep working).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_customer_statuses') || ! Schema::hasTable('commercial_customer_categories')) {
            return;
        }

        $now = now();

        // [code, label, is_active, is_billing, meaning_confirmed, is_pending]
        $statuses = [
            ['HOLD', 'Holding billing action (billing paused)', true, false, true, false],
            ['DISN', 'Disconnected, no payment', false, false, true, false],
            ['NSC', 'New service connection (not yet billing)', true, false, true, false],
            ['CNTL', 'CNTL', false, false, false, true],
        ];

        foreach ($statuses as [$code, $label, $active, $billing, $confirmed, $pending]) {
            DB::table('commercial_customer_statuses')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'is_active' => $active, 'is_billing' => $billing, 'meaning_confirmed' => $confirmed, 'is_pending' => $pending, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        // A blank status maps to UNKNOWN (pending, not billing, not active).
        $legacy = DB::table('commercial_customer_statuses')->where('code', '?')->first();
        $unknown = DB::table('commercial_customer_statuses')->where('code', 'UNKNOWN')->first();

        if ($legacy && ! $unknown) {
            DB::table('commercial_customer_statuses')->where('id', $legacy->id)->update(['code' => 'UNKNOWN', 'label' => 'Unknown (no status given)', 'updated_at' => $now]);
        } elseif (! $unknown) {
            DB::table('commercial_customer_statuses')->insert(['code' => 'UNKNOWN', 'label' => 'Unknown (no status given)', 'is_active' => false, 'is_billing' => false, 'meaning_confirmed' => false, 'is_pending' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('commercial_customer_categories')->updateOrInsert(
            ['code' => '999'],
            ['name' => 'Category 999', 'category_group' => 'unknown', 'group_is_proposed' => true, 'is_unknown' => false, 'is_pending' => true, 'created_at' => $now, 'updated_at' => $now]
        );
    }

    public function down(): void
    {
        // The codes are data a file may already point at: nothing is removed.
    }
};
