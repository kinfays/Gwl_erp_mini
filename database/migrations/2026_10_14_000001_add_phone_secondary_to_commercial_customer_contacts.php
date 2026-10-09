<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b: a customer's SECOND mobile number gets its own searchable column (the real export holds at most two numbers in the
 * Mobile cell). Staging carries it too, so the set-based merge copies it with the other contact fields. Existing contact rows
 * keep NULL until their contact hash changes (the hash now covers the "two numbers" bit), which a normal upload does by itself
 * for exactly the customers that have two numbers; nothing else is rewritten and no customers row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_customer_contacts') && ! Schema::hasColumn('commercial_customer_contacts', 'phone_secondary')) {
            Schema::table('commercial_customer_contacts', function (Blueprint $table): void {
                $table->string('phone_secondary', 20)->nullable()->after('phone_primary');
                $table->index('phone_secondary');
            });
        }

        if (Schema::hasTable('commercial_customer_staging') && ! Schema::hasColumn('commercial_customer_staging', 'phone_secondary')) {
            Schema::table('commercial_customer_staging', function (Blueprint $table): void {
                $table->string('phone_secondary', 20)->nullable()->after('phone_primary');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('commercial_customer_contacts', 'phone_secondary')) {
            Schema::table('commercial_customer_contacts', function (Blueprint $table): void {
                $table->dropIndex(['phone_secondary']);
                $table->dropColumn('phone_secondary');
            });
        }

        if (Schema::hasColumn('commercial_customer_staging', 'phone_secondary')) {
            Schema::table('commercial_customer_staging', function (Blueprint $table): void {
                $table->dropColumn('phone_secondary');
            });
        }
    }
};
