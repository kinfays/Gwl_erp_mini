<?php

use Database\Seeders\MdmStarterPolicySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Ships the starter "GWL Standard Phone" policy with the schema, so a `migrate` on a running database ends up in the
 * same state as a fresh `migrate` + `db:seed` (DatabaseSeeder also calls the seeder; it never overwrites an edited policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mdm_policies') && Schema::hasTable('mdm_policy_apps')) {
            (new MdmStarterPolicySeeder)->run();
        }
    }

    public function down(): void
    {
        // The policy may have been edited and published; removing it is a deliberate action in the UI.
    }
};
