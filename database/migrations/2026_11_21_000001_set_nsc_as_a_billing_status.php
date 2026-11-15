<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8b, step 0.3. The Commercial team confirmed that the status NSC (new service connection) IS a billing status: the account
 * is billed from the day it is connected. Phase 8a had set it "active, not billing" (migration 2026_11_20_000003, which stays as it
 * was: a migration is never edited), so this one flips the flag.
 *
 *   NSC   active = yes (unchanged)   billing = YES (was no)   meaning confirmed = yes
 *
 * HOLD (billing paused) and DISN (disconnected, no payment, with DISC) are not touched. Everything that counts "billing accounts"
 * reads commercial_customer_statuses.is_billing, so the base mix, the coverage eligibility, the arrears and debtor figures, the
 * consumption anomaly and the SMS billing group now include NSC; see design doc section 8.33 for the list of places and for how the
 * rollups already stored keep the figures they were built with.
 *
 * A fresh install runs the 8a migration first (NSC created as not billing) and this one straight after, so both paths end the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_customer_statuses')) {
            return;
        }

        $values = ['label' => 'New service connection', 'is_active' => true, 'is_billing' => true, 'meaning_confirmed' => true, 'is_pending' => false, 'updated_at' => now()];

        if (DB::table('commercial_customer_statuses')->where('code', 'NSC')->exists()) {
            DB::table('commercial_customer_statuses')->where('code', 'NSC')->update($values);
        } else {
            DB::table('commercial_customer_statuses')->insert(['code' => 'NSC', 'created_at' => now()] + $values);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('commercial_customer_statuses')) {
            return;
        }

        DB::table('commercial_customer_statuses')->where('code', 'NSC')->update(['is_billing' => false, 'label' => 'New service connection (not yet billing)', 'updated_at' => now()]);
    }
};
