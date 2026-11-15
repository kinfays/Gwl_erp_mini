<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8b: about 5% of customers have TWO e-mail addresses in the one cell ("a@x.com / b@y.com"). The first is the primary (what is
 * shown and used, still in email / email_lower); the second gets its own column, stored lower-cased and indexed, because the search by
 * e-mail and the "shared e-mail" list look at it too. Staging carries it so the set-based merge copies it with the other contact
 * fields. Existing contact rows keep NULL until their contact hash changes, which a normal upload does by itself for exactly the
 * customers that have a second address (the hash only covers it when there is one); no customers row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_customer_contacts') && ! Schema::hasColumn('commercial_customer_contacts', 'email_secondary')) {
            Schema::table('commercial_customer_contacts', function (Blueprint $table): void {
                $table->string('email_secondary', 191)->nullable()->after('email_lower');
                $table->index('email_secondary');
            });
        }

        if (Schema::hasTable('commercial_customer_staging') && ! Schema::hasColumn('commercial_customer_staging', 'email_secondary')) {
            Schema::table('commercial_customer_staging', function (Blueprint $table): void {
                $table->string('email_secondary', 191)->nullable()->after('email_lower');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('commercial_customer_contacts', 'email_secondary')) {
            Schema::table('commercial_customer_contacts', function (Blueprint $table): void {
                $table->dropIndex(['email_secondary']);
                $table->dropColumn('email_secondary');
            });
        }

        if (Schema::hasColumn('commercial_customer_staging', 'email_secondary')) {
            Schema::table('commercial_customer_staging', function (Blueprint $table): void {
                $table->dropColumn('email_secondary');
            });
        }
    }
};
